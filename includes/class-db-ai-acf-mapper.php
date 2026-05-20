<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_ACF_Mapper {

	/**
	 * Fallback flex field name als geen Settings/filter gezet. Alleen actief op de
	 * originele Digitale Bazen site; andere sites kiezen via DB_AI_Settings.
	 */
	public const FLEX_FIELD_NAME = 'paginacontent';

	/**
	 * Image fields per layout (whitelist that the AI returns as { query, alt } objects).
	 * Keyed by [layout_name][field_name] = true.
	 */
	private const IMAGE_FIELDS = [
		'banner'                => [ 'afbeelding' => true ],
		'tekst_met_afbeelding'  => [ 'afbeelding' => true ],
		'fotogalerij'           => [ /* nested via repeater 'afbeeldingen' */ ],
	];

	/**
	 * Repeater fields that must contain at least one item, and their required sub-fields.
	 */
	private const REPEATER_RULES = [
		'usps' => [
			'repeater_field' => 'usps',
			'required_subs'  => [ 'titel_content', 'tekst_content' ],
		],
		'fotogalerij' => [
			'repeater_field' => 'afbeeldingen',
			'required_subs'  => [ 'afbeelding' ], // image object
			'image_subs'     => [ 'afbeelding' => true ],
		],
	];

	/**
	 * Resolved field group key voor deze site (Settings → constant → auto-detect).
	 */
	public function get_field_group_key(): string {
		return DB_AI_Settings::get_field_group_key();
	}

	/**
	 * Resolved flex field name binnen de field group (Settings → first available).
	 */
	public function get_flex_field_name(): string {
		$name = DB_AI_Settings::get_flex_field_name();
		return '' === $name ? self::FLEX_FIELD_NAME : $name;
	}

	/**
	 * Return raw layouts array from the ACF field group, keyed by layout key.
	 *
	 * @return array|WP_Error
	 */
	public function get_raw_layouts() {
		if ( ! function_exists( 'acf_get_field_group' ) || ! function_exists( 'acf_get_fields' ) ) {
			return new WP_Error( 'db_ai_acf_missing', __( 'ACF Pro functies niet beschikbaar.', 'digitale-bazen-ai-module' ) );
		}

		$group_key = $this->get_field_group_key();
		if ( '' === $group_key ) {
			return new WP_Error(
				'db_ai_field_group_missing',
				__( 'Geen ACF field group geconfigureerd. Kies één in Instellingen → AI Module → ACF integratie.', 'digitale-bazen-ai-module' )
			);
		}

		$group = acf_get_field_group( $group_key );
		if ( empty( $group ) ) {
			return new WP_Error(
				'db_ai_field_group_missing',
				sprintf(
					/* translators: %s = field group key */
					__( 'ACF field group %s niet gevonden.', 'digitale-bazen-ai-module' ),
					$group_key
				)
			);
		}

		$fields = acf_get_fields( $group );
		if ( empty( $fields ) ) {
			return new WP_Error( 'db_ai_no_fields', __( 'Field group bevat geen velden.', 'digitale-bazen-ai-module' ) );
		}

		$flex_name = $this->get_flex_field_name();
		$flex      = null;
		foreach ( $fields as $field ) {
			if ( ( $field['name'] ?? '' ) === $flex_name && ( $field['type'] ?? '' ) === 'flexible_content' ) {
				$flex = $field;
				break;
			}
		}

		if ( null === $flex || empty( $flex['layouts'] ) ) {
			return new WP_Error(
				'db_ai_flex_missing',
				sprintf(
					/* translators: %s = flex field name */
					__( 'Flexible content veld "%s" niet gevonden in field group.', 'digitale-bazen-ai-module' ),
					$flex_name
				)
			);
		}

		return $flex['layouts'];
	}

	/**
	 * Alle layouts die in de gekozen field group zitten. Volgorde = ACF-volgorde.
	 *
	 * @return string[]
	 */
	public function get_default_allowed_layouts(): array {
		$layouts = $this->get_raw_layouts();
		if ( is_wp_error( $layouts ) ) {
			return [];
		}
		$names = [];
		foreach ( $layouts as $layout ) {
			$n = (string) ( $layout['name'] ?? '' );
			if ( '' !== $n ) {
				$names[] = $n;
			}
		}
		return $names;
	}

	public function get_allowed_layouts(): array {
		return apply_filters( 'db_ai_allowed_layouts', $this->get_default_allowed_layouts() );
	}

	/**
	 * Layout fields keyed by layout name -> field name (for quick lookups).
	 *
	 * @return array|WP_Error
	 */
	public function get_layout_fields( string $layout_name ) {
		$layouts = $this->get_raw_layouts();
		if ( is_wp_error( $layouts ) ) {
			return $layouts;
		}
		foreach ( $layouts as $layout ) {
			if ( ( $layout['name'] ?? '' ) === $layout_name ) {
				return $layout['sub_fields'] ?? [];
			}
		}
		return new WP_Error(
			'db_ai_layout_missing',
			sprintf(
				/* translators: %s = layout name */
				__( 'Layout "%s" niet gevonden in field group.', 'digitale-bazen-ai-module' ),
				$layout_name
			)
		);
	}

	/**
	 * Build a compact layout spec for the AI prompt. Only includes allowed layouts.
	 *
	 * @return array|WP_Error  Array of ['name' => ..., 'fields' => [...]]
	 */
	public function get_layout_spec_for_prompt() {
		$layouts = $this->get_raw_layouts();
		if ( is_wp_error( $layouts ) ) {
			return $layouts;
		}
		$allowed = $this->get_allowed_layouts();

		$spec = [];
		foreach ( $layouts as $layout ) {
			$name = $layout['name'] ?? '';
			if ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}
			$spec[] = [
				'name'   => $name,
				'fields' => $this->describe_fields( $layout['sub_fields'] ?? [], $name ),
			];
		}
		return $spec;
	}

	private function describe_fields( array $sub_fields, string $layout_name ): array {
		$out = [];
		foreach ( $sub_fields as $field ) {
			$type = $field['type'] ?? '';
			$name = $field['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}

			$entry = [
				'name' => $name,
				'type' => $type,
			];
			if ( ! empty( $field['required'] ) ) {
				$entry['required'] = true;
			}

			if ( 'select' === $type ) {
				$choices = $field['choices'] ?? [];
				$entry['choices'] = is_array( $choices ) ? array_keys( $choices ) : [];
				if ( ! empty( $field['default_value'] ) ) {
					$entry['default'] = $field['default_value'];
				}
			}

			if ( 'image' === $type ) {
				$entry['expected_shape'] = '{ query: string (English search term), alt: string (Dutch alt) }';
			}

			if ( 'repeater' === $type ) {
				$entry['sub_fields'] = $this->describe_fields( $field['sub_fields'] ?? [], $layout_name . '.' . $name );
			}

			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * The output schema we want from the AI, embedded as-is in the prompt.
	 * Mirrors PROJECT_BRIEF section 8.
	 */
	public function get_output_schema_example(): array {
		return [
			'post' => [
				'title'   => 'string, 40-70 chars, bevat hoofdzoekwoord',
				'slug'    => 'string, kebab-case, NL, max 70 chars',
				'excerpt' => 'string, 120-160 chars, samenvatting',
			],
			'seo' => [
				'focus_keyword'    => 'string, exact het hoofdzoekwoord',
				'meta_title'       => 'string, max 60 chars, focus keyword vooraan',
				'meta_description' => 'string, max 155 chars, focus keyword + CTA',
			],
			'featured_image' => [
				'query' => 'string, ENGELSE zoekterm',
				'alt'   => 'string, NEDERLANDSE alt-tekst',
			],
			'blocks' => [
				'array van block-objecten met "acf_fc_layout" key, zie layout spec hierboven voor exacte veldspec',
			],
		];
	}

	/**
	 * Validate the AI output. Returns ['valid' => bool, 'errors' => [...], 'warnings' => [...]].
	 */
	public function validate_ai_output( $ai_output, string $main_keyword ): array {
		$errors   = [];
		$warnings = [];

		if ( ! is_array( $ai_output ) ) {
			return [
				'valid'    => false,
				'errors'   => [ __( 'AI output is geen JSON-object.', 'digitale-bazen-ai-module' ) ],
				'warnings' => [],
			];
		}

		// Rule 1: top-level keys
		foreach ( [ 'post', 'seo', 'featured_image', 'blocks' ] as $k ) {
			if ( ! array_key_exists( $k, $ai_output ) ) {
				$errors[] = sprintf( __( 'Top-level key "%s" ontbreekt.', 'digitale-bazen-ai-module' ), $k );
			}
		}
		if ( $errors ) {
			return [ 'valid' => false, 'errors' => $errors, 'warnings' => $warnings ];
		}

		// Rule 2: blocks non-empty
		if ( ! is_array( $ai_output['blocks'] ) || empty( $ai_output['blocks'] ) ) {
			$errors[] = __( '"blocks" is leeg of geen array.', 'digitale-bazen-ai-module' );
		}

		// Rule 7 for featured_image
		$this->validate_image_object( $ai_output['featured_image'], 'featured_image', $errors );

		// Rules 3-7 per block
		$allowed = $this->get_allowed_layouts();
		if ( is_array( $ai_output['blocks'] ) ) {
			foreach ( $ai_output['blocks'] as $i => $block ) {
				if ( ! is_array( $block ) ) {
					$errors[] = sprintf( __( 'Block %d is geen object.', 'digitale-bazen-ai-module' ), $i );
					continue;
				}
				$layout = $block['acf_fc_layout'] ?? '';
				if ( ! in_array( $layout, $allowed, true ) ) {
					$errors[] = sprintf(
						/* translators: 1 = index, 2 = layout */
						__( 'Block %1$d: layout "%2$s" niet toegestaan.', 'digitale-bazen-ai-module' ),
						$i,
						$layout
					);
					continue;
				}
				$this->validate_block( $i, $layout, $block, $errors );
			}
		}

		// Rule 8: soft warnings on meta lengths
		$mt = isset( $ai_output['seo']['meta_title'] ) ? (string) $ai_output['seo']['meta_title'] : '';
		if ( mb_strlen( $mt ) > 60 ) {
			$warnings[] = sprintf( __( 'meta_title is %d tekens (>60).', 'digitale-bazen-ai-module' ), mb_strlen( $mt ) );
		}
		$md = isset( $ai_output['seo']['meta_description'] ) ? (string) $ai_output['seo']['meta_description'] : '';
		if ( mb_strlen( $md ) > 155 ) {
			$warnings[] = sprintf( __( 'meta_description is %d tekens (>155).', 'digitale-bazen-ai-module' ), mb_strlen( $md ) );
		}

		// Rule 9: focus_keyword matches main_keyword
		$fk = isset( $ai_output['seo']['focus_keyword'] ) ? trim( (string) $ai_output['seo']['focus_keyword'] ) : '';
		if ( 0 !== strcasecmp( $fk, trim( $main_keyword ) ) ) {
			$errors[] = sprintf(
				/* translators: 1 = returned focus keyword, 2 = expected */
				__( 'seo.focus_keyword "%1$s" matcht niet met hoofdzoekwoord "%2$s".', 'digitale-bazen-ai-module' ),
				$fk,
				$main_keyword
			);
		}

		return [
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	private function validate_block( int $i, string $layout, array $block, array &$errors ): void {
		$fields = $this->get_layout_fields( $layout );
		if ( is_wp_error( $fields ) ) {
			$errors[] = sprintf( __( 'Block %1$d (%2$s): %3$s', 'digitale-bazen-ai-module' ), $i, $layout, $fields->get_error_message() );
			return;
		}

		// Required fields per ACF field group
		foreach ( $fields as $field ) {
			$name = $field['name'] ?? '';
			$type = $field['type'] ?? '';

			if ( ! empty( $field['required'] ) ) {
				$value = $block[ $name ] ?? null;
				if ( $this->is_empty_value( $value ) ) {
					$errors[] = sprintf(
						__( 'Block %1$d (%2$s): verplicht veld "%3$s" ontbreekt of is leeg.', 'digitale-bazen-ai-module' ),
						$i,
						$layout,
						$name
					);
				}
			}

			// Select-choice validation if present
			if ( 'select' === $type && isset( $block[ $name ] ) && '' !== $block[ $name ] ) {
				$choices = $field['choices'] ?? [];
				$allowed_values = is_array( $choices ) ? array_keys( $choices ) : [];
				if ( ! in_array( $block[ $name ], $allowed_values, true ) ) {
					$errors[] = sprintf(
						__( 'Block %1$d (%2$s): select "%3$s" heeft ongeldige waarde "%4$s".', 'digitale-bazen-ai-module' ),
						$i,
						$layout,
						$name,
						(string) $block[ $name ]
					);
				}
			}
		}

		// Image object validation (top-level "afbeelding" on layouts with an image field)
		$image_fields = self::IMAGE_FIELDS[ $layout ] ?? [];
		foreach ( $image_fields as $img_name => $_ ) {
			if ( ! isset( $block[ $img_name ] ) ) {
				continue;
			}
			$this->validate_image_object( $block[ $img_name ], "block[$i].$img_name", $errors );
		}

		// Repeater rules
		if ( isset( self::REPEATER_RULES[ $layout ] ) ) {
			$this->validate_repeater_block( $i, $layout, $block, $errors );
		}

		// Special: veelgestelde_vragen has nested repeaters (onderwerpen > vragen)
		if ( 'veelgestelde_vragen' === $layout ) {
			$this->validate_faq_block( $i, $block, $errors );
		}
	}

	private function validate_repeater_block( int $i, string $layout, array $block, array &$errors ): void {
		$rule          = self::REPEATER_RULES[ $layout ];
		$repeater_name = $rule['repeater_field'];
		$items         = $block[ $repeater_name ] ?? null;

		if ( ! is_array( $items ) || empty( $items ) ) {
			$errors[] = sprintf(
				__( 'Block %1$d (%2$s): repeater "%3$s" moet minstens 1 item bevatten.', 'digitale-bazen-ai-module' ),
				$i,
				$layout,
				$repeater_name
			);
			return;
		}

		foreach ( $items as $j => $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = sprintf(
					__( 'Block %1$d (%2$s): %3$s[%4$d] is geen object.', 'digitale-bazen-ai-module' ),
					$i,
					$layout,
					$repeater_name,
					$j
				);
				continue;
			}
			foreach ( $rule['required_subs'] as $sub_name ) {
				$value = $item[ $sub_name ] ?? null;
				$is_image_sub = isset( $rule['image_subs'][ $sub_name ] );
				if ( $is_image_sub ) {
					$this->validate_image_object( $value, "block[$i].$repeater_name" . "[$j].$sub_name", $errors );
					continue;
				}
				if ( $this->is_empty_value( $value ) ) {
					$errors[] = sprintf(
						__( 'Block %1$d (%2$s): %3$s[%4$d].%5$s ontbreekt of is leeg.', 'digitale-bazen-ai-module' ),
						$i,
						$layout,
						$repeater_name,
						$j,
						$sub_name
					);
				}
			}
		}
	}

	private function validate_faq_block( int $i, array $block, array &$errors ): void {
		$onderwerpen = $block['onderwerpen'] ?? null;
		if ( ! is_array( $onderwerpen ) || empty( $onderwerpen ) ) {
			$errors[] = sprintf( __( 'Block %d (veelgestelde_vragen): "onderwerpen" moet minstens 1 item bevatten.', 'digitale-bazen-ai-module' ), $i );
			return;
		}

		foreach ( $onderwerpen as $oi => $onderwerp ) {
			if ( ! is_array( $onderwerp ) ) {
				$errors[] = sprintf( __( 'Block %1$d (veelgestelde_vragen): onderwerpen[%2$d] is geen object.', 'digitale-bazen-ai-module' ), $i, $oi );
				continue;
			}
			$vragen = $onderwerp['vragen'] ?? null;
			if ( ! is_array( $vragen ) || empty( $vragen ) ) {
				$errors[] = sprintf( __( 'Block %1$d (veelgestelde_vragen): onderwerpen[%2$d].vragen moet minstens 1 item bevatten.', 'digitale-bazen-ai-module' ), $i, $oi );
				continue;
			}
			foreach ( $vragen as $vi => $vraag ) {
				if ( ! is_array( $vraag ) ) {
					$errors[] = sprintf( __( 'Block %1$d (veelgestelde_vragen): onderwerpen[%2$d].vragen[%3$d] is geen object.', 'digitale-bazen-ai-module' ), $i, $oi, $vi );
					continue;
				}
				if ( $this->is_empty_value( $vraag['vraag'] ?? null ) ) {
					$errors[] = sprintf( __( 'Block %1$d (veelgestelde_vragen): onderwerpen[%2$d].vragen[%3$d].vraag ontbreekt of is leeg.', 'digitale-bazen-ai-module' ), $i, $oi, $vi );
				}
				if ( $this->is_empty_value( $vraag['antwoord'] ?? null ) ) {
					$errors[] = sprintf( __( 'Block %1$d (veelgestelde_vragen): onderwerpen[%2$d].vragen[%3$d].antwoord ontbreekt of is leeg.', 'digitale-bazen-ai-module' ), $i, $oi, $vi );
				}
			}
		}
	}

	private function validate_image_object( $value, string $path, array &$errors ): void {
		if ( ! is_array( $value ) ) {
			$errors[] = sprintf( __( '"%s" moet een object zijn met "query" en "alt".', 'digitale-bazen-ai-module' ), $path );
			return;
		}
		if ( $this->is_empty_value( $value['query'] ?? null ) ) {
			$errors[] = sprintf( __( '"%s.query" ontbreekt of is leeg.', 'digitale-bazen-ai-module' ), $path );
		}
		if ( $this->is_empty_value( $value['alt'] ?? null ) ) {
			$errors[] = sprintf( __( '"%s.alt" ontbreekt of is leeg.', 'digitale-bazen-ai-module' ), $path );
		}
	}

	/**
	 * Site-specifieke layout.field combinaties die ALTIJD leeg moeten blijven —
	 * backwards-compat defaults voor de originele Digitale Bazen site. Op andere
	 * sites zijn deze layout-namen er niet, dus deze map heeft daar geen effect.
	 * Voor extra site-specifieke regels: gebruik filter `db_ai_always_empty_fields`.
	 */
	private const ALWAYS_EMPTY_FIELDS = [
		'banner'               => [ 'button', 'button_2', 'mobiele_afbeelding' ],
		'tekst_met_afbeelding' => [ 'button', 'button_2' ],
		'tekst_weergaves'      => [ 'button', 'button_2' ],
		'usps.usps'            => [ 'icoon_content' ],
	];

	/**
	 * Schrijf AI-blocks (na image-replacement) naar het flex field.
	 * Verwacht dat image-velden al ints (attachment IDs) zijn.
	 */
	public function write_blocks_to_post( int $post_id, array $blocks ): void {
		$allowed  = $this->get_allowed_layouts();
		$prepared = [];

		foreach ( $blocks as $block ) {
			$layout = $block['acf_fc_layout'] ?? '';
			if ( ! in_array( $layout, $allowed, true ) ) {
				continue;
			}
			$fields = $this->get_layout_fields( $layout );
			if ( is_wp_error( $fields ) ) {
				continue;
			}

			$row                  = $this->sanitize_block( $fields, $block, $layout );
			$row['acf_fc_layout'] = $layout;
			$prepared[]           = $row;
		}

		update_field( $this->get_flex_field_name(), $prepared, $post_id );
	}

	/**
	 * Bouwt de lijst velden die op deze layout altijd leeg moeten blijven.
	 * Combineert:
	 *  - Auto-detectie van `link` velden (AI moet geen URLs verzinnen)
	 *  - Hardcoded ALWAYS_EMPTY_FIELDS (alleen actief op de Digitale Bazen site;
	 *    layout-namen matchen niet op andere sites dus daar effect-loos)
	 *  - Filter `db_ai_always_empty_fields` voor site-specifieke overrides
	 *
	 * @param array  $sub_fields  ACF sub_fields van deze layout/context.
	 * @param string $context     Layout-naam, of `layout.repeater` bij nested.
	 * @return string[]
	 */
	private function compute_always_empty_for( array $sub_fields, string $context ): array {
		$auto = [];
		foreach ( $sub_fields as $field ) {
			if ( ( $field['type'] ?? '' ) === 'link' ) {
				$name = (string) ( $field['name'] ?? '' );
				if ( '' !== $name ) {
					$auto[] = $name;
				}
			}
		}
		$manual = self::ALWAYS_EMPTY_FIELDS[ $context ] ?? [];
		$merged = array_values( array_unique( array_merge( $auto, $manual ) ) );
		return (array) apply_filters( 'db_ai_always_empty_fields', $merged, $context );
	}

	private function sanitize_block( array $sub_fields, array $data, string $context ): array {
		$out          = [];
		$always_empty = $this->compute_always_empty_for( $sub_fields, $context );

		foreach ( $sub_fields as $field ) {
			$name = $field['name'] ?? '';
			$type = $field['type'] ?? '';
			if ( '' === $name ) {
				continue;
			}

			if ( in_array( $name, $always_empty, true ) ) {
				$out[ $name ] = $this->empty_value_for_type( $type );
				continue;
			}

			if ( ! array_key_exists( $name, $data ) ) {
				continue;
			}

			$value = $data[ $name ];

			switch ( $type ) {
				case 'text':
					$out[ $name ] = sanitize_text_field( (string) $value );
					break;
				case 'textarea':
					$out[ $name ] = sanitize_textarea_field( (string) $value );
					break;
				case 'wysiwyg':
					$out[ $name ] = wp_kses_post( (string) $value );
					break;
				case 'select':
					$choices  = isset( $field['choices'] ) && is_array( $field['choices'] ) ? array_keys( $field['choices'] ) : [];
					$default  = $field['default_value'] ?? '';
					$out[ $name ] = $this->coerce_select_value( $value, $choices, $default );
					break;
				case 'image':
					$out[ $name ] = is_numeric( $value ) ? (int) $value : '';
					break;
				case 'link':
					$out[ $name ] = $this->empty_value_for_type( 'link' );
					break;
				case 'repeater':
					$sub        = $field['sub_fields'] ?? [];
					$nested_ctx = $context . '.' . $name;
					if ( ! is_array( $value ) ) {
						$out[ $name ] = [];
						break;
					}
					$rows = [];
					foreach ( $value as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$rows[] = $this->sanitize_block( $sub, $item, $nested_ctx );
					}
					$out[ $name ] = $rows;
					break;
				default:
					$out[ $name ] = $value;
			}
		}

		return $out;
	}

	private function coerce_select_value( $value, array $choices, $default ) {
		if ( is_string( $value ) && in_array( $value, $choices, true ) ) {
			return $value;
		}
		if ( '' !== $default && in_array( $default, $choices, true ) ) {
			return $default;
		}
		return $choices[0] ?? '';
	}

	private function empty_value_for_type( string $type ) {
		switch ( $type ) {
			case 'link':
				return [ 'title' => '', 'url' => '', 'target' => '' ];
			case 'repeater':
				return [];
			case 'image':
				return '';
			default:
				return '';
		}
	}

	private function is_empty_value( $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}
		if ( is_array( $value ) ) {
			return empty( $value );
		}
		return false;
	}
}
