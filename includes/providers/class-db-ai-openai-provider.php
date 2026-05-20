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

SEO-RICHTLIJNEN:
- Hoofdzoekwoord verwerken in: post-titel, eerste paragraaf van banner, minimaal 2 H2's (titel-velden), meta_title, meta_description
- Secundaire keywords natuurlijk verweven (niet stuffing)
- FAQ-vragen formuleren als echte gebruikersvragen (long-tail keywords)
- Meta_title: focus keyword vooraan, max 60 chars
- Meta_description: focus keyword + duidelijke CTA, max 155 chars

LENGTE: streef naar 1200-1800 woorden totaal in alle tekst-velden samen (titel/subtitel-velden niet meegeteld).
TXT;
	}

	private function build_user_prompt( string $main_keyword, array $secondary_keywords, array $context ): string {
		$layout_spec   = $context['layout_spec'] ?? [];
		$output_schema = $context['output_schema'] ?? [];

		$secondary_list = empty( $secondary_keywords )
			? __( '(geen secundaire keywords beschikbaar)', 'digitale-bazen-ai-module' )
			: implode( ', ', $secondary_keywords );

		$layout_spec_json   = wp_json_encode( $layout_spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$output_schema_json = wp_json_encode( $output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$structure = $this->build_structure_section( array_column( $layout_spec, 'name' ) );

		return sprintf(
			'Schrijf een Nederlandse blogpost over: "%1$s"' . "\n\n"
			. 'Secundaire keywords om natuurlijk te verwerken: %2$s' . "\n\n"
			. '%3$s' . "\n\n"
			. 'Beschikbare blok-layouts en hun exacte veldspec:' . "\n"
			. '%4$s' . "\n\n"
			. 'Geef antwoord als één JSON-object volgens deze exacte structuur:' . "\n"
			. '%5$s',
			$main_keyword,
			$secondary_list,
			$structure,
			$layout_spec_json,
			$output_schema_json
		);
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
		$lines[] = '';
		$lines[] = 'De exacte velden + types per layout staan in de layout-spec hieronder. Match je output daar exact op.';

		return implode( "\n", $lines );
	}
}
