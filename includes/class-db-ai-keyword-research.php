<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistente opslag van zoekwoordenonderzoeken als CPT `db_ai_kwo`.
 *
 * Eén CPT-entry = één opgeslagen onderzoek met naam (post_title), upload-datum
 * (post_date) en de geparseerde rijen in `_db_ai_kwo_rows` meta (JSON-encoded).
 *
 * Niet publiek zichtbaar — bedoeld als data store, niet als content type.
 */
class DB_AI_Keyword_Research {

	public const POST_TYPE = 'db_ai_kwo';

	public const META_ROWS  = '_db_ai_kwo_rows';
	public const META_COUNT = '_db_ai_kwo_count';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_cpt' ] );
	}

	public static function register_cpt(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'               => __( 'Zoekwoordenonderzoeken', 'digitale-bazen-ai-module' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => [ 'title' ],
			]
		);
	}

	/**
	 * Sla een nieuw onderzoek op. Returnt post_id of WP_Error.
	 *
	 * @param string $name  User-defined naam ("KWO 2026 Q1")
	 * @param array  $rows  Genormaliseerde rijen [{zoekwoord, volume, pagina, onderwerp, ...}]
	 * @return int|WP_Error
	 */
	public static function save( string $name, array $rows ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'db_ai_kwo_missing_name', __( 'Geef het onderzoek een naam.', 'digitale-bazen-ai-module' ) );
		}
		if ( empty( $rows ) ) {
			return new WP_Error( 'db_ai_kwo_empty', __( 'Geen zoekwoorden gevonden in het bestand.', 'digitale-bazen-ai-module' ) );
		}

		$post_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			],
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$json = wp_json_encode( array_values( $rows ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( (int) $post_id, self::META_ROWS, wp_slash( $json ) );
		update_post_meta( (int) $post_id, self::META_COUNT, count( $rows ) );

		return (int) $post_id;
	}

	/**
	 * Alle opgeslagen onderzoeken, recent eerst.
	 *
	 * @return array<int,array{id:int,name:string,count:int,uploaded_at:string}>
	 */
	public static function get_all(): array {
		$posts = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			]
		);

		$out = [];
		foreach ( $posts as $p ) {
			$out[] = [
				'id'          => (int) $p->ID,
				'name'        => $p->post_title,
				'count'       => (int) get_post_meta( $p->ID, self::META_COUNT, true ),
				'uploaded_at' => mysql2date( get_option( 'date_format' ) . ' H:i', $p->post_date ),
			];
		}
		return $out;
	}

	/**
	 * Laad de rijen + grouped structuur voor een specifiek onderzoek.
	 *
	 * @return array{rows:array,grouped:array,name:string,count:int}|WP_Error
	 */
	public static function get_with_rows( int $id ) {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'db_ai_kwo_not_found', __( 'Onderzoek niet gevonden.', 'digitale-bazen-ai-module' ) );
		}

		$json = (string) get_post_meta( $id, self::META_ROWS, true );
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'db_ai_kwo_corrupt', __( 'Opgeslagen rijen zijn corrupt — upload het onderzoek opnieuw.', 'digitale-bazen-ai-module' ) );
		}

		return [
			'rows'    => $rows,
			'grouped' => self::group_rows( $rows ),
			'name'    => $post->post_title,
			'count'   => count( $rows ),
		];
	}

	public static function delete( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Groepeert rijen per pagina → onderwerp → rijen[]. Matcht het format dat
	 * DB_AI_Keyword_Importer::parse_csv() teruggeeft.
	 */
	private static function group_rows( array $rows ): array {
		$grouped = [];
		foreach ( $rows as $row ) {
			$pagina    = (string) ( $row['pagina'] ?? '' );
			$onderwerp = (string) ( $row['onderwerp'] ?? '' );
			$pagina    = '' === $pagina ? '—' : $pagina;
			$onderwerp = '' === $onderwerp ? '—' : $onderwerp;
			$grouped[ $pagina ][ $onderwerp ][] = $row;
		}
		return $grouped;
	}
}
