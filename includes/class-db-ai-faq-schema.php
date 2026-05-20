<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injecteert FAQPage JSON-LD voor élke post met een `veelgestelde_vragen` ACF
 * flexible-content block — niet alleen AI-gegenereerde. Brief sectie 12.
 */
class DB_AI_FAQ_Schema {

	public function register(): void {
		add_action( 'wp_head', [ $this, 'inject_faq_schema' ], 20 );
	}

	public function inject_faq_schema(): void {
		if ( ! is_singular() ) {
			return;
		}
		if ( ! function_exists( 'get_field' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( empty( $post_id ) ) {
			return;
		}

		$blocks = get_field( 'paginacontent', $post_id );
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return;
		}

		$faq_items = [];
		foreach ( $blocks as $block ) {
			if ( ( $block['acf_fc_layout'] ?? '' ) !== 'veelgestelde_vragen' ) {
				continue;
			}
			foreach ( ( $block['onderwerpen'] ?? [] ) as $onderwerp ) {
				foreach ( ( $onderwerp['vragen'] ?? [] ) as $vraag ) {
					$question = isset( $vraag['vraag'] ) ? trim( wp_strip_all_tags( (string) $vraag['vraag'] ) ) : '';
					$answer   = isset( $vraag['antwoord'] ) ? trim( wp_strip_all_tags( (string) $vraag['antwoord'] ) ) : '';
					if ( '' === $question || '' === $answer ) {
						continue;
					}
					$faq_items[] = [
						'@type'          => 'Question',
						'name'           => $question,
						'acceptedAnswer' => [
							'@type' => 'Answer',
							'text'  => $answer,
						],
					];
				}
			}
		}

		if ( empty( $faq_items ) ) {
			return;
		}

		$schema = [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $faq_items,
		];

		echo "\n<script type=\"application/ld+json\">\n"
			. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. "\n</script>\n";
	}
}
