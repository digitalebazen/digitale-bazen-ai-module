<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schrijft RankMath SEO meta voor een post. Brief sectie 11.
 */
class DB_AI_SEO_Mapper {

	/**
	 * @param int   $post_id
	 * @param array $seo  ['focus_keyword' => ..., 'meta_title' => ..., 'meta_description' => ...]
	 */
	public function apply( int $post_id, array $seo ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! empty( $seo['focus_keyword'] ) ) {
			update_post_meta(
				$post_id,
				'rank_math_focus_keyword',
				sanitize_text_field( (string) $seo['focus_keyword'] )
			);
		}

		if ( ! empty( $seo['meta_title'] ) ) {
			update_post_meta(
				$post_id,
				'rank_math_title',
				sanitize_text_field( (string) $seo['meta_title'] )
			);
		}

		if ( ! empty( $seo['meta_description'] ) ) {
			update_post_meta(
				$post_id,
				'rank_math_description',
				sanitize_text_field( (string) $seo['meta_description'] )
			);
		}
	}
}
