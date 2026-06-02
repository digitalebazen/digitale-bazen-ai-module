<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Levert een lijst van recent gegenereerde blog-titels + focus-keywords mee aan
 * de prompt zodat de AI geen herhalende titels of structuren produceert (bv.
 * elke generatie "X in 7 stappen"). Werkt op het ingestelde post-type (default
 * `blog`, filterbaar via `db_ai_post_type`).
 */
final class DB_AI_Past_Blogs_Context {

	/**
	 * Recente publicaties + drafts op het AI-post-type.
	 *
	 * @return array<int,array{title:string,focus_keyword:string}>
	 */
	public static function get_recent( int $limit = 20 ): array {
		$limit     = max( 1, (int) apply_filters( 'db_ai_past_blogs_limit', $limit ) );
		$post_type = (string) apply_filters( 'db_ai_post_type', 'blog' );

		$q = new WP_Query( [
			'post_type'      => $post_type,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$out = [];
		foreach ( $q->posts as $post_id ) {
			$title = trim( (string) get_the_title( $post_id ) );
			if ( '' === $title ) {
				continue;
			}
			$out[] = [
				'title'         => $title,
				'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
			];
		}
		return $out;
	}

	/**
	 * Bouwt het prompt-blok dat aan de user-prompt wordt toegevoegd.
	 * Lege string als er nog geen eerdere blogs zijn.
	 *
	 * @param array<int,array{title:string,focus_keyword:string}> $recent
	 */
	public static function get_prompt_addition( array $recent ): string {
		if ( empty( $recent ) ) {
			return '';
		}

		$lines   = [];
		$lines[] = 'EERDER GEPUBLICEERDE BLOGS OP DEZE SITE — vermijd herhaling van structuur, getal of invalshoek:';
		foreach ( $recent as $entry ) {
			$title = (string) ( $entry['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$line = '- ' . $title;
			$kw   = trim( (string) ( $entry['focus_keyword'] ?? '' ) );
			if ( '' !== $kw ) {
				$line .= ' (focus: ' . $kw . ')';
			}
			$lines[] = $line;
		}
		$lines[] = '';
		$lines[] = 'KRITIEK voor je nieuwe titel:';
		$lines[] = '- Kies een ONDERSCHEIDEND getal (niet hetzelfde getal als hierboven al gebruikt — als "7" of "5" vaak voorkomt, varieer naar 3, 4, 6, 8, 9, 10 of een logisch alternatief).';
		$lines[] = '- Kies een andere structuur/invalshoek dan eerdere titels. Niet elke blog hoeft "X in N stappen" te zijn. Andere patronen: "N redenen waarom...", "Complete gids voor...", "Checklist: hoe je...", "Wat werkt en wat niet bij...", "N veelgemaakte fouten bij...".';
		$lines[] = '- Vermijd overlappende focus-keywords met eerdere blogs — als een thema al gedekt is, kies een specifiekere sub-invalshoek.';

		return implode( "\n", $lines );
	}
}
