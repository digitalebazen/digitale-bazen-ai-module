<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Image_Service {

	public const PEXELS_ENDPOINT   = 'https://api.pexels.com/v1/search';
	public const UNSPLASH_ENDPOINT = 'https://api.unsplash.com/search/photos';

	public const SEARCH_TIMEOUT   = 15;
	public const DOWNLOAD_TIMEOUT = 60;

	/**
	 * Search image, download, sideload to media library, return attachment ID.
	 *
	 * @param string $query    English search term
	 * @param string $alt_text Dutch alt text
	 * @param int    $post_id  Post to attach to (0 = unattached)
	 * @return int|WP_Error    Attachment ID
	 */
	public function find_and_import( string $query, string $alt_text, int $post_id = 0 ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new WP_Error( 'db_ai_image_empty_query', __( 'Lege zoekterm voor afbeelding.', 'digitale-bazen-ai-module' ) );
		}

		$orientation = (string) apply_filters( 'db_ai_image_orientation', 'landscape' );

		$photo = $this->search_pexels( $query, $orientation );
		if ( is_wp_error( $photo ) ) {
			$pexels_error = $photo;
			$photo        = $this->search_unsplash( $query, $orientation );
			if ( is_wp_error( $photo ) ) {
				return new WP_Error(
					'db_ai_no_image_found',
					sprintf(
						/* translators: 1 = pexels error, 2 = unsplash error */
						__( 'Geen afbeelding gevonden via Pexels of Unsplash. Pexels: %1$s. Unsplash: %2$s', 'digitale-bazen-ai-module' ),
						$pexels_error->get_error_message(),
						$photo->get_error_message()
					)
				);
			}
		}

		return $this->sideload_photo( $photo, $query, $alt_text, $post_id );
	}

	/**
	 * @return array|WP_Error  ['provider', 'image_url', 'source_url', 'photographer']
	 */
	private function search_pexels( string $query, string $orientation ) {
		$key = DB_AI_Settings::get_api_key( 'pexels' );
		if ( '' === trim( $key ) ) {
			return new WP_Error( 'db_ai_pexels_missing_key', __( 'Pexels API-key niet ingesteld (Instellingen → AI Module, of via DB_AI_PEXELS_API_KEY in wp-config.php).', 'digitale-bazen-ai-module' ) );
		}

		$url = add_query_arg(
			[
				'query'       => $query,
				'per_page'    => 5,
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

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$photos = $body['photos'] ?? [];
		if ( empty( $photos ) ) {
			return new WP_Error( 'db_ai_pexels_empty', __( 'Pexels gaf geen resultaten.', 'digitale-bazen-ai-module' ) );
		}

		$first = $photos[0];
		$image_url = $first['src']['large2x'] ?? $first['src']['large'] ?? $first['src']['original'] ?? '';
		if ( '' === $image_url ) {
			return new WP_Error( 'db_ai_pexels_no_url', __( 'Pexels resultaat zonder bruikbare URL.', 'digitale-bazen-ai-module' ) );
		}

		return [
			'provider'     => 'pexels',
			'image_url'    => $image_url,
			'source_url'   => $first['url'] ?? '',
			'photographer' => $first['photographer'] ?? '',
		];
	}

	/**
	 * @return array|WP_Error
	 */
	private function search_unsplash( string $query, string $orientation ) {
		$key = DB_AI_Settings::get_api_key( 'unsplash' );
		if ( '' === trim( $key ) ) {
			return new WP_Error( 'db_ai_unsplash_missing_key', __( 'Unsplash API-key niet ingesteld (optioneel — fallback voor Pexels).', 'digitale-bazen-ai-module' ) );
		}

		$url = add_query_arg(
			[
				'query'       => $query,
				'per_page'    => 5,
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

		$first     = $results[0];
		$image_url = $first['urls']['regular'] ?? $first['urls']['full'] ?? '';
		if ( '' === $image_url ) {
			return new WP_Error( 'db_ai_unsplash_no_url', __( 'Unsplash resultaat zonder bruikbare URL.', 'digitale-bazen-ai-module' ) );
		}

		return [
			'provider'     => 'unsplash',
			'image_url'    => $image_url,
			'source_url'   => $first['links']['html'] ?? '',
			'photographer' => $first['user']['name'] ?? '',
		];
	}

	/**
	 * @param array $photo  Result from search_pexels / search_unsplash
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

		$file_array = [
			'name'     => sanitize_file_name( $query . '-' . wp_generate_password( 6, false ) . '.jpg' ),
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

		return $attachment_id;
	}
}
