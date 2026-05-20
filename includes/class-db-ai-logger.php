<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Logger {

	public const TABLE_SUFFIX = 'db_ai_generations';

	public const DB_VERSION = '1.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Idempotent via dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta-compliant SQL: two spaces, no backticks, primary key on its own line.
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			keyword VARCHAR(255) NOT NULL DEFAULT '',
			model VARCHAR(100) NOT NULL DEFAULT '',
			tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT '',
			error_message TEXT NULL,
			warnings TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function log( int $post_id, int $user_id, string $keyword, string $model, int $tokens, string $status, string $error = '', array $warnings = [] ): int {
		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			[
				'post_id'       => $post_id,
				'user_id'       => $user_id,
				'keyword'       => mb_substr( $keyword, 0, 255 ),
				'model'         => mb_substr( $model, 0, 100 ),
				'tokens_used'   => $tokens,
				'status'        => mb_substr( $status, 0, 20 ),
				'error_message' => $error,
				'warnings'      => implode( "\n", $warnings ),
				'created_at'    => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Aantal *successful* generations door deze user sinds UTC-middernacht.
	 */
	public function count_successful_today( int $user_id ): int {
		global $wpdb;
		$start = gmdate( 'Y-m-d 00:00:00' );
		$table = self::table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = %s AND created_at >= %s",
				$user_id,
				'success',
				$start
			)
		);
	}
}
