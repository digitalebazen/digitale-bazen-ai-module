<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_OpenAI_Provider implements DB_AI_Provider {

	public const ENDPOINT       = 'https://api.openai.com/v1/chat/completions';
	public const DEFAULT_MODEL  = 'gpt-4o';
	public const HTTP_TIMEOUT   = 120;

	private $api_key;
	private $last_tokens = 0;
	private $last_model  = self::DEFAULT_MODEL;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	public function get_model_identifier(): string {
		return 'openai:' . $this->last_model;
	}

	public function get_last_token_usage(): int {
		return $this->last_tokens;
	}

	/**
	 * @param string   $main_keyword
	 * @param string[] $secondary_keywords
	 * @param array    $context  Expects 'layout_spec' (array) and 'output_schema' (array).
	 * @return array|WP_Error
	 */
	public function generate_blog( string $main_keyword, array $secondary_keywords, array $context ) {
		if ( '' === trim( $this->api_key ) ) {
			return new WP_Error(
				'db_ai_missing_api_key',
				__( 'OpenAI API-sleutel ontbreekt. Definieer DB_AI_OPENAI_API_KEY in wp-config.php.', 'digitale-bazen-ai-module' )
			);
		}

		$model       = apply_filters( 'db_ai_openai_model', self::DEFAULT_MODEL );
		$temperature = (float) apply_filters( 'db_ai_openai_temperature', 0.7 );
		$max_tokens  = (int) apply_filters( 'db_ai_openai_max_tokens', 8000 );

		$this->last_model = (string) $model;

		$system_prompt = apply_filters( 'db_ai_system_prompt', $this->build_system_prompt() );
		$user_prompt   = apply_filters(
			'db_ai_user_prompt',
			$this->build_user_prompt( $main_keyword, $secondary_keywords, $context ),
			$main_keyword,
			$secondary_keywords
		);

		$body = [
			'model'           => $model,
			'messages'        => [
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user', 'content' => $user_prompt ],
			],
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => $temperature,
			'max_tokens'      => $max_tokens,
		];

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'db_ai_openai_http_error',
				sprintf( __( 'OpenAI HTTP-fout: %s', 'digitale-bazen-ai-module' ), $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$snippet = mb_substr( (string) $raw, 0, 400 );
			return new WP_Error(
				'db_ai_openai_status_error',
				sprintf(
					/* translators: 1 = status code, 2 = response snippet */
					__( 'OpenAI antwoordde met status %1$d. Response: %2$s', 'digitale-bazen-ai-module' ),
					$code,
					$snippet
				)
			);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'db_ai_openai_invalid_json', __( 'OpenAI antwoord is geen geldige JSON.', 'digitale-bazen-ai-module' ) );
		}

		$content = $decoded['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new WP_Error( 'db_ai_openai_empty_content', __( 'OpenAI antwoord bevat geen content.', 'digitale-bazen-ai-module' ) );
		}

		$parsed = json_decode( $content, true );
		if ( ! is_array( $parsed ) ) {
			$snippet = mb_substr( $content, 0, 400 );
			return new WP_Error(
				'db_ai_openai_content_invalid_json',
				sprintf( __( 'AI gaf geen geldig JSON-object terug. Begin van content: %s', 'digitale-bazen-ai-module' ), $snippet )
			);
		}

		$this->last_tokens = isset( $decoded['usage']['total_tokens'] ) ? (int) $decoded['usage']['total_tokens'] : 0;

		do_action( 'db_ai_after_ai_response', $parsed, $main_keyword );

		return $parsed;
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
4. HTML in tekstvelden beperkt tot: <p>, <strong>, <em>, <ul>, <ol>, <li>, <a href="">.
   GEEN <h1>, <h2>, <h3> in tekstvelden (titels staan in aparte "titel" velden).
   GEEN inline styles, klassen of IDs.
5. Geen externe links naar concurrenten of onbekende bronnen.
6. Geen verzonnen statistieken of percentages.

SCHRIJFSTIJL:
- Professioneel maar toegankelijk
- Aanspreekvorm: "je" / "jij" (informeel-zakelijk)
- Doelgroep: MKB-ondernemers en marketingmanagers in Nederland
- Concreet en praktisch — geef voorbeelden, vermijd holle frasen
- Vermijd: "innovatieve oplossingen", "unieke kans", "in deze snel veranderende wereld"
- Vermijd jargon, of leg het uit als het nodig is

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
- meta_title MOET hetzelfde power-word EN het getal van de post-titel bevatten — kort herformuleren mag, maar laat NOOIT het power-word weg om ruimte te maken. Schrap eerst bijvoeglijke vulwoorden, dan eventueel het getal, en pas als allerlaatste het power-word.
- Secundaire keywords natuurlijk verweven (max 1× per zin, geen stuffing)
- FAQ-vragen formuleren als echte gebruikersvragen (long-tail keywords)
- Meta_title: focus keyword vooraan, max 60 chars, bevat indien mogelijk power-word
- Meta_description: focus keyword + duidelijke CTA, max 155 chars
- Image alt-teksten: focus keyword waar natuurlijk past, niet bij elke afbeelding herhalen

LENGTE: streef naar 1200-1800 woorden totaal in alle tekst-velden samen (titel/subtitel-velden niet meegeteld).
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
			. 'Geef antwoord als één JSON-object volgens deze exacte structuur:' . "\n"
			. '%5$s',
			$main_keyword,
			$secondary_list,
			$structure,
			$layout_spec_json,
			$output_schema_json
		);

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

		return $prompt;
	}

	/**
	 * Site-agnostisch STRUCTUUR-blok — geen hardcoded layout-namen.
	 * Zie DB_AI_Anthropic_Provider::build_structure_section voor uitleg.
	 *
	 * @param string[] $available
	 */
	private function build_structure_section( array $available ): string {
		$names = array_filter( array_map( 'strval', $available ) );

		$lines   = [];
		$lines[] = 'STRUCTUUR — bepaal zelf wat past bij het onderwerp:';
		$lines[] = '';
		$lines[] = 'Op deze site zijn deze layouts beschikbaar: ' . ( empty( $names ) ? '(geen)' : implode( ', ', $names ) );
		$lines[] = '';
		$lines[] = 'RICHTLIJNEN VOOR JE KEUZE:';
		$lines[] = '- Begin met een visueel intro-blok als beschikbaar (vaak een banner/hero/intro-achtige layout — hoofdzoekwoord prominent in titel + eerste paragraaf).';
		$lines[] = '- Eindig bij voorkeur met een FAQ-blok als de site er een heeft (vaak `faq`, `veelgestelde_vragen` of vergelijkbaar — 5-8 vragen).';
		$lines[] = '- Voor de middelste blocks: kies aantal en mix op basis van topic-complexiteit en wat de inhoud écht nodig heeft.';
		$lines[] = '- USP-achtige layouts: voeg toe ALS er concrete sterke punten/voordelen te vermelden zijn. Sla over als het onderwerp daar niet om vraagt.';
		$lines[] = '- Korte/eenvoudige onderwerpen → 3-4 blocks totaal';
		$lines[] = '- Brede/complexe/how-to onderwerpen → 5-7 blocks totaal';
		$lines[] = '- Niet meer blocks dan nodig. Vermijd block-padding.';

		$role_hints = $this->build_layout_role_hints( $names );
		if ( '' !== $role_hints ) {
			$lines[] = '';
			$lines[] = $role_hints;
		}

		$lines[] = '';
		$lines[] = 'DIVERSITEIT — vermijd dat elke blog dezelfde drie-vier "veilige" layouts gebruikt:';
		$lines[] = '- Gebruik minstens 4 VERSCHILLENDE layout-types per blog van 5-7 blocks.';
		$lines[] = '- Sta niet 3+ identieke layouts (zoals 3× tekst_met_afbeelding) achter elkaar toe.';
		$lines[] = '- Mix tekstuele blocks met visuele/conversie-blocks (quote, video, cta, gallery) waar de inhoud dat draagt.';
		$lines[] = '- Liever 1 quote-block dan 2 extra tekst-blocks waar je dezelfde boodschap herhaalt.';
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
