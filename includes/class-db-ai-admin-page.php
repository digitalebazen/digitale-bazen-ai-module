<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Admin_Page {

	/**
	 * Slug van het eigen top-level menu "Generator" (sinds v3.0.0, §0N). Het
	 * eerste submenu (Creatie) deelt deze slug zodat WordPress geen dubbel
	 * "Generator"-item toont. Settings hangt als submenu onder dezelfde parent.
	 */
	public const MENU_SLUG = 'db-ai';

	/** Oude Creatie-slug (was submenu onder Blogs) — alleen nog voor de redirect-shim. */
	public const LEGACY_SLUG = 'db-ai-generator';

	private $page_hooks = [];

	public function register(): void {
		// Priority 9: het top-level menu + Creatie-submenu moeten geregistreerd zijn
		// vóór DB_AI_Settings (priority 11) er een submenu onder hangt, zodat de
		// volgorde Creatie → (Plan) → Instellingen klopt.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 9 );
		add_action( 'admin_init', [ $this, 'redirect_legacy_slugs' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
	}

	public function register_menu(): void {
		$capability = (string) apply_filters( 'db_ai_admin_menu_capability', 'publish_posts' );
		$parent     = (string) apply_filters( 'db_ai_admin_menu_parent', self::MENU_SLUG );
		$icon       = (string) apply_filters( 'db_ai_admin_menu_icon', 'dashicons-edit-page' );
		$position   = (int) apply_filters( 'db_ai_admin_menu_position', 26 ); // net onder Berichten (25)

		// NB: de oude filter `db_ai_admin_menu_parents` (meervoud, array) is sinds
		// v3.0.0 deprecated — het submenu hangt niet langer onder bestaande WP-menu's
		// maar onder het eigen top-level "Generator". De filter is een no-op (niet
		// langer aangeroepen); een bestaande override in functions.php geeft geen fatal.

		$top_hook = add_menu_page(
			__( 'Generator', 'digitale-bazen-ai-module' ),
			__( 'Generator', 'digitale-bazen-ai-module' ),
			$capability,
			$parent,
			[ $this, 'render' ],
			$icon,
			$position
		);
		if ( $top_hook ) {
			$this->page_hooks[] = $top_hook;
		}

		// Eerste submenu op dezelfde slug = "Creatie" (anders dupliceert WP het
		// top-level label als eerste submenu-item).
		$creatie_hook = add_submenu_page(
			$parent,
			__( 'AI Blog Genereren', 'digitale-bazen-ai-module' ),
			__( 'Creatie', 'digitale-bazen-ai-module' ),
			$capability,
			$parent,
			[ $this, 'render' ]
		);
		if ( $creatie_hook ) {
			$this->page_hooks[] = $creatie_hook;
		}
	}

	/**
	 * Lichte redirect van de oude slugs naar de nieuwe menu-locaties zodat
	 * bestaande bookmarks blijven werken (v3.0.0 menu-herstructurering, §0N):
	 *  - edit.php?post_type=blog&page=db-ai-generator  → admin.php?page=db-ai
	 *  - options-general.php?page=db-ai-settings        → admin.php?page=db-ai-settings
	 */
	public function redirect_legacy_slugs(): void {
		if ( empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$page    = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		$target  = '';

		if ( self::LEGACY_SLUG === $page ) {
			$target = self::MENU_SLUG;
		} elseif ( DB_AI_Settings::PAGE_SLUG === $page && 'options-general.php' === $pagenow ) {
			// Alleen vanaf de oude locatie redirecten — op admin.php?page=db-ai-settings
			// (de nieuwe locatie) niet, anders ontstaat een loop.
			$target = DB_AI_Settings::PAGE_SLUG;
		}

		if ( '' !== $target ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $target ) );
			exit;
		}
	}

	public function maybe_enqueue_assets( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		// Cache-buster: filemtime + plugin-versie. DB_AI_VERSION verandert niet
		// bij elke code-tweak in development, dus zonder mtime krijgt de browser
		// stale CSS/JS. mtime garandeert dat asset-wijzigingen meteen geladen worden.
		$css_path = DB_AI_PLUGIN_DIR . 'assets/admin.css';
		$js_path  = DB_AI_PLUGIN_DIR . 'assets/admin.js';
		$css_ver  = file_exists( $css_path ) ? DB_AI_VERSION . '.' . filemtime( $css_path ) : DB_AI_VERSION;
		$js_ver   = file_exists( $js_path )  ? DB_AI_VERSION . '.' . filemtime( $js_path )  : DB_AI_VERSION;

		wp_enqueue_style(
			'db-ai-admin',
			DB_AI_PLUGIN_URL . 'assets/admin.css',
			[],
			$css_ver
		);

		// SheetJS Community Edition 0.20.3 (MIT) — parseert xlsx/xls/csv/ods client-side
		wp_enqueue_script(
			'db-ai-xlsx',
			DB_AI_PLUGIN_URL . 'assets/vendor/xlsx.full.min.js',
			[],
			'0.20.3',
			true
		);

		wp_enqueue_script(
			'db-ai-admin',
			DB_AI_PLUGIN_URL . 'assets/admin.js',
			[ 'db-ai-xlsx' ],
			$js_ver,
			true
		);

		$rate_limiter = new DB_AI_Rate_Limiter( new DB_AI_Logger() );
		$user_id      = get_current_user_id();
		$rate_remaining = $rate_limiter->remaining( $user_id );
		$rate_limit     = $rate_limiter->limit_per_day();

		// Genereren vanuit het Plan-scherm (§14 Stap 8d): het keyword + onderzoek-id
		// komen via de URL. admin.js selecteert het keyword voor en stuurt het
		// onderzoek-id mee bij genereren zodat de server de plan-context afleidt.
		$plan = [];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only pre-fill, generatie zelf is nonce-protected.
		if ( isset( $_GET['plan_keyword'] ) && '' !== trim( (string) wp_unslash( $_GET['plan_keyword'] ) ) ) {
			$plan = [
				'keyword'  => sanitize_text_field( wp_unslash( $_GET['plan_keyword'] ) ),
				'research' => isset( $_GET['plan_research'] ) ? absint( wp_unslash( $_GET['plan_research'] ) ) : 0,
			];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'db-ai-admin',
			'dbAi',
			[
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( DB_AI_Ajax::NONCE_ACTION ),
				'rateRemaining'  => $rate_remaining,
				'rateLimit'      => $rate_limit,
				'plan'           => $plan,
				'i18n'           => [
					'choosePlaceholder' => __( '— Kies een hoofdzoekwoord —', 'digitale-bazen-ai-module' ),
					'previewTitle'      => __( 'Geselecteerd', 'digitale-bazen-ai-module' ),
					'mainLabel'         => __( 'Hoofdzoekwoord:', 'digitale-bazen-ai-module' ),
					'topicLabel'        => __( 'Onderwerp:', 'digitale-bazen-ai-module' ),
					'secondaryLabel'    => __( 'Secundaire keywords:', 'digitale-bazen-ai-module' ),
					'noSecondary'       => __( 'Geen secundaire keywords (geen ander zoekwoord met hetzelfde onderwerp).', 'digitale-bazen-ai-module' ),
					'uploading'         => __( 'Bezig met uploaden…', 'digitale-bazen-ai-module' ),
					/* translators: %d = aantal zoekwoorden */
					'uploadOk'          => __( '%d zoekwoorden geladen.', 'digitale-bazen-ai-module' ),
					'uploadFailed'      => __( 'Upload mislukt.', 'digitale-bazen-ai-module' ),
					'networkError'      => __( 'Netwerkfout.', 'digitale-bazen-ai-module' ),
					'parsing'           => __( 'Bestand lezen…', 'digitale-bazen-ai-module' ),
					'parseFailed'       => __( 'Bestand kon niet gelezen worden.', 'digitale-bazen-ai-module' ),
					'mappingNone'       => __( '(geen)', 'digitale-bazen-ai-module' ),
					'mappingReady'      => __( 'Headers gevonden — controleer de mapping hieronder.', 'digitale-bazen-ai-module' ),
					'mappingMissingKeyword' => __( 'Selecteer een bron-kolom voor "Zoekwoord".', 'digitale-bazen-ai-module' ),
					/* translators: %d = aantal tokens */
					'tokensLabel'       => __( 'Tokens: %d', 'digitale-bazen-ai-module' ),
					'errorsLabel'       => __( 'Validatiefouten', 'digitale-bazen-ai-module' ),
					'warningsLabel'     => __( 'Waarschuwingen', 'digitale-bazen-ai-module' ),
					'generateOk'        => __( 'Draft aangemaakt', 'digitale-bazen-ai-module' ),
					'generateFailed'    => __( 'Generatie mislukt.', 'digitale-bazen-ai-module' ),
					'progressQueued'    => __( 'In wachtrij…', 'digitale-bazen-ai-module' ),
					'progressDone'      => __( 'Klaar!', 'digitale-bazen-ai-module' ),
					'progressFailed'    => __( 'Mislukt — zie melding hieronder.', 'digitale-bazen-ai-module' ),
					'jobTimeout'        => __( 'Duurt onverwacht lang. Controleer je Blogs — de draft kan alsnog verschijnen.', 'digitale-bazen-ai-module' ),
					'draftLabel'        => __( 'Post ID:', 'digitale-bazen-ai-module' ),
					'openDraftLabel'    => __( 'Open draft in nieuwe tab', 'digitale-bazen-ai-module' ),
					'previewDraftLabel' => __( 'Preview', 'digitale-bazen-ai-module' ),
					/* translators: %d = aantal generaties */
					'remainingLabel'    => __( 'Nog %d generaties vandaag.', 'digitale-bazen-ai-module' ),
					/* translators: 1 = used, 2 = limit */
					'quotaLabel'        => __( '%1$d van %2$d generaties vandaag gebruikt', 'digitale-bazen-ai-module' ),
				],
			]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'digitale-bazen-ai-module' ) );
		}

		$acf_active        = DB_AI_Plugin::acf_available();
		$field_group_found = $acf_active && DB_AI_Plugin::field_group_exists();

		include DB_AI_PLUGIN_DIR . 'templates/admin-page.php';
	}
}
