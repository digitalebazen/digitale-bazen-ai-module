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
	 * Outline-first fase 1: genereer alleen de structuur (geen volledige tekst).
	 *
	 * @return array|WP_Error  { post_title_suggestion, focus_keyword, outline: [ { acf_fc_layout, titel, summary } ] }
	 */
	public function generate_outline( string $main_keyword, array $secondary_keywords, array $context );

	/**
	 * Outline-first fase 2: schrijf de volledige blog volgens een goedgekeurde outline.
	 *
	 * @param array $approved_outline  Door de redacteur bewerkte outline-secties.
	 * @return array|WP_Error  Zelfde volledige blog-JSON als generate_blog().
	 */
	public function expand_outline( string $main_keyword, array $secondary_keywords, array $approved_outline, array $context );

	/**
	 * Stable identifier of the model used (e.g. 'openai:gpt-4o').
	 */
	public function get_model_identifier(): string;

	/**
	 * Total tokens used in the most recent call (0 if no call yet or unknown).
	 */
	public function get_last_token_usage(): int;
}
