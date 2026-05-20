<?php
/**
 * Plugin Name: Digitale Bazen AI Module
 * Description: Genereer SEO-blogposts met AI op basis van zoekwoordenonderzoek.
 * Version:     1.0.0
 * Author:      Digitale Bazen
 * Author URI:  https://digitalebazen.nl
 * Text Domain: digitale-bazen-ai-module
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Update URI:  https://github.com/DigitaleBazen/digitale-bazen-ai-module/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DB_AI_VERSION', '1.0.0' );
define( 'DB_AI_PLUGIN_FILE', __FILE__ );
define( 'DB_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DB_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DB_AI_ACF_FIELD_GROUP_KEY', 'group_5da97023a084d' );

require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-plugin.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-settings.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-style-profile.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-admin-page.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-keyword-importer.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-acf-mapper.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-image-service.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-seo-mapper.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-faq-schema.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-logger.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-rate-limiter.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-post-creator.php';
require_once DB_AI_PLUGIN_DIR . 'includes/providers/interface-db-ai-provider.php';
require_once DB_AI_PLUGIN_DIR . 'includes/providers/class-db-ai-openai-provider.php';
require_once DB_AI_PLUGIN_DIR . 'includes/providers/class-db-ai-anthropic-provider.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-ajax.php';
require_once DB_AI_PLUGIN_DIR . 'includes/class-db-ai-updater.php';

register_activation_hook( __FILE__, [ 'DB_AI_Plugin', 'on_activate' ] );

add_action( 'plugins_loaded', [ 'DB_AI_Plugin', 'instance' ] );

// Auto-update via GitHub Releases (zie includes/class-db-ai-updater.php).
add_action( 'init', [ 'DB_AI_Updater', 'register' ], 0 );
