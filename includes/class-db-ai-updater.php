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

	public static function register(): void {
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

		if ( defined( 'DB_AI_GITHUB_TOKEN' ) && '' !== trim( (string) DB_AI_GITHUB_TOKEN ) ) {
			$checker->setAuthentication( (string) DB_AI_GITHUB_TOKEN );
		}

		// Lees release-info uit GitHub Releases (i.p.v. uit de plugin-header van de master branch).
		// Dit zorgt dat alleen getagde releases als update worden gezien — werk-in-uitvoering op main
		// triggert geen update notice voor klantensites.
		$vcs_api = $checker->getVcsApi();
		if ( $vcs_api && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
			// Optioneel: gebruik een meegeleverd zip-asset uit de GitHub release ipv source-tarball.
			// Aanzetten als je per release een schoon .zip uploadt onder Assets:
			// $vcs_api->enableReleaseAssets( '/digitale-bazen-ai\.zip/' );
		}
	}

	private static function repo_url(): string {
		$url = self::DEFAULT_REPO_URL;
		if ( defined( 'DB_AI_GITHUB_REPO_URL' ) && '' !== trim( (string) DB_AI_GITHUB_REPO_URL ) ) {
			$url = (string) DB_AI_GITHUB_REPO_URL;
		}
		return (string) apply_filters( 'db_ai_github_repo_url', $url );
	}
}
