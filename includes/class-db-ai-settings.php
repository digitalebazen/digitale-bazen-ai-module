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
		$opts        = self::get_options();
		$known       = array_keys( self::LAYOUT_LABELS );
		$default_all = $known;
		$current     = $opts['allowed_layouts'] ?? null;
		// Niet eerder opgeslagen → default alles aan.
		if ( ! is_array( $current ) ) {
			$current = $default_all;
		}

		echo '<fieldset class="db-ai-layouts-fieldset">';
		foreach ( self::LAYOUT_LABELS as $name => $label ) {
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
		esc_html_e( 'Bepaalt welke ACF-flex layouts de AI mag gebruiken. Standaard alle 6 aan. De AI kiest zelf welke + hoeveel per blog op basis van het onderwerp. Tip: laat banner en veelgestelde_vragen aan staan — de prompt rekent erop dat die beschikbaar zijn. Alles uitvinken = fallback naar standaard.', 'digitale-bazen-ai-module' );
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
