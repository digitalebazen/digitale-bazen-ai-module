<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hookt YahnisElsts/plugin-update-checker (vendor/plugin-update-checker/) in op WordPress'
 * update-systeem. Sites met deze plugin actief checken automatisch op nieuwe versies via
 * de GitHub Releases API en tonen "Update available" in de admin.
 *
 * Vereiste constants in wp-config.php (per site):
 *   define( 'DB_AI_GITHUB_REPO_URL', 'https://github.com/DigitaleBazen/digitale-bazen-ai/' );
 *   define( 'DB_AI_GITHUB_TOKEN',    'ghp_...' );  // Personal Access Token (alleen voor private repos)
 *
 * Optionele filter:
 *   add_filter( 'db_ai_update_branch', fn() => 'main' );  // default 'main'
 */
class DB_AI_Updater {

	/**
	 * Default repo URL — kan overschreven worden per site via constant of filter.
	 * Pas dit aan naar de daadwerkelijke repo van Digitale Bazen.
	 */
	public const DEFAULT_REPO_URL = 'https://github.com/DigitaleBazen/digitale-bazen-ai-module/';

	/**
	 * Option-key die bijhoudt voor welke plugin-versie de update-cache het laatst
	 * is gepurged. Bij elke versie-bump wordt de cache eenmalig gewist zodat
	 * stale asset-URLs (na release-asset replace met nieuw asset_id) geen
	 * PCLZIP_ERR_BAD_FORMAT meer kunnen veroorzaken.
	 */
	private const PURGE_FLAG_OPTION = 'db_ai_updater_last_purge_for_version';

	public static function register(): void {
		// Eenmalige cache-purge per versie. Voorkomt PCLZIP errors door stale
		// download URLs in PUC's eigen option en in de update_plugins transient.
		if ( is_admin() && get_option( self::PURGE_FLAG_OPTION ) !== DB_AI_VERSION ) {
			delete_site_transient( 'update_plugins' );
			delete_option( 'external_updates-digitale-bazen-ai-module' );
			update_option( self::PURGE_FLAG_OPTION, DB_AI_VERSION, false );
		}

		$loader = DB_AI_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $loader ) ) {
			return; // Library niet aanwezig — graceful exit, plugin werkt zonder updates
		}
		require_once $loader;

		$factory_v5 = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory_v5 ) ) {
			return; // Library aanwezig maar autoloader heeft niet ingehaakt
		}

		$repo_url = self::repo_url();
		if ( '' === $repo_url ) {
			return; // Geen repo geconfigureerd
		}

		$checker = call_user_func(
			[ $factory_v5, 'buildUpdateChecker' ],
			$repo_url,
			DB_AI_PLUGIN_FILE,
			'digitale-bazen-ai-module'
		);

		$branch = (string) apply_filters( 'db_ai_update_branch', 'main' );
		if ( '' !== $branch ) {
			$checker->setBranch( $branch );
		}

		// Token leest uitsluitend uit wp-config constant. Zonder token blijft de checker
		// unauthenticated en geeft 404 op private repos.
		$token = defined( 'DB_AI_GITHUB_TOKEN' ) ? (string) DB_AI_GITHUB_TOKEN : '';
		if ( '' !== trim( $token ) ) {
			$checker->setAuthentication( $token );
		}

		// Lees release-info uit GitHub Releases (i.p.v. uit de plugin-header van de master branch).
		// Dit zorgt dat alleen getagde releases als update worden gezien — werk-in-uitvoering op main
		// triggert geen update notice voor klantensites.
		//
		// VEREIST voor PRIVATE repos: enable release assets en upload per release een schoon zip-bestand
		// onder Assets. Reden: PUC kan source-tarballs van private repos niet betrouwbaar downloaden
		// (auth-header wordt niet doorgegeven aan github's archive endpoint, geeft 404).
		// Workflow: build zip lokaal, upload als asset bij de GitHub Release.
		$vcs_api = $checker->getVcsApi();
		if ( $vcs_api && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
			$vcs_api->enableReleaseAssets( '/digitale-bazen-ai-module.*\.zip/' );
		}
	}

	private static function repo_url(): string {
		$url = self::DEFAULT_REPO_URL;
		if ( defined( 'DB_AI_GITHUB_REPO_URL' ) ) {
			$override = trim( (string) DB_AI_GITHUB_REPO_URL );
			if ( '' !== $override ) {
				$url = $override;
			}
		}
		return (string) apply_filters( 'db_ai_github_repo_url', $url );
	}
}
