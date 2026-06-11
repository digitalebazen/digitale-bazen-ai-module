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

	// Contentplan (v3.0.0, §0O/§14 Stap 8c) — opgeslagen als één JSON-meta op het
	// onderzoek (de db_ai_kwo-post zélf), niet als losse meta per keyword: het
	// onderzoek is één post met alle rijen, dus het plan reist als één array mee.
	public const META_PLAN         = '_db_ai_plan';
	public const META_PLAN_VERSION = '_db_ai_plan_version';
	public const META_PLAN_AT      = '_db_ai_plan_generated_at';

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

		// Er is altijd maar één onderzoek tegelijk. Bestaat er al een? → UPDATE die
		// IN-PLACE (zelfde post-id) i.p.v. verwijderen + opnieuw aanmaken. Zo blijven
		// het contentplan, de statussen en de post-koppelingen behouden: een nieuwe
		// upload is meestal een verversing van hetzelfde onderzoek, geen reset. Het
		// plan wordt pas echt bijgewerkt bij de volgende "Analyseer onderzoek", waar
		// reeds-gegenereerde keywords hun status behouden (zie save_plan()).
		$all = self::get_all();
		if ( ! empty( $all ) ) {
			$post_id = (int) $all[0]['id'];
			wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => $name,
				]
			);
			// Defensief: ruim eventuele verweesde extra onderzoeken op (zou er niet moeten zijn).
			foreach ( array_slice( $all, 1 ) as $extra ) {
				wp_delete_post( (int) $extra['id'], true );
			}
		} else {
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

	/**
	 * Het (enige) opgeslagen onderzoek, of null als er nog geen is.
	 *
	 * @return array{id:int,name:string,count:int,uploaded_at:string}|null
	 */
	public static function get_current(): ?array {
		$all = self::get_all();
		return $all[0] ?? null;
	}

	public static function delete( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Verwijder álle opgeslagen onderzoeken. Dwingt af dat er maar één onderzoek
	 * tegelijk bestaat — aangeroepen vanuit save() vóór een nieuwe upload.
	 */
	public static function delete_all(): void {
		$ids = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			]
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	// ─── Contentplan (§0O / §14 Stap 8c) ───────────────────────────────────

	/**
	 * Sla het contentplan op bij een onderzoek. Decoreert elke entry met
	 * status/post_id en behoudt bestaande status (`gegenereerd`/`gepubliceerd`)
	 * bij her-analyse, zodat al gegenereerde rijen niet terugvallen naar `open`.
	 *
	 * @param int   $id    db_ai_kwo post-id
	 * @param array $plan  Plan-entries uit DB_AI_Planner::build_plan()
	 */
	public static function save_plan( int $id, array $plan ): bool {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$existing = [];
		foreach ( self::get_plan( $id ) as $entry ) {
			$key = self::plan_key( (string) ( $entry['keyword'] ?? '' ) );
			if ( '' !== $key ) {
				$existing[ $key ] = $entry;
			}
		}

		$decorated = [];
		foreach ( $plan as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['keyword'] ) ) {
				continue;
			}
			$old             = $existing[ self::plan_key( (string) $entry['keyword'] ) ] ?? [];
			$keep            = ! empty( $old['status'] ) && in_array( $old['status'], [ 'gegenereerd', 'gepubliceerd' ], true );
			$entry['status'] = $keep ? $old['status'] : 'open';
			$entry['post_id'] = $keep && isset( $old['post_id'] ) ? (int) $old['post_id'] : 0;
			$decorated[]     = $entry;
		}

		update_post_meta( $id, self::META_PLAN, wp_slash( wp_json_encode( $decorated ) ) );
		$version = (int) get_post_meta( $id, self::META_PLAN_VERSION, true );
		update_post_meta( $id, self::META_PLAN_VERSION, $version + 1 );
		update_post_meta( $id, self::META_PLAN_AT, current_time( 'mysql', true ) );
		return true;
	}

	/** Het opgeslagen plan (lijst entries) of een lege array. */
	public static function get_plan( int $id ): array {
		$json = (string) get_post_meta( $id, self::META_PLAN, true );
		$plan = json_decode( $json, true );
		return is_array( $plan ) ? $plan : [];
	}

	public static function get_plan_version( int $id ): int {
		return (int) get_post_meta( $id, self::META_PLAN_VERSION, true );
	}

	/**
	 * Zet de status (+ optioneel post_id) van één plan-rij. Gebruikt door de
	 * koppeling met Creatie (§14 Stap 8d).
	 */
	public static function update_plan_status( int $id, string $keyword, string $status, int $post_id = 0 ): bool {
		$plan = self::get_plan( $id );
		if ( empty( $plan ) ) {
			return false;
		}
		$key     = self::plan_key( $keyword );
		$changed = false;
		foreach ( $plan as &$entry ) {
			if ( self::plan_key( (string) ( $entry['keyword'] ?? '' ) ) === $key ) {
				$entry['status'] = $status;
				if ( $post_id > 0 ) {
					$entry['post_id'] = $post_id;
				}
				$changed = true;
				break;
			}
		}
		unset( $entry );
		if ( $changed ) {
			update_post_meta( $id, self::META_PLAN, wp_slash( wp_json_encode( $plan ) ) );
		}
		return $changed;
	}

	/**
	 * Koppel een zojuist gegenereerde post aan het plan (§14 Stap 8d). Bestaat er
	 * een plan-rij voor het keyword → status `gegenereerd` + post_id. Is het een
	 * GEBUNDELD synoniem → promoveer het tot een eigen supporting-rij onder zijn
	 * pillar (en haal het uit de bundel), zodat de gebruiker het als verwant
	 * artikel kan genereren zonder de bundel-aanbeveling te verliezen.
	 */
	public static function attach_generated_post( int $id, string $keyword, int $post_id ): bool {
		$plan = self::get_plan( $id );
		if ( empty( $plan ) || $post_id <= 0 ) {
			return false;
		}
		$key = self::plan_key( $keyword );

		// 1. Bestaande entry → status bijwerken.
		foreach ( $plan as $i => $entry ) {
			if ( self::plan_key( (string) ( $entry['keyword'] ?? '' ) ) === $key ) {
				$plan[ $i ]['status']  = 'gegenereerd';
				$plan[ $i ]['post_id'] = $post_id;
				update_post_meta( $id, self::META_PLAN, wp_slash( wp_json_encode( $plan ) ) );
				return true;
			}
		}

		// 2. Gebundeld synoniem → promoveer naar supporting onder zijn pillar.
		foreach ( $plan as $i => $entry ) {
			if ( 'pillar' !== (string) ( $entry['role'] ?? '' ) ) {
				continue;
			}
			$bundled = (array) ( $entry['bundled_keywords'] ?? [] );
			$at      = null;
			foreach ( $bundled as $bi => $bk ) {
				if ( self::plan_key( (string) $bk ) === $key ) {
					$at = $bi;
					break;
				}
			}
			if ( null === $at ) {
				continue;
			}

			$promoted = (string) $bundled[ $at ];
			unset( $bundled[ $at ] );
			$plan[ $i ]['bundled_keywords'] = array_values( $bundled );

			$plan[] = [
				'keyword'          => $promoted,
				'volume'           => self::volume_for( $id, $promoted ),
				'intent'           => 'informatief',
				'cluster'          => (string) ( $entry['cluster'] ?? '' ),
				'role'             => 'supporting',
				'pillar_ref'       => (string) ( $entry['keyword'] ?? '' ),
				'bundled_keywords' => [],
				'angle'            => '',
				'funnel_target'    => null,
				'bridge'           => '',
				'link_target_hint' => '',
				'reason'           => __( 'Handmatig als verwant artikel gegenereerd (was een gebundeld synoniem).', 'digitale-bazen-ai-module' ),
				'status'           => 'gegenereerd',
				'post_id'          => $post_id,
			];
			update_post_meta( $id, self::META_PLAN, wp_slash( wp_json_encode( $plan ) ) );
			return true;
		}

		return false;
	}

	/** Maandvolume voor een zoekterm uit de onderzoek-rijen (0 indien onbekend). */
	private static function volume_for( int $id, string $keyword ): int {
		$data = self::get_with_rows( $id );
		if ( is_wp_error( $data ) ) {
			return 0;
		}
		$key = self::plan_key( $keyword );
		foreach ( (array) $data['rows'] as $row ) {
			if ( self::plan_key( (string) ( $row['zoekwoord'] ?? '' ) ) === $key ) {
				return (int) ( $row['volume'] ?? 0 );
			}
		}
		return 0;
	}

	/** Werk de inkaderende funnel-hoek ("angle") van één plan-rij bij (inline bewerkt in de UI, §0O/8c). */
	public static function update_plan_angle( int $id, string $keyword, string $angle ): bool {
		$plan = self::get_plan( $id );
		if ( empty( $plan ) ) {
			return false;
		}
		$key     = self::plan_key( $keyword );
		$changed = false;
		foreach ( $plan as $i => $entry ) {
			if ( self::plan_key( (string) ( $entry['keyword'] ?? '' ) ) === $key ) {
				$plan[ $i ]['angle'] = $angle;
				$changed             = true;
				break;
			}
		}
		if ( $changed ) {
			update_post_meta( $id, self::META_PLAN, wp_slash( wp_json_encode( $plan ) ) );
		}
		return $changed;
	}

	private static function plan_key( string $keyword ): string {
		$keyword = trim( $keyword );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword );
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
