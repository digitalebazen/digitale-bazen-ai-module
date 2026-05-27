<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Externe link-suggesties: prompt-instructie, validatie van AI-output, HEAD-check.
 *
 * In tegenstelling tot {@see DB_AI_Internal_Links} bouwen we geen pool van bestaande
 * URLs op — de AI suggereert zelf URLs (met bias naar autoritaire bronnen). De
 * suggesties worden NIET automatisch ingevoegd; ze landen in `_db_ai_external_link_suggestions`
 * post meta en worden via een metabox aan de redacteur getoond ter keuze.
 */
final class DB_AI_External_Links {

	public const META_KEY            = '_db_ai_external_link_suggestions';
	public const META_KEY_INSERTED   = '_db_ai_external_links_inserted';
	public const HEAD_CACHE_TTL      = DAY_IN_SECONDS;
	public const HEAD_CACHE_PREFIX   = 'db_ai_ext_head_';

	public static function is_enabled(): bool {
		return DB_AI_Settings::is_external_links_enabled();
	}

	public static function get_max_suggestions(): int {
		return DB_AI_Settings::get_external_links_max();
	}

	/**
	 * User-prompt addition. Vraagt de AI een `external_link_suggestions`-array
	 * terug te geven naast het bestaande JSON-object. Bias naar bekende
	 * autoritaire bronnen om hallucinatie te beperken.
	 */
	public static function get_prompt_addition( int $max ): string {
		if ( $max <= 0 ) {
			return '';
		}

		return sprintf(
			"EXTERNE BRONNEN — geef in dezelfde JSON-output een aparte array `external_link_suggestions` " .
			"met %d voorgestelde externe links naar autoritaire bronnen. Deze worden NIET ingevoegd in de blog — " .
			"de redacteur kiest later welke gebruikt worden. Format per item:\n" .
			"  - anchor: ankertekst die natuurlijk in een zin past (NL, 2-6 woorden)\n" .
			"  - url: VOLLEDIGE https:// URL\n" .
			"  - why: 1 korte zin waarom deze bron relevant is\n" .
			"  - block_index: integer, in welk blok van `blocks` deze link thematisch het best past (0-based)\n\n" .
			"BRONNEN — kies BIJ VOORKEUR uit:\n" .
			"  - nl.wikipedia.org (bij algemene definities / achtergrond)\n" .
			"  - rijksoverheid.nl, belastingdienst.nl, kvk.nl, autoriteitconsumentenmarkt.nl (bij wet/regelgeving)\n" .
			"  - cbs.nl (bij statistieken / cijfers — alleen pagina-URLs, geen specifieke rapport-PDFs)\n" .
			"  - Bekende Nederlandse brancheorganisaties (.nl domein, herkenbare naam)\n" .
			"  - europa.eu, eur-lex.europa.eu (bij EU-regelgeving)\n\n" .
			"VERMIJD:\n" .
			"  - Concurrenten of commerciële partijen\n" .
			"  - Specifieke nieuwsartikelen (datums veranderen, URLs verlopen)\n" .
			"  - Deep-links naar tijdelijke landingpages\n" .
			"  - Affiliate-links of trackers in de URL\n\n" .
			"BELANGRIJK: verzin GEEN URLs. Als je niet 100%% zeker bent dat een URL bestaat, " .
			"kies een algemenere variant (Wikipedia search-pagina mag, bv. https://nl.wikipedia.org/wiki/Onderwerp). " .
			"Beter een Wikipedia-fallback dan een dode deep-link.",
			$max
		);
	}

	/**
	 * Sanitize AI-output. Verwijdert ongeldige entries, normaliseert URLs,
	 * cap't op `$max`. Returnt array van gevalideerde { anchor, url, why, block_index }.
	 *
	 * @param mixed $raw  Wat de AI in `external_link_suggestions` heeft gezet (kan alles zijn).
	 */
	public static function sanitize_suggestions( $raw, int $max ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$anchor      = trim( (string) ( $item['anchor'] ?? '' ) );
			$url         = trim( (string) ( $item['url'] ?? '' ) );
			$why         = trim( (string) ( $item['why'] ?? '' ) );
			$block_index = isset( $item['block_index'] ) ? (int) $item['block_index'] : -1;

			if ( '' === $anchor || '' === $url ) {
				continue;
			}

			// Alleen https:// of http:// URLs. esc_url_raw blokkeert protocol smuggling.
			$url = esc_url_raw( $url, [ 'http', 'https' ] );
			if ( '' === $url ) {
				continue;
			}

			// Externe link betekent: andere host dan deze site.
			if ( self::is_same_host( $url ) ) {
				continue;
			}

			$out[] = [
				'anchor'      => sanitize_text_field( $anchor ),
				'url'         => $url,
				'why'         => sanitize_text_field( $why ),
				'block_index' => $block_index >= 0 ? $block_index : 0,
			];

			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * HEAD-request validatie. Gecached 24u via transient om RankMath-paneel-reloads
	 * niet bij elke open een rondje requests te laten doen.
	 *
	 * @return array { status: 'ok'|'redirect'|'dead'|'timeout'|'blocked', http_code?: int, final_url?: string }
	 */
	public static function validate_url( string $url ): array {
		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $url ) {
			return [ 'status' => 'dead' ];
		}

		$cache_key = self::HEAD_CACHE_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_head(
			$url,
			[
				'timeout'     => 5,
				'redirection' => 3,
				'user-agent'  => 'Mozilla/5.0 (compatible; DB-AI-LinkCheck/1.0; +https://digitalebazen.nl)',
			]
		);

		$result = [ 'status' => 'ok' ];
		if ( is_wp_error( $response ) ) {
			$err           = $response->get_error_message();
			$result['status'] = false !== stripos( $err, 'timeout' ) ? 'timeout' : 'dead';
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$result['http_code'] = $code;
			if ( $code >= 200 && $code < 300 ) {
				$result['status'] = 'ok';
			} elseif ( $code >= 300 && $code < 400 ) {
				$result['status'] = 'redirect';
			} elseif ( 403 === $code || 405 === $code || 429 === $code ) {
				// Veel sites blokkeren HEAD of vragen rate-limit; daar is anti-bot vaak de oorzaak.
				$result['status'] = 'blocked';
			} else {
				$result['status'] = 'dead';
			}
		}

		set_transient( $cache_key, $result, self::HEAD_CACHE_TTL );
		return $result;
	}

	private static function is_same_host( string $url ): bool {
		$site_host = parse_url( home_url(), PHP_URL_HOST );
		$link_host = parse_url( $url, PHP_URL_HOST );
		if ( ! $site_host || ! $link_host ) {
			return false;
		}
		return strcasecmp( $site_host, $link_host ) === 0;
	}
}
