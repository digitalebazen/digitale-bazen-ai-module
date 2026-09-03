<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Image_Service {

	public const PEXELS_ENDPOINT   = 'https://api.pexels.com/v1/search';
	public const UNSPLASH_ENDPOINT = 'https://api.unsplash.com/search/photos';
	public const GEMINI_ENDPOINT   = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	public const GEMINI_DEFAULT_MODEL = 'gemini-2.5-flash-image';

	public const SEARCH_TIMEOUT   = 15;
	public const DOWNLOAD_TIMEOUT = 60;
	public const GENERATE_TIMEOUT = 60;

	/**
	 * Meta-key waarin het foto-id van de stockprovider bewaard wordt
	 * (`pexels:12345`). Hiermee slaan we bij een volgende generatie foto's over
	 * die al op deze site staan.
	 */
	public const META_STOCK_ID = '_db_ai_stock_id';

	/**
	 * Hoeveel zoekresultaten we opvragen om uit te kiezen. Tot v3.3.0 vroegen we
	 * er 5 op en pakten altijd nummer 1 — zelfde zoekterm gaf dus altijd dezelfde
	 * foto. Filterbaar via `db_ai_image_candidates`. Pexels staat max 80 toe,
	 * Unsplash max 30.
	 */
	public const DEFAULT_CANDIDATES = 30;

	/**
	 * Al gebruikte stockfoto's, als `provider:id => true`. Wordt één keer uit de
	 * database geladen en tijdens de generatie aangevuld, zodat twee blokken in
	 * dezelfde blog ook niet hetzelfde beeld krijgen.
	 *
	 * @var array<string,true>|null
	 */
	private $used_stock_ids = null;

	/**
	 * Resolve één afbeelding naar een attachment ID. Afhankelijk van de instelling
	 * `image_source` wordt het beeld AI-gegenereerd (Google Gemini) of via stockfoto's
	 * (Pexels → Unsplash) gezocht. Mislukt de AI-generatie, dan valt hij automatisch
	 * terug op stock — zodat een API-hapering nooit een blog zonder beeld oplevert.
	 *
	 * @param string $query    Engelse zoekterm / beeld-seed
	 * @param string $alt_text Nederlandse alt-tekst
	 * @param int    $post_id  Post om aan te koppelen (0 = los)
	 * @param array  $opts     Optioneel: [ 'role' => 'featured'|'block' ]
	 * @return int|WP_Error    Attachment ID
	 */
	public function find_and_import( string $query, string $alt_text, int $post_id = 0, array $opts = [] ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new WP_Error( 'db_ai_image_empty_query', __( 'Lege zoekterm voor afbeelding.', 'digitale-bazen-ai-module' ) );
		}

		$role = 'featured' === ( $opts['role'] ?? '' ) ? 'featured' : 'block';

		if ( $this->should_generate_ai( $role ) ) {
			$generated = $this->generate_with_gemini( $query, $alt_text, $post_id, $role );
			if ( ! is_wp_error( $generated ) ) {
				return $generated;
			}
			// Conform instelling: val terug op stockfoto's als generatie mislukt.
		}

		return $this->find_stock( $query, $alt_text, $post_id );
	}

	/**
	 * Bepaalt of dit beeld AI-gegenereerd moet worden op basis van de gekozen modus
	 * en de rol (featured vs. block).
	 */
	private function should_generate_ai( string $role ): bool {
		if ( ! class_exists( 'DB_AI_Settings' ) ) {
			return false;
		}
		$source = DB_AI_Settings::get_image_source();
		if ( 'ai_all' === $source ) {
			return true;
		}
		if ( 'ai_featured' === $source && 'featured' === $role ) {
			return true;
		}
		return false;
	}

	/**
	 * Zoek een stockfoto (Pexels primair, Unsplash fallback), download en sideload.
	 *
	 * @return int|WP_Error  Attachment ID
	 */
	private function find_stock( string $query, string $alt_text, int $post_id ) {
		$orientation = (string) apply_filters( 'db_ai_image_orientation', 'landscape' );

		$candidates = $this->search_pexels( $query, $orientation );
		if ( is_wp_error( $candidates ) ) {
			$pexels_error = $candidates;
			$candidates   = $this->search_unsplash( $query, $orientation );
			if ( is_wp_error( $candidates ) ) {
				return new WP_Error(
					'db_ai_no_image_found',
					sprintf(
						/* translators: 1 = pexels error, 2 = unsplash error */
						__( 'Geen afbeelding gevonden via Pexels of Unsplash. Pexels: %1$s. Unsplash: %2$s', 'digitale-bazen-ai-module' ),
						$pexels_error->get_error_message(),
						$candidates->get_error_message()
					)
				);
			}
		}

		$photo = $this->pick_photo( $candidates );
		if ( null === $photo ) {
			return new WP_Error( 'db_ai_no_image_found', __( 'Geen bruikbaar zoekresultaat.', 'digitale-bazen-ai-module' ) );
		}

		return $this->sideload_photo( $photo, $query, $alt_text, $post_id );
	}

	/**
	 * Kies een foto uit de zoekresultaten.
	 *
	 * De resultaten staan op relevantie, dus we nemen de eerste die nog niet
	 * eerder op deze site is gebruikt. Dat houdt het beeld passend én voorkomt
	 * dat elke blog met dezelfde zoekterm hetzelfde plaatje krijgt. Is alles al
	 * een keer langsgekomen, dan pakken we willekeurig iets uit de hele set —
	 * liever een herhaling dan een blog zonder beeld.
	 *
	 * @param array $candidates
	 * @return array|null
	 */
	private function pick_photo( array $candidates ): ?array {
		if ( empty( $candidates ) ) {
			return null;
		}

		$used = $this->used_stock_ids();

		foreach ( $candidates as $candidate ) {
			$id = (string) ( $candidate['stock_id'] ?? '' );
			if ( '' !== $id && ! isset( $used[ $id ] ) ) {
				return $candidate;
			}
		}

		return $candidates[ random_int( 0, count( $candidates ) - 1 ) ];
	}

	/**
	 * Alle stockfoto's die al eerder zijn geïmporteerd, als opzoeklijst.
	 *
	 * @return array<string,true>
	 */
	private function used_stock_ids(): array {
		if ( null === $this->used_stock_ids ) {
			global $wpdb;

			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
					self::META_STOCK_ID
				)
			);

			$this->used_stock_ids = array_fill_keys( array_map( 'strval', (array) $rows ), true );
		}

		return $this->used_stock_ids;
	}

	/** Onthoud een zojuist gebruikte foto voor de rest van deze generatie. */
	private function mark_stock_used( string $stock_id ): void {
		if ( '' === $stock_id ) {
			return;
		}
		$this->used_stock_ids();
		$this->used_stock_ids[ $stock_id ] = true;
	}

	/** Aantal zoekresultaten om uit te kiezen. */
	private function candidate_count(): int {
		$count = (int) apply_filters( 'db_ai_image_candidates', self::DEFAULT_CANDIDATES );
		return max( 1, min( 30, $count ) );
	}

	/**
	 * @return array|WP_Error  Lijst kandidaten op relevantie: [ ['provider','stock_id','image_url','source_url','photographer'], … ]
	 */
	private function search_pexels( string $query, string $orientation ) {
		$key = DB_AI_Settings::get_api_key( 'pexels' );
		if ( '' === trim( $key ) ) {
			return new WP_Error( 'db_ai_pexels_missing_key', __( 'Pexels API-key niet ingesteld (Instellingen → AI Module, of via DB_AI_PEXELS_API_KEY in wp-config.php).', 'digitale-bazen-ai-module' ) );
		}

		$url = add_query_arg(
			[
				'query'       => $query,
				'per_page'    => $this->candidate_count(),
				'orientation' => $orientation,
			],
			self::PEXELS_ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::SEARCH_TIMEOUT,
				'headers' => [
					'Authorization' => $key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'db_ai_pexels_status_error',
				sprintf( __( 'Pexels antwoordde met status %d.', 'digitale-bazen-ai-module' ), $code )
			);
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$photos = $body['photos'] ?? [];
		if ( empty( $photos ) ) {
			return new WP_Error( 'db_ai_pexels_empty', __( 'Pexels gaf geen resultaten.', 'digitale-bazen-ai-module' ) );
		}

		$candidates = [];
		foreach ( $photos as $photo ) {
			$image_url = $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'] ?? '';
			if ( '' === $image_url ) {
				continue;
			}
			$candidates[] = [
				'provider'     => 'pexels',
				'stock_id'     => isset( $photo['id'] ) ? 'pexels:' . $photo['id'] : '',
				'image_url'    => $image_url,
				'source_url'   => $photo['url'] ?? '',
				'photographer' => $photo['photographer'] ?? '',
			];
		}

		if ( empty( $candidates ) ) {
			return new WP_Error( 'db_ai_pexels_no_url', __( 'Pexels resultaat zonder bruikbare URL.', 'digitale-bazen-ai-module' ) );
		}

		return $candidates;
	}

	/**
	 * @return array|WP_Error  Lijst kandidaten, zelfde vorm als search_pexels().
	 */
	private function search_unsplash( string $query, string $orientation ) {
		$key = DB_AI_Settings::get_api_key( 'unsplash' );
		if ( '' === trim( $key ) ) {
			return new WP_Error( 'db_ai_unsplash_missing_key', __( 'Unsplash API-key niet ingesteld (optioneel — fallback voor Pexels).', 'digitale-bazen-ai-module' ) );
		}

		$url = add_query_arg(
			[
				'query'       => $query,
				'per_page'    => $this->candidate_count(),
				'orientation' => $orientation,
			],
			self::UNSPLASH_ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::SEARCH_TIMEOUT,
				'headers' => [
					'Authorization' => 'Client-ID ' . $key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'db_ai_unsplash_status_error',
				sprintf( __( 'Unsplash antwoordde met status %d.', 'digitale-bazen-ai-module' ), $code )
			);
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$results = $body['results'] ?? [];
		if ( empty( $results ) ) {
			return new WP_Error( 'db_ai_unsplash_empty', __( 'Unsplash gaf geen resultaten.', 'digitale-bazen-ai-module' ) );
		}

		$candidates = [];
		foreach ( $results as $result ) {
			$image_url = $result['urls']['regular'] ?? $result['urls']['full'] ?? '';
			if ( '' === $image_url ) {
				continue;
			}
			$candidates[] = [
				'provider'     => 'unsplash',
				'stock_id'     => isset( $result['id'] ) ? 'unsplash:' . $result['id'] : '',
				'image_url'    => $image_url,
				'source_url'   => $result['links']['html'] ?? '',
				'photographer' => $result['user']['name'] ?? '',
			];
		}

		if ( empty( $candidates ) ) {
			return new WP_Error( 'db_ai_unsplash_no_url', __( 'Unsplash resultaat zonder bruikbare URL.', 'digitale-bazen-ai-module' ) );
		}

		return $candidates;
	}

	/**
	 * @param array $photo  Eén gekozen kandidaat uit search_pexels / search_unsplash
	 * @return int|WP_Error  Attachment ID
	 */
	private function sideload_photo( array $photo, string $query, string $alt_text, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp = download_url( $photo['image_url'], self::DOWNLOAD_TIMEOUT );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Converteer de gedownloade JPG naar WebP. Lukt dat niet (server zonder
		// WebP-support of conversiefout), dan blijft het origineel behouden.
		$ext  = 'jpg';
		$webp = $this->convert_to_webp( $tmp );
		if ( ! is_wp_error( $webp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$tmp = $webp;
			$ext = 'webp';
		}

		$file_array = [
			'name'     => sanitize_file_name( $query . '-' . wp_generate_password( 6, false ) . '.' . $ext ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}
		if ( ! empty( $photo['source_url'] ) ) {
			update_post_meta( $attachment_id, '_db_ai_source_url', esc_url_raw( $photo['source_url'] ) );
		}
		if ( ! empty( $photo['photographer'] ) ) {
			update_post_meta( $attachment_id, '_db_ai_photographer', sanitize_text_field( $photo['photographer'] ) );
		}
		update_post_meta( $attachment_id, '_db_ai_source_provider', sanitize_key( $photo['provider'] ) );

		// Vastleggen wélke foto dit was, zodat een volgende generatie hem overslaat.
		$stock_id = (string) ( $photo['stock_id'] ?? '' );
		if ( '' !== $stock_id ) {
			update_post_meta( $attachment_id, self::META_STOCK_ID, sanitize_text_field( $stock_id ) );
			$this->mark_stock_used( $stock_id );
		}

		return $attachment_id;
	}

	/**
	 * Converteer een gedownloade afbeelding (JPG/PNG) naar WebP. Geeft het pad naar
	 * een nieuw tijdelijk .webp-bestand terug, of een WP_Error wanneer conversie niet
	 * mogelijk is — de caller valt dan terug op het origineel zodat er nooit een
	 * blog zonder beeld ontstaat.
	 *
	 * Filters:
	 *   - `db_ai_image_convert_webp` (bool)  conversie globaal aan/uit (default true)
	 *   - `db_ai_image_webp_quality` (int)   WebP-kwaliteit 1-100 (default 82)
	 *
	 * @return string|WP_Error  Pad naar het WebP-bestand
	 */
	private function convert_to_webp( string $source_path ) {
		if ( ! apply_filters( 'db_ai_image_convert_webp', true ) ) {
			return new WP_Error( 'db_ai_webp_disabled', __( 'WebP-conversie is uitgeschakeld.', 'digitale-bazen-ai-module' ) );
		}

		if ( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) {
			return new WP_Error( 'db_ai_webp_unsupported', __( 'De server ondersteunt geen WebP (GD/Imagick zonder WebP-support).', 'digitale-bazen-ai-module' ) );
		}

		$editor = wp_get_image_editor( $source_path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$quality = (int) apply_filters( 'db_ai_image_webp_quality', 82 );
		if ( $quality >= 1 && $quality <= 100 ) {
			$editor->set_quality( $quality );
		}

		$target = $source_path . '.webp';
		$saved  = $editor->save( $target, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $saved['path'];
	}

	// ─── Gemini (AI-gegenereerde afbeeldingen) ─────────────────────────────

	/**
	 * Genereer een afbeelding met Google Gemini (Nano Banana) en sideload hem.
	 *
	 * @return int|WP_Error  Attachment ID
	 */
	private function generate_with_gemini( string $query, string $alt_text, int $post_id, string $role ) {
		$key = DB_AI_Settings::get_api_key( 'gemini' );
		if ( '' === trim( $key ) ) {
			return new WP_Error( 'db_ai_gemini_missing_key', __( 'Gemini API-key niet ingesteld (Instellingen → API-keys, of via DB_AI_GEMINI_API_KEY in wp-config.php).', 'digitale-bazen-ai-module' ) );
		}

		$model  = (string) apply_filters( 'db_ai_gemini_image_model', self::GEMINI_DEFAULT_MODEL );
		$prompt = $this->build_gemini_prompt( $query, $alt_text, $role );
		$aspect = $this->gemini_aspect_ratio( $role );

		$body = [
			'contents'         => [
				[ 'parts' => [ [ 'text' => $prompt ] ] ],
			],
			'generationConfig' => [
				'responseModalities' => [ 'IMAGE' ],
				'imageConfig'        => [ 'aspectRatio' => $aspect ],
			],
		];

		$response = wp_remote_post(
			sprintf( self::GEMINI_ENDPOINT, rawurlencode( $model ) ),
			[
				'timeout' => self::GENERATE_TIMEOUT,
				'headers' => [
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $key,
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'db_ai_gemini_status_error',
				sprintf( __( 'Gemini antwoordde met status %d.', 'digitale-bazen-ai-module' ), $code )
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$inline  = $this->extract_gemini_image( $decoded );
		if ( is_wp_error( $inline ) ) {
			return $inline;
		}

		return $this->sideload_bytes( $inline['bytes'], $inline['mime'], $query, $alt_text, $post_id );
	}

	/**
	 * Bouwt de generatie-prompt: globale beeldstijl + onderwerp + alt-context +
	 * vaste guardrail tegen tekst/logo's in beeld. Filterbaar via
	 * `db_ai_gemini_image_prompt`.
	 */
	private function build_gemini_prompt( string $query, string $alt_text, string $role ): string {
		$style = class_exists( 'DB_AI_Settings' ) ? trim( DB_AI_Settings::get_image_style() ) : '';
		if ( '' === $style ) {
			$style = __( 'Professionele, realistische fotografie van hoge kwaliteit, natuurlijk licht.', 'digitale-bazen-ai-module' );
		}

		$parts   = [ $style, $query ];
		$alt_text = trim( $alt_text );
		if ( '' !== $alt_text ) {
			$parts[] = sprintf( __( 'Onderwerp: %s', 'digitale-bazen-ai-module' ), $alt_text );
		}
		// Guardrail: modellen renderen anders soms rommelige tekst.
		$parts[] = __( 'Geen tekst, letters, woorden, cijfers, watermerken of logo\'s in de afbeelding.', 'digitale-bazen-ai-module' );

		$prompt = implode( '. ', $parts );

		return (string) apply_filters( 'db_ai_gemini_image_prompt', $prompt, $query, $alt_text, $role );
	}

	/**
	 * Aspect ratio per rol: featured = breed (16:9), block = 4:3. Filterbaar.
	 */
	private function gemini_aspect_ratio( string $role ): string {
		$aspect = 'featured' === $role ? '16:9' : '4:3';
		return (string) apply_filters( 'db_ai_gemini_aspect_ratio', $aspect, $role );
	}

	/**
	 * Haal de eerste inline-image (base64 + mime) uit een Gemini-respons.
	 *
	 * @return array{bytes:string,mime:string}|WP_Error
	 */
	private function extract_gemini_image( $decoded ) {
		$parts = $decoded['candidates'][0]['content']['parts'] ?? null;
		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'db_ai_gemini_no_parts', __( 'Gemini-respons zonder bruikbare inhoud.', 'digitale-bazen-ai-module' ) );
		}

		foreach ( $parts as $part ) {
			// camelCase (REST) en snake_case (sommige SDK-proxies) ondersteunen.
			$inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
			$data   = $inline['data'] ?? '';
			if ( '' === $data ) {
				continue;
			}
			$bytes = base64_decode( $data, true );
			if ( false === $bytes || '' === $bytes ) {
				continue;
			}
			$mime = (string) ( $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png' );
			return [ 'bytes' => $bytes, 'mime' => $mime ];
		}

		return new WP_Error( 'db_ai_gemini_no_image', __( 'Gemini gaf geen afbeelding terug.', 'digitale-bazen-ai-module' ) );
	}

	/**
	 * Schrijf ruwe afbeeldingsbytes naar een tijdelijk bestand en sideload het.
	 *
	 * @return int|WP_Error  Attachment ID
	 */
	private function sideload_bytes( string $bytes, string $mime, string $query, string $alt_text, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$ext = 'image/jpeg' === $mime ? 'jpg' : ( 'image/webp' === $mime ? 'webp' : 'png' );
		$filename = sanitize_file_name( $query . '-' . wp_generate_password( 6, false ) . '.' . $ext );

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'db_ai_gemini_tmp_failed', __( 'Kon geen tijdelijk bestand maken voor de gegenereerde afbeelding.', 'digitale-bazen-ai-module' ) );
		}
		if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'db_ai_gemini_write_failed', __( 'Kon de gegenereerde afbeelding niet wegschrijven.', 'digitale-bazen-ai-module' ) );
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		if ( '' !== trim( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}
		update_post_meta( $attachment_id, '_db_ai_source_provider', 'gemini' );

		return $attachment_id;
	}
}
