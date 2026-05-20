<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Rate_Limiter {

	public const DEFAULT_LIMIT_PER_DAY = 10;

	private $logger;

	public function __construct( DB_AI_Logger $logger ) {
		$this->logger = $logger;
	}

	public function limit_per_day(): int {
		return (int) apply_filters( 'db_ai_rate_limit_per_day', self::DEFAULT_LIMIT_PER_DAY );
	}

	public function used_today( int $user_id ): int {
		return $this->logger->count_successful_today( $user_id );
	}

	public function remaining( int $user_id ): int {
		return max( 0, $this->limit_per_day() - $this->used_today( $user_id ) );
	}

	public function can_generate( int $user_id ): bool {
		return $this->remaining( $user_id ) > 0;
	}
}
