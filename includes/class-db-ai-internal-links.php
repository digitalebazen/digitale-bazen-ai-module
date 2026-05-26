<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bouwt een interne-links sectie voor de AI user prompt en ruimt na generatie
 * eventuele "verzonnen" interne links op die niet bestaan op de site.
 *
 * Architectuur:
 *  - Pool wordt opgebouwd op basis van Settings (post types, max aantal candidates)
 *  - Per kandidaat een relevance score op title-overlap met hoofdzoekwoord/onderwerp
 *  - Top-N candidates worden als URL+title lijst in de user prompt geïnjecteerd
 *  - Cache via transient zodat herhaalde generaties met zelfde keyword sneller zijn
 *  - Post-hoc cleanup vervangt <a href="...">x</a> waarvoor geen matching slug bestaat
 *    door alleen de inner text — voorkomt 404 links in de output
 */
class DB_AI_Internal_Links {

	public const CACHE_TTL = HOUR_IN_SECONDS;
	public const CACHE_KEY_PREFIX = 'db_ai_link_pool_';

	public const MIN_KEYWORD_LENGTH = 4;
	public const POOL_SIZE = 15;

	/**
	 * Is internal linking aangezet in Settings?
	 */
	public static function is_enabled(): bool {
		$opts = DB_AI_Settings::get_options();
		return ! empty( $opts['internal_links_enabled'] );
	}

	public static function get_max_links(): int {
		$opts = DB_AI_Settings::get_options();
		$val  = (int) ( $opts['internal_links_max'] ?? 3 );
		return max( 1, min( 5, $val ) );
	}

	public static function get_post_types(): array {
		$opts = DB_AI_Settings::get_options();
		$picks = $opts['internal_links_post_types'] ?? [ 'page', 'blog' ];
		if ( ! is_array( $picks ) || empty( $picks ) ) {
			$picks = [ 'page', 'blog' ];
		}
		return array_values( array_filter( array_map( 'sanitize_key', $picks ) ) );
	}

	/**
	 * Bouw de top-N relevante pages voor een hoofdzoekwoord. Cached via transient.
	 *
	 * @return array<int,array{url:string,title:string,slug:string}>
	 */
	public static function get_link_pool( string $main_keyword, array $secondary_keywords = [] ): array {
		if ( ! self::is_enabled() ) {
			return [];
		}

		$cache_key = self::CACHE_KEY_PREFIX . md5( $main_keyword . '|' . implode( ',', $secondary_keywords ) . '|' . implode( ',', self::get_post_types() ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$candidates = get_posts(
			[
				'post_type'        => self::get_post_types(),
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
				'meta_query'       => [
					'relation' => 'OR',
					[ 'key' => '_db_ai_skip_internal_link', 'compare' => 'NOT EXISTS' ],
					[ 'key' => '_db_ai_skip_internal_link', 'value' => '1', 'compare' => '!=' ],
				],
			]
		);

		$keywords = array_filter(
			array_merge(
				preg_split( '/\s+/', strtolower( $main_keyword ) ) ?: [],
				array_map( 'strtolower', $secondary_keywords )
			),
			static fn( $w ) => mb_strlen( (string) $w ) >= self::MIN_KEYWORD_LENGTH
		);

		$scored = [];
		foreach ( $candidates as $post ) {
			$score = self::score_post( $post, $keywords );
			if ( $score <= 0 ) {
				continue;
			}
			$scored[] = [
				'post'  => $post,
				'score' => $score,
			];
		}

		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$scored = array_slice( $scored, 0, self::POOL_SIZE );

		$pool = [];
		foreach ( $scored as $item ) {
			$post  = $item['post'];
			$pool[] = [
				'url'   => self::relative_url( get_permalink( $post ) ),
				'title' => $post->post_title,
				'slug'  => $post->post_name,
			];
		}

		set_transient( $cache_key, $pool, self::CACHE_TTL );
		return $pool;
	}

	/**
	 * Relevantie-score: hoeveel keyword-words komen voor in title + slug + excerpt.
	 * Bonus voor pages (vaak service-/landingspagina's) en voor recent gewijzigd.
	 */
	private static function score_post( WP_Post $post, array $keyword_words ): int {
		if ( empty( $keyword_words ) ) {
			return 0;
		}

		$haystack = strtolower( $post->post_title . ' ' . $post->post_name . ' ' . $post->post_excerpt );
		$score    = 0;
		foreach ( $keyword_words as $word ) {
			if ( false !== mb_strpos( $haystack, (string) $word ) ) {
				$score += 5;
			}
		}

		if ( 'page' === $post->post_type ) {
			$score += 3;
		}

		$days_old = ( time() - (int) strtotime( $post->post_modified_gmt ) ) / DAY_IN_SECONDS;
		if ( $days_old < 30 ) {
			$score += 2;
		} elseif ( $days_old < 90 ) {
			$score += 1;
		}

		return $score;
	}

	/**
	 * Resolve een lijst post IDs naar URL/title-entries. Behoudt de volgorde
	 * van de input (zodat user-keuzes prioriteit krijgen).
	 *
	 * @param array<int|string> $ids
	 * @return array<int,array{url:string,title:string,slug:string}>
	 */
	public static function get_forced_links( array $ids ): array {
		$forced = [];
		foreach ( $ids as $id ) {
			$id   = absint( $id );
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$forced[] = [
				'url'   => self::relative_url( get_permalink( $post ) ),
				'title' => $post->post_title,
				'slug'  => $post->post_name,
			];
		}
		return $forced;
	}

	/**
	 * Combineer geforceerde + automatische pool, deduplicate op URL, en respecteer
	 * het globale max — forced krijgen voorrang.
	 *
	 * @return array{pool:array,forced_count:int}
	 */
	public static function merge_pools( array $forced, array $auto_pool, int $max_links ): array {
		$pool         = [];
		$seen         = [];
		$forced_count = 0;

		foreach ( $forced as $entry ) {
			$key = $entry['url'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$pool[]       = $entry;
			$forced_count++;
		}

		foreach ( $auto_pool as $entry ) {
			$key = $entry['url'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$pool[]       = $entry;
		}

		return [
			'pool'         => $pool,
			'forced_count' => $forced_count,
		];
	}

	/**
	 * Format de pool als prompt-sectie. Forced links worden apart gemarkeerd als
	 * PRIORITEIT zodat de AI ze probeert te plaatsen.
	 */
	public static function get_prompt_addition( array $pool, int $max_links, int $forced_count = 0 ): string {
		if ( empty( $pool ) ) {
			return '';
		}

		$forced = array_slice( $pool, 0, $forced_count );
		$auto   = array_slice( $pool, $forced_count );

		$forced_block = '';
		if ( ! empty( $forced ) ) {
			$lines = [];
			foreach ( $forced as $entry ) {
				$lines[] = '- ' . $entry['url'] . ' — "' . $entry['title'] . '"';
			}
			$forced_block = "PRIORITEIT — gebruik deze links als ze maar enigszins passen in de tekst (1× per stuk, max):\n" . implode( "\n", $lines ) . "\n\n";
		}

		$auto_block = '';
		if ( ! empty( $auto ) ) {
			$lines = [];
			foreach ( $auto as $entry ) {
				$lines[] = '- ' . $entry['url'] . ' — "' . $entry['title'] . '"';
			}
			$auto_block = "OVERIGE OPTIES — gebruik 1-2 ervan als ze écht passen:\n" . implode( "\n", $lines ) . "\n\n";
		}

		return sprintf(
			"INTERNE LINKS BESCHIKBAAR — verwerk maximaal %d natuurlijk in de tekst. " .
			"Gebruik alleen URLs uit onderstaande lijsten, verzin GEEN nieuwe URLs.\n\n" .
			"%s%s" .
			"Plaats links via standaard HTML: <a href=\"/pad/\">anchor-tekst</a>. " .
			"Anchor-tekst hoeft NIET de exacte page-titel te zijn — kies natuurlijke bewoording die past in de zin.",
			$max_links,
			$forced_block,
			$auto_block
		);
	}

	/**
	 * Loopt recursief door $data en vervangt <a href="/..."> die niet matchen
	 * met een entry in $pool door alleen de inner text. Voorkomt 404's bij
	 * AI-hallucinaties.
	 *
	 * @return int Aantal opgeruimde orphan-links (voor logging).
	 */
	public static function clean_orphan_links( array &$data, array $pool ): int {
		$valid_paths = [];
		foreach ( $pool as $entry ) {
			$path = self::normalize_path( $entry['url'] );
			if ( '' !== $path ) {
				$valid_paths[ $path ] = true;
			}
		}

		$stripped = 0;
		self::walk_strings(
			$data,
			static function ( $html ) use ( $valid_paths, &$stripped ) {
				if ( ! is_string( $html ) || false === strpos( $html, '<a ' ) ) {
					return $html;
				}
				return preg_replace_callback(
					'#<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>(.*?)</a>#is',
					static function ( $m ) use ( $valid_paths, &$stripped ) {
						$href = $m[2];
						// Externe of mailto/tel links ongewijzigd laten
						if ( preg_match( '#^(https?:)?//#i', $href ) ) {
							$home = wp_parse_url( home_url() );
							$link = wp_parse_url( $href );
							if ( ! $home || ! $link || ( $home['host'] ?? '' ) !== ( $link['host'] ?? '' ) ) {
								return $m[0]; // andere host = extern, niet aanraken
							}
							$href = $link['path'] ?? '/';
						} elseif ( '/' !== substr( $href, 0, 1 ) ) {
							return $m[0]; // relatieve link of fragment — niet onze zorg
						}

						$path = self::normalize_path( $href );
						if ( isset( $valid_paths[ $path ] ) ) {
							return $m[0]; // geldige interne link
						}
						$stripped++;
						return $m[4]; // strip <a> wrapper, behoud anchor-text
					},
					$html
				);
			}
		);

		return $stripped;
	}

	/**
	 * Wandelt recursief door array-data en past callback toe op alle strings.
	 */
	private static function walk_strings( array &$data, callable $cb ): void {
		foreach ( $data as $key => &$val ) {
			if ( is_string( $val ) ) {
				$val = $cb( $val );
			} elseif ( is_array( $val ) ) {
				self::walk_strings( $val, $cb );
			}
		}
		unset( $val );
	}

	private static function relative_url( string $url ): string {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$link_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $home_host === $link_host ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			return $path ? $path : '/';
		}
		return $url;
	}

	private static function normalize_path( string $url ): string {
		$path = $url;
		if ( '/' !== substr( $url, 0, 1 ) ) {
			$parsed = wp_parse_url( $url );
			$path   = $parsed['path'] ?? '';
		}
		return rtrim( $path, '/' ) ?: '/';
	}
}
