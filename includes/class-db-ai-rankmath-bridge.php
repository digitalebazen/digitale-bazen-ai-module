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
 * Oplossing: bij het laden van de post-editor renderen we de échte theme-templates
 * (`paginablokken/{layout}.php`) in een output buffer en geven die HTML mee via
 * wp_localize_script aan een JS-handler die hem op RankMath's `rank_math_content`
 * filter hookt. Voordeel: zodra het theme een blok-template wijzigt (heading-
 * niveau, extra veld, andere classnames) ziet RankMath dat automatisch — geen
 * plugin-update meer nodig per blok-wijziging.
 *
 * Fallback: als er geen `paginablokken/`-map in het theme staat of een template
 * faalt tijdens rendering, valt de bridge terug op een handmatige mirror
 * (`render_rows_to_html`) die de bekende layouts hardcoded mapt. Houdt oudere
 * sites + niet-Digitale-Bazen-themes werkend.
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

		// Probeer eerst de échte theme-templates te renderen — dan ziet RankMath
		// 1-op-1 wat de bezoeker ook ziet, zonder dat we per blok-wijziging in de
		// plugin iets hoeven aan te passen.
		$html = $this->render_via_theme_templates( $post_id, $flex_field );

		// Fallback: theme heeft geen `paginablokken/`-map, een template faalde, of
		// de flex-data leverde geen output. Dan gebruiken we de handmatige mirror
		// (hardcoded layouts) zodat oudere sites + niet-DB-themes blijven werken.
		if ( '' === trim( $html ) ) {
			$rows = function_exists( 'get_field' ) ? get_field( $flex_field, $post_id ) : null;
			if ( empty( $rows ) || ! is_array( $rows ) ) {
				return;
			}
			$html = $this->render_rows_to_html( $rows );
			if ( '' === trim( $html ) ) {
				return;
			}
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
	 * Rendert de échte theme-templates uit `paginablokken/{layout}.php` voor elk
	 * flex-row van deze post, in een output buffer. Dat geeft 1-op-1 dezelfde
	 * HTML als de bezoeker ziet, zodat RankMath's checks (heading-hiërarchie,
	 * woordtelling, focus-keyword-in-subkop, outbound links) op de werkelijkheid
	 * draaien in plaats van op een hardcoded mirror.
	 *
	 * Returnt lege string bij ontbrekende theme-folder, geen ACF-data, of een
	 * template die throwt — de caller valt dan terug op `render_rows_to_html()`.
	 */
	private function render_via_theme_templates( int $post_id, string $flex_field ): string {
		if ( ! function_exists( 'have_rows' ) || ! function_exists( 'get_row_layout' ) ) {
			return '';
		}

		// Theme moet een `paginablokken/`-map hebben — anders is er niks om aan
		// te roepen via `get_template_part()`. We kijken in zowel het actieve als
		// het parent-theme (child-themes vallen op het parent terug).
		$has_folder = is_dir( get_stylesheet_directory() . '/paginablokken' )
			|| is_dir( get_template_directory() . '/paginablokken' );
		if ( ! $has_folder ) {
			return '';
		}

		// De blok-templates draaien hun eigen WP_Query-loops (medewerkers, projecten,
		// ...) met the_post()/setup_postdata() en resetten global $post niet. Op het
		// admin-bewerkscherm laat dat global $post op de laatste medewerker staan,
		// waardoor ACF de verkeerde veldgroepen matcht (je bewerkt schijnbaar een
		// medewerker i.p.v. de pagina). Bewaar de context en herstel hem hieronder.
		global $post;
		$original_post = $post;

		ob_start();
		$rendered = false;
		$html     = '';
		try {
			if ( have_rows( $flex_field, $post_id ) ) {
				while ( have_rows( $flex_field, $post_id ) ) {
					the_row();
					$layout = (string) get_row_layout();
					if ( '' === $layout ) {
						continue;
					}
					get_template_part( 'paginablokken/' . $layout );
					$rendered = true;
				}
			}
			$html = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			// Onverwachte fatal in een template: schoonruimen en terugvallen op de
			// handmatige mirror (lege return hieronder).
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$rendered = false;
		}

		// Herstel de globale post-context die de blok-templates vervuild hebben.
		$post = $original_post;
		if ( $original_post instanceof \WP_Post ) {
			setup_postdata( $original_post );
		} else {
			wp_reset_postdata();
		}

		return $rendered ? trim( $html ) : '';
	}

	/**
	 * Handmatige mirror — walk de flex-rows en bouw een HTML-string met dezelfde
	 * heading-hiërarchie als de frontend (zie themes/bazentemplate/paginablokken/
	 * *.php). Wordt alleen gebruikt als fallback wanneer `render_via_theme_templates()`
	 * geen output oplevert (theme zonder paginablokken/, template-fatal, of geen
	 * ACF-data).
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
					// Spiegelt frontend (themes/bazentemplate/paginablokken/veelgestelde_vragen.php): één heading-niveau voor álle vragen in dit blok — H4 zodra er ergens een onderwerp_titel staat (gegroepeerd), anders H3 (plat).
					$out[] = $this->render_simple_block( $row, 'h2' );
					$onderwerpen             = (array) ( $row['onderwerpen'] ?? [] );
					$has_any_onderwerp_titel = false;
					foreach ( $onderwerpen as $o ) {
						if ( ! empty( $o['onderwerp_titel'] ) ) {
							$has_any_onderwerp_titel = true;
							break;
						}
					}
					$vraag_tag = $has_any_onderwerp_titel ? 'h4' : 'h3';
					foreach ( $onderwerpen as $onderwerp ) {
						if ( ! empty( $onderwerp['onderwerp_titel'] ) ) {
							$out[] = '<h3>' . esc_html( (string) $onderwerp['onderwerp_titel'] ) . '</h3>';
						}
						foreach ( (array) ( $onderwerp['vragen'] ?? [] ) as $vraag ) {
							if ( ! empty( $vraag['vraag'] ) ) {
								$out[] = '<' . $vraag_tag . '>' . esc_html( (string) $vraag['vraag'] ) . '</' . $vraag_tag . '>';
							}
							$antwoord_html = $this->faq_answer_html( $vraag['antwoord'] ?? '' );
							if ( '' !== $antwoord_html ) {
								$out[] = $antwoord_html;
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

	/**
	 * Rendert een FAQ-`antwoord` naar HTML voor de analyzer. `antwoord` is van
	 * wysiwyg-string omgezet naar flexible_content (layouts tekst/afbeelding/button);
	 * we pakken de tekst-sub-velden uit elke flex-rij en slaan afbeelding/button
	 * (arrays) over. Oude string-antwoorden blijven werken.
	 *
	 * @param mixed $antwoord
	 */
	private function faq_answer_html( $antwoord ): string {
		if ( is_string( $antwoord ) ) {
			return '' === trim( $antwoord ) ? '' : $this->wysiwyg( $antwoord );
		}
		if ( ! is_array( $antwoord ) ) {
			return '';
		}
		$parts = [];
		foreach ( $antwoord as $row ) {
			if ( ! is_array( $row ) ) {
				if ( is_string( $row ) && '' !== trim( $row ) ) {
					$parts[] = $this->wysiwyg( $row );
				}
				continue;
			}
			foreach ( $row as $key => $value ) {
				if ( 'acf_fc_layout' === $key || ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}
				$parts[] = $this->wysiwyg( $value );
			}
		}
		return implode( "\n", $parts );
	}
}
