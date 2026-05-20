<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bouwt de TOV / business-context / huisregels / referentie-voorbeelden sectie die
 * aan de AI system prompt wordt toegevoegd. Leest uit `db_ai_settings` option.
 *
 * Velden:
 *   tone_of_voice       (string)  Vrijetekst beschrijving van merkstem
 *   site_context        (string)  Bedrijf + doelgroep + WAT NIET DOEN
 *   style_rules         (string)  Huisstijl/uitvoeringsregels (geen em-dashes etc.)
 *   reference_post_ids  (int[])   Tot 5 post IDs gebruikt als few-shot voorbeelden
 */
class DB_AI_Style_Profile {

	public const MAX_REFERENCE_POSTS = 5;

	public const CHARS_PER_SAMPLE = 600;

	/**
	 * Het tekstblok dat aan de system prompt wordt toegevoegd. Leeg als niets ingesteld.
	 */
	public static function get_prompt_addition(): string {
		$opts     = DB_AI_Settings::get_options();
		$sections = [];

		$tov = trim( (string) ( $opts['tone_of_voice'] ?? '' ) );
		if ( '' !== $tov ) {
			$sections[] = "MERKSTEM:\n" . $tov;
		}

		$context = trim( (string) ( $opts['site_context'] ?? '' ) );
		if ( '' !== $context ) {
			$sections[] = "SITECONTEXT (bedrijf, doelgroep, WAT NIET TE DOEN):\n" . $context;
		}

		$rules = trim( (string) ( $opts['style_rules'] ?? '' ) );
		if ( '' !== $rules ) {
			$sections[] = "EXTRA HUISSTIJL-REGELS (volg STRIKT):\n" . $rules;
		}

		$samples = self::get_reference_samples();
		if ( ! empty( $samples ) ) {
			$sample_block = "VOORBEELDEN VAN GEWENSTE SCHRIJFSTIJL — match toon, ritme en zinslengte:";
			foreach ( $samples as $i => $sample ) {
				$sample_block .= "\n\n=== Voorbeeld " . ( $i + 1 ) . ' — "' . $sample['title'] . "\" ===\n" . $sample['text'];
			}
			$sections[] = $sample_block;
		}

		if ( empty( $sections ) ) {
			return '';
		}

		return "\n\n---\n\n" . implode( "\n\n", $sections );
	}

	/**
	 * @return array{title:string,text:string}[]
	 */
	public static function get_reference_samples(): array {
		$opts = DB_AI_Settings::get_options();
		$ids  = $opts['reference_post_ids'] ?? [];
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return [];
		}

		$samples = [];
		foreach ( $ids as $id ) {
			if ( count( $samples ) >= self::MAX_REFERENCE_POSTS ) {
				break;
			}
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$text = self::extract_post_text( $id );
			if ( '' === $text ) {
				continue;
			}
			$samples[] = [
				'title' => sanitize_text_field( $post->post_title ),
				'text'  => $text,
			];
		}
		return $samples;
	}

	/**
	 * Haalt platte tekst uit een post. Probeert eerst `post_content`, valt anders
	 * terug op ACF flex `paginacontent` walken voor wysiwyg/tekst velden.
	 */
	public static function extract_post_text( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		// 1. Probeer post_content via the_content filter (vangt classic editor + Gutenberg)
		$raw = '';
		if ( ! empty( $post->post_content ) ) {
			$raw = apply_filters( 'the_content', $post->post_content );
		}
		$text = wp_strip_all_tags( (string) $raw );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( mb_strlen( $text ) >= 80 ) {
			return mb_substr( $text, 0, self::CHARS_PER_SAMPLE );
		}

		// 2. Fallback: walk ACF flex `paginacontent`
		if ( function_exists( 'get_field' ) ) {
			$blocks = get_field( 'paginacontent', $post_id );
			if ( is_array( $blocks ) ) {
				$parts = [];
				self::collect_text_recursive( $blocks, $parts );
				$text = implode( ' ', $parts );
				$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
				return mb_substr( $text, 0, self::CHARS_PER_SAMPLE );
			}
		}

		return '';
	}

	/**
	 * Recursief alle string-waarden van >30 chars verzamelen uit een geneste array
	 * (typisch ACF flex output).
	 */
	private static function collect_text_recursive( array $data, array &$parts ): void {
		foreach ( $data as $val ) {
			if ( is_string( $val ) ) {
				$stripped = wp_strip_all_tags( $val );
				if ( mb_strlen( trim( $stripped ) ) > 30 ) {
					$parts[] = $stripped;
				}
			} elseif ( is_array( $val ) ) {
				self::collect_text_recursive( $val, $parts );
			}
		}
	}
}
