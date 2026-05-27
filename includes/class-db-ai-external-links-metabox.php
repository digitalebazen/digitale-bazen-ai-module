<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metabox die AI-gegenereerde externe link-suggesties toont en de gekozen
 * suggesties op verzoek invoegt in de ACF flex-content.
 *
 * Suggesties zijn opgeslagen in post-meta {@see DB_AI_External_Links::META_KEY}
 * door {@see DB_AI_Post_Creator}. URL-status (groen/oranje) wordt bij metabox-load
 * via HEAD-check bepaald en 24u gecached.
 */
final class DB_AI_External_Links_Metabox {

	public const NONCE_ACTION = 'db_ai_external_links_metabox';
	public const NONCE_NAME   = 'db_ai_external_links_nonce';
	public const META_BOX_ID  = 'db-ai-external-links';

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_action( 'wp_ajax_db_ai_insert_external_link', [ $this, 'ajax_insert' ] );
		add_action( 'wp_ajax_db_ai_dismiss_external_link', [ $this, 'ajax_dismiss' ] );
	}

	public function register_metabox(): void {
		$post_types = $this->get_applicable_post_types();
		foreach ( $post_types as $pt ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'AI — Externe bronnen', 'digitale-bazen-ai-module' ),
				[ $this, 'render_metabox' ],
				$pt,
				'side',
				'default'
			);
		}
	}

	public function maybe_enqueue_assets( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->get_applicable_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'db-ai-external-links-metabox',
			DB_AI_PLUGIN_URL . 'assets/external-links-metabox.js',
			[ 'jquery' ],
			DB_AI_VERSION,
			true
		);
		wp_localize_script(
			'db-ai-external-links-metabox',
			'dbAiExternalLinks',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'postId'  => (int) $post->ID,
				'i18n'    => [
					'inserting'    => __( 'Bezig met invoegen…', 'digitale-bazen-ai-module' ),
					'inserted'     => __( 'Link ingevoegd.', 'digitale-bazen-ai-module' ),
					'insertFailed' => __( 'Invoegen mislukt.', 'digitale-bazen-ai-module' ),
					'noSelection'  => __( 'Selecteer eerst minstens één suggestie.', 'digitale-bazen-ai-module' ),
					'networkError' => __( 'Netwerkfout.', 'digitale-bazen-ai-module' ),
					'confirmDismiss' => __( 'Deze suggestie verwijderen?', 'digitale-bazen-ai-module' ),
				],
			]
		);
	}

	public function render_metabox( WP_Post $post ): void {
		$suggestions = get_post_meta( $post->ID, DB_AI_External_Links::META_KEY, true );
		$inserted    = get_post_meta( $post->ID, DB_AI_External_Links::META_KEY_INSERTED, true );
		$inserted    = is_array( $inserted ) ? $inserted : [];

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( empty( $suggestions ) || ! is_array( $suggestions ) ) {
			echo '<p class="description">' . esc_html__( 'Geen externe link-suggesties beschikbaar voor deze post.', 'digitale-bazen-ai-module' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Suggesties worden automatisch gegenereerd bij nieuwe blogs als de feature aanstaat in Settings.', 'digitale-bazen-ai-module' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'AI-gegenereerde voorstellen voor externe bronnen. Vink aan welke je wilt invoegen — links worden geplaatst in het aangegeven blok.', 'digitale-bazen-ai-module' ) . '</p>';

		echo '<ul class="db-ai-extlinks-list" style="margin:8px 0 12px;">';
		foreach ( $suggestions as $i => $s ) {
			$check  = DB_AI_External_Links::validate_url( (string) $s['url'] );
			$status = $check['status'] ?? 'ok';
			$icon   = $this->status_icon( $status );
			$label  = $this->status_label( $status );

			printf(
				'<li data-index="%1$d" style="margin:8px 0;padding:8px;background:#f6f7f7;border-left:3px solid %5$s;">' .
				'<label style="display:flex;align-items:flex-start;gap:6px;">' .
				'<input type="checkbox" class="db-ai-extlink-check" value="%1$d">' .
				'<span style="flex:1;">' .
				'<strong>%2$s</strong> %3$s<br>' .
				'<a href="%4$s" target="_blank" rel="noopener" style="word-break:break-all;font-size:11px;">%4$s</a>' .
				'</span></label>' .
				'<p class="description" style="margin:4px 0 0 22px;font-size:11px;">' .
				'%6$s · <em>blok %7$d</em>' .
				'</p>' .
				'<button type="button" class="button-link db-ai-extlink-dismiss" data-index="%1$d" style="margin-left:22px;font-size:11px;color:#a00;">%8$s</button>' .
				'</li>',
				(int) $i,
				esc_html( (string) $s['anchor'] ),
				$icon . ' <small style="color:#666;">(' . esc_html( $label ) . ')</small>',
				esc_url( (string) $s['url'] ),
				esc_attr( $this->status_color( $status ) ),
				esc_html( (string) ( $s['why'] ?? '' ) ),
				(int) ( $s['block_index'] ?? 0 ),
				esc_html__( 'Verwerpen', 'digitale-bazen-ai-module' )
			);
		}
		echo '</ul>';

		echo '<button type="button" class="button button-primary" id="db-ai-extlinks-insert">'
			. esc_html__( 'Geselecteerde invoegen', 'digitale-bazen-ai-module' )
			. '</button>';
		echo '<div id="db-ai-extlinks-status" class="db-ai-status" style="margin-top:8px;" role="status" aria-live="polite"></div>';

		if ( ! empty( $inserted ) ) {
			echo '<details style="margin-top:12px;font-size:11px;color:#555;">';
			echo '<summary>' . esc_html__( 'Al ingevoegd (audit)', 'digitale-bazen-ai-module' ) . '</summary>';
			echo '<ul style="margin:6px 0;padding-left:18px;">';
			foreach ( $inserted as $entry ) {
				printf(
					'<li>%s → <code>%s</code></li>',
					esc_html( (string) ( $entry['anchor'] ?? '' ) ),
					esc_html( (string) ( $entry['url'] ?? '' ) )
				);
			}
			echo '</ul></details>';
		}
	}

	public function ajax_insert(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$raw_indexes = isset( $_POST['indexes'] ) && is_array( $_POST['indexes'] ) ? $_POST['indexes'] : [];
		$indexes     = array_map( 'absint', $raw_indexes );
		$indexes     = array_values( array_unique( $indexes ) );
		if ( empty( $indexes ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen selectie ontvangen.', 'digitale-bazen-ai-module' ) ] );
		}

		$suggestions = get_post_meta( $post_id, DB_AI_External_Links::META_KEY, true );
		if ( ! is_array( $suggestions ) || empty( $suggestions ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen suggesties gevonden voor deze post.', 'digitale-bazen-ai-module' ) ] );
		}

		if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
			wp_send_json_error( [ 'message' => __( 'ACF niet beschikbaar.', 'digitale-bazen-ai-module' ) ] );
		}

		// Field-KEY (niet -name) gebruiken voor lees én write. Dat voorkomt dat ACF
		// naar de verkeerde field group schrijft als er meerdere groups met
		// dezelfde flex-name bestaan — zelfde patroon als DB_AI_ACF_Mapper.
		$acf_mapper      = new DB_AI_ACF_Mapper();
		$flex_field_key  = $acf_mapper->get_flex_field_key();
		$flex_field_name = $acf_mapper->get_flex_field_name();
		$read_target     = '' !== $flex_field_key ? $flex_field_key : $flex_field_name;
		$write_target    = $read_target;
		if ( '' === $write_target ) {
			wp_send_json_error( [ 'message' => __( 'Geen ACF flex field geconfigureerd.', 'digitale-bazen-ai-module' ) ] );
		}

		// Derde arg `false` = unformatted: images blijven attachment-IDs, selects
		// blijven raw values. Anders krijgen we image-arrays terug die ACF bij
		// update_field niet correct terug-serialiseert.
		$rows = get_field( $read_target, $post_id, false );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen flex-content gevonden om de link in te plaatsen.', 'digitale-bazen-ai-module' ) ] );
		}

		$inserted_audit = get_post_meta( $post_id, DB_AI_External_Links::META_KEY_INSERTED, true );
		$inserted_audit = is_array( $inserted_audit ) ? $inserted_audit : [];

		$results = [];
		$diag    = [];
		$trace   = []; // Per-suggestie: welk block, welk veld, lengte voor/na
		foreach ( $indexes as $idx ) {
			if ( ! isset( $suggestions[ $idx ] ) ) {
				$results[ $idx ] = 'missing';
				$diag[ $idx ]    = 'suggestion-index niet in opgeslagen lijst';
				continue;
			}
			$entry      = $suggestions[ $idx ];
			$reason     = '';
			$trace_item = [];
			$ok         = $this->inject_link_into_rows( $rows, $entry, $reason, $trace_item );
			$results[ $idx ] = $ok ? 'ok' : 'failed';
			$trace[ $idx ]   = $trace_item;
			if ( ! $ok ) {
				$diag[ $idx ] = $reason;
			}
			if ( $ok ) {
				$inserted_audit[] = [
					'anchor' => (string) $entry['anchor'],
					'url'    => (string) $entry['url'],
					'when'   => current_time( 'mysql' ),
				];
				unset( $suggestions[ $idx ] );
			}
		}

		// Pre-TOC werkte de write zonder normalize-stap (rauwe field-key-keyed rows
		// direct naar update_field). Houd dat pad — gebruiker bevestigde dat het toen
		// werkte. Mijn post-TOC normalize_rows_to_names() was waarschijnlijk de regressie.
		$update_result = update_field( $write_target, $rows, $post_id );

		// Bypass ACF's in-request cache: lees de RAUWE meta direct uit `wp_postmeta`.
		// ACF flex slaat sub-field waardes op als `{flex_name}_{row_idx}_{subfield_name}`.
		// Per trace-item bouwen we die key en checken of de URL in de raw DB-string staat.
		$flex_field_name = $acf_mapper->get_flex_field_name();
		foreach ( $trace as $idx => &$t ) {
			$final_block = $t['final_block'] ?? null;
			$field_name  = $t['field_name'] ?? '';
			$url         = (string) ( $t['url'] ?? '' );
			if ( null === $final_block || '' === $field_name || '' === $url ) {
				continue;
			}
			$meta_key  = $flex_field_name . '_' . (int) $final_block . '_' . $field_name;
			$raw_value = get_post_meta( $post_id, $meta_key, true );

			$t['raw_db_meta_key']      = $meta_key;
			$t['raw_db_is_string']     = is_string( $raw_value );
			$t['raw_db_length']        = is_string( $raw_value ) ? strlen( $raw_value ) : 0;
			$t['raw_db_contains_url']  = is_string( $raw_value ) && false !== strpos( $raw_value, $url );
			$t['raw_db_tail']          = is_string( $raw_value ) ? substr( $raw_value, -300 ) : '(non-string)';
		}
		unset( $t );

		// Verificatie via ACF (kan cache-pollutie geven, alleen secundair signaal).
		$verify_rows = get_field( $write_target, $post_id );
		$persisted   = $this->find_anchors_in_rows( $verify_rows );

		$debug = (bool) apply_filters( 'db_ai_debug_external_links_insert', false );
		if ( $debug ) {
			error_log( 'DB_AI EXT-LINK INSERT — write_target: ' . $write_target . ', result: ' . var_export( $update_result, true ) );
			error_log( 'DB_AI EXT-LINK INSERT — rows: ' . wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			error_log( 'DB_AI EXT-LINK INSERT — persisted anchors: ' . wp_json_encode( $persisted, JSON_UNESCAPED_UNICODE ) );
		}

		// Suggesties die niet meer relevant zijn opnieuw indexeren zodat de JS-indexes
		// na een page-reload weer kloppen.
		$suggestions = array_values( $suggestions );
		if ( empty( $suggestions ) ) {
			delete_post_meta( $post_id, DB_AI_External_Links::META_KEY );
		} else {
			update_post_meta( $post_id, DB_AI_External_Links::META_KEY, $suggestions );
		}

		update_post_meta( $post_id, DB_AI_External_Links::META_KEY_INSERTED, $inserted_audit );

		$ok_count   = count( array_filter( $results, fn( $r ) => 'ok' === $r ) );
		$fail_count = count( $results ) - $ok_count;

		// Kruisverwijzing trace ↔ persisted: ging de URL daadwerkelijk naar de DB?
		foreach ( $trace as $idx => &$t ) {
			$url = (string) ( $t['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$t['persisted_in_db'] = in_array( $url, $persisted, true );
		}
		unset( $t );

		$message = sprintf(
			/* translators: %d = aantal */
			_n( '%d link verwerkt.', '%d links verwerkt.', $ok_count, 'digitale-bazen-ai-module' ),
			$ok_count
		);
		if ( $fail_count > 0 && ! empty( $diag ) ) {
			$message .= ' — ' . implode( '; ', $diag );
		}

		// Trace-warning als de write claimt succes maar de URL niet in de DB landde.
		$missing_in_db = array_filter( $trace, fn( $t ) => ! empty( $t ) && empty( $t['persisted_in_db'] ?? true ) );
		if ( $ok_count > 0 && ! empty( $missing_in_db ) ) {
			$message .= ' ⚠ ACF write claimde succes maar URL niet teruggevonden in DB — zie diagnose.';
		}

		wp_send_json_success(
			[
				'results'         => $results,
				'diag'            => $diag,
				'trace'           => $trace,
				'update_result'   => $update_result,
				'persisted_urls'  => $persisted,
				'message'         => $message,
			]
		);
	}

	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1;
		if ( $post_id <= 0 || $index < 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$suggestions = get_post_meta( $post_id, DB_AI_External_Links::META_KEY, true );
		if ( ! is_array( $suggestions ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen suggesties.', 'digitale-bazen-ai-module' ) ] );
		}

		unset( $suggestions[ $index ] );
		$suggestions = array_values( $suggestions );

		if ( empty( $suggestions ) ) {
			delete_post_meta( $post_id, DB_AI_External_Links::META_KEY );
		} else {
			update_post_meta( $post_id, DB_AI_External_Links::META_KEY, $suggestions );
		}

		wp_send_json_success();
	}

	/**
	 * Walk de flex-rows, vind het aangewezen block en injecteer de link in z'n
	 * body-text veld. Mutate-by-reference op `$rows`. `$reason` vangt waarom een
	 * insert faalt zodat het naar de UI gebubbeld kan worden voor diagnose.
	 *
	 * Veld-detectie is heuristisch (naam bevat tekst/antwoord/content/body en
	 * geen titel) zodat het ook werkt op sites met andere veld-namen dan de
	 * Digitale Bazen-defaults.
	 */
	private function inject_link_into_rows( array &$rows, array $entry, string &$reason, array &$trace ): bool {
		$target = (int) ( $entry['block_index'] ?? 0 );
		$anchor = (string) ( $entry['anchor'] ?? '' );
		$url    = (string) ( $entry['url'] ?? '' );

		$trace = [
			'requested_block' => $target,
			'anchor'          => $anchor,
			'url'             => $url,
		];

		if ( '' === $anchor || '' === $url ) {
			$reason = 'anchor of url leeg';
			return false;
		}

		// Resolve target block.
		if ( ! isset( $rows[ $target ] ) ) {
			$alt = $this->find_block_with_body_field( $rows );
			if ( $alt < 0 ) {
				$reason = sprintf( 'block_index %d buiten bereik en geen block met body-text gevonden', $target );
				return false;
			}
			$target = $alt;
		}
		$trace['resolved_block'] = $target;
		$trace['layout']         = (string) ( $rows[ $target ]['acf_fc_layout'] ?? '' );

		// Pick the best body-text field on the target block.
		$field = $this->pick_body_text_field( $rows[ $target ] );

		// Geen geschikt veld op target → zoek een ander block dat er wel een heeft.
		if ( null === $field ) {
			$alt = $this->find_block_with_body_field( $rows, $target );
			if ( $alt < 0 ) {
				$keys = is_array( $rows[ $target ] ) ? array_keys( $rows[ $target ] ) : [];
				$reason = sprintf(
					'geen body-text veld op block %d (velden: %s) en geen ander block met body',
					$target,
					implode( ',', $keys )
				);
				return false;
			}
			$target = $alt;
			$field  = $this->pick_body_text_field( $rows[ $target ] );
		}
		if ( null === $field ) {
			$reason = 'pick_body_text_field gaf null terug na fallback (onverwacht)';
			return false;
		}

		$trace['final_block']      = $target;
		$trace['final_layout']     = (string) ( $rows[ $target ]['acf_fc_layout'] ?? '' );
		$trace['field_key']        = $field;
		$trace['field_name']       = $this->resolve_field_meta( (string) $field )['name'];

		$current = (string) ( $rows[ $target ][ $field ] ?? '' );
		$trace['len_before']       = strlen( $current );

		$new_html = $this->inject_into_html( $current, $anchor, $url );
		$method   = null !== $new_html ? 'inline-replace' : 'fallback-append';
		if ( null === $new_html ) {
			$new_html = $current . "\n" . $this->fallback_paragraph( $anchor, $url );
		}

		$rows[ $target ][ $field ] = $new_html;
		$trace['len_after']        = strlen( $new_html );
		$trace['method']           = $method;
		$trace['contains_anchor_post_inject'] = ( false !== strpos( $new_html, $url ) );

		return true;
	}

	/**
	 * Walk de gerenderde flex-rows na een write om te checken welke URLs daadwerkelijk
	 * in de DB landden. Zoekt elke `<a href="…">` in alle string-velden recursief.
	 *
	 * @return string[]  Lijst van URLs die in de wysiwyg-output staan.
	 */
	private function find_anchors_in_rows( $data ): array {
		$urls = [];
		$this->collect_anchor_urls( $data, $urls );
		return array_values( array_unique( $urls ) );
	}

	private function collect_anchor_urls( $data, array &$urls ): void {
		if ( is_string( $data ) ) {
			if ( false !== strpos( $data, '<a ' ) && preg_match_all( '/href=[\"\']([^\"\']+)[\"\']/i', $data, $m ) ) {
				foreach ( $m[1] as $u ) {
					$urls[] = (string) $u;
				}
			}
			return;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $v ) {
				$this->collect_anchor_urls( $v, $urls );
			}
		}
	}

	/**
	 * Selecteert het body-text veld van een block.
	 *
	 * Werkt zowel met rows die gekeyed zijn op field-NAMES (zoals AI-output) als
	 * met rows die gekeyed zijn op field-KEYS (zoals `get_field($k, $id, false)` retourneert
	 * voor opgeslagen ACF flex-content). Drie detectie-lagen:
	 *   1. ACF veld-type via `acf_get_field()` — `wysiwyg`/`textarea` zijn body.
	 *   2. Veld-naam heuristiek — bevat `tekst`/`antwoord`/`content`/`body`/`beschrijving`.
	 *   3. Content-based — HTML-tags of lange string (>80 chars) = body.
	 * Pakt het langst-gevulde matchende veld.
	 */
	private function pick_body_text_field( array $row ): ?string {
		$candidates = [];

		foreach ( $row as $key => $value ) {
			if ( 'acf_fc_layout' === $key ) {
				continue;
			}
			if ( ! is_string( $value ) && null !== $value ) {
				continue;
			}

			$resolved = $this->resolve_field_meta( (string) $key );
			$name_lc  = $resolved['name'];
			$type     = $resolved['type'];
			$str_val  = is_string( $value ) ? $value : '';

			// Hard skip op titels (ook als "tekst" in de naam staat, zoals 'tekst_kolom_titel').
			if ( false !== strpos( $name_lc, 'titel' ) ) {
				continue;
			}

			// Hard skip op niet-tekst types (image, select, link, repeater zonder body etc.).
			if ( in_array( $type, [ 'image', 'gallery', 'file', 'select', 'true_false', 'number', 'link', 'taxonomy', 'post_object', 'relationship' ], true ) ) {
				continue;
			}

			$by_type    = in_array( $type, [ 'wysiwyg', 'textarea' ], true );
			$by_name    = (
				false !== strpos( $name_lc, 'tekst' )
				|| false !== strpos( $name_lc, 'antwoord' )
				|| false !== strpos( $name_lc, 'content' )
				|| false !== strpos( $name_lc, 'body' )
				|| false !== strpos( $name_lc, 'beschrijving' )
				|| false !== strpos( $name_lc, 'omschrijving' )
			);
			$by_content = ( strlen( $str_val ) > 80 )
				|| false !== strpos( $str_val, '<p>' )
				|| false !== strpos( $str_val, '<ul>' )
				|| false !== strpos( $str_val, '<ol>' )
				|| false !== strpos( $str_val, '<br' );

			if ( ! ( $by_type || $by_name || $by_content ) ) {
				continue;
			}

			// Score: by_type/by_name krijgen een boost zodat ze winnen van lange titels die per ongeluk matchen.
			$score = strlen( $str_val ) + ( $by_type ? 100000 : 0 ) + ( $by_name ? 50000 : 0 );
			$candidates[ $key ] = $score;
		}

		if ( empty( $candidates ) ) {
			return null;
		}
		arsort( $candidates );
		return (string) array_key_first( $candidates );
	}

	/**
	 * Resolved field meta voor een row-key. Werkt zowel met `field_xxx`-keys
	 * (gebruikt door ACF in storage) als met field-names. Cached per request.
	 *
	 * @return array { name: string lowercase, type: string }
	 */
	private function resolve_field_meta( string $key ): array {
		static $cache = [];
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$name = strtolower( $key );
		$type = '';

		if ( 0 === strpos( $key, 'field_' ) && function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $key );
			if ( is_array( $field ) ) {
				$name = strtolower( (string) ( $field['name'] ?? $name ) );
				$type = (string) ( $field['type'] ?? '' );
			}
		}

		$cache[ $key ] = [ 'name' => $name, 'type' => $type ];
		return $cache[ $key ];
	}

	/**
	 * Zoekt het eerste block met een bruikbaar body-text veld. Optioneel
	 * `$skip_index` om een al-geprobeerd block over te slaan.
	 */
	private function find_block_with_body_field( array $rows, int $skip_index = -1 ): int {
		foreach ( $rows as $i => $row ) {
			if ( (int) $i === $skip_index ) {
				continue;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( null !== $this->pick_body_text_field( $row ) ) {
				return (int) $i;
			}
		}
		return -1;
	}

	/**
	 * Vervang het eerste case-insensitive voorkomen van `$anchor` in `$html` door
	 * een `<a>`-tag. Vermijdt vervanging binnen bestaande `<a>...</a>` of binnen
	 * tag-attributen.
	 *
	 * @return string|null  Aangepaste HTML, of null als anchor niet voorkwam.
	 */
	private function inject_into_html( string $html, string $anchor, string $url ): ?string {
		if ( '' === $html ) {
			return null;
		}

		// Skip if anchor zit binnen reeds bestaande <a>...</a>.
		$pattern = '#(?<!\w)(' . preg_quote( $anchor, '#' ) . ')(?!\w)#iu';

		$inside_anchor = false;
		$found         = false;
		$out           = '';
		$len           = strlen( $html );
		$i             = 0;

		while ( $i < $len ) {
			if ( ! $inside_anchor && 0 === substr_compare( $html, '<a ', $i, 3, true ) ) {
				$inside_anchor = true;
				$out          .= $html[ $i ];
				$i++;
				continue;
			}
			if ( $inside_anchor && 0 === substr_compare( $html, '</a>', $i, 4, true ) ) {
				$inside_anchor = false;
				$out          .= substr( $html, $i, 4 );
				$i           += 4;
				continue;
			}

			// Buiten een <a>-tag — probeer match op anchor vanaf positie $i.
			if ( ! $inside_anchor && ! $found ) {
				$rest = substr( $html, $i );
				if ( preg_match( $pattern, $rest, $m, PREG_OFFSET_CAPTURE ) && 0 === $m[1][1] ) {
					$matched = $m[1][0];
					$out    .= sprintf(
						'<a href="%s" target="_blank" rel="noopener">%s</a>',
						esc_url( $url ),
						esc_html( $matched )
					);
					$i    += strlen( $matched );
					$found = true;
					continue;
				}
			}

			$out .= $html[ $i ];
			$i++;
		}

		return $found ? $out : null;
	}

	private function fallback_paragraph( string $anchor, string $url ): string {
		return sprintf(
			'<p>%s <a href="%s" target="_blank" rel="noopener">%s</a>.</p>',
			esc_html__( 'Meer informatie:', 'digitale-bazen-ai-module' ),
			esc_url( $url ),
			esc_html( $anchor )
		);
	}

	private function status_icon( string $status ): string {
		$icons = [
			'ok'       => '✓',
			'redirect' => '↪',
			'blocked'  => '?',
			'timeout'  => '⏱',
			'dead'     => '✗',
		];
		return $icons[ $status ] ?? '?';
	}

	private function status_label( string $status ): string {
		$map = [
			'ok'       => __( 'bereikbaar', 'digitale-bazen-ai-module' ),
			'redirect' => __( 'redirected', 'digitale-bazen-ai-module' ),
			'blocked'  => __( 'check geblokkeerd', 'digitale-bazen-ai-module' ),
			'timeout'  => __( 'timeout', 'digitale-bazen-ai-module' ),
			'dead'     => __( 'onbereikbaar', 'digitale-bazen-ai-module' ),
		];
		return $map[ $status ] ?? $status;
	}

	private function status_color( string $status ): string {
		$colors = [
			'ok'       => '#2ecc71',
			'redirect' => '#f39c12',
			'blocked'  => '#bdc3c7',
			'timeout'  => '#e67e22',
			'dead'     => '#e74c3c',
		];
		return $colors[ $status ] ?? '#bdc3c7';
	}

	/**
	 * Voor welke post-types is de metabox relevant. Default: alle post types die
	 * de gekozen ACF field group dragen. Filterbaar via `db_ai_external_links_post_types`.
	 */
	private function get_applicable_post_types(): array {
		$default = [ 'blog', 'post', 'page' ];

		if ( class_exists( 'DB_AI_Settings' ) && function_exists( 'acf_get_field_group' ) ) {
			$group_key = DB_AI_Settings::get_field_group_key();
			if ( '' !== $group_key ) {
				$group = acf_get_field_group( $group_key );
				if ( ! empty( $group['location'] ) && is_array( $group['location'] ) ) {
					$detected = [];
					foreach ( $group['location'] as $rule_group ) {
						foreach ( $rule_group as $rule ) {
							if ( 'post_type' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
								$detected[] = (string) $rule['value'];
							}
						}
					}
					if ( ! empty( $detected ) ) {
						$default = array_values( array_unique( $detected ) );
					}
				}
			}
		}

		return (array) apply_filters( 'db_ai_external_links_post_types', $default );
	}
}
