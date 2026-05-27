<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maakt ACF flex-content zichtbaar voor RankMath's content-analyzer.
 *
 * Probleem: dit theme rendert `paginacontent` via een directe template-include
 * zonder `the_content()` filter — RankMath's analyzer scant alleen `post_content`
 * en ziet daardoor geen H2/H3-titels, body-tekst of outbound links uit ACF blocks.
 *
 * Oplossing: bouw bij het laden van de post-editor een gerenderde HTML-versie van
 * de flex-content op basis van het frontend template-niveau (h1/h2/h4 etc.),
 * geef die mee via wp_localize_script, en hook hem via `wp.hooks.addFilter` op
 * RankMath's `rank_math_content` filter. Frontend rendering blijft ongewijzigd.
 */
final class DB_AI_Rankmath_Bridge {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
	}

	public function maybe_enqueue( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// Rank Math actief? Anders heeft het geen zin een filter te enqueuen.
		if ( ! defined( 'RANK_MATH_VERSION' ) && ! class_exists( 'RankMath' ) ) {
			return;
		}

		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$flex_field = $this->resolve_flex_field_name();
		if ( '' === $flex_field ) {
			return;
		}

		$rows = function_exists( 'get_field' ) ? get_field( $flex_field, $post_id ) : null;
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}

		$html = $this->render_rows_to_html( $rows );
		if ( '' === trim( $html ) ) {
			return;
		}

		wp_register_script(
			'db-ai-rankmath-bridge',
			DB_AI_PLUGIN_URL . 'assets/rankmath-bridge.js',
			[ 'wp-hooks' ],
			DB_AI_VERSION,
			true
		);

		wp_localize_script(
			'db-ai-rankmath-bridge',
			'dbAiRankmathBridge',
			[ 'html' => $html ]
		);

		wp_enqueue_script( 'db-ai-rankmath-bridge' );
	}

	private function resolve_post_id(): int {
		$post = get_post();
		if ( $post instanceof WP_Post ) {
			return (int) $post->ID;
		}
		if ( isset( $_GET['post'] ) ) {
			return absint( $_GET['post'] );
		}
		return 0;
	}

	private function resolve_flex_field_name(): string {
		if ( class_exists( 'DB_AI_Settings' ) && method_exists( 'DB_AI_Settings', 'get_flex_field_name' ) ) {
			$name = (string) DB_AI_Settings::get_flex_field_name();
			if ( '' !== $name ) {
				return $name;
			}
		}
		return 'paginacontent';
	}

	/**
	 * Walk de flex-rows en bouw een HTML-string met dezelfde heading-hiërarchie
	 * als de frontend (zie themes/bazentemplate/paginablokken/*.php).
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function render_rows_to_html( array $rows ): string {
		$out = [];

		foreach ( $rows as $row ) {
			$layout = (string) ( $row['acf_fc_layout'] ?? '' );
			switch ( $layout ) {
				case 'banner':
					$out[] = $this->render_simple_block( $row, 'h1' );
					break;
				case 'tekst_met_afbeelding':
				case 'tekst_weergaves':
				case 'fotogalerij':
					$out[] = $this->render_simple_block( $row, 'h2' );
					if ( ! empty( $row['tekst_kolom_2'] ) ) {
						$out[] = $this->wysiwyg( (string) $row['tekst_kolom_2'] );
					}
					break;
				case 'usps':
					$out[] = $this->render_simple_block( $row, 'h2', 'subtitel_usp' );
					foreach ( (array) ( $row['usps'] ?? [] ) as $usp ) {
						if ( ! empty( $usp['titel_content'] ) ) {
							$out[] = '<p>' . esc_html( (string) $usp['titel_content'] ) . '</p>';
						}
						if ( ! empty( $usp['tekst_content'] ) ) {
							$out[] = $this->wysiwyg( (string) $usp['tekst_content'] );
						}
					}
					break;
				case 'veelgestelde_vragen':
					$out[] = $this->render_simple_block( $row, 'h2' );
					foreach ( (array) ( $row['onderwerpen'] ?? [] ) as $onderwerp ) {
						if ( ! empty( $onderwerp['onderwerp_titel'] ) ) {
							$out[] = '<h4>' . esc_html( (string) $onderwerp['onderwerp_titel'] ) . '</h4>';
						}
						foreach ( (array) ( $onderwerp['vragen'] ?? [] ) as $vraag ) {
							if ( ! empty( $vraag['vraag'] ) ) {
								$out[] = '<p><strong>' . esc_html( (string) $vraag['vraag'] ) . '</strong></p>';
							}
							if ( ! empty( $vraag['antwoord'] ) ) {
								$out[] = $this->wysiwyg( (string) $vraag['antwoord'] );
							}
						}
					}
					break;
			}
		}

		return implode( "\n", array_filter( $out ) );
	}

	private function render_simple_block( array $row, string $titel_tag, string $subtitel_key = 'subtitel' ): string {
		$parts = [];
		if ( ! empty( $row[ $subtitel_key ] ) ) {
			$parts[] = '<p>' . esc_html( (string) $row[ $subtitel_key ] ) . '</p>';
		}
		if ( ! empty( $row['titel'] ) ) {
			$parts[] = '<' . $titel_tag . '>' . esc_html( (string) $row['titel'] ) . '</' . $titel_tag . '>';
		}
		if ( ! empty( $row['tekst'] ) ) {
			$parts[] = $this->wysiwyg( (string) $row['tekst'] );
		}
		return implode( "\n", $parts );
	}

	/**
	 * Wysiwyg-veldwaarden zijn al HTML (afkomstig uit de TinyMCE-output of door
	 * `wp_kses_post()` gesaneerd bij creatie). Onbewerkt teruggeven is veilig
	 * voor RankMath's parser; we strippen alleen `<script>`/`<style>` om die niet
	 * onbedoeld in de analyzer mee te tellen.
	 */
	private function wysiwyg( string $html ): string {
		return preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
	}
}
