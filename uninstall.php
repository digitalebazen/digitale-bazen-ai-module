<?php
/**
 * Schone deletion bij plugin-verwijdering.
 *
 * Verwijderd:
 * - `wp_db_ai_generations` tabel
 * - Plugin-options (`db_ai_db_version`, rate-limit transients)
 *
 * Bewust BEWAARD:
 * - Gegenereerde drafts/posts en hun ACF flex content (user-data)
 * - Geüploade attachments en hun source-meta (user-data)
 * - `_db_ai_*` post meta (audit trail blijft staan)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'db_ai_generations';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'db_ai_db_version' );
delete_option( 'db_ai_settings' );

// Verwijder eventuele rate-limit transients (per-user-per-dag).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_db_ai_rate_%',
		'_transient_timeout_db_ai_rate_%'
	)
);
