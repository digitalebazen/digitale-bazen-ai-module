<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Anthropic_Provider implements DB_AI_Provider {

	public const ENDPOINT          = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION       = '2023-06-01';
	public const DEFAULT_MODEL     = 'claude-sonnet-4-6';
	public const HTTP_TIMEOUT      = 120;
	// 16k output-tokens geeft comfortabele headroom voor een volledige blog +
	// external_link_suggestions + ingevulde CTA-buttons. Sonnet 4.6 ondersteunt
	// tot 64k output, dus dit is ruim binnen veilige marges. Kosten zijn per
	// daadwerkelijk gebruikte token, niet per cap.
	public const DEFAULT_MAX_TOKENS = 16000;

	private $api_key;
	private $last_tokens     = 0;
	private $last_model      = self::DEFAULT_MODEL;
	private $last_json_error = '';

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	public function get_model_identifier(): string {
		return 'anthropic:' . $this->last_model;
	}

	public function get_last_token_usage(): int {
		return $this->last_tokens;
	}

	/**
	 * @param string   $main_keyword
	 * @param string[] $secondary_keywords
	 * @param array    $context  Expects 'layout_spec' and 'output_schema'.
	 * @return array|WP_Error
	 */
	public function generate_blog( string $main_keyword, array $secondary_keywords, array $context ) {
		if ( '' === trim( $this->api_key ) ) {
			return new WP_Error(
				'db_ai_missing_api_key',
				__( 'Anthropic API-sleutel ontbreekt. Definieer DB_AI_ANTHROPIC_API_KEY in wp-config.php.', 'digitale-bazen-ai-module' )
			);
		}

		$model      = (string) apply_filters( 'db_ai_anthropic_model', self::DEFAULT_MODEL );
		$max_tokens = (int) apply_filters( 'db_ai_anthropic_max_tokens', self::DEFAULT_MAX_TOKENS );

		$this->last_model = $model;

		$system_prompt = apply_filters( 'db_ai_system_prompt', $this->build_system_prompt() );
		$user_prompt   = apply_filters(
			'db_ai_user_prompt',
			$this->build_user_prompt( $main_keyword, $secondary_keywords, $context ),
			$main_keyword,
			$secondary_keywords
		);

		$body = [
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => $user_prompt,
				],
			],
		];

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => [
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'db_ai_anthropic_http_error',
				sprintf( __( 'Anthropic HTTP-fout: %s', 'digitale-bazen-ai-module' ), $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$snippet = mb_substr( (string) $raw, 0, 400 );
			return new WP_Error(
				'db_ai_anthropic_status_error',
				sprintf(
					/* translators: 1 = status, 2 = response snippet */
					__( 'Anthropic antwoordde met status %1$d. Response: %2$s', 'digitale-bazen-ai-module' ),
					$code,
					$snippet
				)
			);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'db_ai_anthropic_invalid_json', __( 'Anthropic antwoord is geen geldige JSON.', 'digitale-bazen-ai-module' ) );
		}

		// Extract first text block (skipping thinking blocks if present).
		$text = '';
		foreach ( $decoded['content'] ?? [] as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' && isset( $block['text'] ) ) {
				$text = (string) $block['text'];
				break;
			}
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'db_ai_anthropic_empty_content', __( 'Anthropic antwoord bevat geen text content.', 'digitale-bazen-ai-module' ) );
		}

		$text = $this->strip_markdown_fences( $text );

		$parsed    = $this->decode_blog_json( $text );
		$stop      = (string) ( $decoded['stop_reason'] ?? '' );
		if ( ! is_array( $parsed ) ) {
			// Diagnose: log de volledige rauwe content zodat de exacte malformatie
			// te vinden is. Gated via filter (default uit) — zet aan met
			// add_filter( 'db_ai_debug_raw_response', '__return_true' ) in een mu-plugin.
			if ( (bool) apply_filters( 'db_ai_debug_raw_response', false ) ) {
				error_log( 'DB_AI Anthropic JSON-decode faalde (' . $this->last_json_error . '; stop_reason: ' . $stop . '). Volledige content volgt:' );
				error_log( $text );
			}
			// Afgekapt antwoord: JSON is dan per definitie onvolledig en niet te repareren.
			if ( 'max_tokens' === $stop ) {
				return new WP_Error(
					'db_ai_anthropic_truncated',
					__( 'Het AI-antwoord werd afgekapt doordat de max_tokens-limiet werd bereikt; de JSON is daardoor onvolledig. Verhoog `db_ai_anthropic_max_tokens` of vraag een korter blog.', 'digitale-bazen-ai-module' )
				);
			}
			$snippet = mb_substr( $text, 0, 400 );
			return new WP_Error(
				'db_ai_anthropic_content_invalid_json',
				sprintf(
					/* translators: 1 = json-foutmelding, 2 = lengte, 3 = stop_reason, 4 = begin van content */
					__( 'AI gaf geen geldig JSON-object terug (%1$s; lengte %2$d; stop_reason: %3$s). Begin van content: %4$s', 'digitale-bazen-ai-module' ),
					'' !== $this->last_json_error ? $this->last_json_error : 'onbekende JSON-fout',
					mb_strlen( $text ),
					'' !== $stop ? $stop : 'onbekend',
					$snippet
				)
			);
		}

		$input  = isset( $decoded['usage']['input_tokens'] ) ? (int) $decoded['usage']['input_tokens'] : 0;
		$output = isset( $decoded['usage']['output_tokens'] ) ? (int) $decoded['usage']['output_tokens'] : 0;
		$this->last_tokens = $input + $output;

		do_action( 'db_ai_after_ai_response', $parsed, $main_keyword );

		return $parsed;
	}

	/**
	 * Decodeer het blog-JSON-object robuust. `json_decode` is strikt; Claude levert
	 * af en toe net-niet-geldige JSON ondanks de instructie. We proberen meerdere
	 * strategieën, oplopend in "agressiviteit", en stoppen bij de eerste die een
	 * array oplevert. Slaat de laatste JSON-foutmelding op voor diagnose.
	 *
	 * Strategieën:
	 *  1. Direct decoden (gewone, geldige output — de normale weg).
	 *  2. Alleen het buitenste object (eerste `{` t/m laatste `}`) — strip eventueel
	 *     omringende toelichting/fence-resten.
	 *  3. Rauwe control-chars (letterlijke newline/tab/CR) binnen strings escapen —
	 *     komt voor bij HTML-content in `tekst`-velden.
	 *
	 * @return array|null
	 */
	private function decode_blog_json( string $text ) {
		$this->last_json_error = '';

		$candidates = [ $text ];

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$inner = substr( $text, $start, $end - $start + 1 );
			if ( $inner !== $text ) {
				$candidates[] = $inner;
			}
		}

		foreach ( $candidates as $candidate ) {
			$decoded = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			$this->last_json_error = json_last_error_msg();

			$repaired = $this->escape_raw_control_chars( $candidate );
			if ( $repaired !== $candidate ) {
				$decoded = json_decode( $repaired, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return null;
	}

	/**
	 * Escape letterlijke control-chars (newline, CR, tab) die BINNEN een JSON-string
	 * staan — die maken de JSON ongeldig. Werkt op byte-niveau met een kleine state-
	 * machine; multibyte UTF-8 (hoge bytes) en chars buiten strings blijven onaangeroerd.
	 */
	private function escape_raw_control_chars( string $json ): string {
		$out       = '';
		$in_string = false;
		$escaped   = false;
		$len       = strlen( $json );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $json[ $i ];

			if ( $in_string ) {
				if ( $escaped ) {
					$out    .= $ch;
					$escaped = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$out    .= $ch;
					$escaped = true;
					continue;
				}
				if ( '"' === $ch ) {
					$out      .= $ch;
					$in_string = false;
					continue;
				}
				if ( "\n" === $ch ) {
					$out .= '\\n';
					continue;
				}
				if ( "\r" === $ch ) {
					$out .= '\\r';
					continue;
				}
				if ( "\t" === $ch ) {
					$out .= '\\t';
					continue;
				}
				$out .= $ch;
				continue;
			}

			if ( '"' === $ch ) {
				$in_string = true;
			}
			$out .= $ch;
		}

		return $out;
	}

	/**
	 * Claude soms wraps JSON in ```json ... ``` ondanks de instructie. Strippen.
	 */
	private function strip_markdown_fences( string $text ): string {
		$text = trim( $text );
		if ( preg_match( '/^```(?:json)?\s*\n?(.*)\n?```\s*$/s', $text, $m ) ) {
			return trim( $m[1] );
		}
		return $text;
	}

	private function build_system_prompt(): string {
		$base = $this->base_system_prompt();
		return $base . DB_AI_Style_Profile::get_prompt_addition();
	}

	private function base_system_prompt(): string {
		return <<<TXT
Je bent een ervaren Nederlandse contentstrateeg en SEO-copywriter voor MKB-bedrijven.
Je schrijft blogartikelen die zowel voor lezers waardevol zijn als goed scoren in Google.

OUTPUTREGELS:
1. Je antwoordt UITSLUITEND met één geldig JSON-object, geen markdown, geen toelichting, geen code fences.
2. De JSON-structuur is exact zoals gespecificeerd in de gebruikersinstructie.
3. Alle teksten zijn in het Nederlands, behalve de "query" velden voor afbeeldingen (die zijn Engels).
4. HTML in tekstvelden beperkt tot: <p>, <strong>, <em>, <ul>, <ol>, <li> en links.
   GEEN <h1>, <h2>, <h3> in tekstvelden (titels staan in aparte "titel" velden).
   GEEN inline styles, klassen of IDs.
5. KRITIEK VOOR GELDIGE JSON — HTML-attributen: gebruik in álle HTML-attributen ALTIJD
   enkele quotes, NOOIT dubbele. Dus schrijf <a href='https://voorbeeld.nl/pad/'>tekst</a>,
   NOOIT <a href="...">. Dubbele quotes in attribuutwaarden botsen met de JSON-string-
   delimiters en maken het hele JSON-object ongeldig. Dit geldt voor href en elk ander attribuut.
6. Geen externe links naar concurrenten of onbekende bronnen.
7. Geen verzonnen statistieken of percentages.

SCHRIJFSTIJL:
- Professioneel maar toegankelijk
- Aanspreekvorm: "je" / "jij" (informeel-zakelijk)
- Doelgroep: MKB-ondernemers en marketingmanagers in Nederland
- Concreet en praktisch — geef voorbeelden, vermijd holle frasen
- Vermijd: "innovatieve oplossingen", "unieke kans", "in deze snel veranderende wereld"
- Vermijd jargon, of leg het uit als het nodig is
- LEESTEKENS (strikt): gebruik NOOIT een gedachtestreepje (— em-dash of – en-dash) en ook geen losse " - " als zinsonderbreking of opsomming. Schrijf losse zinnen of gebruik een komma, dubbele punt of haakjes. Koppeltekens BINNEN een woord (samenstellingen en afkortingen zoals e-mail, SEO-tips, MKB-ondernemers, B2B-markt) zijn correct Nederlands en blijven wél toegestaan.

SEO-RICHTLIJNEN (RankMath-optimalisatie — volg strikt):
- Hoofdzoekwoord MOET voorkomen in:
  * post-titel — bij voorkeur in de eerste drie woorden
  * eerste paragraaf van het intro-blok
  * MINIMAAL 2 verschillende `titel`-velden van blocks (deze worden gerenderd als H2/H3)
  * meta_title — als allereerste woord(en), niet in het midden
  * meta_description — minstens één keer, liefst vooraan
- post-titel MOET bevatten (beide, tenzij echt onnatuurlijk):
  * Eén power-word — kies UITSLUITEND uit onderstaande lijst. Elk woord staat letterlijk in RankMath's NL power-word lijst (`seo-by-rank-math/assets/vendor/powerwords/nl.php`) én is gefilterd op B2B/MKB-toon (geen sensatie/clickbait varianten). Plaats het direct na het focus keyword waar grammaticaal mogelijk. Voorkeur voor `bewezen` als veilige default — past participle inflecteert nooit.

    Autoriteit / vakkundig:
      `bewezen`, `beste`, `effectief`/`effectieve`, `professioneel`, `betrouwbaar`/`betrouwbare`,
      `ervaren`, `gezaghebbende`, `gedetailleerde`, `informatieve`, `expert`, `intelligent`

    Kracht / impact:
      `krachtige`, `baanbrekende`, `revolutionair`, `innovatief`, `succesvol`, `winstgevende`,
      `lucratief`

    Helder / efficient:
      `handige`/`handig`, `praktische`, `efficient`, `eenvoud`, `helder`, `duidelijk`,
      `gerichte`, `solide`

    Aantrekkelijk / waardevol:
      `aantrekkelijke`, `aanzienlijke`, `waardevolle`, `indrukwekkende`, `opmerkelijk`,
      `kostbaar`

    Energiek / boeiend:
      `boeiend`, `dynamisch`, `inspirerend`, `fascinerend`, `intrigerende`

    Praktisch / haalbaar:
      `moeiteloos`, `kipsimpel`, `comfortabel`, `kickstart`, `stap-voor-stap`

  KIES op basis van topic-toon: een resultaat/strategie-blog past bij `bewezen`/`effectief`/`succesvol`; een hoe-doe-je-blog bij `handige`/`praktische`/`moeiteloos`; een uitleg-blog bij `gedetailleerde`/`informatieve`/`helder`; een trend-blog bij `baanbrekende`/`innovatief`/`fascinerend`.

  VERBODEN (lijken op power words maar staan NIET in RankMath's NL lijst — worden NIET gedetecteerd): `essentiële`, `ultieme`, `slimme`, `simpele`, `snelle`, `definitieve`, `gegarandeerde`, `volledige`, `complete`. Gebruik deze NOOIT, kies een variant uit de lijst hierboven.

  * Een concreet getal — bv. "5 manieren", "7 stappen", "10 tips", "in 3 stappen", of het huidige jaartal (2026) als dat redactioneel klopt. Geforceerd klinkende cijfers vermijden, maar een natuurlijke variant kan vrijwel altijd bedacht worden.
- post-titel LENGTE (belangrijk): max 60 tekens, mik op 40-55 tekens. Houd 'm BEKNOPT: focus-keyword + power-word + getal + minimale verbinding. Schrap aanhef- en sluit-woorden zoals "voor MKB", "voor jouw bedrijf", "in 2026", "stap voor stap", "voor meer resultaat" — die horen in de meta-description of het intro thuis, niet in de titel. Een te lange titel wordt door Google afgekapt en oogt log.
- meta_title MOET hetzelfde power-word EN het getal van de post-titel bevatten — kort herformuleren mag, maar laat NOOIT het power-word weg om ruimte te maken. Schrap eerst bijvoeglijke vulwoorden, dan eventueel het getal, en pas als allerlaatste het power-word.
- Secundaire keywords natuurlijk verweven (max 1× per zin, geen stuffing)
- FAQ-vragen formuleren als echte gebruikersvragen (long-tail keywords)
- Meta_title: focus keyword vooraan, max 60 chars, bevat indien mogelijk power-word
- Meta_description: focus keyword + duidelijke CTA, max 155 chars
- Image alt-teksten: focus keyword waar natuurlijk past, niet bij elke afbeelding herhalen

LENGTE & VERDELING (belangrijk):
- Totaal: streef naar 1200-1800 woorden in alle tekst-velden samen (titel/subtitel-velden niet meegeteld).
- Houd de tekst PER BLOK kort: maximaal 2-3 korte alinea's per tekstveld, elke alinea 2-4 zinnen. Geen muren van tekst.
- Verdeel de inhoud liever over een EXTRA blok dan alles in een paar lange blocks te proppen. Wordt een tekstveld lang? Splits het op in twee blocks met elk één duidelijk deelonderwerp. Liever één blok te veel dan te volle blocks.
TXT;
	}

	private function build_user_prompt( string $main_keyword, array $secondary_keywords, array $context ): string {
		$layout_spec   = $context['layout_spec'] ?? [];
		$output_schema = $context['output_schema'] ?? [];
		$blog_input    = (array) ( $context['blog_input'] ?? [] );
		$link_pool     = (array) ( $context['internal_link_pool'] ?? [] );
		$max_links     = (int) ( $context['internal_link_max'] ?? 0 );
		$forced_count  = (int) ( $context['internal_link_forced'] ?? 0 );
		$external_max  = (int) ( $context['external_links_max'] ?? 0 );

		$secondary_list = empty( $secondary_keywords )
			? __( '(geen secundaire keywords beschikbaar)', 'digitale-bazen-ai-module' )
			: implode( ', ', $secondary_keywords );

		$layout_spec_json   = wp_json_encode( $layout_spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$output_schema_json = wp_json_encode( $output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$structure = $this->build_structure_section( array_column( $layout_spec, 'name' ) );

		$prompt = sprintf(
			'Schrijf een Nederlandse blogpost over: "%1$s"' . "\n\n"
			. 'Secundaire keywords om natuurlijk te verwerken: %2$s' . "\n\n"
			. '%3$s' . "\n\n"
			. 'Beschikbare blok-layouts en hun exacte veldspec:' . "\n"
			. '%4$s' . "\n\n"
			. 'KRITIEK — FIELD NAMES:' . "\n"
			. 'Gebruik field-namen EXACT zoals in de spec hierboven, inclusief suffixen.' . "\n"
			. 'Sub-fields in repeaters mogen NIET afgekort of hernoemd worden — als de spec' . "\n"
			. '`titel_content` of `tekst_content` zegt, gebruik die letterlijk (NIET `titel`/`tekst`).' . "\n"
			. 'Velden voor repeater-items zoals `usps[]`, `vragen[]`, `onderwerpen[]` hebben vaak' . "\n"
			. 'andere namen dan top-level block velden — vul ze in volgens de spec.' . "\n\n"
			. 'KRITIEK — REPEATER-AANTAL:' . "\n"
			. 'Heeft een repeater-veld in de spec een `min_items` of `max_items`? Dan MOET de' . "\n"
			. 'repeater respectievelijk minstens / hoogstens dat aantal items bevatten. Een' . "\n"
			. 'repeater met `min_items: 2` waar je maar 1 item invult faalt de validatie en de' . "\n"
			. 'hele blog wordt afgekeurd. Heeft het content-onderwerp niet genoeg om het minimum' . "\n"
			. 'natuurlijk te halen? Kies dan een andere layout voor dat blok.' . "\n\n"
			. 'Geef antwoord als één JSON-object volgens deze exacte structuur:' . "\n"
			. '%5$s',
			$main_keyword,
			$secondary_list,
			$structure,
			$layout_spec_json,
			$output_schema_json
		);

		$layout_guidance = DB_AI_Layout_Calibration::get_prompt_addition( $layout_spec );
		if ( '' !== $layout_guidance ) {
			$prompt .= "\n\n" . $layout_guidance;
		}

		$blog_input_block = DB_AI_Blog_Input::get_prompt_addition( $blog_input );
		if ( '' !== $blog_input_block ) {
			$prompt .= "\n\n" . $blog_input_block;
		}

		if ( ! empty( $link_pool ) && $max_links > 0 ) {
			$links_block = DB_AI_Internal_Links::get_prompt_addition( $link_pool, $max_links, $forced_count );
			if ( '' !== $links_block ) {
				$prompt .= "\n\n" . $links_block;
			}
		}

		if ( $external_max > 0 ) {
			$ext_block = DB_AI_External_Links::get_prompt_addition( $external_max );
			if ( '' !== $ext_block ) {
				$prompt .= "\n\n" . $ext_block;
			}
		}

		$past_blogs       = (array) ( $context['past_blogs'] ?? [] );
		$past_blogs_block = DB_AI_Past_Blogs_Context::get_prompt_addition( $past_blogs );
		if ( '' !== $past_blogs_block ) {
			$prompt .= "\n\n" . $past_blogs_block;
		}

		$prompt .= "\n\n" . 'BELANGRIJK: antwoord met UITSLUITEND het JSON-object zelf. Geen markdown, geen ```json fences, geen toelichting ervoor of erna.';

		return $prompt;
	}

	/**
	 * Bouwt het STRUCTUUR-blok in de user prompt op basis van welke layouts daadwerkelijk
	 * beschikbaar zijn op deze site. Site-agnostisch: geen hardcoded layout-namen. De AI
	 * kijkt naar de full layout-spec (verderop in de prompt) om de juiste keuzes te maken.
	 *
	 * @param string[] $available  Layout-namen die de AI mag gebruiken.
	 */
	private function build_structure_section( array $available ): string {
		$names = array_filter( array_map( 'strval', $available ) );

		$lines   = [];
		$lines[] = 'STRUCTUUR — bepaal zelf wat past bij het onderwerp:';
		$lines[] = '';
		$lines[] = 'BESCHIKBARE LAYOUTS — gebruik UITSLUITEND deze: ' . ( empty( $names ) ? '(geen)' : implode( ', ', $names ) );
		$lines[] = 'HARDE REGEL: een layout die NIET in bovenstaande lijst staat mag NOOIT in je output voorkomen — ook geen banner/hero/intro als die er niet bij staat. Elke `acf_fc_layout`-waarde moet exact één van de beschikbare namen zijn.';
		$lines[] = 'HARDE REGEL: een banner-/hero-/intro-layout (een blok dat als opener fungeert) mag UITSLUITEND als allereerste blok (index 0) in de blocks-array voorkomen. Nooit later in de post — voor body-content gebruik je tekst-/USP-/FAQ-layouts.';
		$lines[] = '';
		$lines[] = 'RICHTLIJNEN VOOR JE KEUZE:';
		$lines[] = '- Begin met het intro/hero-achtige blok ALS er zo\'n layout in de lijst hierboven staat (hoofdzoekwoord prominent in titel + eerste paragraaf). Houd de banner/hero-tekst KORT en wervend: één pakkende, uitnodigende alinea van 1-3 zinnen die nieuwsgierig maakt en de lezer het artikel in trekt, geen volledige uitleg (dat komt in de volgende blocks). Staat er geen intro-layout? Open dan met het eerste beschikbare tekst-blok.';

		// FAQ-instructie alleen meegeven als er ook écht een FAQ-achtige layout
		// beschikbaar is — anders verleidt de regel de AI om alsnog vraag-antwoord
		// content te bedenken, terwijl het block daarna gewoon gedropt wordt.
		$has_faq_layout = false;
		foreach ( $names as $n ) {
			if ( preg_match( '/faq|veelgestelde|vraag/i', $n ) ) {
				$has_faq_layout = true;
				break;
			}
		}
		if ( $has_faq_layout ) {
			$lines[] = '- Eindig bij voorkeur met een FAQ-blok (5-8 vragen).';
		} else {
			$lines[] = '- Er is geen FAQ-/veelgestelde-vragen-layout beschikbaar — voeg dus GEEN vraag-antwoord blok toe en bedenk er ook geen content voor.';
		}

		$lines[] = '- Voor de middelste blocks: kies aantal en mix op basis van topic-complexiteit en wat de inhoud écht nodig heeft.';
		$lines[] = '- USP-achtige layouts: voeg toe ALS er concrete sterke punten/voordelen te vermelden zijn. Sla over als het onderwerp daar niet om vraagt.';
		$lines[] = '- Korte/eenvoudige onderwerpen → 4-5 blocks totaal';
		$lines[] = '- Brede/complexe/how-to onderwerpen → 6-8 blocks totaal';
		$lines[] = '- Liever een blok MEER met korte tekst dan een paar blocks met lange teksten. Splits een blok waarvan het tekstveld lang wordt op in twee blocks met elk één deelonderwerp. Wel echte inhoud per blok — geen lege filler-blocks of herhaling.';

		$role_hints = $this->build_layout_role_hints( $names );
		if ( '' !== $role_hints ) {
			$lines[] = '';
			$lines[] = $role_hints;
		}

		$lines[] = '';
		$lines[] = 'DIVERSITEIT — vermijd dat elke blog dezelfde drie-vier "veilige" layouts gebruikt:';
		$lines[] = '- Gebruik minstens 4 VERSCHILLENDE layout-types per blog (zolang er genoeg layouts beschikbaar zijn).';
		$lines[] = '- Sta niet 3+ identieke layouts (zoals 3× tekst_met_afbeelding) achter elkaar toe.';
		$lines[] = '- Mix tekstuele blocks met visuele/conversie-blocks (video, cta, gallery) waar de inhoud dat draagt.';
		$lines[] = '- Liever 1 visueel of conversie-block dan 2 extra tekst-blocks waar je dezelfde boodschap herhaalt.';
		$lines[] = '';
		$lines[] = 'De exacte velden + types per layout staan in de layout-spec hieronder. Match je output daar exact op.';

		return implode( "\n", $lines );
	}

	/**
	 * Genereert per beschikbare layout-name een korte uitleg WANNEER die layout
	 * zinvol is. Detectie via regex op naam-patronen — site-agnostisch.
	 * Geeft AI semantische context zodat hij niet alleen voor "veilige" tekst-blocks kiest.
	 */
	private function build_layout_role_hints( array $available ): string {
		$patterns = [
			'/cta|call.?to.?action|contact|formulier/' => 'voor expliciete call-to-actions of contact-secties — meestal 1× per blog, vlak vóór de FAQ of helemaal aan het eind',
			'/quote|testimonial|review|aanbeveling/'   => 'voor sociaal bewijs, klant- of medewerker-quotes ter ondersteuning van een argument',
			'/video/'                                  => 'alleen als het onderwerp zich visueel laat uitleggen (demo, instructie, productpresentatie)',
			'/case|project/'                           => 'voor case-studies of "hoe wij het hebben gedaan" verhalen — concreet resultaat per item',
			'/afbeeldingen|gallery|fotogalerij|gallerij/' => 'voor visuele showcases (voorbeelden, voor/na, portfolio-stijl)',
			'/counter|cijfer|statistiek|getal/'        => 'bij data-gedreven onderwerpen — geen verzonnen cijfers, alleen plausibele scenarios',
			'/partner/'                                => 'voor geloofwaardigheid via partner-/klant-logos of associaties',
			'/proces|stappen|stappenplan/'             => 'voor stap-voor-stap uitleg of methodologie',
			'/usp|feature|voordeel|sterke.?punt/'      => 'voor 3-5 concrete USPs of voordelen — niet voor algemene "wij zijn geweldig" tekst',
			'/faq|veelgestelde|vraag/'                 => 'voor 5-8 echte gebruikersvragen — long-tail zoekwoorden, niet je eigen marketing-vragen',
		];

		$hints = [];
		foreach ( $available as $name ) {
			$lower = strtolower( (string) $name );
			foreach ( $patterns as $pattern => $explanation ) {
				if ( preg_match( $pattern, $lower ) ) {
					$hints[] = '- `' . $name . '`: ' . $explanation;
					break;
				}
			}
		}

		if ( empty( $hints ) ) {
			return '';
		}

		return "LAYOUT-ROLES — wanneer welk block-type past:\n" . implode( "\n", $hints );
	}
}
