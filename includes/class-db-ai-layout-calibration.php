<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layout-calibratie — Fase 1 (deterministisch).
 *
 * Leidt uit de actieve theme-templates (`paginablokken/{layout}.php`) af welke
 * velden bij welke `weergave` daadwerkelijk gerenderd worden, en levert dat als
 * guidance-blok voor de AI-prompt. Doel: voorkomen dat de generator content in
 * een veld zet dat de gekozen weergave niet toont — dat leidt tot lege kolommen
 * of secties (zie bv. `tekst_weergaves` → `tekst-alternatief` rendert de body uit
 * `tekst_kolom_2`, niet uit `tekst`).
 *
 * Volledig read-only en lokaal; geen AI. De rijkere, esthetische calibratie
 * (AI-synthese + review in Settings) komt in fase 2 hier bovenop via het filter
 * `db_ai_layout_guidance`.
 */
class DB_AI_Layout_Calibration {

	/**
	 * Geformatteerde guidance voor de user-prompt, of '' als er niets bruikbaars
	 * uit de templates te halen valt.
	 *
	 * @param array $layout_spec Zoals DB_AI_ACF_Mapper::get_layout_spec_for_prompt():
	 *                           [ ['name'=>.., 'fields'=>[['name'=>..,'type'=>..], ..]], .. ]
	 */
	public static function get_prompt_addition( array $layout_spec ): string {
		$map      = self::build_render_map( $layout_spec );
		$guidance = empty( $map ) ? '' : self::format_guidance( $map );

		// Fase-2-haak: laat opgeslagen/AI-verrijkte guidance dit overschrijven.
		return (string) apply_filters( 'db_ai_layout_guidance', $guidance, $map, $layout_spec );
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

		$fingerprint = [];
		foreach ( $layout_spec as $layout ) {
			$name = (string) ( $layout['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$file                 = self::locate_template( $name );
			$fingerprint[ $name ] = ( '' !== $file ) ? $file . ':' . (string) filemtime( $file ) : '';
		}

		$key    = 'db_ai_layout_calib_' . md5( (string) wp_json_encode( $fingerprint ) );
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
