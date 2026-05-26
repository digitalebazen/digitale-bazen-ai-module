<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bouwt de per-blog instructie-sectie die aan de AI user prompt wordt toegevoegd.
 *
 * Verschilt van DB_AI_Style_Profile: dat injecteert algemene (Settings) data in
 * de SYSTEM prompt, deze klas injecteert per-blog input in de USER prompt zodat
 * de instructies primair gelden voor déze ene generatie en algemene Settings
 * overrulen waar ze conflicteren.
 *
 * Verwacht $blog_input keys (allemaal optioneel):
 *   type_content      (string)  blog|landing|faq|comparison|case|service
 *   funnel_phase      (string)  tofu|mofu|bofu
 *   awareness_level   (string)  unaware|problem|solution|product
 *   must_include      (string)  vrije tekst — wat moet er in
 *   must_avoid        (string)  vrije tekst — wat mag niet
 *   beat_competition  (string)  vrije tekst — waarom beter dan top-3
 *   extra_instructions(string)  catch-all per-blog instructies
 */
class DB_AI_Blog_Input {

	private const TYPE_CONTENT_HINTS = [
		'blog'       => 'Schrijf een Nederlands blogartikel — informatief, met een duidelijke rode draad.',
		'landing'    => 'Schrijf een landingspagina — sterke openingsbelofte, scanbare structuur, expliciete CTAs naar het einde toe.',
		'faq'        => 'Schrijf een FAQ-pagina — focus op vraag-antwoord, korte antwoorden, gegroepeerd per thema.',
		'comparison' => 'Schrijf een vergelijkingspagina — weeg opties tegen elkaar af op concrete criteria, eerlijk en zonder voorbij te gaan aan zwakke punten van de eigen aanpak.',
		'case'       => 'Schrijf als praktijkcase — situatie / uitdaging → aanpak → concreet resultaat. Realistische scenarios, geen verzonnen cijfers.',
		'service'    => 'Schrijf een dienst-/productpagina — focus op wat het oplevert (niet alleen wat het is), met expliciete CTAs.',
	];

	private const FUNNEL_HINTS = [
		'tofu' => 'Top of funnel — lezer ontdekt nu pas het probleem. Geen sales-talk, leg basics uit, eindig met "wil je meer weten" i.p.v. "neem nu contact op".',
		'mofu' => 'Middle of funnel — lezer weegt oplossingen af. Geef objectieve criteria om te kiezen, niet alleen "kies ons". CTA = vergelijking/checklist downloaden.',
		'bofu' => 'Bottom of funnel — lezer staat op het punt te kiezen. Focus op vertrouwen, USPs, expliciete actie. CTA = direct contact, offerte, demo.',
	];

	private const AWARENESS_HINTS = [
		'unaware'  => 'Doelgroep is unaware — begin met het probleem zichtbaar maken via een herkenbare situatie, niet meteen met de oplossing.',
		'problem'  => 'Doelgroep is problem-aware — kort het probleem benoemen, snel doorpakken naar oplossingsrichtingen.',
		'solution' => 'Doelgroep is solution-aware — vergelijk verschillende oplossingen op concrete criteria, positioneer jouw aanpak slim.',
		'product'  => 'Doelgroep is product-aware — adresseer de laatste twijfels (prijs, risico, garanties) en geef het zetje richting beslissing.',
	];

	/**
	 * Bouw de user-prompt-sectie. Returns lege string als alle input leeg is.
	 */
	public static function get_prompt_addition( array $blog_input ): string {
		$lines = [];

		$type = (string) ( $blog_input['type_content'] ?? '' );
		if ( isset( self::TYPE_CONTENT_HINTS[ $type ] ) ) {
			$lines[] = 'CONTENTTYPE: ' . self::TYPE_CONTENT_HINTS[ $type ];
		}

		$funnel = (string) ( $blog_input['funnel_phase'] ?? '' );
		if ( isset( self::FUNNEL_HINTS[ $funnel ] ) ) {
			$lines[] = 'FUNNEL-FASE: ' . self::FUNNEL_HINTS[ $funnel ];
		}

		$awareness = (string) ( $blog_input['awareness_level'] ?? '' );
		if ( isset( self::AWARENESS_HINTS[ $awareness ] ) ) {
			$lines[] = 'AWARENESS: ' . self::AWARENESS_HINTS[ $awareness ];
		}

		$must_include = trim( (string) ( $blog_input['must_include'] ?? '' ) );
		if ( '' !== $must_include ) {
			$lines[] = "MOET ZEKER BENOEMD WORDEN:\n" . $must_include;
		}

		$must_avoid = trim( (string) ( $blog_input['must_avoid'] ?? '' ) );
		if ( '' !== $must_avoid ) {
			$lines[] = "MAG ABSOLUUT NIET:\n" . $must_avoid;
		}

		$beat = trim( (string) ( $blog_input['beat_competition'] ?? '' ) );
		if ( '' !== $beat ) {
			$lines[] = "HOE BETER DAN TOP-3 IN GOOGLE VOOR DIT ZOEKWOORD:\n" . $beat;
		}

		$extra = trim( (string) ( $blog_input['extra_instructions'] ?? '' ) );
		if ( '' !== $extra ) {
			$lines[] = "AANVULLENDE INSTRUCTIES:\n" . $extra;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "BLOG-SPECIFIEKE INSTRUCTIES (gaan boven de algemene Settings waar ze conflicteren):\n\n" . implode( "\n\n", $lines );
	}
}
