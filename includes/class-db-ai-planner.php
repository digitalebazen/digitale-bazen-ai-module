<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * De strateeg (v3.0.0, §0O / §14 Stap 8b).
 *
 * Zet een zoekwoordenonderzoek om in een contentplan: classificeert elk zoekwoord
 * op zoekintentie, clustert de informatieve zoekwoorden, en bepaalt per cluster
 * een pillar (bundelt synoniemen) + supporting posts (eigen deelvraag).
 *
 * Volledig los testbaar: input = geparseerde db_ai_kwo-rijen, output = plan-array.
 * GEEN UI, GEEN opslag — dat komt in §14 Stap 8c. Eén AI-call op de hele lijst via
 * de bestaande DB_AI_Anthropic_Provider (geen nieuwe provider).
 */
class DB_AI_Planner {

	/** Toegestane zoekintenties. */
	public const INTENTS = [ 'informatief', 'commercieel', 'lokaal' ];

	/** Toegestane rollen. `—` = geen blog (vaste pagina). */
	public const ROLES = [ 'pillar', 'supporting', '—' ];

	/** Bovengrens op het aantal zoekwoorden per analyse (defensief tegen reuze-uploads). */
	private const MAX_KEYWORDS = 400;

	/**
	 * Max zoekwoorden per AI-call. Eén grote lijst geeft zóveel JSON-output dat de
	 * niet-streamende call de 300s-timeout overschrijdt ("0 bytes received"). Daarom
	 * verwerken we in batches; verwante keywords blijven samen door op onderwerp/pagina
	 * te sorteren vóór het batchen.
	 */
	private const BATCH_SIZE = 35;

	/**
	 * Classificeer + cluster een set onderzoek-rijen tot een contentplan.
	 *
	 * @param array         $rows      Genormaliseerde db_ai_kwo-rijen ({zoekwoord, volume, concurrentie, pagina, onderwerp}).
	 * @param callable|null $progress  Optioneel: fn( int $done, int $total ): void — per afgeronde batch.
	 * @return array|WP_Error  Lijst plan-entries (zie normalize_entry) of WP_Error.
	 */
	public function build_plan( array $rows, ?callable $progress = null ) {
		$keywords = $this->collect_keywords( $rows );
		if ( empty( $keywords ) ) {
			return new WP_Error( 'db_ai_plan_empty', __( 'Geen zoekwoorden om te analyseren.', 'digitale-bazen-ai-module' ) );
		}

		if ( ! class_exists( 'DB_AI_Anthropic_Provider' ) || ! class_exists( 'DB_AI_Settings' ) ) {
			return new WP_Error( 'db_ai_plan_unavailable', __( 'De planner is niet beschikbaar in deze context.', 'digitale-bazen-ai-module' ) );
		}

		$key = DB_AI_Settings::get_api_key( 'anthropic' );
		if ( '' === trim( $key ) ) {
			return new WP_Error( 'db_ai_plan_missing_key', __( 'Anthropic API-sleutel ontbreekt. Vul hem in bij Generator → Instellingen → API-keys.', 'digitale-bazen-ai-module' ) );
		}

		$provider = new DB_AI_Anthropic_Provider( $key );
		$system   = $this->system_prompt();

		$batches     = $this->batch_keywords( $keywords );
		$total       = count( $batches );
		$raw_entries = [];

		foreach ( $batches as $i => $batch ) {
			$user = $this->user_prompt( $batch );
			do_action( 'db_ai_planner_debug_prompts', $system, $user );

			$result = $provider->complete_json( $system, $user, $this->max_tokens_for( count( $batch ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			do_action( 'db_ai_planner_debug_result', $result );

			$entries = $this->extract_entries( $result );
			if ( null !== $entries ) {
				$raw_entries = array_merge( $raw_entries, $entries );
			}

			if ( null !== $progress ) {
				$progress( $i + 1, $total );
			}
		}

		if ( empty( $raw_entries ) ) {
			return new WP_Error(
				'db_ai_plan_malformed',
				__( 'De planner gaf geen geldige zoekwoorden-lijst terug. Probeer het opnieuw.', 'digitale-bazen-ai-module' )
			);
		}

		// Normaliseer over álle batches samen (dedup, bundel, vangnet), en dwing
		// daarna per cluster precies één pillar af (batches kunnen er meerdere geven).
		$plan = $this->normalize_plan( $raw_entries, $keywords );
		return self::reconcile_pillars( $plan );
	}

	/**
	 * Verdeel de keywords in batches die elk binnen de timeout passen. Sorteer eerst
	 * op onderwerp → pagina → keyword zodat verwante termen in dezelfde batch vallen
	 * (zodat synoniem-bundeling en clustering zo min mogelijk over batches verloren gaat).
	 *
	 * @return array<int,array>  Lijst van batches (elk een keyed sub-map zoals collect_keywords).
	 */
	private function batch_keywords( array $keywords ): array {
		if ( count( $keywords ) <= self::BATCH_SIZE ) {
			return [ $keywords ];
		}

		$list = array_values( $keywords );
		usort(
			$list,
			static function ( $a, $b ) {
				return [ (string) $a['onderwerp'], (string) $a['pagina'], (string) $a['keyword'] ]
					<=> [ (string) $b['onderwerp'], (string) $b['pagina'], (string) $b['keyword'] ];
			}
		);

		$batches = [];
		foreach ( array_chunk( $list, self::BATCH_SIZE ) as $chunk ) {
			$keyed = [];
			foreach ( $chunk as $k ) {
				$keyed[ $this->norm( $k['keyword'] ) ] = $k;
			}
			$batches[] = $keyed;
		}
		return $batches;
	}

	/**
	 * Dwing per cluster precies ÉÉN pillar af. De hoogste-volume blog-entry van een
	 * cluster wordt de pillar; de rest wordt supporting. Dit lost twee dingen op:
	 *  - meerdere pillars (uit verschillende batches) → één winnaar, rest supporting;
	 *  - GEEN pillar (cluster met alleen supporting) → de hoogste-volume wordt pillar,
	 *    anders zit het cluster muurvast (pillar-eerst blokkeert alles).
	 * Gebundelde synoniemen van gedegradeerde pillars verhuizen mee naar de winnaar.
	 * Public static zodat een opgeslagen plan tokenvrij gerepareerd kan worden.
	 */
	public static function reconcile_pillars( array $plan ): array {
		// Verzamel per cluster de indexen van blog-entries (pillar + supporting).
		$by_cluster = [];
		foreach ( $plan as $i => $e ) {
			$role = (string) ( $e['role'] ?? '' );
			if ( 'pillar' !== $role && 'supporting' !== $role ) {
				continue;
			}
			$cluster = (string) ( $e['cluster'] ?? '' );
			if ( '' === $cluster ) {
				continue;
			}
			$by_cluster[ $cluster ][] = $i;
		}

		foreach ( $by_cluster as $indexes ) {
			// Kies de hoogste-volume entry als pillar van dit cluster.
			$win = $indexes[0];
			foreach ( $indexes as $i ) {
				if ( (int) $plan[ $i ]['volume'] > (int) $plan[ $win ]['volume'] ) {
					$win = $i;
				}
			}

			foreach ( $indexes as $i ) {
				if ( $i === $win ) {
					$plan[ $i ]['role']       = 'pillar';
					$plan[ $i ]['pillar_ref'] = null;
					continue;
				}
				// Degradeer naar supporting; gebundelde synoniemen verhuizen naar de winnaar.
				if ( 'pillar' === ( $plan[ $i ]['role'] ?? '' ) && ! empty( $plan[ $i ]['bundled_keywords'] ) ) {
					$plan[ $win ]['bundled_keywords'] = array_values( array_unique( array_merge(
						(array) $plan[ $win ]['bundled_keywords'],
						(array) $plan[ $i ]['bundled_keywords']
					) ) );
				}
				$plan[ $i ]['role']             = 'supporting';
				$plan[ $i ]['pillar_ref']       = (string) $plan[ $win ]['keyword'];
				$plan[ $i ]['bundled_keywords'] = [];
			}
		}

		return $plan;
	}

	// ─── Input ─────────────────────────────────────────────────────────────

	/**
	 * Dedupliceer + normaliseer de onderzoek-rijen naar de velden die de planner nodig
	 * heeft. Keyed op lowercased zoekwoord, behoudt de oorspronkelijke schrijfwijze.
	 *
	 * @return array<string,array{keyword:string,volume:int,concurrentie:string,pagina:string,onderwerp:string}>
	 */
	private function collect_keywords( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$keyword = trim( (string) ( $row['zoekwoord'] ?? '' ) );
			if ( '' === $keyword ) {
				continue;
			}
			$lc = $this->norm( $keyword );
			if ( isset( $out[ $lc ] ) ) {
				continue; // eerste schrijfwijze wint
			}
			$out[ $lc ] = [
				'keyword'      => $keyword,
				'volume'       => (int) ( $row['volume'] ?? 0 ),
				'concurrentie' => trim( (string) ( $row['concurrentie'] ?? '' ) ),
				'pagina'       => trim( (string) ( $row['pagina'] ?? '' ) ),
				'onderwerp'    => trim( (string) ( $row['onderwerp'] ?? '' ) ),
			];
			if ( count( $out ) >= self::MAX_KEYWORDS ) {
				break;
			}
		}
		return $out;
	}

	private function max_tokens_for( int $count ): int {
		// ~300 output-tokens per keyword-object (incl. angle/bridge/reason + soms een
		// lange bundled-lijst). Ruime marge BOVEN het werkelijke gebruik zodat de JSON
		// niet afkapt (stop_reason: max_tokens → onvolledige JSON). De batch-grootte
		// houdt de daadwerkelijke generatietijd onder de 300s-timeout.
		return (int) min( 32000, max( 6000, $count * 300 ) );
	}

	// ─── Prompts ───────────────────────────────────────────────────────────

	private function system_prompt(): string {
		return <<<TXT
Je bent een Nederlandse SEO-contentstrateeg. Je krijgt een zoekwoordenonderzoek en zet dat om in een contentplan: per zoekwoord de zoekintentie, en voor de informatieve zoekwoorden een clustering met pillar- en supporting-rollen.

OUTPUTREGELS:
1. Antwoord UITSLUITEND met één geldig JSON-object: begin met { en eindig met }, geen tekst ervoor of erna, geen markdown, geen code fences.
2. Het object heeft EXACT deze vorm — let op de buitenste "keywords"-sleutel, geef GEEN kale lijst: { "keywords": [ <één object per zoekwoord> ] }.
3. Verwerk ELK aangeleverd zoekwoord (verzin er geen bij), maar NIET per se als eigen object: een synoniem dat in een pillar opgaat krijgt GEEN eigen object — het hoort uitsluitend in de "bundled_keywords" van die pillar. Geef per cluster dus precies één object met role "pillar".
4. Houd "reason", "angle" en "bridge" BONDIG — één korte zin of zinsdeel per veld, geen uitweidingen. Dit houdt de output compact.

INTENTIE-CLASSIFICATIE — bepaal de FUNCTIE die de zoeker zoekt (niet alleen het label); pas de regels in volgorde toe, eerste match wint:
1. Bevat de term een PLAATSNAAM of regio? → intent "lokaal" (vaste lokale landingspagina, geen blog). Lokale rankings leunen op lokale signalen + conversie; die horen op een vaste pagina, niet in de blogfeed.
2. Is de term ORIËNTEREND/informatief — de zoeker wil eerst leren, vergelijken of beslissen (nog niet kopen)? → intent "informatief" (blog). Dit geldt ÓÓK voor termen met een commerciële ondertoon, zolang het frame oriënterend is. Hieronder vallen:
   - Vragen: wat / hoe / waarom / wanneer / hoeveel / "wat is" / "wat kost".
   - Kosten- en prijs-oriëntatie zónder plaatsnaam: "wat kost een website", "website prijzen".
   - Vergelijkingen & keuzehulp: "wordpress vs maatwerk", "zelf maken of laten doen", "hoe kies je een webdesign bureau", "waar moet je op letten".
   - Proces/aanpak: "hoe lang duurt een website maken", "wat heb je nodig om te starten", "stappenplan".
   De blog informeert en linkt vervolgens dóór naar de bijbehorende dienstenpagina.
3. Is de term PUUR TRANSACTIONEEL — de zoeker wil NU een leverancier/offerte, geen uitleg? → intent "commercieel" (vaste dienstenpagina, geen blog). Typisch: een kale "[dienst] laten maken/bouwen", "professionele/maatwerk [dienst]", "[vakgebied] bureau/specialist", "offerte" — ZONDER vraag- of vergelijkingsframe. Een blog zou hier slecht ranken (Google toont voor zulke queries dienstpagina's) én slecht converteren (de zoeker wil een offerte, geen leesvoer).

TOETS BIJ TWIJFEL (informatief vs. commercieel): wil de zoeker LEREN/vergelijken/beslissen → informatief (blog, linkt naar de dienst); wil 'ie NU kopen/een offerte → commercieel (vaste pagina). Een vraag-, kosten- of vergelijkingsframe weegt richting informatief; een kale "[dienst] laten maken" zonder vraag weegt richting commercieel. Trefwoorden zijn een hulpmiddel, geen wet: "waarom een website" = informatief ondanks geen vraagwoord; "website laten maken" = commercieel ondanks geen plaatsnaam.

CLUSTERING & ROLLEN (alleen voor intent "informatief"):
- Groepeer informatieve zoekwoorden op zoekintentie: dezelfde gewenste antwoord/onderwerp = zelfde "cluster" (korte, herkenbare cluster-naam in kleine letters).
- PILLAR: per cluster is het breedste / hoogste-volume zoekwoord de pillar (role "pillar"). De pillar bundelt UITSLUITEND échte synoniemen.
- BUNDELEN — strenge toets: bundel een zoekwoord ALLEEN in de pillar als je er LETTERLIJK HETZELFDE artikel voor zou schrijven als voor de pillar — exact dezelfde vraag, exact hetzelfde antwoord, alleen anders verwoord (bv. "wat kost een website" / "hoeveel kost een website" / "website maken kosten" — pure herformuleringen). Zet die in "bundled_keywords", zonder eigen object.
- SUPPORTING — ruim toepassen: heeft een zoekwoord een EIGEN invalshoek, kwalificeerder, niche, platform, doelgroep of deelaspect waardoor het een (deels) ANDER antwoord/artikel verdient? → role "supporting" met een eigen object + "pillar_ref" naar de pillar. Voorbeelden binnen een kosten-cluster: "wat kost een website bouwen" (zelf bouwen vs. laten maken = andere insteek), "professionele website laten maken kosten" (specifiek pro-segment), "wat kost onderhoud" (ander deelonderwerp). Elk daarvan = eigen supporting-post, NIET bundelen.
- BELANGRIJK — bij twijfel kies je SUPPORTING, niet bundelen: elk aangeleverd zoekwoord is bewust geselecteerd op zoekvolume/clicks, dus een zoekwoord met een eigen hoek mag NIET onzichtbaar in de bundel verdwijnen. Bundel alleen wat écht inwisselbaar is. Tegelijk: knip pure synoniemen NIET los (dat veroorzaakt kannibalisatie) — alleen een eigen invalshoek rechtvaardigt een eigen post.

FUNNEL-BRUG (alleen voor intent "informatief") — kader elk informatief keyword in richting een dienst:
Een informatief keyword trekt verkeer, maar de winst zit in de HOEK waarmee je de post schrijft. Niet de platte how-to ("5 manieren om je snelheid te testen" — lezer loopt weg), maar de inkaderende hoek die de vraag eerlijk beantwoordt én naar een dienst leidt ("Test je snelheid: deze signalen zeggen dat je site toe is aan vernieuwing"). Bepaal per informatief keyword:
- "angle": de inkaderende invalshoek/titelrichting die de vraag EERLIJK beantwoordt én de lezer naar een dienst leidt.
- "funnel_target": naar welke commerciële intentie/dienst/pillar de post eerlijk doorlinkt (bv. "redesign", "website laten maken", "webshop laten maken"). Kies bij voorkeur een dienst/pillar die óók in dit onderzoek voorkomt.
- "bridge": de eerlijke logische verbinding keyword → dienst (bv. "een trage site is vaak een verouderde site → redesign overwegen").
EERLIJKHEIDS-GRENS: de brug MOET een echte, logische connectie zijn. Forceer GEEN brug naar een irrelevante dienst (snelheid testen → "koop een webshop" = mismatch; lezer bounce't). Is er geen eerlijke brug? → "funnel_target": null en de post blijft puur informatief (mag, maar lagere prioriteit).

NIET-BLOG (intent "commercieel" of "lokaal"):
- role = "—", cluster = "", pillar_ref = null, bundled_keywords = [].
- Vul "link_target_hint" met het type vaste pagina dat dit zoekwoord voedt (bv. "Dienstenpagina: website laten maken" of "Lokale pagina: webdesign Eindhoven").
- BLOG-COMPANION (optioneel, dit is de oplossing voor "ogenschijnlijk losse koop-termen"): bestaat er over HETZELFDE onderwerp een eerlijke INFORMATIEVE invalshoek die een nuttige blog oplevert en die naar deze dienst funnelt? Vul dan "angle" (de informatieve blog-invalshoek/titelrichting, bv. "responsive website laten maken" → "Wat is een responsive website en waarom is het belangrijk?"), "funnel_target" (de dienst) en "bridge" (de eerlijke connectie) in. Dit is een EXTRA blogkans NÁÁST de vaste pagina — GEEN herclassificatie: het keyword houdt role "—" en blijft een vaste-pagina-doel. Geen eerlijke informatieve invalshoek (bv. een kale plaatsnaam-term zoals "website laten maken eindhoven")? → angle = "", funnel_target = null, bridge = "".

VELDEN PER ZOEKWOORD:
- keyword: exact het aangeleverde zoekwoord (zelfde schrijfwijze).
- intent: "informatief" | "commercieel" | "lokaal".
- cluster: korte cluster-naam (alleen bij informatief; anders "").
- role: "pillar" | "supporting" | "—".
- pillar_ref: bij supporting het keyword van de pillar; anders null.
- bundled_keywords: bij pillar de gebundelde synoniemen (lijst van strings); anders [].
- angle: bij informatief de inkaderende hoek; anders "".
- funnel_target: bij informatief de dienst/pillar waar het naartoe linkt (of null als er geen eerlijke brug is); anders null.
- bridge: bij informatief de eerlijke brug; anders "".
- link_target_hint: bij commercieel/lokaal een korte hint; anders "".
- reason: één korte zin met de motivatie.
TXT;
	}

	private function user_prompt( array $keywords ): string {
		$lines = [];
		foreach ( $keywords as $k ) {
			$lines[] = sprintf(
				'- %s | volume: %d | concurrentie: %s | pagina: %s | onderwerp: %s',
				$k['keyword'],
				$k['volume'],
				'' !== $k['concurrentie'] ? $k['concurrentie'] : '—',
				'' !== $k['pagina'] ? $k['pagina'] : '—',
				'' !== $k['onderwerp'] ? $k['onderwerp'] : '—'
			);
		}
		$list = implode( "\n", $lines );

		$example = <<<JSON
{
  "keywords": [
    {
      "keyword": "website laten maken kosten",
      "intent": "informatief",
      "cluster": "kosten",
      "role": "pillar",
      "pillar_ref": null,
      "bundled_keywords": ["website maken kosten", "wat kost een website laten maken", "hoeveel kost een website"],
      "angle": "Wat bepaalt de prijs van een website — en wanneer is laten maken de investering waard?",
      "funnel_target": "website laten maken",
      "bridge": "Wie de kosten begrijpt, staat dichter bij de keuze om het te laten doen → dienstenpagina.",
      "link_target_hint": "",
      "reason": "Brede kostenvraag; deze varianten zijn pure herformuleringen met exact hetzelfde antwoord en bundelen op één pagina."
    },
    {
      "keyword": "snelheid website testen",
      "intent": "informatief",
      "cluster": "redesign",
      "role": "supporting",
      "pillar_ref": "website redesign",
      "bundled_keywords": [],
      "angle": "Test je snelheid: deze signalen zeggen dat je site toe is aan vernieuwing",
      "funnel_target": "redesign",
      "bridge": "Een trage site is vaak een verouderde site → redesign overwegen.",
      "link_target_hint": "",
      "reason": "Diagnose-vraag die eerlijk naar een redesign leidt; eigen supporting-post met de redesign-pillar als doel."
    },
    {
      "keyword": "website laten maken",
      "intent": "commercieel",
      "cluster": "",
      "role": "—",
      "pillar_ref": null,
      "bundled_keywords": [],
      "angle": "",
      "funnel_target": null,
      "bridge": "",
      "link_target_hint": "Dienstenpagina: website laten maken",
      "reason": "Pure koopintentie zonder vraag-/plaatssignaal; vaste dienstenpagina, geen eerlijke losse blog-invalshoek."
    },
    {
      "keyword": "responsive website laten maken",
      "intent": "commercieel",
      "cluster": "",
      "role": "—",
      "pillar_ref": null,
      "bundled_keywords": [],
      "angle": "Wat is een responsive website en waarom is het belangrijk?",
      "funnel_target": "website laten maken",
      "bridge": "Uitleg over responsive design maakt duidelijk waarom je het wilt laten maken → dienstenpagina.",
      "link_target_hint": "Dienstenpagina: website laten maken",
      "reason": "Koopterm (vaste pagina), maar het onderwerp leent zich óók voor een eerlijke informatieve blog die naar de dienst funnelt — blog-companion."
    }
  ]
}
JSON;

		return "Zoekwoordenonderzoek (één regel per zoekwoord, met maandvolume, concurrentie en de pagina/onderwerp-groepering uit het onderzoek als hint):\n\n"
			. $list
			. "\n\nGeef het contentplan terug als precies dit JSON-formaat (één object per zoekwoord hierboven):\n"
			. $example;
	}

	// ─── Output-extractie (tolerant) ───────────────────────────────────────

	/**
	 * Haal de lijst keyword-entries uit het AI-resultaat, ongeacht de exacte vorm.
	 * De generieke decoder (complete_json) levert óf de wikkel `{ "keywords": [...] }`,
	 * óf — als het model de wikkel negeert — een kale array `[ {...}, ... ]`.
	 *
	 * @param mixed $result
	 * @return array|null  Lijst entries, of null als er niets bruikbaars in zit.
	 */
	private function extract_entries( $result ): ?array {
		if ( ! is_array( $result ) ) {
			return null;
		}
		// 1. Wikkel met een bekende top-key (EN/NL).
		foreach ( [ 'keywords', 'zoekwoorden', 'plan', 'items', 'entries', 'result', 'data' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) && $this->looks_like_entry_list( $result[ $key ] ) ) {
				return array_values( $result[ $key ] );
			}
		}
		// 2. Kale lijst van entries.
		if ( $this->looks_like_entry_list( $result ) ) {
			return array_values( array_filter( $result, 'is_array' ) );
		}
		// 3. Eén los entry-object → als lijst behandelen.
		if ( $this->is_entry( $result ) ) {
			return [ $result ];
		}
		// 4. Laatste redmiddel: verzamel entries uit ALLE geneste arrays (bv. output
		//    die per cluster gegroepeerd is), zodat niets verloren gaat.
		$collected = [];
		foreach ( $result as $value ) {
			if ( $this->is_entry( $value ) ) {
				$collected[] = $value;
			} elseif ( is_array( $value ) ) {
				foreach ( $value as $sub ) {
					if ( $this->is_entry( $sub ) ) {
						$collected[] = $sub;
					}
				}
			}
		}
		return ! empty( $collected ) ? $collected : null;
	}

	/** Lijkt dit een lijst van keyword-entries (minstens één bruikbaar item)? */
	private function looks_like_entry_list( array $arr ): bool {
		foreach ( $arr as $item ) {
			if ( $this->is_entry( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/** Een entry herken je aan een keyword-veld (EN of NL schrijfwijze). */
	private function is_entry( $item ): bool {
		return is_array( $item ) && ( isset( $item['keyword'] ) || isset( $item['zoekwoord'] ) );
	}

	// ─── Output-normalisatie (defensief) ───────────────────────────────────

	/**
	 * Valideer + saneer de AI-entries en garandeer dat elk input-zoekwoord vertegenwoordigd
	 * is. Synoniemen die als "bundled_keywords" onder een pillar vallen worden NIET ook als
	 * losse supporting-rij behouden (afdwingen van de bundel-regel uit §0O).
	 *
	 * @param array $entries   Rauwe AI-entries.
	 * @param array $keywords  collect_keywords()-map (lc => meta).
	 * @return array  Lijst genormaliseerde plan-entries.
	 */
	private function normalize_plan( array $entries, array $keywords ): array {
		// 1. Verzamel alle gebundelde synoniemen, zodat we losse duplicaten kunnen droppen.
		$bundled = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			foreach ( (array) ( $entry['bundled_keywords'] ?? [] ) as $b ) {
				if ( is_string( $b ) && '' !== trim( $b ) ) {
					$bundled[ $this->norm( $b ) ] = true;
				}
			}
		}

		$plan = [];
		$seen = [];
		foreach ( $entries as $entry ) {
			$norm = $this->normalize_entry( $entry, $keywords );
			if ( null === $norm ) {
				continue;
			}
			$lc = $this->norm( $norm['keyword'] );

			// Een zoekwoord dat elders als synoniem in een pillar gebundeld is, hoort niet
			// óók als eigen rij — ongeacht welke rol de AI eraan gaf (soms labelt 'ie een
			// gebundeld synoniem onterecht als "pillar"). Uitzondering: de échte pillar die
			// zélf een bundel bezit, blijft behouden.
			$owns_bundle = ( 'pillar' === $norm['role'] && ! empty( $norm['bundled_keywords'] ) );
			if ( isset( $bundled[ $lc ] ) && ! $owns_bundle ) {
				continue;
			}
			if ( isset( $seen[ $lc ] ) ) {
				continue; // dubbele entry van de AI
			}
			$seen[ $lc ]  = true;
			$plan[]       = $norm;
		}

		// 2. Vangnet: elk input-zoekwoord dat noch een eigen entry kreeg, noch gebundeld is,
		//    krijgt een entry zodat er niets stilletjes verdwijnt (handmatig na te kijken).
		foreach ( $keywords as $lc => $meta ) {
			if ( isset( $seen[ $lc ] ) || isset( $bundled[ $lc ] ) ) {
				continue;
			}
			$plan[] = [
				'keyword'          => $meta['keyword'],
				'volume'           => $meta['volume'],
				'intent'           => 'informatief',
				'cluster'          => 'overig',
				'role'             => 'supporting',
				'pillar_ref'       => null,
				'bundled_keywords' => [],
				'angle'            => '',
				'funnel_target'    => null,
				'bridge'           => '',
				'link_target_hint' => '',
				'reason'           => __( 'Automatisch toegevoegd (ontbrak in het plannerantwoord) — controleer de classificatie.', 'digitale-bazen-ai-module' ),
			];
			$seen[ $lc ] = true;
		}

		return $plan;
	}

	/**
	 * Valideer één AI-entry tegen de toegestane waarden en het input-zoekwoord.
	 *
	 * @return array|null  Genormaliseerde entry, of null als onbruikbaar.
	 */
	private function normalize_entry( $entry, array $keywords ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$keyword = trim( (string) ( $entry['keyword'] ?? $entry['zoekwoord'] ?? '' ) );
		if ( '' === $keyword ) {
			return null;
		}
		$lc = $this->norm( $keyword );
		if ( ! isset( $keywords[ $lc ] ) ) {
			return null; // AI verzon een zoekwoord dat niet in de input zat
		}
		$meta = $keywords[ $lc ];

		$intent = (string) ( $entry['intent'] ?? '' );
		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			$intent = 'informatief'; // meest toegeeflijke default (blog)
		}

		$is_blog = ( 'informatief' === $intent );

		$role = (string) ( $entry['role'] ?? '' );
		if ( ! $is_blog ) {
			$role = '—';
		} elseif ( ! in_array( $role, [ 'pillar', 'supporting' ], true ) ) {
			$role = 'supporting';
		}

		$cluster = $is_blog ? trim( (string) ( $entry['cluster'] ?? '' ) ) : '';

		$pillar_ref = null;
		if ( 'supporting' === $role ) {
			$ref = trim( (string) ( $entry['pillar_ref'] ?? '' ) );
			$pillar_ref = '' !== $ref ? $ref : null;
		}

		$bundled = [];
		if ( 'pillar' === $role ) {
			foreach ( (array) ( $entry['bundled_keywords'] ?? [] ) as $b ) {
				if ( is_string( $b ) && '' !== trim( $b ) ) {
					$bundled[] = trim( $b );
				}
			}
		}

		$hint = ( ! $is_blog ) ? trim( (string) ( $entry['link_target_hint'] ?? '' ) ) : '';

		// Funnel-brug (§0O). Voor informatief: de inkaderende hoek van DEZE blog.
		// Voor commercieel/lokaal: een OPTIONELE informatieve blog-invalshoek over
		// hetzelfde onderwerp die naar de dienst funnelt (bv. responsive → "wat is
		// een responsive website"). Leeg als er geen eerlijke invalshoek is.
		$angle  = trim( (string) ( $entry['angle'] ?? '' ) );
		$bridge = trim( (string) ( $entry['bridge'] ?? '' ) );
		$funnel_target = null;
		$ft = $entry['funnel_target'] ?? null;
		if ( is_string( $ft ) ) {
			$ft = trim( $ft );
			$funnel_target = ( '' !== $ft && 'null' !== strtolower( $ft ) ) ? $ft : null;
		}

		return [
			'keyword'          => $meta['keyword'], // canonieke schrijfwijze uit de input
			'volume'           => $meta['volume'],
			'intent'           => $intent,
			'cluster'          => $cluster,
			'role'             => $role,
			'pillar_ref'       => $pillar_ref,
			'bundled_keywords' => $bundled,
			'angle'            => $angle,
			'funnel_target'    => $funnel_target,
			'bridge'           => $bridge,
			'link_target_hint' => $hint,
			'reason'           => trim( (string) ( $entry['reason'] ?? '' ) ),
		];
	}

	/** Genormaliseerde sleutel voor case-/spatie-ongevoelige vergelijking. */
	private function norm( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ) ) : strtolower( trim( $s ) );
	}
}
