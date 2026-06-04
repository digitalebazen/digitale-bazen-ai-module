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
					$question = $this->value_to_text( $vraag['vraag'] ?? '' );
					$answer   = $this->answer_to_text( $vraag['antwoord'] ?? '' );
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

	/**
	 * Coerce een ACF-veldwaarde naar platte tekst. Normaal is `vraag`/`antwoord`
	 * een string (text/wysiwyg), maar op sommige sites kan een veld als array
	 * binnenkomen (bv. afwijkende veldconfig of geneste sub-velden). Dan halen
	 * we de string-bladeren eruit i.p.v. de waarde blind naar string te casten
	 * — dat laatste gaf een "Array to string conversion"-warning.
	 *
	 * @param mixed $value
	 */
	/**
	 * Haal de tekst van een FAQ-antwoord op voor het JSON-LD schema.
	 *
	 * `antwoord` was een wysiwyg-string maar is omgezet naar een flexible_content
	 * met layouts tekst/afbeelding/button. Voor het schema willen we alleen de
	 * lees-tekst: we pakken de string-sub-velden (zoals `tekst`) uit elke flex-rij
	 * en slaan afbeelding-/button-rijen (arrays) over. Oude string-antwoorden
	 * (backwards-compat) blijven gewoon werken.
	 *
	 * @param mixed $value
	 */
	private function answer_to_text( $value ): string {
		if ( is_string( $value ) ) {
			return trim( wp_strip_all_tags( $value ) );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$parts = [];
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				$text = $this->value_to_text( $row );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
				continue;
			}
			// Flex-rij: alleen string-sub-velden (tekst); afbeelding/button = arrays → overslaan.
			foreach ( $row as $key => $sub_value ) {
				if ( 'acf_fc_layout' === $key || ! is_string( $sub_value ) ) {
					continue;
				}
				$text = trim( wp_strip_all_tags( $sub_value ) );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
		}
		return trim( implode( ' ', $parts ) );
	}

	private function value_to_text( $value ): string {
		if ( is_string( $value ) ) {
			return trim( wp_strip_all_tags( $value ) );
		}
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}
		if ( is_array( $value ) ) {
			$parts = [];
			foreach ( $value as $item ) {
				$text = $this->value_to_text( $item );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
			return trim( implode( ' ', $parts ) );
		}
		return '';
	}
}
