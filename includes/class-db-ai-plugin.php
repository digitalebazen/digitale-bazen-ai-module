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
	private $rankmath_bridge;
	private $external_links_metabox;

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

		// Zoekwoordenonderzoek CPT — moet vroeg geregistreerd worden (`init`) zodat
		// REST/AJAX queries werken. Geen aparte instance nodig; static method.
		DB_AI_Keyword_Research::register();

		// Async job-queue — runner-hook + onderhoud-cron registreren ongeacht
		// context (worker draait buiten admin). Zie PROJECT_BRIEF.md sectie 0F.
		DB_AI_Job_Queue::register();

		// DB_AI_Settings registreert de `db_ai_allowed_layouts` filter die de
		// generatie beïnvloedt — altijd instantiëren zodat die filter óók in de
		// async worker (non-admin) actief is. De admin-UI hooks erin zijn intern
		// achter is_admin() gegated, dus geen frontend-overhead.
		$this->settings = new DB_AI_Settings();
		$this->settings->register();

		if ( is_admin() ) {
			$this->admin_page = new DB_AI_Admin_Page();
			$this->admin_page->register();

			$this->rankmath_bridge = new DB_AI_Rankmath_Bridge();
			$this->rankmath_bridge->register();

			$this->external_links_metabox = new DB_AI_External_Links_Metabox();
			$this->external_links_metabox->register();
		}

		// DB_AI_Ajax registreert óók de async worker-handler ('generate_blog').
		// Die moet beschikbaar zijn in de worker-request (Action Scheduler / WP-Cron),
		// welke noch admin noch ajax is — daarom altijd instantiëren. De wp_ajax_*
		// hooks die hij toevoegt vuren alleen op admin-ajax.php, dus geen overhead
		// of gedragswijziging op frontend-requests.
		$this->ajax = new DB_AI_Ajax();
		$this->ajax->register();
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
				esc_html__( 'Digitale Bazen AI Module: geen ACF field group met een flexible content veld gevonden op deze site. Importeer of activeer eerst een veldgroep met minstens één flex content veld en probeer opnieuw.', 'digitale-bazen-ai-module' ),
				esc_html__( 'ACF field group ontbreekt', 'digitale-bazen-ai-module' ),
				[ 'back_link' => true ]
			);
		}

		DB_AI_Logger::create_table();
		update_option( 'db_ai_db_version', DB_AI_Logger::DB_VERSION );

		DB_AI_Job_Queue::maybe_upgrade_table();
	}

	public static function on_deactivate(): void {
		// Ruim onderhoud-cron-events op zodat er geen orphan-schedules achterblijven.
		DB_AI_Job_Queue::clear_scheduled_events();
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

		// Jobs-tabel heeft een eigen versie-optie zodat beide onafhankelijk migreren.
		DB_AI_Job_Queue::maybe_upgrade_table();
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
				esc_html_e( 'Digitale Bazen AI Module: geen ACF field group met een flexible content veld gevonden. Maak er één aan of importeer een bestaande, en kies hem in Instellingen → AI Module.', 'digitale-bazen-ai-module' );
				echo '</p></div>';
			} );
		}
	}

	public static function acf_available(): bool {
		return function_exists( 'acf_get_field_group' ) && function_exists( 'get_field' );
	}

	/**
	 * Heeft deze site überhaupt een bruikbare ACF field group met een flex content veld?
	 *
	 * Sinds V1.1: niet meer locked op de Digitale Bazen-specifieke `group_5da97023a084d`,
	 * maar accepteert élke field group met minstens één flex veld. De daadwerkelijke
	 * keuze welke field group de plugin gebruikt is configureerbaar in Instellingen.
	 */
	public static function field_group_exists(): bool {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return false;
		}
		return DB_AI_ACF_Discovery::has_any();
	}
}
