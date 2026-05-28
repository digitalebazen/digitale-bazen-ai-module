<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface DB_AI_Provider {

	/**
	 * Generate a blog post structure from a keyword.
	 *
	 * @param string   $main_keyword
	 * @param string[] $secondary_keywords
	 * @param array    $context  Extra context (e.g. ['layout_spec' => [...], 'output_schema' => [...]])
	 * @return array|WP_Error  Raw AI output decoded as associative array.
	 */
	public function generate_blog( string $main_keyword, array $secondary_keywords, array $context );

	/**
	 * Stable identifier of the model used (e.g. 'anthropic:claude-sonnet-4-6').
	 */
	public function get_model_identifier(): string;

	/**
	 * Total tokens used in the most recent call (0 if no call yet or unknown).
	 */
	public function get_last_token_usage(): int;
}
