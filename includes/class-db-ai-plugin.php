<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DB_AI_Plugin {

	private static $instance = null;

	private $admin_page;
	private $ajax;
	private $faq_schema;
	private $settings;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		add_action( 'admin_init', [ $this, 'check_dependencies' ] );
		add_action( 'admin_init', [ $this, 'maybe_upgrade_db' ] );

		// FAQ JSON-LD draait op élke frontend pageload (én in admin previews) — registreren ongeacht context.
		$this->faq_schema = new DB_AI_FAQ_Schema();
		$this->faq_schema->register();

		if ( is_admin() ) {
			$this->admin_page = new DB_AI_Admin_Page();
			$this->admin_page->register();

			$this->settings = new DB_AI_Settings();
			$this->settings->register();
		}

		if ( wp_doing_ajax() || is_admin() ) {
			$this->ajax = new DB_AI_Ajax();
			$this->ajax->register();
		}
	}

	public static function on_activate(): void {
		if ( ! self::acf_available() ) {
			deactivate_plugins( plugin_basename( DB_AI_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'Digitale Bazen AI Module vereist ACF Pro. Activeer ACF Pro voordat je deze plugin activeert.', 'digitale-bazen-ai-module' ),
				esc_html__( 'Plugin-afhankelijkheid ontbreekt', 'digitale-bazen-ai-module' ),
				[ 'back_link' => true ]
			);
		}

		if ( ! self::field_group_exists() ) {
			deactivate_plugins( plugin_basename( DB_AI_PLUGIN_FILE ) );
			wp_die(
				sprintf(
					/* translators: %s = ACF field group key */
					esc_html__( 'Digitale Bazen AI Module kon de vereiste ACF field group (%s) niet vinden. Importeer of activeer deze veldgroep en probeer opnieuw.', 'digitale-bazen-ai-module' ),
					esc_html( DB_AI_ACF_FIELD_GROUP_KEY )
				),
				esc_html__( 'ACF field group ontbreekt', 'digitale-bazen-ai-module' ),
				[ 'back_link' => true ]
			);
		}

		DB_AI_Logger::create_table();
		update_option( 'db_ai_db_version', DB_AI_Logger::DB_VERSION );
	}

	/**
	 * Run dbDelta migrations als de opgeslagen db-versie achterloopt.
	 * Komt vooral van pas bij bestaande installaties die geactiveerd waren
	 * vóór deze build-stap, of bij toekomstige schema-wijzigingen.
	 */
	public function maybe_upgrade_db(): void {
		$current = (string) get_option( 'db_ai_db_version', '0' );
		if ( version_compare( $current, DB_AI_Logger::DB_VERSION, '<' ) ) {
			DB_AI_Logger::create_table();
			update_option( 'db_ai_db_version', DB_AI_Logger::DB_VERSION );
		}
	}

	public function check_dependencies(): void {
		if ( ! self::acf_available() ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>';
				esc_html_e( 'Digitale Bazen AI Module: ACF Pro is niet actief. De plugin is uitgeschakeld tot ACF Pro geactiveerd is.', 'digitale-bazen-ai-module' );
				echo '</p></div>';
			} );
			return;
		}

		if ( ! self::field_group_exists() ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>';
				printf(
					/* translators: %s = ACF field group key */
					esc_html__( 'Digitale Bazen AI Module: vereiste ACF field group %s ontbreekt.', 'digitale-bazen-ai-module' ),
					'<code>' . esc_html( DB_AI_ACF_FIELD_GROUP_KEY ) . '</code>'
				);
				echo '</p></div>';
			} );
		}
	}

	public static function acf_available(): bool {
		return function_exists( 'acf_get_field_group' ) && function_exists( 'get_field' );
	}

	public static function field_group_exists(): bool {
		if ( ! function_exists( 'acf_get_field_group' ) ) {
			return false;
		}
		$group = acf_get_field_group( DB_AI_ACF_FIELD_GROUP_KEY );
		return ! empty( $group );
	}
}
