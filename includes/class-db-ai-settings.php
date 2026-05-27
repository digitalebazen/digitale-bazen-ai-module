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

	public static function is_external_links_enabled(): bool {
		$opts = self::get_options();
		// Default ON: niet aanwezig in opties = aan. Pas op false als expliciet '0' is opgeslagen.
		if ( ! array_key_exists( 'external_links_enabled', $opts ) ) {
			return true;
		}
		return ! empty( $opts['external_links_enabled'] );
	}

	public static function get_external_links_max(): int {
		$opts = self::get_options();
		$val  = (int) ( $opts['external_links_max'] ?? 4 );
		if ( $val < 2 ) {
			return 2;
		}
		if ( $val > 5 ) {
			return 5;
		}
		return $val;
	}

	// ─── Instance: menu + Settings API ─────────────────────────────────────

	private $page_hook = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_filter( 'db_ai_allowed_layouts', [ $this, 'filter_allowed_layouts' ] );
	}

	public function maybe_enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'db-ai-settings',
			DB_AI_PLUGIN_URL . 'assets/settings.css',
			[],
			DB_AI_VERSION
		);

		// SheetJS Community Edition voor client-side xlsx/xls/ods → csv conversie.
		wp_enqueue_script(
			'db-ai-xlsx',
			DB_AI_PLUGIN_URL . 'assets/vendor/xlsx.full.min.js',
			[],
			'0.20.3',
			true
		);

		wp_enqueue_script(
			'db-ai-settings',
			DB_AI_PLUGIN_URL . 'assets/settings.js',
			[ 'db-ai-xlsx' ],
			DB_AI_VERSION,
			true
		);

		wp_localize_script(
			'db-ai-settings',
			'dbAiSettings',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( DB_AI_Ajax::NONCE_ACTION ),
				'i18n'    => [
					'missingName'      => __( 'Geef het onderzoek een naam.', 'digitale-bazen-ai-module' ),
					'missingFile'      => __( 'Selecteer een bestand.', 'digitale-bazen-ai-module' ),
					'uploading'        => __( 'Bezig met uploaden…', 'digitale-bazen-ai-module' ),
					'parsing'          => __( 'Bestand lezen…', 'digitale-bazen-ai-module' ),
					/* translators: %d = aantal zoekwoorden */
					'uploadOk'         => __( 'Opgeslagen — %d zoekwoorden.', 'digitale-bazen-ai-module' ),
					'uploadFailed'     => __( 'Upload mislukt.', 'digitale-bazen-ai-module' ),
					'parseFailed'      => __( 'Bestand kon niet gelezen worden.', 'digitale-bazen-ai-module' ),
					'networkError'     => __( 'Netwerkfout.', 'digitale-bazen-ai-module' ),
					'confirmDelete'    => __( 'Dit onderzoek verwijderen?', 'digitale-bazen-ai-module' ),
					'deleted'          => __( 'Verwijderd.', 'digitale-bazen-ai-module' ),
					'deleteFailed'     => __( 'Verwijderen mislukt.', 'digitale-bazen-ai-module' ),
					'noKwoYet'         => __( 'Nog geen onderzoeken opgeslagen.', 'digitale-bazen-ai-module' ),
					'tableNameLabel'   => __( 'Naam', 'digitale-bazen-ai-module' ),
					'tableCountLabel'  => __( 'Zoekwoorden', 'digitale-bazen-ai-module' ),
					'tableDateLabel'   => __( 'Geüpload op', 'digitale-bazen-ai-module' ),
					'tableDeleteLabel' => __( 'Verwijder', 'digitale-bazen-ai-module' ),
				],
			]
		);
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
		$this->page_hook = (string) add_options_page(
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

		// ─── Block-layouts sectie (los van style section voor wizard-stap 4) ───
		add_settings_section(
			'db_ai_layouts_section',
			__( 'Block-layouts', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Welke ACF flex-layouts mag de AI gebruiken in gegenereerde blogs.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'allowed_layouts',
			__( 'Beschikbare block-layouts', 'digitale-bazen-ai-module' ),
			[ $this, 'render_allowed_layouts_field' ],
			self::PAGE_SLUG,
			'db_ai_layouts_section'
		);

		// ─── Bedrijfsinformatie sectie ────────────────────────────────────
		add_settings_section(
			'db_ai_company_section',
			__( 'Bedrijfsinformatie', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Wat is jouw bedrijf, branche en wat maakt je uniek. Wordt aan de AI system prompt toegevoegd zodat output past bij jouw business.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'company_name',
			__( 'Bedrijfsnaam', 'digitale-bazen-ai-module' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'db_ai_company_section',
			[ 'key' => 'company_name', 'placeholder' => __( 'Bv: Digitale Bazen', 'digitale-bazen-ai-module' ) ]
		);

		add_settings_field(
			'company_industry',
			__( 'Branche / sector', 'digitale-bazen-ai-module' ),
			[ $this, 'render_text_field' ],
			self::PAGE_SLUG,
			'db_ai_company_section',
			[ 'key' => 'company_industry', 'placeholder' => __( 'Bv: online marketing voor MKB, bruidsmode, restaurantcatering', 'digitale-bazen-ai-module' ) ]
		);

		add_settings_field(
			'company_services',
			__( 'Diensten / producten', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_company_section',
			[
				'key'         => 'company_services',
				'rows'        => 3,
				'placeholder' => __( "Wat verkoop / lever je. Bv:\n- Website-bouw (WordPress)\n- SEO + content\n- Online marketing campagnes", 'digitale-bazen-ai-module' ),
				'description' => __( 'Wat je daadwerkelijk levert. Voorkomt dat AI dingen belooft die je niet doet.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'company_usps',
			__( "USP's — wat maakt je uniek", 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_company_section',
			[
				'key'         => 'company_usps',
				'rows'        => 3,
				'placeholder' => __( "Bv:\n- Vaste contactpersoon, geen tickets\n- 10+ jaar ervaring in jouw branche\n- Prijs vooraf bekend, geen verrassingen", 'digitale-bazen-ai-module' ),
				'description' => __( 'Concrete, controleerbare onderscheidende punten. Geen "marktleider" of "innovatief" — die werken niet.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'company_competitors',
			__( 'Concurrenten (intern, niet noemen in blogs)', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_company_section',
			[
				'key'         => 'company_competitors',
				'rows'        => 2,
				'placeholder' => __( 'Bv: bureau X, platform Y, freelancer-collectief Z', 'digitale-bazen-ai-module' ),
				'description' => __( 'AI gebruikt deze om jouw USPs slimmer te positioneren, maar noemt ze NOOIT bij naam in de tekst.', 'digitale-bazen-ai-module' ),
			]
		);

		// ─── Doelgroep sectie ─────────────────────────────────────────────
		add_settings_section(
			'db_ai_audience_section',
			__( 'Doelgroep', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Beschrijf één hoofddoelgroep diep. Bezwaren, frustraties en aankoopcriteria zijn de sterkste hefbomen voor content die echt aansluit.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'audience_who',
			__( 'Voor wie schrijf je?', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_audience_section',
			[
				'key'         => 'audience_who',
				'rows'        => 3,
				'placeholder' => __( "Bv: MKB-ondernemers tussen 35-55, vaak technisch ongeschoold maar wel digitaal-vaardig. Hebben 5-50 werknemers, runnen meestal een diensten- of webshop-business.", 'digitale-bazen-ai-module' ),
				'description' => __( 'Demografie + situatie. Concreter is beter.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'audience_objections',
			__( 'Bezwaren die weggenomen moeten worden', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_audience_section',
			[
				'key'         => 'audience_objections',
				'rows'        => 4,
				'placeholder' => __( "Wat zegt je doelgroep tegen zichzelf om NIET te kopen. Bv:\n- \"Dat kan ik zelf wel\"\n- \"Te duur voor mijn bedrijf\"\n- \"Bureaus beloven veel maar leveren weinig\"\n- \"Ik heb eerder slechte ervaringen gehad\"", 'digitale-bazen-ai-module' ),
				'description' => __( 'Sterkste hefboom voor content die converteert. AI weeft tegenargumenten subtiel in.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'audience_frustrations',
			__( 'Frustraties / pijnpunten', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_audience_section',
			[
				'key'         => 'audience_frustrations',
				'rows'        => 3,
				'placeholder' => __( "Wat irriteert / frustreert ze dagelijks. Bv:\n- Geen tijd om zelf marketing te doen\n- Bureaus die alleen rapportages sturen, geen resultaat\n- Concurrenten die hoger scoren op Google", 'digitale-bazen-ai-module' ),
				'description' => __( 'Geeft AI input om aan de juiste pijn te raken vroeg in de tekst.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'audience_buying_criteria',
			__( 'Wat de doelgroep belangrijk vindt bij beslissen', 'digitale-bazen-ai-module' ),
			[ $this, 'render_textarea_field' ],
			self::PAGE_SLUG,
			'db_ai_audience_section',
			[
				'key'         => 'audience_buying_criteria',
				'rows'        => 3,
				'placeholder' => __( "Bv:\n- Transparante prijzen\n- Aantoonbare cases uit hun branche\n- Persoonlijk contact, geen ticket-systeem\n- Vaste contactpersoon", 'digitale-bazen-ai-module' ),
				'description' => __( 'Wat hun beslissing dichtbij brengt. AI verwerkt deze in CTAs en argumentatie.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'audience_language_level',
			__( 'Taalniveau', 'digitale-bazen-ai-module' ),
			[ $this, 'render_select_field' ],
			self::PAGE_SLUG,
			'db_ai_audience_section',
			[
				'key'     => 'audience_language_level',
				'options' => [
					''       => __( 'Standaard (informeel-zakelijk)', 'digitale-bazen-ai-module' ),
					'b1'     => __( 'B1 — eenvoudig, geen jargon', 'digitale-bazen-ai-module' ),
					'expert' => __( 'Expert — vakjargon mag', 'digitale-bazen-ai-module' ),
				],
			]
		);

		// ─── Anti-generiek sectie ─────────────────────────────────────────
		add_settings_section(
			'db_ai_antigeneric_section',
			__( 'Anti-generieke content', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Standaard AI-output voelt vaak vlak en safe. Vink hieronder aan wat je expliciet wél wilt — dat maakt het verschil tussen "duidelijk AI" en authentiek.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'anti_opinion',
			__( 'AI mag een mening / standpunt geven', 'digitale-bazen-ai-module' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE_SLUG,
			'db_ai_antigeneric_section',
			[
				'key'   => 'anti_opinion',
				'label' => __( 'Geef expliciete meningen / standpunten in de tekst', 'digitale-bazen-ai-module' ),
				'help'  => __( 'Vermijdt "objectieve" gepolijste teksten die niemand interesseren. Aanrader voor opinion blogs en deep-dive content.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'anti_examples',
			__( 'Werk met concrete praktijkvoorbeelden', 'digitale-bazen-ai-module' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE_SLUG,
			'db_ai_antigeneric_section',
			[
				'key'   => 'anti_examples',
				'label' => __( 'Voeg realistische scenario\'s en voorbeelden toe', 'digitale-bazen-ai-module' ),
				'help'  => __( 'Vervangt theoretische algemeenheden door concrete cases. AI verzint geen statistieken, alleen plausibele scenarios.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'anti_downsides',
			__( 'Benoem ook nadelen / beperkingen', 'digitale-bazen-ai-module' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE_SLUG,
			'db_ai_antigeneric_section',
			[
				'key'   => 'anti_downsides',
				'label' => __( 'Vermeld ook "wanneer dit NIET past" of nadelen', 'digitale-bazen-ai-module' ),
				'help'  => __( 'Bouwt vertrouwen — een tekst die alleen voordelen noemt voelt als sales-talk.', 'digitale-bazen-ai-module' ),
			]
		);

		// ─── Interne links sectie ─────────────────────────────────────────
		add_settings_section(
			'db_ai_internal_links_section',
			__( 'Interne links', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Laat de AI automatisch 2-5 interne links toevoegen naar relevante pagina\'s op je site. Top-15 meest relevante pagina\'s worden in de prompt aangeboden (op basis van keyword-overlap). Niet-bestaande URLs worden na generatie automatisch opgeruimd.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'internal_links_enabled',
			__( 'Interne links aanzetten', 'digitale-bazen-ai-module' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE_SLUG,
			'db_ai_internal_links_section',
			[
				'key'   => 'internal_links_enabled',
				'label' => __( 'AI mag interne links toevoegen aan gegenereerde blogs', 'digitale-bazen-ai-module' ),
				'help'  => __( 'Bij uit: geen interne links in output. Bij aan: 2-5 links naar relevante bestaande pagina\'s.', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'internal_links_max',
			__( 'Max aantal links per blog', 'digitale-bazen-ai-module' ),
			[ $this, 'render_select_field' ],
			self::PAGE_SLUG,
			'db_ai_internal_links_section',
			[
				'key'     => 'internal_links_max',
				'options' => [
					'2' => __( '2 links', 'digitale-bazen-ai-module' ),
					'3' => __( '3 links (aanbevolen)', 'digitale-bazen-ai-module' ),
					'4' => __( '4 links', 'digitale-bazen-ai-module' ),
					'5' => __( '5 links', 'digitale-bazen-ai-module' ),
				],
			]
		);

		add_settings_field(
			'internal_links_post_types',
			__( 'Post types als linkbron', 'digitale-bazen-ai-module' ),
			[ $this, 'render_internal_link_post_types_field' ],
			self::PAGE_SLUG,
			'db_ai_internal_links_section'
		);

		// ─── Externe bronnen sectie ───────────────────────────────────────
		add_settings_section(
			'db_ai_external_links_section',
			__( 'Externe bronnen', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'AI stelt na elke generatie 3-5 externe link-suggesties voor naar autoritaire bronnen (Wikipedia, overheid, brancheorganisaties). Suggesties verschijnen in een metabox op de post-edit-screen — geen automatische invoeging. Redacteur ziet eerst groen/oranje per URL (HEAD-check) en kiest welke ingevoegd worden.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'external_links_enabled',
			__( 'Externe link-suggesties', 'digitale-bazen-ai-module' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE_SLUG,
			'db_ai_external_links_section',
			[
				'key'   => 'external_links_enabled',
				'label' => __( 'Genereer externe link-suggesties bij elke blog', 'digitale-bazen-ai-module' ),
				'help'  => __( 'Bij uit: geen suggesties, geen tokens-overhead, geen metabox. Bij aan: ~200 extra output-tokens per blog (~$0.005).', 'digitale-bazen-ai-module' ),
			]
		);

		add_settings_field(
			'external_links_max',
			__( 'Max aantal suggesties per blog', 'digitale-bazen-ai-module' ),
			[ $this, 'render_select_field' ],
			self::PAGE_SLUG,
			'db_ai_external_links_section',
			[
				'key'     => 'external_links_max',
				'options' => [
					'2' => __( '2 suggesties', 'digitale-bazen-ai-module' ),
					'3' => __( '3 suggesties', 'digitale-bazen-ai-module' ),
					'4' => __( '4 suggesties (aanbevolen)', 'digitale-bazen-ai-module' ),
					'5' => __( '5 suggesties', 'digitale-bazen-ai-module' ),
				],
			]
		);

		// ─── Zoekwoordenonderzoek sectie ──────────────────────────────────
		add_settings_section(
			'db_ai_kwo_section',
			__( 'Zoekwoordenonderzoeken', 'digitale-bazen-ai-module' ),
			function () {
				echo '<p>' . esc_html__( 'Upload zoekwoordenonderzoeken één keer hier en kies ze in de generator. Geen Settings-save nodig — uploads en verwijderen werken direct.', 'digitale-bazen-ai-module' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'kwo_manager',
			__( 'Opgeslagen onderzoeken', 'digitale-bazen-ai-module' ),
			[ $this, 'render_kwo_manager_field' ],
			self::PAGE_SLUG,
			'db_ai_kwo_section'
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
		foreach ( [ 'anthropic', 'openai', 'pexels', 'unsplash' ] as $name ) {
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

		// Tone of voice / context / rules + bedrijfs- en doelgroep-velden — freeform textareas/text.
		$textarea_fields = [
			'tone_of_voice', 'site_context', 'style_rules',
			'company_services', 'company_usps', 'company_competitors',
			'audience_who', 'audience_objections', 'audience_frustrations', 'audience_buying_criteria',
		];
		foreach ( $textarea_fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$out[ $field ] = sanitize_textarea_field( (string) $input[ $field ] );
		}

		// Single-line text velden (bedrijfsnaam, branche).
		foreach ( [ 'company_name', 'company_industry' ] as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$out[ $field ] = sanitize_text_field( (string) $input[ $field ] );
		}

		// Taalniveau select — alleen toegestane waarden.
		if ( array_key_exists( 'audience_language_level', $input ) ) {
			$val = strtolower( trim( (string) $input['audience_language_level'] ) );
			$out['audience_language_level'] = in_array( $val, [ '', 'b1', 'expert' ], true ) ? $val : '';
		}

		// Anti-generiek + interne links toggles — unchecked checkboxes komen niet in
		// $input. Form bevat alle tabs in één submit, dus we resetten ze altijd
		// unconditioneel.
		foreach ( [ 'anti_opinion', 'anti_examples', 'anti_downsides', 'internal_links_enabled', 'external_links_enabled' ] as $bool_key ) {
			$out[ $bool_key ] = ! empty( $input[ $bool_key ] ) ? 1 : 0;
		}

		// Internal links max — select, alleen 2..5
		if ( array_key_exists( 'internal_links_max', $input ) ) {
			$val = (int) $input['internal_links_max'];
			$out['internal_links_max'] = ( $val >= 2 && $val <= 5 ) ? $val : 3;
		}

		// External links max — select, alleen 2..5
		if ( array_key_exists( 'external_links_max', $input ) ) {
			$val = (int) $input['external_links_max'];
			$out['external_links_max'] = ( $val >= 2 && $val <= 5 ) ? $val : 4;
		}

		// Internal links post types — array van post type names, intersect met public ones
		$valid_pts = array_keys( get_post_types( [ 'public' => true ], 'names' ) );
		$raw_pts   = isset( $input['internal_links_post_types'] ) && is_array( $input['internal_links_post_types'] )
			? $input['internal_links_post_types']
			: [];
		$out['internal_links_post_types'] = array_values(
			array_intersect( $valid_pts, array_map( 'sanitize_key', $raw_pts ) )
		);

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

	public function render_text_field( array $args ): void {
		$key         = (string) ( $args['key'] ?? '' );
		$placeholder = (string) ( $args['placeholder'] ?? '' );
		$description = (string) ( $args['description'] ?? '' );

		$opts    = self::get_options();
		$current = (string) ( $opts[ $key ] ?? '' );

		printf(
			'<input type="text" name="%s[%s]" value="%s" class="regular-text" placeholder="%s">',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $key ),
			esc_attr( $current ),
			esc_attr( $placeholder )
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
		}
	}

	public function render_select_field( array $args ): void {
		$key         = (string) ( $args['key'] ?? '' );
		$options     = (array) ( $args['options'] ?? [] );
		$description = (string) ( $args['description'] ?? '' );

		$opts    = self::get_options();
		$current = (string) ( $opts[ $key ] ?? '' );

		echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[' . esc_attr( $key ) . ']">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		if ( '' !== $description ) {
			echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
		}
	}

	public function render_checkbox_field( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$label = (string) ( $args['label'] ?? '' );
		$help  = (string) ( $args['help'] ?? '' );

		$opts    = self::get_options();
		$checked = ! empty( $opts[ $key ] );

		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s> %s</label>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
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

	public function render_internal_link_post_types_field(): void {
		$opts    = self::get_options();
		$current = $opts['internal_links_post_types'] ?? [ 'page', 'blog' ];
		if ( ! is_array( $current ) ) {
			$current = [ 'page', 'blog' ];
		}

		$available = get_post_types( [ 'public' => true ], 'objects' );
		// Sluit attachment etc. uit — alleen post-types die content kunnen zijn
		unset( $available['attachment'] );

		echo '<fieldset>';
		foreach ( $available as $pt ) {
			$checked = in_array( $pt->name, $current, true );
			printf(
				'<label style="display:block;margin:4px 0;"><input type="checkbox" name="%s[internal_links_post_types][]" value="%s"%s> %s <code>%s</code></label>',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $pt->name ),
				$checked ? ' checked' : '',
				esc_html( $pt->labels->name ?? $pt->name ),
				esc_html( $pt->name )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Vink aan welke post types als bron voor interne links mogen dienen. Standaard alleen pages en blogs (de meest waardevolle landingsmateriaal).', 'digitale-bazen-ai-module' ) . '</p>';
	}

	public function render_kwo_manager_field(): void {
		$all = DB_AI_Keyword_Research::get_all();
		?>
		<div class="db-ai-kwo-uploader" id="db-ai-kwo-uploader">
			<div class="db-ai-kwo-upload-form">
				<p>
					<label for="db-ai-kwo-name"><?php esc_html_e( 'Naam onderzoek', 'digitale-bazen-ai-module' ); ?></label>
					<input
						type="text"
						id="db-ai-kwo-name"
						class="regular-text"
						placeholder="<?php echo esc_attr__( 'Bv: KWO 2026 Q1 — Digitale Bazen', 'digitale-bazen-ai-module' ); ?>"
					>
				</p>
				<p>
					<label for="db-ai-kwo-file"><?php esc_html_e( 'Bestand', 'digitale-bazen-ai-module' ); ?></label>
					<input
						type="file"
						id="db-ai-kwo-file"
						accept=".csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.oasis.opendocument.spreadsheet"
					>
					<button type="button" id="db-ai-kwo-upload-btn" class="button button-primary">
						<?php esc_html_e( 'Upload + opslaan', 'digitale-bazen-ai-module' ); ?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Accepteert .xlsx, .xls, .csv en .ods. Headers worden automatisch herkend (Zoekwoord verplicht; Maandelijks volume / Pagina / Onderwerp / Concurrentie / CPC optioneel).', 'digitale-bazen-ai-module' ); ?>
				</p>
				<div id="db-ai-kwo-status" class="db-ai-status" role="status" aria-live="polite"></div>
			</div>

			<h3><?php esc_html_e( 'Opgeslagen onderzoeken', 'digitale-bazen-ai-module' ); ?></h3>
			<table class="widefat striped db-ai-kwo-table" id="db-ai-kwo-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Naam', 'digitale-bazen-ai-module' ); ?></th>
						<th><?php esc_html_e( 'Zoekwoorden', 'digitale-bazen-ai-module' ); ?></th>
						<th><?php esc_html_e( 'Geüpload op', 'digitale-bazen-ai-module' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $all ) ) : ?>
						<tr class="db-ai-kwo-empty">
							<td colspan="4"><em><?php esc_html_e( 'Nog geen onderzoeken opgeslagen.', 'digitale-bazen-ai-module' ); ?></em></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $all as $r ) : ?>
							<tr data-kwo-id="<?php echo (int) $r['id']; ?>">
								<td><?php echo esc_html( $r['name'] ); ?></td>
								<td><?php echo (int) $r['count']; ?></td>
								<td><?php echo esc_html( $r['uploaded_at'] ); ?></td>
								<td>
									<button type="button" class="button-link-delete db-ai-kwo-delete-btn" data-kwo-id="<?php echo (int) $r['id']; ?>">
										<?php esc_html_e( 'Verwijder', 'digitale-bazen-ai-module' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
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

	/**
	 * Definitie van de tab-secties. Elke tab koppelt aan één of meer
	 * Settings API sections die in die tab gerenderd worden.
	 */
	private function get_tabs(): array {
		return [
			[
				'id'       => 'company',
				'label'    => __( 'Bedrijf', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Bedrijfsinformatie', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Wat is jouw bedrijf en wat maakt je uniek. AI gebruikt dit om context te krijgen voor elke gegenereerde blog.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_company_section' ],
			],
			[
				'id'       => 'audience',
				'label'    => __( 'Doelgroep', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Doelgroep', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Beschrijf je hoofddoelgroep diep — bezwaren en frustraties zijn de sterkste hefbomen voor content die echt aansluit.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_audience_section' ],
			],
			[
				'id'       => 'style',
				'label'    => __( 'Tone of voice', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Tone of voice & content', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Beschrijf je merkstem, stijlregels en referentie-posts. Alles optioneel — leeg laten = generieke output.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_style_section' ],
			],
			[
				'id'       => 'antigeneric',
				'label'    => __( 'Anti-generiek', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Anti-generieke content', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Vink aan wat je expliciet wél wilt — dat is het verschil tussen "duidelijk AI" en authentieke content.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_antigeneric_section' ],
			],
			[
				'id'       => 'links',
				'label'    => __( 'Interne links', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Interne links', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'AI plaatst automatisch interne links naar relevante pagina\'s op je site bij elke blog-generatie. Goed voor SEO en doorklik-gedrag.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_internal_links_section' ],
			],
			[
				'id'       => 'externallinks',
				'label'    => __( 'Externe bronnen', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Externe bronnen', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'AI suggereert externe link-bronnen (Wikipedia, overheid, brancheorganisaties) bij elke blog. Redacteur kiest welke ingevoegd worden via een metabox op de post-edit-screen.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_external_links_section' ],
			],
			[
				'id'       => 'kwo',
				'label'    => __( 'Zoekwoorden', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Zoekwoordenonderzoeken', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Upload één of meer onderzoeken; in de generator kies je welke je wilt gebruiken zonder opnieuw te uploaden.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_kwo_section' ],
			],
			[
				'id'       => 'layouts',
				'label'    => __( 'Block-layouts', 'digitale-bazen-ai-module' ),
				'title'    => __( 'Beschikbare block-layouts', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Welke ACF flex-layouts mag de AI gebruiken in gegenereerde blogs. Standaard staat alles aan.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_layouts_section' ],
			],
			[
				'id'       => 'acf',
				'label'    => __( 'ACF integratie', 'digitale-bazen-ai-module' ),
				'title'    => __( 'ACF integratie', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Kies welke ACF field group + welk flex content veld de plugin gebruikt voor AI-generatie.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_acf_section' ],
			],
			[
				'id'       => 'ai',
				'label'    => __( 'AI setup', 'digitale-bazen-ai-module' ),
				'title'    => __( 'AI provider + API keys', 'digitale-bazen-ai-module' ),
				'intro'    => __( 'Welke AI provider gebruikt de plugin en welke keys horen daarbij. Constants in wp-config.php winnen altijd van waarden hier.', 'digitale-bazen-ai-module' ),
				'sections' => [ 'db_ai_provider_section', 'db_ai_keys_section' ],
			],
		];
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'digitale-bazen-ai-module' ) );
		}

		$tabs = $this->get_tabs();
		?>
		<div class="wrap db-ai-tabs">
			<h1>
				<?php esc_html_e( 'Digitale Bazen AI Module — Instellingen', 'digitale-bazen-ai-module' ); ?>
				<span class="db-ai-dirty-badge"><?php esc_html_e( 'Niet opgeslagen', 'digitale-bazen-ai-module' ); ?></span>
			</h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=blog&page=db-ai-generator' ) ); ?>">
					<?php esc_html_e( '← Terug naar de generator', 'digitale-bazen-ai-module' ); ?>
				</a>
			</p>
			<?php settings_errors( self::OPTION_NAME ); ?>

			<ul class="db-ai-tabs-nav">
				<?php foreach ( $tabs as $tab ) : ?>
					<li data-tab="<?php echo esc_attr( $tab['id'] ); ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="post" action="options.php">
				<?php settings_fields( 'db_ai_settings_group' ); ?>

				<?php foreach ( $tabs as $tab ) : ?>
					<section class="db-ai-tabs-pane" data-tab="<?php echo esc_attr( $tab['id'] ); ?>">
						<h2><?php echo esc_html( $tab['title'] ); ?></h2>
						<p class="description-intro"><?php echo esc_html( $tab['intro'] ); ?></p>
						<table class="form-table" role="presentation">
							<?php
							foreach ( $tab['sections'] as $section_id ) {
								do_settings_fields( self::PAGE_SLUG, $section_id );
							}
							?>
						</table>
					</section>
				<?php endforeach; ?>

				<div class="db-ai-fallback-submit">
					<?php submit_button(); ?>
				</div>

				<footer class="db-ai-tabs-savebar">
					<span class="db-ai-spacer"></span>
					<?php submit_button( __( 'Opslaan', 'digitale-bazen-ai-module' ), 'primary', 'submit', false ); ?>
				</footer>
			</form>
		</div>
		<?php
	}
}
