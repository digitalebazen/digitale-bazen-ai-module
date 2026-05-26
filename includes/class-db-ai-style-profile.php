<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bouwt de algemene contextsectie (bedrijfsinfo + doelgroep + TOV + anti-generiek +
 * huisregels + referentie-voorbeelden) die aan de AI system prompt wordt toegevoegd.
 * Leest uit `db_ai_settings` option.
 *
 * Velden (allemaal optioneel):
 *   — Bedrijfsinformatie:
 *     company_name, company_industry, company_services, company_usps, company_competitors
 *   — Doelgroep:
 *     audience_who, audience_objections, audience_frustrations, audience_buying_criteria,
 *     audience_language_level (b1|expert|'')
 *   — Tone of voice + content (bestaand):
 *     tone_of_voice, site_context, style_rules, reference_post_ids
 *   — Anti-generiek toggles:
 *     anti_opinion, anti_examples, anti_downsides (bool)
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

		$company = self::build_company_section( $opts );
		if ( '' !== $company ) {
			$sections[] = $company;
		}

		$audience = self::build_audience_section( $opts );
		if ( '' !== $audience ) {
			$sections[] = $audience;
		}

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

		$anti_generic = self::build_anti_generic_section( $opts );
		if ( '' !== $anti_generic ) {
			$sections[] = $anti_generic;
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

	private static function build_company_section( array $opts ): string {
		$lines = [];

		$pairs = [
			'company_name'        => 'Bedrijfsnaam',
			'company_industry'    => 'Branche',
			'company_services'    => 'Diensten/producten',
			'company_usps'        => "USP's / wat maakt het uniek",
			'company_competitors' => 'Concurrenten (noem deze NOOIT in de blog, maar gebruik ze om te positioneren)',
		];

		foreach ( $pairs as $key => $label ) {
			$val = trim( (string) ( $opts[ $key ] ?? '' ) );
			if ( '' === $val ) {
				continue;
			}
			$lines[] = '- ' . $label . ': ' . $val;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "BEDRIJFSINFORMATIE:\n" . implode( "\n", $lines );
	}

	private static function build_audience_section( array $opts ): string {
		$lines = [];

		$pairs = [
			'audience_who'             => 'Voor wie schrijf je',
			'audience_objections'      => 'Bezwaren die weggenomen moeten worden',
			'audience_frustrations'    => 'Frustraties / pijnpunten',
			'audience_buying_criteria' => 'Wat de doelgroep belangrijk vindt bij beslissen',
		];

		foreach ( $pairs as $key => $label ) {
			$val = trim( (string) ( $opts[ $key ] ?? '' ) );
			if ( '' === $val ) {
				continue;
			}
			$lines[] = '- ' . $label . ': ' . $val;
		}

		$level = (string) ( $opts['audience_language_level'] ?? '' );
		if ( 'b1' === $level ) {
			$lines[] = '- Taalniveau: B1 — eenvoudige zinnen, geen vaktermen tenzij uitgelegd, korte alinea\'s.';
		} elseif ( 'expert' === $level ) {
			$lines[] = '- Taalniveau: expert — vakjargon mag, geen basisuitleg van bekende begrippen.';
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "DOELGROEP:\n" . implode( "\n", $lines );
	}

	private static function build_anti_generic_section( array $opts ): string {
		$lines = [];

		if ( ! empty( $opts['anti_opinion'] ) ) {
			$lines[] = '- Geef expliciet een gefundeerde mening of standpunt waar het past — vermijd neutrale "objectieve" formuleringen die alles open laten.';
		}
		if ( ! empty( $opts['anti_examples'] ) ) {
			$lines[] = '- Werk met concrete praktijkvoorbeelden in plaats van algemene principes (geen verzonnen cijfers — gebruik realistische scenarios).';
		}
		if ( ! empty( $opts['anti_downsides'] ) ) {
			$lines[] = '- Benoem ook nadelen, beperkingen of "wanneer dit NIET voor jou is" — toont expertise en bouwt vertrouwen.';
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "ANTI-GENERIEKE CONTENT (vermijd standaard AI-tekst):\n" . implode( "\n", $lines );
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
