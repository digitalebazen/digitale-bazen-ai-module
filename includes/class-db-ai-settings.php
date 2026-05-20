<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings-page voor API keys + provider keuze.
 *
 * Constants in wp-config.php winnen ALTIJD van waarden in de DB. Reden: staging/productie
 * scheiden, leakage van keys via DB exports voorkomen. Als constant gedefinieerd is wordt
 * het bijbehorende veld op de settings-page disabled en getoond als "ingesteld via
 * wp-config.php".
 *
 * Schema voor option `db_ai_settings`:
 *   [
 *     'provider'      => ''|'anthropic'|'openai',
 *     'anthropic_key' => '...',
 *     'openai_key'    => '...',
 *     'pexels_key'    => '...',
 *     'unsplash_key'  => '...',
 *   ]
 */
class DB_AI_Settings {

	public const OPTION_NAME = 'db_ai_settings';

	public const PAGE_SLUG = 'db-ai-settings';

	private const KEY_TO_CONSTANT = [
		'anthropic' => 'DB_AI_ANTHROPIC_API_KEY',
		'openai'    => 'DB_AI_OPENAI_API_KEY',
		'pexels'    => 'DB_AI_PEXELS_API_KEY',
		'unsplash'  => 'DB_AI_UNSPLASH_API_KEY',
		'github'    => 'DB_AI_GITHUB_TOKEN',
	];

	/**
	 * User-facing labels voor de block-layout checkboxes. Volgorde = volgorde in UI.
	 */
	private const LAYOUT_LABELS = [
		'banner'               => 'Banner (intro / hero — meestal eerste blok)',
		'tekst_met_afbeelding' => 'Tekst met afbeelding (body content, alternerend links/rechts)',
		'tekst_weergaves'      => 'Tekst-weergaves (1- of 2-koloms tekst-blok)',
		'usps'                 => 'USPs (sterke punten / waarom kiezen)',
		'veelgestelde_vragen'  => 'Veelgestelde vragen / FAQ (meestal laatste blok, triggert ook JSON-LD)',
		'fotogalerij'          => 'Fotogalerij (optioneel — AI gebruikt zelden, kost extra image-fetches)',
	];

	// ─── Static helpers ────────────────────────────────────────────────────

	public static function get_options(): array {
		$opts = get_option( self::OPTION_NAME, [] );
		return is_array( $opts ) ? $opts : [];
	}

	public static function get_api_key( string $name ): string {
		$const = self::KEY_TO_CONSTANT[ $name ] ?? '';
		if ( '' !== $const && defined( $const ) ) {
			$val = (string) constant( $const );
			if ( '' !== trim( $val ) ) {
				return $val;
			}
		}
		$opts = self::get_options();
		return (string) ( $opts[ $name . '_key' ] ?? '' );
	}

	public static function is_constant_defined( string $name ): bool {
		$const = self::KEY_TO_CONSTANT[ $name ] ?? '';
		return '' !== $const && defined( $const ) && '' !== trim( (string) constant( $const ) );
	}

	/**
	 * GitHub repo URL voor auto-update. Fallback-volgorde:
	 *  1. Constant `DB_AI_GITHUB_REPO_URL` in wp-config (wint altijd)
	 *  2. Settings: `github_repo_url`
	 *  3. Plugin default in DB_AI_Updater::DEFAULT_REPO_URL
	 */
	public static function get_github_repo_url(): string {
		if ( defined( 'DB_AI_GITHUB_REPO_URL' ) ) {
			$val = trim( (string) DB_AI_GITHUB_REPO_URL );
			if ( '' !== $val ) {
				return $val;
			}
		}
		$opts   = self::get_options();
		$stored = trim( (string) ( $opts['github_repo_url'] ?? '' ) );
		if ( '' !== $stored ) {
			return $stored;
		}
		if ( class_exists( 'DB_AI_Updater' ) ) {
			return DB_AI_Updater::DEFAULT_REPO_URL;
		}
		return '';
	}

	public static function is_github_url_constant_defined(): bool {
		return defined( 'DB_AI_GITHUB_REPO_URL' ) && '' !== trim( (string) DB_AI_GITHUB_REPO_URL );
	}

	/**
	 * Welke ACF field group gebruikt de plugin? Fallback-volgorde:
	 *  1. Settings: `acf_field_group_key`
	 *  2. Filter `db_ai_field_group_key`
	 *  3. Default constante `DB_AI_ACF_FIELD_GROUP_KEY` (alleen als die bestaat op deze site)
	 *  4. Eerste auto-detected field group met flex content
	 */
	public static function get_field_group_key(): string {
		$opts   = self::get_options();
		$stored = (string) ( $opts['acf_field_group_key'] ?? '' );
		$key    = (string) apply_filters( 'db_ai_field_group_key', $stored );
		if ( '' !== $key ) {
			return $key;
		}

		if ( defined( 'DB_AI_ACF_FIELD_GROUP_KEY' ) && function_exists( 'acf_get_field_group' ) ) {
			$const = (string) DB_AI_ACF_FIELD_GROUP_KEY;
			if ( '' !== $const && acf_get_field_group( $const ) ) {
				return $const;
			}
		}

		$groups = DB_AI_ACF_Discovery::find_flex_field_groups();
		return $groups[0]['key'] ?? '';
	}

	/**
	 * Welk flex_content veld binnen de gekozen field group? Fallback-volgorde:
	 *  1. Settings: `acf_flex_field_name`
	 *  2. Filter `db_ai_flex_field_name`
	 *  3. Eerste flex-veld binnen de resolved field group
	 */
	public static function get_flex_field_name(): string {
		$opts   = self::get_options();
		$stored = (string) ( $opts['acf_flex_field_name'] ?? '' );
		$name   = (string) apply_filters( 'db_ai_flex_field_name', $stored );
		if ( '' !== $name ) {
			return $name;
		}

		$key = self::get_field_group_key();
		if ( '' === $key ) {
			return '';
		}
		foreach ( DB_AI_ACF_Discovery::find_flex_field_groups() as $group ) {
			if ( $group['key'] === $key && ! empty( $group['flex_fields'] ) ) {
				return $group['flex_fields'][0]['name'];
			}
		}
		return '';
	}

	/**
	 * '' = auto (default), 'anthropic', 'openai'.
	 */
	public static function get_provider(): string {
		if ( defined( 'DB_AI_PROVIDER' ) ) {
			$val = strtolower( trim( (string) DB_AI_PROVIDER ) );
			if ( '' !== $val ) {
				return $val;
			}
		}
		$opts = self::get_options();
		return strtolower( (string) ( $opts['provider'] ?? '' ) );
	}

	public static function is_provider_constant_defined(): bool {
		return defined( 'DB_AI_PROVIDER' ) && '' !== trim( (string) DB_AI_PROVIDER );
	}

	// ─── Instance: menu + Settings API ─────────────────────────────────────

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'db_ai_allowed_layouts', [ $this, 'filter_allowed_layouts' ] );
	}

	/**
	 * Filter callback voor `db_ai_allowed_layouts`. Past de Settings-keuzes toe op
	 * de default whitelist. User-keuzes worden geintersect met de defaults zodat
	 * onbekende layouts nooit binnenkomen.
	 */
	public function filter_allowed_layouts( array $defaults ): array {
		$opts = self::get_options();
		if ( ! isset( $opts['allowed_layouts'] ) || ! is_array( $opts['allowed_layouts'] ) ) {
			return $defaults; // Geen Settings-keuze = standaard (alles aan)
		}
		$picks = array_values( array_filter( array_map( 'strval', $opts['allowed_layouts'] ) ) );
		if ( empty( $picks ) ) {
			return $defaults; // Alle vinkjes uit = veiligheidsfallback naar defaults
		}
		return array_values( array_intersect( $defaults, $picks ) );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'AI Module', 'digitale-bazen-ai-module' ),
			__( 'AI Module', 'digitale-bazen-ai-module' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'db_ai_settings_group',
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);

		// ─── ACF integratie ───────────────────────────────────────────────
		add_settings_section(
			'db_ai_acf_section',
			__( 'ACF integratie', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Kies welke ACF field group + welk flex content veld de plugin gebruikt voor AI-generatie. Wijzig en sla op om de Layout-checkboxes hieronder te verversen.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'acf_field_group_key',
			__( 'ACF field group', 'digitale-bazen-ai-module' ),
			[ $this, 'render_acf_field_group_field' ],
			self::PAGE_SLUG,
			'db_ai_acf_section'
		);

		add_settings_field(
			'acf_flex_field_name',
			__( 'Flex field binnen field group', 'digitale-bazen-ai-module' ),
			[ $this, 'render_acf_flex_field_field' ],
			self::PAGE_SLUG,
			'db_ai_acf_section'
		);

		add_settings_section(
			'db_ai_provider_section',
			__( 'AI provider', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Welke provider gebruikt de plugin om blogs te genereren. "Automatisch" geeft Anthropic voorrang als die key is ingesteld, anders OpenAI.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'provider',
			__( 'Provider', 'digitale-bazen-ai-module' ),
			[ $this, 'render_provider_field' ],
			self::PAGE_SLUG,
			'db_ai_provider_section'
		);

		add_settings_section(
			'db_ai_keys_section',
			__( 'API keys', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Keys worden veilig opgeslagen in de WordPress database en niet teruggetoond. Als een key ook in wp-config.php als constant staat, dan wint die altijd en is het veld hier uitgeschakeld.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$fields = [
			'anthropic' => __( 'Anthropic API key', 'digitale-bazen-ai-module' ),
			'openai'    => __( 'OpenAI API key', 'digitale-bazen-ai-module' ),
			'pexels'    => __( 'Pexels API key', 'digitale-bazen-ai-module' ),
			'unsplash'  => __( 'Unsplash API key', 'digitale-bazen-ai-module' ),
		];
		foreach ( $fields as $name => $label ) {
			add_settings_field(
				$name . '_key',
				$label,
				[ $this, 'render_api_key_field' ],
				self::PAGE_SLUG,
				'db_ai_keys_section',
				[ 'name' => $name ]
			);
		}

		// ─── GitHub auto-update sectie ────────────────────────────────────
		add_settings_section(
			'db_ai_github_section',
			__( 'GitHub auto-update', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Voor het automatisch ophalen van plugin-updates uit de Digitale Bazen GitHub repo. Token = GitHub Personal Access Token met read-access op de repo. Constants in wp-config.php winnen ook hier.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'github_repo_url',
			__( 'GitHub repo URL', 'digitale-bazen-ai-module' ),
			[ $this, 'render_github_repo_url_field' ],
			self::PAGE_SLUG,
			'db_ai_github_section'
		);

		add_settings_field(
			'github_key',
			__( 'GitHub Personal Access Token', 'digitale-bazen-ai-module' ),
			[ $this, 'render_api_key_field' ],
			self::PAGE_SLUG,
			'db_ai_github_section',
			[ 'name' => 'github' ]
		);

		// ─── Tone of voice & content sectie ───────────────────────────────
		add_settings_section(
			'db_ai_style_section',
			__( 'Tone of voice & content', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Optioneel — beschrijf je merkstem, bedrijfscontext en stijlregels. Alles wat je hier invult wordt aan de AI system prompt toegevoegd zodat output past bij jouw merk en doelgroep. Leeg laten = standaard generieke output.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'tone_of_voice',
			__( 'Merkstem (brand voice)', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_style_section',
			[
				'key'         => 'tone_of_voice',
				'rows'        => 4,
				'placeholder' => __( 'Bv: Warm en uitnodigend zonder klef te worden. Spreek aan met "je". Korte zinnen waar het kan. Vermijd zakelijke clichés. Focus op de lezer, niet op "wij".', 'digitale-bazen-ai-module' ),
				'description' => __( 'Beschrijf de stem van jouw merk in 2-5 zinnen. De AI gebruikt dit als toon-instructie.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'site_context',
			__( 'Site-context (bedrijf + WAT NIET TE DOEN)', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_style_section',
			[
				'key'         => 'site_context',
				'rows'        => 6,
				'placeholder' => __( "Bv: Bruidsmode-winkel in Eindhoven (Brabant). We verkopen bruidsjurken in maat 34-60, met focus op plus size en betaalbare modellen. Doelgroep: bruiden 25-40.\n\nWAT NIET DOEN:\n- Noem geen specifieke concurrent-winkels\n- Claim niet dat we de goedkoopste zijn\n- Verkoop geen producten die we niet hebben (geen schoenen, geen accessoires)\n- Beloof geen levertijden", 'digitale-bazen-ai-module' ),
				'description' => __( 'Vertel de AI <strong>wie je bent</strong>, <strong>voor wie</strong> en — heel belangrijk — een lijstje <strong>WAT NIET TE DOEN</strong>. Concrete don\'ts werken veel beter dan algemene "let op"-zinnen.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'style_rules',
			__( 'Stijl- / formatregels', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_style_section',
			[
				'key'         => 'style_rules',
				'rows'        => 5,
				'placeholder' => __( "Bv:\n- Geen em-dashes (—) of en-dashes (–), gebruik komma's of nieuwe zinnen\n- Max 25 woorden per zin\n- Vermijd 'fantastisch', 'geweldig', 'uniek', 'innovatief'\n- Geen \"in deze snel veranderende wereld\" of soortgelijke clichés\n- Korte alinea's: max 4 zinnen", 'digitale-bazen-ai-module' ),
				'description' => __( 'Concrete do\'s en don\'ts voor de output-format. Werk in concrete regels, niet in algemeenheden.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'reference_post_ids',
			__( 'Referentie-posts (schrijfvoorbeelden)', 'digitale-bazen-ai-module' ),
			[ $this, 'render_reference_posts_field' ],
			self::PAGE_SLUG,
			'db_ai_style_section'
		);

		add_settings_field(
			'allowed_layouts',
			__( 'Beschikbare block-layouts', 'digitale-bazen-ai-module' ),
			[ $this, 'render_allowed_layouts_field' ],
			self::PAGE_SLUG,
			'db_ai_style_section'
		);
	}

	public function sanitize( $input ): array {
		$current = self::get_options();
		$out     = $current;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		// Provider — alleen accepteren als nog niet gelocked via constant.
		if ( ! self::is_provider_constant_defined() && isset( $input['provider'] ) ) {
			$allowed = [ '', 'anthropic', 'openai' ];
			$val     = strtolower( trim( (string) $input['provider'] ) );
			if ( in_array( $val, $allowed, true ) ) {
				$out['provider'] = $val;
			}
		}

		// Keys: lege submission = bestaande waarde behouden (we tonen ze niet terug,
		// dus user kan niet "weten" of er iets staat — we updaten alleen bij niet-lege input).
		foreach ( [ 'anthropic', 'openai', 'pexels', 'unsplash', 'github' ] as $name ) {
			$field = $name . '_key';
			if ( self::is_constant_defined( $name ) ) {
				continue; // Constant wint, optie negeren.
			}
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			$val = trim( (string) $input[ $field ] );
			if ( '' === $val ) {
				continue;
			}
			$out[ $field ] = sanitize_text_field( $val );
		}

		// GitHub repo URL — geen secret dus standaard sanitize + esc_url_raw.
		if ( ! self::is_github_url_constant_defined() && array_key_exists( 'github_repo_url', $input ) ) {
			$val = trim( (string) $input['github_repo_url'] );
			$out['github_repo_url'] = ( '' === $val ) ? '' : esc_url_raw( $val );
		}

		// ACF integratie — alleen accepteren als de gekozen group/flex bestaat op deze site.
		if ( array_key_exists( 'acf_field_group_key', $input ) ) {
			$key       = sanitize_text_field( (string) $input['acf_field_group_key'] );
			$out_key   = '';
			$out_flex  = '';
			if ( '' === $key ) {
				$out['acf_field_group_key'] = '';
				$out['acf_flex_field_name'] = '';
			} else {
				foreach ( DB_AI_ACF_Discovery::find_flex_field_groups() as $group ) {
					if ( $group['key'] !== $key ) {
						continue;
					}
					$out_key = $key;
					$flex_in = isset( $input['acf_flex_field_name'] )
						? sanitize_text_field( (string) $input['acf_flex_field_name'] )
						: '';
					foreach ( $group['flex_fields'] as $flex ) {
						if ( $flex['name'] === $flex_in ) {
							$out_flex = $flex_in;
							break;
						}
					}
					if ( '' === $out_flex && ! empty( $group['flex_fields'] ) ) {
						$out_flex = $group['flex_fields'][0]['name'];
					}
					break;
				}
				$out['acf_field_group_key'] = $out_key;
				$out['acf_flex_field_name'] = $out_flex;
			}
		}

		// Tone of voice / context / rules — freeform textareas. Leeg toegestaan.
		foreach ( [ 'tone_of_voice', 'site_context', 'style_rules' ] as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$out[ $field ] = sanitize_textarea_field( (string) $input[ $field ] );
		}

		// Referentie-posts: array van post IDs, max 5, alleen bestaande gepubliceerde posts.
		if ( array_key_exists( 'reference_post_ids', $input ) ) {
			$raw_ids = is_array( $input['reference_post_ids'] ) ? $input['reference_post_ids'] : [];
			$ids     = array_filter( array_map( 'absint', $raw_ids ) );
			$ids     = array_values( array_unique( $ids ) );
			$ids     = array_slice( $ids, 0, DB_AI_Style_Profile::MAX_REFERENCE_POSTS );
			$out['reference_post_ids'] = $ids;
		}

		// Allowed layouts: array van layout-namen, intersect met bekende layouts.
		// LET OP: 'allowed_layouts' KEY moet altijd gezet worden bij elke save,
		// anders kan een gebruiker geen vinkjes wegnemen (HTML form sluit lege
		// checkbox-groep uit van $input). We accepteren dus ook lege array.
		$known = array_keys( self::LAYOUT_LABELS );
		$raw   = isset( $input['allowed_layouts'] ) && is_array( $input['allowed_layouts'] )
			? $input['allowed_layouts']
			: [];
		$out['allowed_layouts'] = array_values( array_intersect( $known, array_map( 'strval', $raw ) ) );

		add_settings_error(
			self::OPTION_NAME,
			'db_ai_settings_saved',
			__( 'Instellingen opgeslagen.', 'digitale-bazen-ai-module' ),
			'success'
		);

		return $out;
	}

	public function render_textarea_field( array $args ): void {
		$key         = (string) ( $args['key'] ?? '' );
		$rows        = (int) ( $args['rows'] ?? 4 );
		$placeholder = (string) ( $args['placeholder'] ?? '' );
		$description = (string) ( $args['description'] ?? '' );

		$opts    = self::get_options();
		$current = (string) ( $opts[ $key ] ?? '' );

		printf(
			'<textarea name="%s[%s]" rows="%d" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $key ),
			$rows,
			esc_attr( $placeholder ),
			esc_textarea( $current )
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
		}
	}

	public function render_allowed_layouts_field(): void {
		$opts    = self::get_options();
		$group   = self::get_field_group_key();
		$flex    = self::get_flex_field_name();
		$layouts = DB_AI_ACF_Discovery::get_layouts_for( $group, $flex );

		// Fallback: hardcoded labels uit V1 voor backwards-compat als auto-detect niets vindt
		if ( empty( $layouts ) ) {
			foreach ( self::LAYOUT_LABELS as $name => $label ) {
				$layouts[] = [ 'name' => $name, 'label' => $label ];
			}
		}

		if ( empty( $layouts ) ) {
			echo '<p class="description"><em>' . esc_html__( 'Geen layouts gevonden — kies eerst een ACF field group + flex field hierboven en sla op.', 'digitale-bazen-ai-module' ) . '</em></p>';
			return;
		}

		$all_names = array_map( static fn( $l ) => (string) ( $l['name'] ?? '' ), $layouts );
		$current   = $opts['allowed_layouts'] ?? null;
		if ( ! is_array( $current ) ) {
			$current = $all_names; // eerste keer → alles aan
		}

		echo '<fieldset class="db-ai-layouts-fieldset">';
		foreach ( $layouts as $layout ) {
			$name  = (string) ( $layout['name'] ?? '' );
			$label = (string) ( $layout['label'] ?? $name );
			if ( '' === $name ) {
				continue;
			}
			$checked = in_array( $name, $current, true );
			printf(
				'<label style="display:block;margin:4px 0;"><input type="checkbox" name="%s[allowed_layouts][]" value="%s"%s> <strong>%s</strong> <code>%s</code></label>',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $name ),
				$checked ? ' checked' : '',
				esc_html( $label ),
				esc_html( $name )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">';
		esc_html_e( 'Bepaalt welke ACF-flex layouts de AI mag gebruiken op deze site. Auto-gedetecteerd vanuit de gekozen field group + flex field. Standaard alles aan. De AI kiest zelf welke + hoeveel per blog op basis van het onderwerp. Alles uitvinken = fallback naar alle layouts.', 'digitale-bazen-ai-module' );
		echo '</p>';
	}

	public function render_acf_field_group_field(): void {
		$current = self::get_field_group_key();
		$groups  = DB_AI_ACF_Discovery::find_flex_field_groups();

		if ( empty( $groups ) ) {
			echo '<p class="description"><strong>' . esc_html__( 'Geen ACF field groups met flex content gevonden op deze site.', 'digitale-bazen-ai-module' ) . '</strong></p>';
			return;
		}

		echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[acf_field_group_key]" id="db-ai-acf-field-group-key">';
		printf(
			'<option value=""%s>%s</option>',
			'' === $current ? ' selected' : '',
			esc_html__( '— Auto-detect (eerste beschikbare)', 'digitale-bazen-ai-module' )
		);
		foreach ( $groups as $group ) {
			printf(
				'<option value="%s"%s>%s &nbsp;<code>%s</code></option>',
				esc_attr( $group['key'] ),
				$group['key'] === $current ? ' selected' : '',
				esc_html( $group['title'] ),
				esc_html( $group['key'] )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'ACF field group die het flex content veld bevat voor blog-generatie.', 'digitale-bazen-ai-module' ) . '</p>';
	}

	public function render_acf_flex_field_field(): void {
		$current_group = self::get_field_group_key();
		$current_flex  = self::get_flex_field_name();
		$groups        = DB_AI_ACF_Discovery::find_flex_field_groups();

		// Verzamel flex velden van de huidig-geselecteerde group
		$flex_fields = [];
		foreach ( $groups as $group ) {
			if ( $group['key'] === $current_group ) {
				$flex_fields = $group['flex_fields'];
				break;
			}
		}

		if ( empty( $flex_fields ) ) {
			echo '<p class="description"><em>' . esc_html__( 'Kies eerst een field group hierboven en sla op.', 'digitale-bazen-ai-module' ) . '</em></p>';
			return;
		}

		echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[acf_flex_field_name]" id="db-ai-acf-flex-field-name">';
		foreach ( $flex_fields as $flex ) {
			$count = count( $flex['layouts'] );
			printf(
				'<option value="%s"%s>%s &nbsp;<code>%s</code> &nbsp;(%d layouts)</option>',
				esc_attr( $flex['name'] ),
				$flex['name'] === $current_flex ? ' selected' : '',
				esc_html( $flex['label'] ),
				esc_html( $flex['name'] ),
				$count
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Het specifieke flex content veld binnen de gekozen field group. Meestal is er maar één — alleen relevant als de field group meerdere heeft.', 'digitale-bazen-ai-module' ) . '</p>';
	}

	public function render_github_repo_url_field(): void {
		$locked  = self::is_github_url_constant_defined();
		$opts    = self::get_options();
		$current = self::get_github_repo_url();
		$stored  = (string) ( $opts['github_repo_url'] ?? '' );

		if ( $locked ) {
			printf(
				'<input type="text" class="regular-text code" value="%s" disabled>',
				esc_attr( $current )
			);
			echo '<p class="description">'
				. esc_html__( 'Ingesteld via', 'digitale-bazen-ai-module' )
				. ' <code>DB_AI_GITHUB_REPO_URL</code> '
				. esc_html__( 'in wp-config.php — verwijder de constant om hier te kunnen wijzigen.', 'digitale-bazen-ai-module' )
				. '</p>';
			return;
		}

		printf(
			'<input type="text" class="regular-text code" name="%s[github_repo_url]" value="%s" placeholder="%s">',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $stored ),
			esc_attr( $current )
		);
		echo '<p class="description">';
		esc_html_e( 'Leeg laten = gebruik de plugin-default (Digitale Bazen GitHub). Override alleen als je een eigen fork hebt.', 'digitale-bazen-ai-module' );
		echo '</p>';
	}

	public function render_reference_posts_field(): void {
		$opts    = self::get_options();
		$current = $opts['reference_post_ids'] ?? [];
		if ( ! is_array( $current ) ) {
			$current = [];
		}
		$current = array_map( 'absint', $current );

		$recent = get_posts(
			[
				'post_type'      => apply_filters( 'db_ai_reference_post_types', [ 'page', 'post', 'blog' ] ),
				'post_status'    => 'publish',
				'numberposts'    => 80,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'suppress_filters' => false,
			]
		);

		echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[reference_post_ids][]" multiple size="10" class="db-ai-reference-select" style="width:100%;max-width:560px;">';
		foreach ( $recent as $post ) {
			printf(
				'<option value="%d"%s>[%s] %s</option>',
				(int) $post->ID,
				in_array( (int) $post->ID, $current, true ) ? ' selected' : '',
				esc_html( $post->post_type ),
				esc_html( $post->post_title )
			);
		}
		echo '</select>';
		echo '<p class="description">';
		printf(
			/* translators: %d = max aantal */
			esc_html__( 'Cmd/Ctrl + klik om meerdere te selecteren. Max %d posts worden gebruikt als schrijfvoorbeelden in de AI-prompt. Toont laatste 80 gepubliceerde pages, posts en blogs.', 'digitale-bazen-ai-module' ),
			DB_AI_Style_Profile::MAX_REFERENCE_POSTS
		);
		echo '</p>';
	}

	public function render_provider_field(): void {
		$locked  = self::is_provider_constant_defined();
		$current = self::get_provider();
		$options = [
			''          => __( 'Automatisch (Anthropic > OpenAI op basis van aanwezige keys)', 'digitale-bazen-ai-module' ),
			'anthropic' => __( 'Anthropic Claude', 'digitale-bazen-ai-module' ),
			'openai'    => __( 'OpenAI', 'digitale-bazen-ai-module' ),
		];

		if ( $locked ) {
			printf(
				'<input type="text" class="regular-text" value="%s" disabled>',
				esc_attr( $options[ $current ] ?? $current )
			);
			echo '<p class="description">'
				. esc_html__( 'Ingesteld via', 'digitale-bazen-ai-module' )
				. ' <code>DB_AI_PROVIDER</code> '
				. esc_html__( 'in wp-config.php — verwijder de constant om hier te kunnen kiezen.', 'digitale-bazen-ai-module' )
				. '</p>';
			return;
		}

		echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[provider]">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function render_api_key_field( array $args ): void {
		$name           = (string) ( $args['name'] ?? '' );
		$constant       = self::KEY_TO_CONSTANT[ $name ] ?? '';
		$locked         = self::is_constant_defined( $name );
		$current        = self::get_api_key( $name );
		$masked         = '' !== $current && strlen( $current ) > 6
			? str_repeat( '•', 8 ) . substr( $current, -4 )
			: '';
		$field_name     = self::OPTION_NAME . '[' . $name . '_key]';
		$is_set         = '' !== $current;

		if ( $locked ) {
			printf(
				'<input type="text" class="regular-text" value="%s" disabled>',
				esc_attr( $masked )
			);
			echo '<p class="description">'
				. esc_html__( 'Ingesteld via', 'digitale-bazen-ai-module' )
				. ' <code>' . esc_html( $constant ) . '</code> '
				. esc_html__( 'in wp-config.php — verwijder de constant om hier te kunnen wijzigen.', 'digitale-bazen-ai-module' )
				. '</p>';
			return;
		}

		$placeholder = $is_set ? $masked : __( 'Nog niet ingesteld', 'digitale-bazen-ai-module' );

		printf(
			'<input type="password" name="%s" class="regular-text" value="" autocomplete="new-password" placeholder="%s">',
			esc_attr( $field_name ),
			esc_attr( $placeholder )
		);
		echo '<p class="description">';
		if ( $is_set ) {
			esc_html_e( 'Laat leeg om de bestaande key te behouden. Vul iets in om te vervangen.', 'digitale-bazen-ai-module' );
		} else {
			esc_html_e( 'Plak hier de API-key.', 'digitale-bazen-ai-module' );
		}
		echo '</p>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'digitale-bazen-ai-module' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Digitale Bazen AI Module — Instellingen', 'digitale-bazen-ai-module' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=blog&page=db-ai-generator' ) ); ?>">
					<?php esc_html_e( '← Terug naar de generator', 'digitale-bazen-ai-module' ); ?>
				</a>
			</p>
			<?php settings_errors( self::OPTION_NAME ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'db_ai_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
