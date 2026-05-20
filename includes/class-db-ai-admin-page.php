<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Admin_Page {

	public const MENU_SLUG = 'db-ai-generator';

	private $page_hooks = [];

	/**
	 * Admin parents waar het submenu zichtbaar moet zijn.
	 * Filterbaar via `db_ai_admin_menu_parents`.
	 */
	private function get_menu_parents(): array {
		$parents = [
			'edit.php',                  // Berichten
			'edit.php?post_type=blog',   // Blogs CPT
		];
		return apply_filters( 'db_ai_admin_menu_parents', $parents );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
	}

	public function register_menu(): void {
		foreach ( $this->get_menu_parents() as $parent ) {
			$hook = add_submenu_page(
				$parent,
				__( 'AI Blog Genereren', 'digitale-bazen-ai-module' ),
				__( 'AI Blog Genereren', 'digitale-bazen-ai-module' ),
				'publish_posts',
				self::MENU_SLUG,
				[ $this, 'render' ]
			);
			if ( $hook ) {
				$this->page_hooks[] = $hook;
			}
		}
	}

	public function maybe_enqueue_assets( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'db-ai-admin',
			DB_AI_PLUGIN_URL . 'assets/admin.css',
			[],
			DB_AI_VERSION
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
			DB_AI_VERSION,
			true
		);

		$rate_limiter = new DB_AI_Rate_Limiter( new DB_AI_Logger() );
		$user_id      = get_current_user_id();
		$rate_remaining = $rate_limiter->remaining( $user_id );
		$rate_limit     = $rate_limiter->limit_per_day();

		wp_localize_script(
			'db-ai-admin',
			'dbAi',
			[
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( DB_AI_Ajax::NONCE_ACTION ),
				'rateRemaining'  => $rate_remaining,
				'rateLimit'      => $rate_limit,
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
					'generateRunning'   => __( 'AI + afbeeldingen ophalen… kan 30-60 sec duren.', 'digitale-bazen-ai-module' ),
					'generateOk'        => __( 'Draft aangemaakt', 'digitale-bazen-ai-module' ),
					'generateFailed'    => __( 'Generatie mislukt.', 'digitale-bazen-ai-module' ),
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
