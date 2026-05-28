<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Async job-queue voor langlopende generator-operaties.
 *
 * Vervangt de synchrone `db_ai_generate` flow zodat host-timeouts (FastCGI /
 * Nginx / Cloudflare / PHP max_execution_time) geen langlopende AI-calls meer
 * killen, en legt de fundering voor bulk-generatie, per-block regeneratie en
 * outline-first flows (zie ASYNC_REFACTOR_PLAN.md).
 *
 * Lifecycle: queued → running → done | failed (monotoon, geen resurrectie).
 *
 * Runner-strategie: gebruikt Action Scheduler als die beschikbaar is (RankMath
 * bundelt 'm, en RankMath is de SEO-integratie van deze plugin), anders een
 * WP-Cron single-event als fallback. Beide triggeren dezelfde `db_ai_run_job`
 * action zodat er één code-pad is voor het daadwerkelijke uitvoeren.
 */
final class DB_AI_Job_Queue {

	public const TABLE_SUFFIX = 'db_ai_jobs';
	public const DB_VERSION   = '1.0';
	public const DB_OPTION    = 'db_ai_jobs_db_version';

	public const RUN_HOOK     = 'db_ai_run_job';
	public const SWEEP_HOOK   = 'db_ai_sweep_jobs';
	public const CLEANUP_HOOK = 'db_ai_cleanup_jobs';

	/** Een running job zonder heartbeat-update binnen deze tijd geldt als vastgelopen. */
	public const STUCK_AFTER = 5 * MINUTE_IN_SECONDS;

	/** Bewaartermijn afgeronde jobs in de tabel. */
	public const KEEP_DONE   = 30 * DAY_IN_SECONDS;
	public const KEEP_FAILED = 7 * DAY_IN_SECONDS;

	/** @var array<string, callable> job_type => handler( string $job_key, array $payload, int $user_id ) */
	private static $handlers = [];

	/**
	 * Job-types die een post produceren en dus tegen de daglimiet tellen.
	 * `generate_outline` staat hier bewust NIET in — een outline kost geen
	 * generatie-slot; pas de expand-fase telt mee.
	 */
	private const BILLABLE_TYPES = [ 'generate_blog', 'expand_outline' ];

	// ─── Bootstrap ─────────────────────────────────────────────────────────

	public static function register(): void {
		add_action( self::RUN_HOOK, [ __CLASS__, 'run' ], 10, 1 );

		// Janitor + cleanup via WP-Cron. Idempotent ingepland.
		add_action( 'init', [ __CLASS__, 'maybe_schedule_maintenance' ] );
		add_action( self::SWEEP_HOOK, [ __CLASS__, 'sweep_stuck_jobs' ] );
		add_action( self::CLEANUP_HOOK, [ __CLASS__, 'cleanup_old_jobs' ] );
	}

	public static function maybe_schedule_maintenance(): void {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/** Bij plugin-deactivatie: ruim de onderhoud-cron-events op. */
	public static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Een job-type koppelen aan z'n uitvoerder. Door fase 2+ aangeroepen.
	 * De handler is verantwoordelijk voor report_progress() + mark_done()/mark_failed().
	 */
	public static function register_handler( string $job_type, callable $handler ): void {
		self::$handlers[ $job_type ] = $handler;
	}

	// ─── Schema ──────────────────────────────────────────────────────────────

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta-compliant: twee spaties na PRIMARY KEY, geen backticks.
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_key VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			job_type VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
			stage_label VARCHAR(150) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			result LONGTEXT NULL,
			error_code VARCHAR(80) NULL,
			error_msg TEXT NULL,
			created_at DATETIME NOT NULL,
			started_at DATETIME NULL,
			heartbeat DATETIME NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY job_key (job_key),
			KEY user_id (user_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Aangeroepen bij activatie + maybe_upgrade. Idempotent. */
	public static function maybe_upgrade_table(): void {
		$current = (string) get_option( self::DB_OPTION, '0' );
		if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
			self::create_table();
			update_option( self::DB_OPTION, self::DB_VERSION );
		}
	}

	// ─── Dispatch ──────────────────────────────────────────────────────────

	/**
	 * Spin een nieuwe job op. Reserveert een rate-limit slot bij dispatch
	 * (in-flight jobs tellen mee) zodat queue-stacking de daglimiet niet omzeilt.
	 *
	 * @return string|WP_Error  job_key bij succes
	 */
	public static function dispatch( string $job_type, array $payload, int $user_id ) {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'db_ai_job_no_user', __( 'Geen geldige gebruiker.', 'digitale-bazen-ai-module' ) );
		}

		// Alleen post-producerende jobs tellen tegen de daglimiet. Een outline is gratis.
		if ( in_array( $job_type, self::BILLABLE_TYPES, true ) && ! self::can_dispatch( $user_id ) ) {
			return new WP_Error(
				'db_ai_job_rate_limited',
				__( 'Je hebt je dagelijkse generatie-limiet bereikt (lopende jobs tellen mee).', 'digitale-bazen-ai-module' )
			);
		}

		$job_key = self::generate_key();
		$ok      = $wpdb->insert(
			self::table_name(),
			[
				'job_key'     => $job_key,
				'user_id'     => $user_id,
				'job_type'    => mb_substr( $job_type, 0, 40 ),
				'status'      => 'queued',
				'progress'    => 0,
				'stage_label' => '',
				'payload'     => wp_json_encode( $payload ),
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $ok ) {
			return new WP_Error( 'db_ai_job_insert_failed', __( 'Kon de job niet aanmaken.', 'digitale-bazen-ai-module' ) );
		}

		self::schedule_runner( $job_key );

		return $job_key;
	}

	/**
	 * Mag deze user nog een job starten? In-flight (queued/running) + vandaag
	 * succesvol afgerond tellen samen tegen de daglimiet.
	 */
	public static function can_dispatch( int $user_id ): bool {
		$limit = (int) apply_filters( 'db_ai_rate_limit_per_day', DB_AI_Rate_Limiter::DEFAULT_LIMIT_PER_DAY );

		$logger    = new DB_AI_Logger();
		$succeeded = $logger->count_successful_today( $user_id );
		$in_flight = self::count_in_flight_today( $user_id );

		return ( $succeeded + $in_flight ) < $limit;
	}

	private static function count_in_flight_today( int $user_id ): int {
		global $wpdb;
		$table = self::table_name();
		$start = gmdate( 'Y-m-d 00:00:00' );
		// Alleen billable job-types tellen mee — outline-jobs verbruiken geen slot.
		$placeholders = implode( ',', array_fill( 0, count( self::BILLABLE_TYPES ), '%s' ) );
		$params       = array_merge( [ $user_id ], self::BILLABLE_TYPES, [ $start ] );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND job_type IN ({$placeholders}) AND status IN ('queued','running') AND created_at >= %s",
				$params
			)
		);
	}

	/**
	 * Plan de daadwerkelijke uitvoering. Action Scheduler indien aanwezig,
	 * anders WP-Cron single-event + directe spawn voor snelle start.
	 */
	private static function schedule_runner( string $job_key ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::RUN_HOOK, [ 'job_key' => $job_key ], 'db-ai' );
			return;
		}

		// Fallback: cron single-event + meteen proberen te spawnen.
		wp_schedule_single_event( time(), self::RUN_HOOK, [ $job_key ] );
		if ( ! defined( 'DOING_CRON' ) ) {
			spawn_cron();
		}
	}

	// ─── Uitvoeren ───────────────────────────────────────────────────────────

	/**
	 * Hook-callback voor RUN_HOOK (zowel Action Scheduler als WP-Cron).
	 * Resolved het job-type naar de geregistreerde handler.
	 */
	public static function run( $job_key ): void {
		$job_key = (string) $job_key;
		$job     = self::get_job_row( $job_key );

		if ( ! $job || 'queued' !== $job['status'] ) {
			return; // al opgepakt, klaar, of niet bestaand — geen dubbele run.
		}

		self::mark_running( $job_key );

		$type = (string) $job['job_type'];
		if ( empty( self::$handlers[ $type ] ) ) {
			self::mark_failed( $job_key, 'no_handler', sprintf( 'Geen handler geregistreerd voor job-type "%s".', $type ) );
			return;
		}

		$payload = json_decode( (string) $job['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		try {
			call_user_func( self::$handlers[ $type ], $job_key, $payload, (int) $job['user_id'] );
		} catch ( \Throwable $e ) {
			self::mark_failed( $job_key, 'exception', $e->getMessage() );
		}

		// Vangnet: als de handler niets afsloot, markeer als failed zodat de job
		// niet eeuwig op 'running' blijft hangen.
		$after = self::get_job_row( $job_key );
		if ( $after && 'running' === $after['status'] ) {
			self::mark_failed( $job_key, 'no_result', __( 'Handler eindigde zonder resultaat.', 'digitale-bazen-ai-module' ) );
		}
	}

	// ─── Status-mutaties (door handlers gebruikt) ───────────────────────────

	public static function report_progress( string $job_key, int $pct, string $stage_label ): void {
		global $wpdb;
		$pct = max( 0, min( 100, $pct ) );
		$wpdb->update(
			self::table_name(),
			[
				'progress'    => $pct,
				'stage_label' => mb_substr( $stage_label, 0, 150 ),
				'heartbeat'   => current_time( 'mysql', true ),
			],
			[ 'job_key' => $job_key ],
			[ '%d', '%s', '%s' ],
			[ '%s' ]
		);
	}

	public static function mark_done( string $job_key, array $result ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::table_name(),
			[
				'status'       => 'done',
				'progress'     => 100,
				'result'       => wp_json_encode( $result ),
				'heartbeat'    => $now,
				'completed_at' => $now,
			],
			[ 'job_key' => $job_key ],
			[ '%s', '%d', '%s', '%s', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * @param array $data  Optionele extra data (bv. validation_errors) — komt in
	 *                     de result-kolom en wordt door get_status() teruggegeven.
	 */
	public static function mark_failed( string $job_key, string $error_code, string $error_msg, array $data = [] ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::table_name(),
			[
				'status'       => 'failed',
				'error_code'   => mb_substr( $error_code, 0, 80 ),
				'error_msg'    => $error_msg,
				'result'       => empty( $data ) ? null : wp_json_encode( $data ),
				'heartbeat'    => $now,
				'completed_at' => $now,
			],
			[ 'job_key' => $job_key ],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%s' ]
		);
	}

	private static function mark_running( string $job_key ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::table_name(),
			[
				'status'     => 'running',
				'started_at' => $now,
				'heartbeat'  => $now,
			],
			[ 'job_key' => $job_key ],
			[ '%s', '%s', '%s' ],
			[ '%s' ]
		);
	}

	// ─── Status uitlezen (poll-endpoint) ────────────────────────────────────

	/**
	 * Voor het poll-endpoint. Capability-check: alleen de eigenaar (of een
	 * manage_options-admin) mag de status van een job zien.
	 *
	 * @return array|WP_Error
	 */
	public static function get_status( string $job_key, int $current_user_id ) {
		$job = self::get_job_row( $job_key );
		if ( ! $job ) {
			return new WP_Error( 'db_ai_job_not_found', __( 'Job niet gevonden.', 'digitale-bazen-ai-module' ) );
		}

		if ( (int) $job['user_id'] !== $current_user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'db_ai_job_forbidden', __( 'Geen toegang tot deze job.', 'digitale-bazen-ai-module' ) );
		}

		$out = [
			'job_key'     => $job['job_key'],
			'status'      => $job['status'],
			'progress'    => (int) $job['progress'],
			'stage_label' => $job['stage_label'],
		];

		if ( 'done' === $job['status'] ) {
			$out['result'] = json_decode( (string) $job['result'], true ) ?: [];
		}
		if ( 'failed' === $job['status'] ) {
			$out['error_code'] = (string) $job['error_code'];
			$out['error_msg']  = (string) $job['error_msg'];
			$extra             = json_decode( (string) $job['result'], true );
			if ( is_array( $extra ) && ! empty( $extra ) ) {
				$out = array_merge( $extra, $out );
			}
		}

		return $out;
	}

	// ─── Janitor + cleanup ───────────────────────────────────────────────────

	/** Markeer vastgelopen running-jobs (heartbeat te oud) als failed. */
	public static function sweep_stuck_jobs(): void {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'failed', error_code = 'timeout',
				     error_msg = 'Job vastgelopen — geen voortgang binnen tijdslimiet.',
				     completed_at = %s
				 WHERE status = 'running' AND ( heartbeat IS NULL OR heartbeat < %s )",
				current_time( 'mysql', true ),
				$cutoff
			)
		);
	}

	/** Verwijder oude afgeronde/gefaalde jobs zodat de tabel klein blijft. */
	public static function cleanup_old_jobs(): void {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'done' AND completed_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DONE )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'failed' AND completed_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - self::KEEP_FAILED )
			)
		);
	}

	// ─── Interne helpers ──────────────────────────────────────────────────────

	/** @return array<string,mixed>|null */
	private static function get_job_row( string $job_key ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_key = %s", $job_key ),
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function generate_key(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'db_ai_job', true ) );
	}
}
