<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Ajax {

	public const NONCE_ACTION = 'db_ai_admin';

	public const MAX_UPLOAD_BYTES = 1048576; // 1 MB

	public const ALLOWED_MIMES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'application/vnd.ms-excel',
		'application/octet-stream',
	];

	public function register(): void {
		add_action( 'wp_ajax_db_ai_parse_csv', [ $this, 'parse_csv' ] );
		add_action( 'wp_ajax_db_ai_generate', [ $this, 'generate' ] );
		add_action( 'wp_ajax_db_ai_save_kwo', [ $this, 'save_kwo' ] );
		add_action( 'wp_ajax_db_ai_load_kwo', [ $this, 'load_kwo' ] );
		add_action( 'wp_ajax_db_ai_delete_kwo', [ $this, 'delete_kwo' ] );
	}

	/**
	 * Upload + opslaan van een zoekwoordenonderzoek (CSV) als CPT.
	 */
	public function save_kwo(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( [ 'message' => __( 'Geef het onderzoek een naam.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		if ( empty( $_FILES['csv'] ) || ! isset( $_FILES['csv']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen bestand ontvangen.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$file = $_FILES['csv'];

		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( [ 'message' => $this->upload_error_message( (int) $file['error'] ) ], 400 );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Ongeldige upload.', 'digitale-bazen-ai-module' ) ], 400 );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
			wp_send_json_error( [ 'message' => __( 'Bestand is te groot (max 1 MB).', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$filename  = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'upload.csv';
		$ext_check = wp_check_filetype_and_ext( $file['tmp_name'], $filename, [ 'csv' => 'text/csv' ] );
		$ext       = strtolower( $ext_check['ext'] ?? '' );

		if ( 'csv' !== $ext ) {
			wp_send_json_error( [ 'message' => __( 'Alleen .csv bestanden zijn toegestaan (xlsx/ods wordt door je browser eerst geconverteerd).', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$importer = new DB_AI_Keyword_Importer();
		$parsed   = $importer->parse_csv( $file['tmp_name'] );

		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( [ 'message' => $parsed->get_error_message() ], 400 );
		}

		$saved = DB_AI_Keyword_Research::save( $name, $parsed['rows'] );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( [ 'message' => $saved->get_error_message() ], 400 );
		}

		wp_send_json_success(
			[
				'id'    => (int) $saved,
				'name'  => $name,
				'count' => count( $parsed['rows'] ),
				'all'   => DB_AI_Keyword_Research::get_all(),
			]
		);
	}

	/**
	 * Laad een opgeslagen onderzoek (rijen + grouped) voor gebruik in de generator.
	 */
	public function load_kwo(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Geen onderzoek-id ontvangen.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$data = DB_AI_Keyword_Research::get_with_rows( $id );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( [ 'message' => $data->get_error_message() ], 404 );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Verwijder een opgeslagen onderzoek.
	 */
	public function delete_kwo(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Geen onderzoek-id ontvangen.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		if ( ! DB_AI_Keyword_Research::delete( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Verwijderen mislukt.', 'digitale-bazen-ai-module' ) ], 500 );
		}

		wp_send_json_success( [ 'all' => DB_AI_Keyword_Research::get_all() ] );
	}

	public function generate(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$main_keyword = isset( $_POST['main_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['main_keyword'] ) ) : '';
		if ( '' === $main_keyword ) {
			wp_send_json_error( [ 'message' => __( 'Hoofdzoekwoord ontbreekt.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$secondary = [];
		if ( ! empty( $_POST['secondary_keywords'] ) && is_array( $_POST['secondary_keywords'] ) ) {
			foreach ( wp_unslash( $_POST['secondary_keywords'] ) as $kw ) {
				$kw = sanitize_text_field( (string) $kw );
				if ( '' !== $kw ) {
					$secondary[] = $kw;
				}
			}
		}

		$blog_input = $this->collect_blog_input();

		$provider = $this->resolve_provider();
		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( [ 'message' => $provider->get_error_message() ], 400 );
		}

		$logger        = new DB_AI_Logger();
		$rate_limiter  = new DB_AI_Rate_Limiter( $logger );
		$user_id       = get_current_user_id();

		if ( ! $rate_limiter->can_generate( $user_id ) ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %d = limit per dag */
						__( 'Dagelijkse limiet (%d generaties) bereikt. Probeer morgen opnieuw.', 'digitale-bazen-ai-module' ),
						$rate_limiter->limit_per_day()
					),
				],
				429
			);
		}

		$creator = new DB_AI_Post_Creator(
			$provider,
			new DB_AI_ACF_Mapper(),
			new DB_AI_Image_Service(),
			new DB_AI_SEO_Mapper(),
			$logger
		);

		$result = $creator->create_from_keyword( $main_keyword, $secondary, $user_id, $blog_input );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message'           => $result->get_error_message(),
					'validation_errors' => $result->get_error_data()['validation_errors'] ?? null,
				],
				502
			);
		}

		wp_send_json_success(
			array_merge(
				$result,
				[
					'remaining_today' => $rate_limiter->remaining( $user_id ),
				]
			)
		);
	}

	/**
	 * Verzamelt + sanitizeert de per-blog input velden uit POST.
	 *
	 * @return array{type_content:string,funnel_phase:string,awareness_level:string,must_include:string,must_avoid:string,beat_competition:string,extra_instructions:string}
	 */
	private function collect_blog_input(): array {
		$enum_values = [
			'funnel_phase'    => [ 'tofu', 'mofu', 'bofu' ],
			'awareness_level' => [ 'unaware', 'problem', 'solution', 'product' ],
		];

		$out = [];

		// type_content is uit de UI verwijderd — generator produceert alleen nog blogs.
		// Hardcode 'blog' zodat de bijbehorende CONTENTTYPE-hint nog steeds in de
		// AI-prompt belandt via DB_AI_Blog_Input::TYPE_CONTENT_HINTS.
		$out['type_content'] = 'blog';

		foreach ( $enum_values as $key => $allowed ) {
			$raw          = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$out[ $key ]  = in_array( $raw, $allowed, true ) ? $raw : '';
		}

		foreach ( [ 'must_include', 'must_avoid', 'beat_competition', 'extra_instructions' ] as $key ) {
			$out[ $key ] = isset( $_POST[ $key ] )
				? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) )
				: '';
		}

		// Forced internal link IDs — max 5, gevalideerd op bestaan in Post_Creator.
		$out['forced_link_ids'] = [];
		if ( ! empty( $_POST['forced_link_ids'] ) && is_array( $_POST['forced_link_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', wp_unslash( $_POST['forced_link_ids'] ) ) );
			$out['forced_link_ids'] = array_values( array_slice( array_unique( $ids ), 0, 5 ) );
		}

		return $out;
	}

	public function parse_csv(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		if ( empty( $_FILES['csv'] ) || ! isset( $_FILES['csv']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen bestand ontvangen.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$file = $_FILES['csv'];

		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( [ 'message' => $this->upload_error_message( (int) $file['error'] ) ], 400 );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Ongeldige upload.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		if ( (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
			wp_send_json_error( [ 'message' => __( 'CSV is te groot (max 1 MB).', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$filename  = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'upload.csv';
		$ext_check = wp_check_filetype_and_ext( $file['tmp_name'], $filename, [ 'csv' => 'text/csv' ] );
		$ext       = strtolower( $ext_check['ext'] ?? '' );

		if ( 'csv' !== $ext ) {
			wp_send_json_error( [ 'message' => __( 'Alleen .csv bestanden zijn toegestaan.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$type = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';
		if ( '' !== $type && ! in_array( $type, self::ALLOWED_MIMES, true ) ) {
			wp_send_json_error(
				[
					/* translators: %s = detected MIME */
					'message' => sprintf( __( 'Onverwacht bestandstype: %s.', 'digitale-bazen-ai-module' ), $type ),
				],
				400
			);
		}

		$importer = new DB_AI_Keyword_Importer();
		$parsed   = $importer->parse_csv( $file['tmp_name'] );

		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( [ 'message' => $parsed->get_error_message() ], 400 );
		}

		wp_send_json_success(
			[
				'rows'    => $parsed['rows'],
				'grouped' => $parsed['grouped'],
				'count'   => count( $parsed['rows'] ),
			]
		);
	}

	/**
	 * Kies provider. Voorrangsorde (via DB_AI_Settings, dat wp-config constants laat winnen):
	 *  - Provider expliciet gekozen ('anthropic' | 'openai')
	 *  - Anders Anthropic als anthropic-key beschikbaar
	 *  - Anders OpenAI als openai-key beschikbaar
	 *
	 * @return DB_AI_Provider|WP_Error
	 */
	private function resolve_provider() {
		$forced        = DB_AI_Settings::get_provider();
		$anthropic_key = DB_AI_Settings::get_api_key( 'anthropic' );
		$openai_key    = DB_AI_Settings::get_api_key( 'openai' );

		$prefer_anthropic = ( 'anthropic' === $forced )
			|| ( '' === $forced && '' !== trim( $anthropic_key ) );

		if ( $prefer_anthropic ) {
			if ( '' === trim( $anthropic_key ) ) {
				return new WP_Error(
					'db_ai_missing_anthropic_key',
					__( 'Anthropic API-sleutel ontbreekt. Stel hem in onder Instellingen → AI Module, of definieer DB_AI_ANTHROPIC_API_KEY in wp-config.php.', 'digitale-bazen-ai-module' )
				);
			}
			return new DB_AI_Anthropic_Provider( $anthropic_key );
		}

		if ( '' === trim( $openai_key ) ) {
			return new WP_Error(
				'db_ai_missing_openai_key',
				__( 'OpenAI API-sleutel ontbreekt en geen Anthropic-key beschikbaar. Stel een key in onder Instellingen → AI Module.', 'digitale-bazen-ai-module' )
			);
		}
		return new DB_AI_OpenAI_Provider( $openai_key );
	}

	private function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'Bestand is te groot.', 'digitale-bazen-ai-module' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'Upload onvolledig.', 'digitale-bazen-ai-module' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Geen bestand ontvangen.', 'digitale-bazen-ai-module' );
			default:
				return __( 'Upload mislukt.', 'digitale-bazen-ai-module' );
		}
	}
}
