<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layout-calibratie.
 *
 * FASE 1 (deterministisch): leidt uit de actieve theme-templates
 * (`paginablokken/{layout}.php`) af welke velden bij welke `weergave` daadwerkelijk
 * gerenderd worden, en levert dat als guidance-blok voor de AI-prompt. Doel:
 * voorkomen dat de generator content in een veld zet dat de gekozen weergave niet
 * toont (lege kolommen/secties) — bv. `tekst_weergaves` → `tekst-alternatief`
 * rendert de body uit `tekst_kolom_2`, niet uit `tekst`. Read-only, geen AI.
 *
 * FASE 2 (AI + review): `synthesize()` laat Claude per layout stijl/gebruik-guidance
 * schrijven o.b.v. templates, CSS-tokens en echte voorbeeldpagina's. De gebruiker
 * reviewt/bewerkt dat in Settings; het wordt als extra laag onder de deterministische
 * feiten in de prompt gezet (zie `get_prompt_addition()`).
 */
class DB_AI_Layout_Calibration {

	/** Settings-key (binnen db_ai_settings) waaronder de gekalibreerde guidance staat. */
	public const OPTION_GUIDANCE = 'layout_guidance';
	/** Settings-key voor de calibratie-metadata (datum + template-fingerprint). */
	public const OPTION_META = 'layout_guidance_meta';

	/**
	 * Geformatteerde guidance voor de user-prompt: deterministische veld→weergave-
	 * feiten, met eronder de (optionele) gekalibreerde stijl/gebruik-laag.
	 *
	 * @param array $layout_spec Zoals DB_AI_ACF_Mapper::get_layout_spec_for_prompt().
	 */
	public static function get_prompt_addition( array $layout_spec ): string {
		$map           = self::build_render_map( $layout_spec );
		$deterministic = empty( $map ) ? '' : self::format_guidance( $map );

		// Fase 2: door de gebruiker gekalibreerde (AI-gegenereerde + bewerkte) guidance
		// als extra laag eronder. De deterministische veld→weergave-feiten blijven
		// altijd leidend; de calibratie voegt stijl/gebruik-context toe.
		$enrichment = self::stored_guidance_block( $layout_spec );

		$combined = trim( $deterministic . ( '' !== $enrichment ? "\n\n" . $enrichment : '' ) );

		// Externe override-haak.
		return (string) apply_filters( 'db_ai_layout_guidance', $combined, $map, $layout_spec );
	}

	/**
	 * Publieke deterministische render-map (gebruikt door de calibratie-collector
	 * om de AI te gronden op de feitelijke veld→weergave-mapping).
	 */
	public static function get_render_map( array $layout_spec ): array {
		return self::build_render_map( $layout_spec );
	}

	/** Fingerprint van de template-set (paden + mtimes) — voor staleness-detectie. */
	public static function template_fingerprint( array $layout_spec ): string {
		$parts = [];
		foreach ( $layout_spec as $layout ) {
			$name = (string) ( $layout['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$file           = self::locate_template( $name );
			$parts[ $name ] = ( '' !== $file ) ? (string) filemtime( $file ) : '';
		}
		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Formatteert de opgeslagen, per-layout gekalibreerde guidance tot een prompt-blok.
	 * Alleen layouts die in de huidige spec voorkomen worden meegenomen.
	 */
	private static function stored_guidance_block( array $layout_spec ): string {
		if ( ! class_exists( 'DB_AI_Settings' ) ) {
			return '';
		}
		$opts   = DB_AI_Settings::get_options();
		$stored = isset( $opts[ self::OPTION_GUIDANCE ] ) && is_array( $opts[ self::OPTION_GUIDANCE ] )
			? $opts[ self::OPTION_GUIDANCE ]
			: [];
		if ( empty( $stored ) ) {
			return '';
		}

		$names = [];
		foreach ( $layout_spec as $layout ) {
			$name = (string) ( $layout['name'] ?? '' );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}

		$lines = [ 'LAYOUT-STIJL & GEBRUIK (gekalibreerd op dit thema) — gebruik per layout de juiste toon, lengte en inzet:' ];
		foreach ( $stored as $layout => $text ) {
			$text = trim( (string) $text );
			if ( '' === $text || ! isset( $names[ $layout ] ) ) {
				continue;
			}
			$lines[] = sprintf( '- `%s`: %s', $layout, $text );
		}

		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	/**
	 * Fase 2 — AI-synthese. Verzamelt theme-bronnen (templates, deterministische
	 * veld-mapping, CSS-tokens, voorbeeldpagina's) en laat Claude per layout beknopte
	 * stijl/gebruik-guidance schrijven. Persisteert NIET zelf — de aanroeper toont het
	 * resultaat ter review en slaat het op via de Settings-form.
	 *
	 * @return array|WP_Error  [ layoutName => guidance-tekst ]
	 */
	public static function synthesize( array $layout_spec ) {
		if ( empty( $layout_spec ) ) {
			return new WP_Error( 'db_ai_calib_no_layouts', __( 'Geen layouts gevonden om te kalibreren.', 'digitale-bazen-ai-module' ) );
		}
		if ( ! class_exists( 'DB_AI_Anthropic_Provider' ) || ! class_exists( 'DB_AI_Settings' ) ) {
			return new WP_Error( 'db_ai_calib_unavailable', __( 'Calibratie is niet beschikbaar in deze context.', 'digitale-bazen-ai-module' ) );
		}

		$names = [];
		foreach ( $layout_spec as $layout ) {
			$name = (string) ( $layout['name'] ?? '' );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}

		$provider = new DB_AI_Anthropic_Provider( DB_AI_Settings::get_api_key( 'anthropic' ) );

		$system = self::synthesis_system_prompt();
		$user   = self::synthesis_user_prompt( $layout_spec );

		$result = $provider->complete_json( $system, $user, 6000 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$layouts = isset( $result['layouts'] ) && is_array( $result['layouts'] ) ? $result['layouts'] : [];
		$out     = [];
		foreach ( $layouts as $layout => $text ) {
			$layout = (string) $layout;
			if ( ! isset( $names[ $layout ] ) || ! is_string( $text ) ) {
				continue;
			}
			$text = trim( $text );
			if ( '' !== $text ) {
				$out[ $layout ] = $text;
			}
		}

		if ( empty( $out ) ) {
			return new WP_Error( 'db_ai_calib_empty', __( 'De generator gaf geen bruikbare guidance terug. Probeer het opnieuw.', 'digitale-bazen-ai-module' ) );
		}

		return $out;
	}

	private static function synthesis_system_prompt(): string {
		return implode(
			"\n",
			[
				'Je bent een front-end/UX-analist die een AI-contentgenerator helpt om WordPress-blokken (ACF flexible content) goed te vullen.',
				'Je krijgt per layout: de velddefinitie, welke velden per "weergave" daadwerkelijk renderen (afgeleid uit de theme-templates), en de template-broncode. Daarnaast globale CSS-tokens en enkele echte voorbeeldpagina\'s.',
				'Schrijf voor ELKE layout beknopte, praktische guidance (2-4 zinnen, Nederlands) voor de generator:',
				'- hoe het blok visueel oogt;',
				'- wanneer je het inzet (en wanneer niet);',
				'- welk veld in welke positie/kolom landt — vooral relevant bij verschillende weergaves;',
				'- ideale tekstlengte en aantal items.',
				'Wees concreet en specifiek voor dit thema; vermijd algemeenheden. Antwoord UITSLUITEND met één geldig JSON-object, geen markdown of code fences.',
			]
		);
	}

	private static function synthesis_user_prompt( array $layout_spec ): string {
		$render_map = self::get_render_map( $layout_spec );

		$blocks = [];
		foreach ( $layout_spec as $layout ) {
			$name = (string) ( $layout['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$lines = [ '### Layout: ' . $name ];

			$field_descr = [];
			foreach ( (array) ( $layout['fields'] ?? [] ) as $field ) {
				$fname = (string) ( $field['name'] ?? '' );
				if ( '' === $fname ) {
					continue;
				}
				$ftype          = (string) ( $field['type'] ?? '' );
				$choices        = ! empty( $field['choices'] ) && is_array( $field['choices'] ) ? ' [' . implode( '|', $field['choices'] ) . ']' : '';
				$field_descr[]  = $fname . ' (' . $ftype . ')' . $choices;
			}
			if ( ! empty( $field_descr ) ) {
				$lines[] = 'Velden: ' . implode( ', ', $field_descr );
			}

			if ( isset( $render_map[ $name ] ) ) {
				foreach ( $render_map[ $name ] as $weergave => $fields ) {
					$label   = ( '' === $weergave ) ? 'rendert' : 'weergave "' . $weergave . '" rendert';
					$lines[] = $label . ': ' . implode( ', ', $fields );
				}
			}

			$src = self::read_template_source( $name );
			if ( '' !== $src ) {
				$lines[] = "Template:\n" . $src;
			}

			$blocks[] = implode( "\n", $lines );
		}

		$context  = implode( "\n\n", $blocks );
		$css      = self::collect_css_tokens();
		$examples = self::collect_sample_usage();

		$prompt  = 'Lever dit JSON-object terug (gebruik exact de layout-namen hieronder als sleutels):' . "\n";
		$prompt .= '{ "layouts": { "<layout-naam>": "guidance tekst" } }' . "\n\n";
		$prompt .= '=== LAYOUTS & TEMPLATES ===' . "\n" . $context;
		if ( '' !== $css ) {
			$prompt .= "\n\n" . '=== KLEURPALET & STIJL-TOKENS (uit de theme-stylesheet) ===' . "\n" . $css;
		}
		if ( '' !== $examples ) {
			$prompt .= "\n\n" . '=== ECHTE VOORBEELDPAGINA\'S (zo worden de blokken in de praktijk gebruikt) ===' . "\n" . $examples;
		}

		return $prompt;
	}

	/** Template-broncode (afgekapt) voor de AI-context. */
	private static function read_template_source( string $layout ): string {
		$file = self::locate_template( $layout );
		if ( '' === $file ) {
			return '';
		}
		$src = (string) file_get_contents( $file );
		return mb_substr( $src, 0, 4000 );
	}

	/**
	 * Best-effort: haal het echte kleurpalet + semantische kleur-toewijzingen uit de
	 * theme-stylesheet. Zoekt de bron in root én subdirs (css/, assets/, …), accepteert
	 * ook `.less` (waar de merkkleuren vaak als variabelen staan), slaat vendor-CSS over,
	 * en kiest het bestand met de meeste kleur-signalen.
	 */
	private static function collect_css_tokens(): string {
		$best       = '';
		$best_score = -1;
		foreach ( self::find_stylesheet_candidates() as $file ) {
			$css = (string) file_get_contents( $file );
			if ( '' === $css ) {
				continue;
			}
			// Kleur-signalen: hex-kleuren + LESS-vars + CSS custom properties.
			$score = preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $css )
				+ preg_match_all( '/@[\w-]+\s*:/', $css )
				+ preg_match_all( '/--[\w-]+\s*:/', $css );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $css;
			}
		}

		return '' === $best ? '' : self::extract_palette( $best );
	}

	/** Kandidaat-stylesheets (geen vendor, geen stubs) uit theme-root + bekende subdirs. */
	private static function find_stylesheet_candidates(): array {
		$bases = [];
		foreach ( [ 'get_stylesheet_directory', 'get_template_directory' ] as $fn ) {
			if ( function_exists( $fn ) ) {
				$bases[] = rtrim( (string) call_user_func( $fn ), '/' );
			}
		}

		$dirs = [];
		foreach ( array_unique( $bases ) as $base ) {
			$dirs[] = $base;
			foreach ( [ 'css', 'assets', 'assets/css', 'dist', 'build', 'styles', 'src/css' ] as $sub ) {
				$dirs[] = $base . '/' . $sub;
			}
		}

		$skip  = '/(bootstrap|reset|normalize|slick|swiper|video-?js|font-?awesome|select2|flatpickr|magnific|grid|admin-style)/i';
		$files = [];
		foreach ( array_unique( $dirs ) as $dir ) {
			foreach ( [ '/*.less', '/*.css' ] as $pattern ) {
				foreach ( (array) glob( $dir . $pattern ) as $file ) {
					if ( ! is_readable( $file ) || (int) filesize( $file ) < 500 ) {
						continue; // stubs (zoals een 364-byte style.css-header) overslaan
					}
					if ( preg_match( $skip, basename( (string) $file ) ) ) {
						continue;
					}
					$files[ $file ] = true;
				}
			}
		}
		return array_keys( $files );
	}

	/** Pak kleur-gerelateerde variabelen/toewijzingen uit een (LESS/CSS) stylesheet. */
	private static function extract_palette( string $css ): string {
		$lines = [];

		// LESS-variabelen: palet (@x: #hex) + semantische toewijzingen (@title-color: @secondary-800).
		if ( preg_match_all( '/@[\w-]+\s*:\s*[^;{}]+;/', $css, $m ) ) {
			foreach ( $m[0] as $decl ) {
				$decl     = trim( (string) preg_replace( '/\s+/', ' ', $decl ) );
				$is_color = (bool) preg_match( '/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/i', $decl );
				$is_named = (bool) preg_match( '/(colou?r|primary|secondary|accent|neutral|background|\bbg\b|title|font|actie|action|warning|error|success|white|black|blauw|wit|brand|link)/i', $decl );
				$refs_var = (bool) preg_match( '/:\s*@[\w-]+\s*;/', $decl );
				if ( $is_color || ( $is_named && $refs_var ) ) {
					$lines[] = $decl;
				}
			}
		}

		// CSS custom properties (niet-LESS thema's).
		if ( preg_match_all( '/--[\w-]+\s*:\s*[^;{}]+;/', $css, $cm ) ) {
			foreach ( $cm[0] as $decl ) {
				if ( preg_match( '/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(|colou?r|background/i', $decl ) ) {
					$lines[] = trim( (string) preg_replace( '/\s+/', ' ', $decl ) );
				}
			}
		}

		$lines = array_values( array_unique( $lines ) );

		// Fallback: meest voorkomende hex-kleuren als er geen variabelen zijn.
		if ( empty( $lines ) && preg_match_all( '/#[0-9a-fA-F]{6}\b/', $css, $hm ) ) {
			$counts = array_count_values( array_map( 'strtolower', $hm[0] ) );
			arsort( $counts );
			$lines = array_slice( array_keys( $counts ), 0, 12 );
		}

		return trim( mb_substr( implode( "\n", $lines ), 0, 2500 ) );
	}

	/** Compacte samenvatting van enkele echte pagina's die de blokken gebruiken. */
	private static function collect_sample_usage(): string {
		if ( ! class_exists( 'DB_AI_Settings' ) || ! function_exists( 'get_field' ) ) {
			return '';
		}
		$flex_field = DB_AI_Settings::get_flex_field_name();
		if ( '' === $flex_field ) {
			return '';
		}

		$post_types = array_values( get_post_types( [ 'public' => true ], 'names' ) );
		$query      = new WP_Query(
			[
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'     => $flex_field,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$out = [];
		foreach ( $query->posts as $post ) {
			$blocks = get_field( $flex_field, $post->ID );
			if ( empty( $blocks ) || ! is_array( $blocks ) ) {
				continue;
			}
			$summary = [];
			foreach ( $blocks as $block ) {
				$layout = (string) ( $block['acf_fc_layout'] ?? '' );
				if ( '' === $layout ) {
					continue;
				}
				$weergave  = (string) ( $block['weergave'] ?? '' );
				$summary[] = '' !== $weergave ? $layout . ' (' . $weergave . ')' : $layout;
			}
			if ( ! empty( $summary ) ) {
				$out[] = '- "' . get_the_title( $post->ID ) . '": ' . implode( ' -> ', $summary );
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * [ layoutName => [ weergaveValue|'' => string[] gerenderde content-velden ] ].
	 * Gecached in een transient; de sleutel bevat de template-mtimes zodat een
	 * theme-wijziging de cache automatisch invalideert.
	 */
	private static function build_render_map( array $layout_spec ): array {
		if ( empty( $layout_spec ) ) {
			return [];
		}

		$key    = 'db_ai_layout_calib_' . self::template_fingerprint( $layout_spec );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = [];
		foreach ( $layout_spec as $layout ) {
			$name = (string) ( $layout['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$file = self::locate_template( $name );
			if ( '' === $file ) {
				continue;
			}
			$src = (string) file_get_contents( $file );
			if ( '' === $src ) {
				continue;
			}
			$parsed = self::parse_template( $src, (array) ( $layout['fields'] ?? [] ) );
			if ( ! empty( $parsed ) ) {
				$map[ $name ] = $parsed;
			}
		}

		set_transient( $key, $map, DAY_IN_SECONDS );
		return $map;
	}

	/** Zoek de template; child-theme wint van parent-theme. */
	private static function locate_template( string $layout ): string {
		$bases = [];
		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$bases[] = get_stylesheet_directory();
		}
		if ( function_exists( 'get_template_directory' ) ) {
			$bases[] = get_template_directory();
		}
		foreach ( array_unique( $bases ) as $base ) {
			$file = rtrim( (string) $base, '/' ) . '/paginablokken/' . $layout . '.php';
			if ( is_readable( $file ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * @param string $src    Template-broncode.
	 * @param array  $fields Velden van deze layout uit de spec.
	 * @return array  [ weergaveValue|'' => string[] gerenderde content-veldnamen ]
	 */
	private static function parse_template( string $src, array $fields ): array {
		$content_fields = [];
		$select_fields  = [];
		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( 'select' === ( $field['type'] ?? '' ) ) {
				$select_fields[ $name ] = true;
			} else {
				$content_fields[ $name ] = true;
			}
		}
		if ( empty( $content_fields ) ) {
			return [];
		}

		// $var => ACF-veldnaam, plus telling van toewijzingen per var.
		$var_to_field  = [];
		$assign_counts = [];
		if ( preg_match_all( '/\$(\w+)\s*=\s*get_sub_field\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $set ) {
				$var                   = $set[1];
				$var_to_field[ $var ]  = $set[2];
				$assign_counts[ $var ] = ( $assign_counts[ $var ] ?? 0 ) + 1;
			}
		}

		// GATE 1 — hertoewijzing: een var die meerdere keren een get_sub_field krijgt
		// (bv. `$afbeelding = get_sub_field('mobiele_afbeelding')`) maakt de var→veld
		// mapping onbetrouwbaar. Liever geen guidance dan foute. Fase 2 (AI) snapt dit wel.
		foreach ( $assign_counts as $count ) {
			if ( $count > 1 ) {
				return [];
			}
		}

		// Variabelen waar de template op vertakt (select-velden zoals `weergave`).
		$select_vars = [];
		foreach ( $var_to_field as $var => $field ) {
			if ( isset( $select_fields[ $field ] ) ) {
				$select_vars[ $var ] = true;
			}
		}

		// Markers: posities van `if/elseif( $selectVar == 'waarde' )`.
		$markers = [];
		if ( preg_match_all(
			'/(?:if|elseif)\s*\(\s*\$(\w+)\s*==\s*[\'"]([^\'"]+)[\'"]\s*\)/',
			$src,
			$mm,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		) ) {
			foreach ( $mm as $set ) {
				if ( ! isset( $select_vars[ $set[1][0] ] ) ) {
					continue;
				}
				$markers[] = [
					'value'       => $set[2][0],
					'match_start' => $set[0][1],
					'start'       => $set[0][1] + strlen( $set[0][0] ),
				];
			}
		}

		// Geen branches → één generieke context met alle gerenderde velden.
		if ( empty( $markers ) ) {
			return self::single_context( $src, $var_to_field, $content_fields );
		}

		// Mogen we betrouwbaar PER weergave splitsen? Alleen bij een nette, volledige
		// if/elseif-keten:
		//  - minstens 2 takken (1 marker = if/else; de else-tak vangen we niet);
		//  - geen `else`-vangnet (anders missen we die tak volledig);
		//  - geen samengestelde condities (raw `==`-tellingen == aantal markers);
		//  - geen gedeelde content vóór de eerste tak.
		// Lukt dat niet, dan val terug op één generieke context — de UNIE van alle
		// gerenderde velden is dan nog steeds correct, alleen niet per-weergave
		// uitgesplitst. (De lastige gevallen pakt fase 2 met AI op.)
		$raw_comparisons = 0;
		foreach ( array_keys( $select_vars ) as $select_var ) {
			$raw_comparisons += (int) preg_match_all( '/\$' . preg_quote( $select_var, '/' ) . '\s*==/', $src );
		}
		$pre_region = substr( $src, 0, $markers[0]['match_start'] );
		$has_else   = (bool) preg_match( '/\belse\b\s*[:{]/', $src );

		$can_split = count( $markers ) >= 2
			&& ! $has_else
			&& $raw_comparisons === count( $markers )
			&& empty( self::rendered_fields_in( $pre_region, $var_to_field, $content_fields ) );

		if ( ! $can_split ) {
			return self::single_context( $src, $var_to_field, $content_fields );
		}

		$result = [];
		$count  = count( $markers );
		for ( $i = 0; $i < $count; $i++ ) {
			$from     = $markers[ $i ]['start'];
			$to       = ( $i + 1 < $count ) ? $markers[ $i + 1 ]['start'] : strlen( $src );
			$chunk    = substr( $src, $from, $to - $from );
			$rendered = self::rendered_fields_in( $chunk, $var_to_field, $content_fields );
			if ( empty( $rendered ) ) {
				continue;
			}
			$value            = $markers[ $i ]['value'];
			$result[ $value ] = isset( $result[ $value ] )
				? array_values( array_unique( array_merge( $result[ $value ], $rendered ) ) )
				: $rendered;
		}

		return self::collapse_identical( $result );
	}

	/**
	 * Eén generieke context: de unie van alle in de template gerenderde content-
	 * velden. Gebruikt wanneer er geen (betrouwbaar te splitsen) weergave-takken zijn.
	 *
	 * @param array<string,string> $var_to_field
	 * @param array<string,bool>   $content_fields
	 * @return array<string,string[]>
	 */
	private static function single_context( string $src, array $var_to_field, array $content_fields ): array {
		$rendered = self::rendered_fields_in( $src, $var_to_field, $content_fields );
		return empty( $rendered ) ? [] : [ '' => $rendered ];
	}

	/**
	 * Welke content-velden worden in dit stuk template gerenderd? Zowel via een
	 * `$var` (gekoppeld aan een get_sub_field) als via een directe inline-call.
	 *
	 * @param array<string,string> $var_to_field
	 * @param array<string,bool>   $content_fields
	 * @return string[]
	 */
	private static function rendered_fields_in( string $chunk, array $var_to_field, array $content_fields ): array {
		$found = [];

		// Verwijder eerst de toewijzings-statements (`$x = get_sub_field('x')`), anders
		// telt de var aan de linkerkant mee als "gebruikt" terwijl het puur een
		// toewijzing is. We willen alleen echte usages/renders detecteren.
		$chunk = (string) preg_replace( '/\$\w+\s*=\s*get_sub_field\(\s*[\'"][^\'"]+[\'"]\s*\)/', ' ', $chunk );

		if ( preg_match_all( '/\$(\w+)/', $chunk, $vm ) ) {
			foreach ( $vm[1] as $var ) {
				$field = $var_to_field[ $var ] ?? '';
				if ( '' !== $field && isset( $content_fields[ $field ] ) ) {
					$found[ $field ] = true;
				}
			}
		}

		if ( preg_match_all( '/get_sub_field\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $chunk, $gm ) ) {
			foreach ( $gm[1] as $field ) {
				if ( isset( $content_fields[ $field ] ) ) {
					$found[ $field ] = true;
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Als alle weergave-takken exact dezelfde velden tonen, dan voegt het splitsen
	 * niets toe (de select wijzigt bv. alleen een CSS-class) → terugvallen op één
	 * generieke entry.
	 *
	 * @param array<string,string[]> $result
	 * @return array<string,string[]>
	 */
	private static function collapse_identical( array $result ): array {
		if ( count( $result ) < 2 ) {
			return $result;
		}
		$signatures = [];
		foreach ( $result as $fields ) {
			$copy = $fields;
			sort( $copy );
			$signatures[] = implode( '|', $copy );
		}
		if ( count( array_unique( $signatures ) ) === 1 ) {
			return [ '' => reset( $result ) ];
		}
		return $result;
	}

	/** @param array<string,array<string,string[]>> $map */
	private static function format_guidance( array $map ): string {
		$lines   = [];
		$lines[] = 'WEERGAVE-VELD GIDS (automatisch afgeleid uit de actieve theme-templates) — per layout/weergave verschijnen ALLEEN de hieronder genoemde velden op de uiteindelijke pagina.';
		$lines[] = 'Vul exact die velden. Schrijf je hoofdtekst NOOIT in een tekstveld dat de gekozen weergave niet toont — dat veld blijft onzichtbaar en levert een lege sectie of kolom op. Worden er meerdere tekstvelden getoond (bv. twee kolommen)? Verdeel je inhoud daar dan evenwichtig over.';
		$lines[] = '';

		foreach ( $map as $layout => $contexts ) {
			if ( 1 === count( $contexts ) && isset( $contexts[''] ) ) {
				$lines[] = sprintf( '- Layout `%s` toont: %s', $layout, self::join_fields( $contexts[''] ) );
				continue;
			}
			$lines[] = sprintf( '- Layout `%s`:', $layout );
			foreach ( $contexts as $weergave => $fields ) {
				$label   = ( '' === $weergave ) ? '(standaard)' : sprintf( 'weergave `%s`', $weergave );
				$lines[] = sprintf( '   - %s toont: %s', $label, self::join_fields( $fields ) );
			}
		}

		return implode( "\n", $lines );
	}

	/** @param string[] $fields */
	private static function join_fields( array $fields ): string {
		return implode(
			', ',
			array_map(
				static function ( $field ) {
					return '`' . $field . '`';
				},
				$fields
			)
		);
	}
}
