=== Digitale Bazen AI Module ===
Contributors: digitalebazen
Tags: ai, blog, generator, seo, acf, rankmath
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.1.0
License: Proprietary

Genereer SEO-blogposts met AI op basis van zoekwoordenonderzoek.

== Description ==

Onder **Blogs → AI Blog Genereren** krijgen redacteuren
een 1-klik flow:

1. Upload xlsx/xls/csv/ods met zoekwoordenonderzoek (verplichte kolom-mapping
   naar `Zoekwoord` via een wizard met auto-suggesties).
2. Kies een hoofdzoekwoord.
3. De plugin roept Anthropic Claude aan, valideert
   de output tegen het bestaande ACF flexible-content schema (`paginacontent`),
   downloadt featured + block-afbeeldingen via Pexels (fallback Unsplash),
   schrijft RankMath SEO velden en injecteert FAQPage JSON-LD voor élke post
   met een `veelgestelde_vragen` block (site-breed).
4. De draft verschijnt onder Blogs ter review en handmatige publicatie.

Configuratie via **Instellingen → AI Module**: API keys, provider
keuze, tone of voice, business-context, stijlregels, referentie-posts,
en welke ACF-layouts de AI mag gebruiken.

Daglimiet per gebruiker: 10 generaties (filterbaar).

== Requires ==

* WordPress 6.0+
* PHP 7.4+
* ACF Pro (met minstens één field group die een flexible content veld bevat — kiesbaar in Instellingen → AI Module)
* RankMath SEO (free of Pro)
* API keys via Settings-page of `wp-config.php` constants:
    * `DB_AI_ANTHROPIC_API_KEY` (verplicht)
    * `DB_AI_PEXELS_API_KEY` (verplicht)
    * `DB_AI_UNSPLASH_API_KEY` (optioneel — fallback)

Optionele constants:

* `DB_AI_GITHUB_REPO_URL` — voor auto-update vanuit een eigen GitHub repo
* `DB_AI_GITHUB_TOKEN` — Personal Access Token voor private GitHub repo

== Filters ==

* `db_ai_post_type`, `db_ai_admin_menu_parents`
* `db_ai_anthropic_model`, `db_ai_anthropic_max_tokens`
* `db_ai_system_prompt`, `db_ai_user_prompt`
* `db_ai_image_orientation`, `db_ai_rate_limit_per_day`, `db_ai_allowed_layouts`
* `db_ai_reference_post_types`
* `db_ai_field_group_key`, `db_ai_flex_field_name`, `db_ai_always_empty_fields`
* `db_ai_github_repo_url`, `db_ai_update_branch`

== Actions ==

* `db_ai_before_generate( $main_keyword, $secondary, $user_id )`
* `db_ai_after_ai_response( $ai_output, $main_keyword )`
* `db_ai_after_post_created( $post_id, $ai_output, $user_id )`
* `db_ai_generation_failed( $wp_error, $main_keyword, $user_id )`

== Changelog ==

= 3.1.0 =
* **Aanbevelingen-paneel op het Plan ("Aanbevolen om nu te maken"):** toont de
  beste volgende artikelen, transparant onderbouwd. Strategie: maak eerst een
  gestart cluster áf (topical authority), start daarna het cluster met de hoogste
  totale **clusterwaarde** (som van het zoekvolume in dat cluster); binnen elke
  tier weegt clusterwaarde → eigen zoekvolume → funnel-waarde, met pillar-eerst.
  Elke aanbeveling heeft een directe "Genereer"-knop en evolueert mee terwijl je
  genereert.
* **Fix — cluster zonder pillar:** een cluster met alleen supporting-rijen (kon
  ontstaan door batch-verwerking) zat muurvast. `reconcile_pillars` promoveert nu
  de hoogste-volume entry van zo'n cluster tot pillar, en een opgeslagen plan wordt
  bij het openen automatisch hersteld (geen her-analyse / tokens nodig).

= 3.0.0 =
* **Strategische Plan-laag (MAJOR) — van zoekwoordenonderzoek naar contentplan:**
  - **Eigen top-level menu "Generator"** met submenu's **Creatie / Plan / Instellingen**
    (was: generator onder Blogs + instellingen onder Instellingen). Oude bookmarks
    redirecten. Filters: `db_ai_admin_menu_parent` / `_icon` / `_position` / `_capability`
    (de oude `db_ai_admin_menu_parents` is deprecated).
  - **Plan-overzicht (`DB_AI_Planner` + `DB_AI_Plan_Page`):** "Analyseer onderzoek" zet
    je zoekwoorden via de job-queue om in een plan — geclusterd op zoekintentie, met per
    cluster één **pillar** (bundelt synoniemen) en **supporting**-artikelen (eigen deelvraag
    met eigen volume). Grote lijsten worden in batches verwerkt (timeout-proof).
  - **Functie-gebaseerde classificatie:** oriënterende termen (ook met commerciële
    ondertoon) → blog die naar de dienst linkt; pure koop-/lokale termen → vaste pagina.
  - **Funnel-brug:** per informatief keyword een inkaderende `angle` + `funnel_target` +
    eerlijke `bridge` (inline bewerkbaar). En een **blog-companion** voor vaste pagina's:
    een optionele informatieve invalshoek die naar de dienst funnelt.
  - **Genereren vanuit Plan:** geeft cluster-rol, pillar-link (supporting → pillar
    automatisch), avoid-overlap en de funnel-hoek mee via `DB_AI_Blog_Input`
    (`DB_AI_Post_Creator` ongewijzigd). Status volgt: open → gegenereerd → gepubliceerd.
  - **Verwante artikelen:** gebundelde synoniemen kun je optioneel tóch als verwant
    supporting-artikel genereren.
* **Onderzoek update-in-place:** een nieuwe upload ververst de zoekwoorden van het
  bestaande onderzoek — contentplan + statussen + post-koppelingen blijven behouden.
* **Plan export/import:** exporteer een plan als JSON en importeer het op een andere
  site zonder opnieuw te hoeven analyseren (scheelt tokens). Generatie-status reset.

= 2.2.2 =
* **RankMath-bridge vervuilde global $post op het bewerkscherm (fix):**
  - Bij het bewerken van een pagina opende soms een custom post type (bijv. een
    medewerker) i.p.v. de pagina zelf. Oorzaak: de bridge rendert de echte
    front-end-blokken zodat RankMath de content kan analyseren, maar de
    blok-templates draaien eigen `WP_Query`-loops die `global $post` niet
    resetten. Daardoor matchte ACF de verkeerde veldgroepen.
  - `render_via_theme_templates()` bewaart nu de globale post-context vóór het
    renderen en herstelt die erna (ook bij een fout in een template).

= 2.2.1 =
* **Zoekwoorddichtheid omhoog (RankMath bleef "te laag" melden):**
  - De prompt maakt nu expliciet dat RankMath de dichtheid UITSLUITEND op het
    exacte focus keyword meet — varianten/synoniemen tellen niet mee voor die
    meter (die zijn nu "extra", niet "in plaats van"). Dit was de hoofdoorzaak
    dat de dichtheid laag bleef.
  - Doel verhoogd van ±1% naar 1,8-2,2% van het exacte keyword (≈ 50-65×
    bij 2500-3200 woorden), als ondergrens-doel, met vangrails: nooit onder
    ~1,5% en niet boven ~2,5% (over-optimalisatie).

= 2.2.0 =
* **AI-gegenereerde afbeeldingen via Google Gemini (nieuw):**
  - Nieuwe Settings-tab **Afbeeldingen** met een keuze voor de afbeeldingsbron:
    `Stockfoto's` (Pexels/Unsplash — huidig gedrag, default), `AI: alleen coverfoto`
    of `AI: alle afbeeldingen`. Plus een **beeldstijl**-veld dat vóór elke
    generatie-prompt wordt geplakt voor een consistente huisstijl.
  - Gebruikt het Gemini 2.5 Flash Image-model (`gemini-2.5-flash-image`); base64-output
    wordt direct in de mediabibliotheek gesideload. Aspect ratio per rol (coverfoto 16:9,
    blok-afbeeldingen 4:3).
  - **Automatische fallback naar stock** als een generatie mislukt, zodat een blog nooit
    zonder beeld komt te zitten. Alt-teksten blijven behouden (RankMath).
  - Nieuwe `DB_AI_GEMINI_API_KEY` constant / Gemini API-key veld onder API-keys.
  - Filters: `db_ai_image_source`, `db_ai_gemini_image_model`, `db_ai_gemini_image_prompt`,
    `db_ai_gemini_aspect_ratio`.
* **Eén zoekwoordenonderzoek tegelijk:** een nieuwe upload vervangt voortaan het
  bestaande onderzoek. De generator laadt het ene onderzoek automatisch bij openen
  (geen dropdown meer); de Instellingen-UI is naar enkelvoud aangepast.
* **Timeout-fix lange generaties:** de Anthropic-call had een vaste 120s-timeout, wat
  bij uitgebreide blogs (20k max_tokens, niet-streamend) "cURL error 28 — 0 bytes
  received" gaf. Verhoogd naar 300s en filterbaar via `db_ai_anthropic_http_timeout`.

= 2.1.2 =
* **Langere, diepgaandere blogs:**
  - **Streeflengte verhoogd** — de generator mikt nu standaard op een diepgaand,
    volledig artikel van 2500-3200 woorden (9-13 blocks) i.p.v. 1200-1800 woorden.
    Eerst alle relevante invalshoeken bedenken (achtergrond, aanpak, kosten,
    valkuilen, voorbeelden, FAQ) en die met echte diepgang uitwerken.
  - **Geen filler** — expliciete instructie dat lengte nooit met herhaling of
    holle frasen gevuld mag worden; kwaliteit gaat vóór woordenaantal. Smalle
    onderwerpen mogen korter (1500-2000 woorden) blijven.
  - **Zoekwoorddichtheid schaalt mee** — ±1% dichtheid blijft het doel, maar
    schaalt nu mee met de lengte (~25-32 vermeldingen bij 2500-3200 woorden).
  - **max_tokens 16k → 20k** — comfortabele headroom voor de langere output
    zodat artikelen niet afgekapt worden.

= 2.1.1 =
* **SEO-prompt aangescherpt op RankMath-feedback:**
  - **Zoekwoorddichtheid** — de generator mikt nu op ±1% dichtheid (hoofdzoekwoord
    of variant ~12-16× verspreid door de body, natuurlijk verweven). Voorkomt de
    "keyword density te laag"-melding bij blogs die het keyword maar enkele keren
    noemden.
  - **Afbeelding-alt** — de `featured_image.alt` bevat nu verplicht het exacte
    hoofdzoekwoord, zodat RankMath's "afbeelding met focus keyword als alt"-check
    slaagt. Block-afbeeldingen blijven gevarieerd.

= 2.1.0 =
* **Layout-calibratie (nieuw)** — een nieuwe Settings-tab "Layout-calibratie".
  De generator analyseert je theme-templates en schrijft per blok hoe het eruitziet
  en wanneer je het inzet; je controleert/bewerkt dit en slaat het op. Deze guidance
  gaat mee in elke generatie-prompt zodat blokken gerichter gevuld worden.
  - **Fase 1 (deterministisch):** `DB_AI_Layout_Calibration` leidt uit de templates
    af welke velden bij welke `weergave` renderen. Lost op dat de generator content
    in een veld zet dat de gekozen weergave niet toont (lege kolommen/secties), bv.
    `tekst_weergaves` → `tekst-alternatief` rendert de body uit `tekst_kolom_2`, niet
    `tekst`. Confidence-gating voorkomt foute guidance bij lastig te parsen templates.
  - **Fase 2 (AI + review):** een "Calibreren"-knop laat Claude per layout
    stijl/gebruik-guidance schrijven op basis van templates, het echte kleurpalet en
    enkele bestaande pagina's; bewerkbaar en op te slaan in Settings. Staleness-
    waarschuwing als de templates sinds de laatste calibratie zijn gewijzigd.
  - **Kleurpalet-extractie:** leest de echte merkkleuren + semantische toewijzingen
    uit de theme-stylesheet (ook `.less`), zodat de generator weet welke kleuren waar
    voor gebruikt worden.
* **FAQ-`antwoord` ondersteunt flexible_content** — het `antwoord`-veld is in ACF
  omgezet van wysiwyg naar een flexible_content (layouts tekst/afbeelding/button).
  De mapper wikkelt de AI-string nu in een tekst-flexrij; FAQ-schema en RankMath-
  bridge lezen de tekst uit de flex-rijen (loste een "Array to string conversion"-
  warning op). Oude string-antwoorden blijven werken.
* **Robuustere JSON-verwerking van AI-output** — de Anthropic-provider pakt nu het
  buitenste JSON-object, escapet rauwe control-chars en geeft bij falen een precieze
  melding (incl. json-foutmelding en stop_reason; aparte melding bij afkapping op
  max_tokens). Prompt scherpgesteld: HTML-attributen met enkele quotes zodat ze niet
  botsen met de JSON-string-delimiters (voorkomt "geen geldig JSON-object").

= 2.0.7 =
* **CTA-buttons worden nu door de generator gevuld** — ACF `link`-velden
  (zoals `button`, `button_2`) waren tot v2.0.6 onvoorwaardelijk leeg na
  sanitize (defensieve default tegen verzonnen URLs). De generator mag ze
  nu invullen, maar UITSLUITEND met URLs uit `internal_link_pool` (de
  bestaande-posts-feed). Verzonnen of externe URLs worden door
  `DB_AI_ACF_Mapper::sanitize_link_value()` automatisch gestript naar een
  leeg link-veld. Post-creator geeft de pool als whitelist door via
  `set_allowed_link_urls()` vóór de write. Prompt-update in
  `DB_AI_Internal_Links::get_prompt_addition()` legt de generator het
  link-veld-formaat (`{title, url, target}`) uit.
* **DUPLICATEN-regel toegevoegd** — elke afzonderlijke pool-URL mag max 2×
  per blog gebruikt worden (wysiwyg-anchors + CTA-buttons samen geteld),
  liefst 1× per URL als er variatie in het pool is. Voorkomt dat
  bijvoorbeeld "/werkwijze/" 4× in dezelfde blog opduikt — spammerig en
  slecht voor SEO.
* **`DEFAULT_MAX_TOKENS` 8000 → 16000** — de v2.0.6-prompt is fors gegroeid
  (past-blogs-lijst + uitgebreide externe-bronnen + CTA-button-instructies +
  JSON-output dat nu ook buttons bevat). Bij 8000 output-tokens werden
  generaties afgekapt op `"meta_description":` zonder waarde. Sonnet 4.6
  ondersteunt tot 64k output; 16000 geeft comfortabele headroom zonder
  kostenrisico (pricing per daadwerkelijk gebruikte token, niet per cap).
* **`ALWAYS_EMPTY_FIELDS` opgeschoond** — `button`/`button_2` zijn uit de
  hardcoded lijst gehaald. Wat blijft: `banner.mobiele_afbeelding` (fallback
  naar `afbeelding`) en `usps.usps.icoon_content` (V1 niet ondersteund). De
  auto-detectie *"alle link-type velden → always empty"* is ook weg uit
  `compute_always_empty_for()`; sanitize doet nu per veld de whitelist-check.

= 2.0.6 =
* **FAQ-prompt is nu conditioneel op layout-beschikbaarheid** — als er geen
  `veelgestelde_vragen`-achtige layout in de toegestane lijst staat, krijgt
  de generator nu expliciet *"voeg GEEN FAQ-blok toe"* mee. Voorkomt dat de
  AI nog FAQ-content bedenkt voor blogs waar je dat blok niet wilt.
* **Validatie-warnings ontdubbeld** — voor een lege required repeater kreeg
  je twee meldingen voor exact dezelfde root-cause (*"verplicht veld X
  ontbreekt"* + *"repeater X moet minstens 1 item bevatten"*). Nu nog maar
  één, de specifiekere "minstens X items vereist".
* **ACF `conditional_logic` wordt gerespecteerd** — een veld dat ACF als
  required markeert maar dat alleen geldt voor een specifieke weergave
  (bv. `afbeeldingen_alternatief` bij `weergave == alternatief`) wordt
  niet meer als spookmelding gerapporteerd wanneer een andere weergave
  is gekozen. Validator, repeater-min-check en de spec naar de AI nemen de
  conditional-flag nu mee.
* **Banner-/hero-blok alleen als eerste blok** — nieuwe HARDE REGEL in de
  prompt: een banner-/hero-/intro-layout mag UITSLUITEND op index 0 in de
  blocks-array voorkomen. Voor body-content in het midden van een blog
  worden andere layouts gebruikt.
* **Externe-link insert: write daadwerkelijk persistent** — twee problemen
  in het insert-pad zijn opgelost. (1) De rows worden voor `update_field`
  genormaliseerd van field-key-keys naar field-name-keys, anders schreef
  ACF op sommige sites stilletjes niets weg. (2) Na een succesvolle insert
  wordt de pagina automatisch herladen (na 1.8s) zodat de editor-form-state
  ververst en de volgende "Update"-klik de link niet meer overschrijft.
  Plus eerlijker resultaat-melding: als de rauwe DB-check zegt dat de URL
  er niet in staat, wordt de status van 'ok' gedowngrade naar 'failed' met
  duidelijke uitleg — geen misleidend groen meer.
* **Eerder gegenereerde blog-titels worden meegegeven aan de AI** — nieuwe
  `DB_AI_Past_Blogs_Context`-helper haalt de 20 recentste blog-titels +
  focus-keywords op en stopt ze in de user-prompt met de instructie om een
  ander getal, andere structuur en niet-overlappende invalshoek te kiezen.
  Voorkomt de "elke blog 7 stappen"-herhaling. Filterbaar via
  `db_ai_past_blogs_limit`.
* **Kortere blog-titels als default** — nieuwe regel in de SEO-richtlijnen:
  post-titel max 60 tekens, mik op 40-55. Vulwoorden als *"voor MKB"*,
  *"in 2026"*, *"voor meer resultaat"* horen in de meta-description, niet
  in de titel. Voorkomt afkapping in Google en oogt minder log.
* **Diversere externe-link-bronnen** — de externe-bronnen-prompt is grondig
  herschreven. Wikipedia is geen default meer (max 1× per blog, alleen als
  laatste redmiddel), domein-diversiteit is verplicht (geen 2 suggesties
  van hetzelfde domein), en branche-specifieke autoriteiten (ahrefs, moz,
  semrush, thuiswinkel.org, marketingfacts, bouwendnederland, etc.) staan
  expliciet bovenaan de hiërarchie. Per onderwerp de meest specialistische
  autoriteit kiezen i.p.v. altijd Wikipedia.

= 2.0.5 =
* **Validatiefouten verspillen geen volledige generatie meer** — voorheen
  werd de hele blog afgekeurd zodra één blok niet aan het schema voldeed
  (bv. een repeater met te weinig items), waardoor de redacteur opnieuw
  moest laten genereren en alle tokens kwijt waren. Nu wordt de draft
  alsnog aangemaakt zolang er minimaal een titel + één blok met toegestane
  layout is; de overige validatiefouten verschijnen als warnings (prefix
  *"Aanvullen in editor: …"*) zodat de redacteur ze in de editor met de
  hand kan oplossen. Hard-fail blijft alleen over voor écht onbruikbare
  output (geen titel of geen enkel valide blok).
* **`min_items` / `max_items` per repeater meegegeven aan de AI** — de
  validator las ACF's `min`/`max`-flag al sinds v1.2.0, maar gaf die
  waarde niet door aan de AI. Daardoor wist de generator niet hoeveel
  items een repeater minimaal moest hebben en faalde generaties op fouten
  als *"repeater X heeft 1 items, minimaal 2 vereist"*. De layout-spec
  bevat nu `min_items`/`max_items` per repeater en de prompt heeft een
  KRITIEK-blokje dat uitlegt wat die keys betekenen.
* **FAQ-heading-niveau wordt per blok bepaald i.p.v. per onderwerp** —
  voorkomt mixed-case waarin vragen van een titelloos onderwerp op H3
  stonden terwijl een later `onderwerp_titel` ook H3 was. Heeft het blok
  ergens een onderwerp_titel? Dan zijn alle vragen H4, anders H3.
  Spiegelt de theme-aanpassing in `paginablokken/veelgestelde_vragen.php`.

= 2.0.4 =
* **RankMath-bridge rendert nu de échte theme-templates** — voorheen bouwde de
  bridge een hardcoded HTML-mirror per layout (`banner`/`tekst_met_afbeelding`/
  `usps`/etc.). Dat hield in: bij elke theme-aanpassing aan heading-niveaus of
  nieuwe blok-velden moest de plugin mee bijgewerkt worden, en blokken die
  niet in de mirror stonden (`slider`, `videos`, `partners`, `module_overzicht`,
  `tekst_met_formulier`, `module_slider`) waren onzichtbaar voor RankMath's
  content-analyzer. De bridge roept nu via `have_rows() → the_row() →
  get_template_part('paginablokken/{layout}')` de werkelijke theme-templates aan
  en buffert hun output. RankMath ziet daardoor 1-op-1 wat de bezoeker ook
  ziet, en theme-wijzigingen werken automatisch door.
* **Fallback naar de bestaande mirror** als template-rendering geen output
  geeft (theme zonder `paginablokken/`-map, fatal in een template, lege flex-
  data). Beschermt sites die niet de Digitale Bazen template-structuur volgen.
* **FAQ-bridge bijgewerkt op de nieuwe heading-hiërarchie** — `onderwerp_titel`
  is in de fallback nu H3 i.p.v. H4 (sluit het oude H2→H4-gat), en de vragen
  worden conditioneel H3 (zonder onderwerp_titel) of H4 (met onderwerp_titel)
  i.p.v. `<p><strong>`. Spiegelt de theme-aanpassing in
  `paginablokken/veelgestelde_vragen.php` + `functions.php get_faq_item()`.

= 2.0.3 =
* **Stijl-streepjes hard verwijderd uit AI-tekst** — de generator gebruikte van
  zichzelf graag em-dashes (—) als zinsonderbreking ("X — ook wel Y — is..."),
  wat heel AI-gegenereerd oogt. Naast een strikte prompt-regel staat er nu een
  post-processing vangnet (`DB_AI_ACF_Mapper::strip_style_dashes()`) dat alle AI-
  tekst opschoont: em-dash, en-dash en " - " als gedachtestreepje worden komma's,
  numerieke reeksen ("1200–1800", "9:00 - 17:00") worden een koppelteken,
  en koppeltekens binnen woorden (`e-mail`, `SEO-tips`, `MKB-ondernemers`) blijven
  als correcte Nederlandse spelling staan. Toegepast op blok-velden, post-titel,
  excerpt én de RankMath meta-titel/omschrijving.
* **Kortere blokken + wervender banner als default** — de prompt vraagt nu
  expliciet om korte tekst per blok (max 2-3 alinea's van 2-4 zinnen) en om
  inhoud te verdelen over één extra blok in plaats van lange blocks te
  proppen. Blok-aantallen opgehoogd (4-5 voor simpele, 6-8 voor brede
  onderwerpen). De banner/hero krijgt nu de instructie kort en wervend te
  zijn: één pakkende alinea van 1-3 zinnen die nieuwsgierig maakt, geen
  volledige uitleg. Totale woord-target (1200-1800) blijft gelijk.
* **Quote-blokken centraal uitgesloten van generatie** — de AI vulde op sites
  met een `quote`/`testimonial`/`review`-layout fabricated klantcitaten + namen
  in (nep sociaal bewijs). Nieuwe filter (`db_ai_blocked_layout_pattern`,
  default `quote|testimonial|citaat|aanbeveling|review`) strijkt die layouts
  altijd uit de toegestane lijst, ongeacht de per-site Settings-keuze. Werkt
  ook in de async-worker (late prio 99 op `db_ai_allowed_layouts`).
* **Fix: layout-checkboxen sloegen site-eigen layouts niet op** — pre-existing
  bug sinds v1.1.0. Render gebruikte sinds toen auto-detectie uit ACF, maar
  save intersect-te nog tegen een hardcoded V1-lijst van 6 layoutnamen.
  Vink je iets aan dat daar niet in zat (zoals `quote` of `cta`), dan zei
  Settings "opgeslagen" maar bij refresh was het vinkje weer leeg. Save
  gebruikt nu dezelfde auto-detectie als render.
* **V1-fallbacklijst (`LAYOUT_LABELS`) volledig verwijderd** — sinds v1.1.0
  site-agnostisch overbodig en juist misleidend. Bij een onontdekte ACF
  field group toont Settings nu de bestaande "Geen layouts gevonden — kies
  eerst een ACF field group..."-melding (was voorheen onbereikbaar omdat de
  fallback hem overschreef).

= 2.0.2 =
* **OpenAI-provider verwijderd** — de generator gebruikte in de praktijk alleen
  Anthropic Claude; OpenAI stond er nog als tweede provider + fallback en is nu
  volledig weg. De "AI-dienst"-keuze in Instellingen is verdwenen (de tab heet
  nu "API-keys"), net als het OpenAI-key-veld, de `DB_AI_PROVIDER` constant en de
  `db_ai_openai_*` filters. De `DB_AI_Provider` interface blijft als basis voor
  een eventuele toekomstige provider. Let op: een site zonder Anthropic-key valt
  hierdoor stil — stel `DB_AI_ANTHROPIC_API_KEY` in.
* **Generator-submenu alleen nog onder Blogs** — stond eerder zowel onder
  Berichten als Blogs; nu alleen onder Blogs. Blijft filterbaar via
  `db_ai_admin_menu_parents` als je hem ergens anders wilt.

= 2.0.1 =
* **Fix: layout-voorkeur werd genegeerd in de async-worker** — de
  `db_ai_allowed_layouts` filter werd alleen in admin-context geregistreerd,
  maar de v2.0.0 worker draait buiten admin (Action Scheduler / WP-Cron). Daardoor
  viel de generatie terug op álle layouts ongeacht de Settings-keuze. `DB_AI_Settings`
  wordt nu altijd geïnstantieerd zodat de filter ook in de worker actief is; de
  admin-UI hooks blijven intern achter `is_admin()` gegated (geen frontend-overhead).
* **Prompt-hardening: AI gebruikt nooit meer een uitgesloten layout** — de
  structuur-instructie kreeg een harde regel ("gebruik UITSLUITEND de beschikbare
  layouts, een layout die er niet bij staat mag NOOIT in de output") en noemt niet
  langer specifiek "banner/hero" als voorbeeld. Voorkomt validatiefouten wanneer je
  een layout (zoals banner) bewust uitsluit. Beide providers.

= 2.0.0 =
* **Async generatie (architectuur-wijziging)** — de blog-generatie draait nu in
  een achtergrond-job in plaats van een synchrone AJAX-request. Lost de
  productie-timeouts op (504 / FastCGI idle / Cloudflare / PHP max_execution_time):
  de browser krijgt direct een job-id en polled de status, terwijl een worker
  de generatie afhandelt zonder browser-connectie. Geen "Netwerkfout" meer bij
  blogs die 30-60s duren.
  - Nieuw `wp_db_ai_jobs` tabel + `DB_AI_Job_Queue` (dispatch / status / run /
    progress / janitor / cleanup), eigen migratie-versie.
  - Runner: gebruikt Action Scheduler indien aanwezig (RankMath bundelt 'm),
    anders WP-Cron single-event als fallback. Beide via dezelfde `db_ai_run_job`.
  - `db_ai_generate` returnt nu een `job_key`; nieuw `db_ai_job_status`
    poll-endpoint. JS polled elke 2,5s.
  - Rate-limit reservering bij dispatch (in-flight jobs tellen mee tegen de
    daglimiet) zodat queue-stacking de limiet niet omzeilt.
  - Janitor-cron markeert vastgelopen jobs (> 5 min zonder voortgang) als failed;
    cleanup-cron ruimt afgeronde (> 30d) en gefaalde (> 7d) jobs op.
* **Echte progress-bar** — vervangt de geschatte curve van v1.4.0. De bar leest
  nu de werkelijke server-voortgang (8 checkpoints: context → schrijven →
  valideren → aanmaken → afbeeldingen → blocks → afronden) met live stage-labels.
* **Behavior-parity** — alle bestaande functionaliteit werkt identiek: ACF
  blocks, featured + block-afbeeldingen, RankMath SEO, FAQ JSON-LD, interne +
  externe links, `_db_ai_*` meta, quota-teller. De generatie-logica in
  `DB_AI_Post_Creator` is ongewijzigd; alleen de aanroep-context (worker) en
  progress-reporting zijn toegevoegd.

= 1.4.0 =
* **Wizard van 5 → 3 stappen** — de generator-page heeft nu alleen Upload →
  Kies zoekwoord → Genereer als zichtbare stappen. Alle optionele velden
  (funnel-fase, awareness-niveau, must-include, must-avoid, beat-competition,
  forced internal links, extra instructies) staan gegroepeerd onder één
  collapsible "Geavanceerd (optioneel)"-toggle in stap 3. Default dicht. Happy
  path is nu drie clicks. Power-users klappen open voor extra sturing.
* **Progress bar tijdens generatie** — vervangt de eerdere spinner-tekst. Toont
  een asymptotische voortgang (curve `1 - exp(-1.2t)`, capped op 95% tot
  AJAX-respons) met vier stage-labels per zone: "Zoekwoord-context verzamelen"
  → "Generator schrijft je blog" → "Afbeeldingen ophalen" → "Blog aanmaken en
  blokken vullen" → "Bijna klaar". Bij succes/fail snap naar 100% in groen/rood,
  daarna weg na 1,2s.
* **"Type content"-keuze verwijderd uit UI** — generator produceert nu uitsluitend
  blogs. Server-side wordt `type_content = 'blog'` geforceerd in
  `DB_AI_Ajax::collect_blog_input()` zodat de CONTENTTYPE-hint nog steeds in de
  AI-prompt belandt via `DB_AI_Blog_Input::TYPE_CONTENT_HINTS`. De TYPE_CONTENT_HINTS
  array blijft staan voor toekomstige uitbreiding.
* **Cache-buster met `filemtime()`** voor `assets/admin.css` + `admin.js` in
  `DB_AI_Admin_Page::register_assets()`. Voorkomt dat browsers oude assets serven
  na een code-tweak binnen dezelfde plugin-versie — geen hard-refresh meer nodig
  in development.
* **Settings copy gepolijst** — alle tab-intros, section-intros en field-
  descriptions consistent in "de generator" idioom (was mix van "AI", "de plugin",
  "system prompt"). Menu-label en page-titel "AI Module" → "Generator". Tabs
  Block-layouts/ACF/AI setup volledig herschreven naar gebruikersvriendelijke
  toon. ACF-veld "Flex field binnen field group" → "Flex content veld".

= 1.3.0 =
* **RankMath bridge** (`includes/class-db-ai-rankmath-bridge.php` + `assets/rankmath-bridge.js`):
  hookt via `wp.hooks.addFilter('rank_math_content', ...)` op RankMath's
  content-analyzer en feedt hem een gerenderde HTML-versie van de ACF flex.
  Lost de "Focus keyword in subkop(pen)" en density-check op voor sites die
  `paginacontent` via directe template-include renderen (geen `the_content`-
  filter). Mirrort frontend heading-niveaus: banner→h1, tekst_met_afbeelding/
  tekst_weergaves/usps/veelgestelde_vragen→h2, onderwerp_titel→h4.
* **Externe link advisor**: nieuwe Settings-tab "Externe bronnen" met aan/uit
  toggle (default aan) en max-aantal (2-5). AI genereert tijdens elke blog
  3-5 link-suggesties naar autoritaire bronnen (Wikipedia, overheid, branche-
  organisaties), opgeslagen als `_db_ai_external_link_suggestions` post-meta.
  Nieuwe metabox "AI — Externe bronnen" op de post-edit-screen toont
  suggesties met HEAD-check status (✓ ok / ↪ redirect / ⏱ timeout / ✗ dead),
  redacteur kiest welke ingevoegd worden. AJAX-insert injecteert anchor-tag
  in het beste body-text veld via heuristische detectie (ACF type +
  veld-naam + content-detectie), met fallback-append als anchor niet
  inline gevonden.
* **Power-word prompt aangescherpt op RankMath NL-detected vormen**: de
  Anthropic + OpenAI system prompt gebruikt nu een gecureerde lijst van
  ~35 power-words die letterlijk in `seo-by-rank-math/assets/vendor/
  powerwords/nl.php` staan én B2B-veilig zijn. Verbogen varianten als
  `essentiële`, `ultieme`, `slimme` (die RankMath NIET detecteert) zijn
  expliciet verboden. Power-words zijn gegroepeerd per topic-toon zodat
  AI een passende keuze maakt per onderwerp.
* **Power-word + getal verplicht in meta_title én post-titel**: prompt-regel
  van "bij voorkeur" naar "MOET bevatten (beide)", met expliciete schrap-
  volgorde voor korte meta_titles (vulwoorden → getal → power-word als
  allerlaatste).

= 1.2.0 =
* **Interne links feature**: nieuwe Settings-tab "Interne links" met aan/uit
  toggle, max-aantal (2-5), en post-type-selector als linkbron. Generator-
  stap 4 krijgt optioneel een "Verplicht linken naar"-veld om specifieke
  pagina's te forceren. Top-15 relevance-gescoorde pagina's worden in de
  AI user-prompt geïnjecteerd; verzonnen URLs worden post-generatie
  opgeruimd via `clean_orphan_links()`.
* **Site-agnostische repeater-validatie**: hardcoded `REPEATER_RULES`
  constant verwijderd. Validator gebruikt nu ACF's eigen `required:1` flag
  per sub_field. Werkt op elke site ongeacht naming-conventie. Vroeger
  faalde validatie als sub_fields anders heetten dan `titel_content` /
  `tekst_content` (de Digitale Bazen-conventie); nu volgt de plugin de
  ACF-definitie automatisch.
* **Recursieve image-walker** in `DB_AI_Post_Creator::process_block_images`:
  detecteert image-objecten `{query, alt}` op elk nestingniveau (top-level,
  in repeaters, in nested repeaters) op basis van object-signatuur, niet
  op hardcoded layout-namen. Lost op dat images in `usps[].icon`,
  `tekst_met_afbeelding.afbeeldingen[].afbeelding` en andere nested
  posities niet werden gedownload.
* **Kritieke ACF write-fix**: `write_blocks_to_post` gebruikt nu de
  field-KEY in plaats van field-NAME bij `update_field()`. Voorkomt dat
  ACF naar de verkeerde field group schrijft als er meerdere groups met
  dezelfde flex-name bestaan (bv. `paginacontent` op meerdere CPTs).
  Veroorzaakte voorheen dat alle blocks leeg leken (alleen `acf_fc_layout`
  werd opgeslagen, alle data van de verkeerde group werd gedropt).
* **RankMath SEO-prompt strikter**: power-word verplicht in titel (uit
  concrete lijst), getal in titel waar logisch, hoofdzoekwoord in MINIMAAL
  2 `titel`-velden (H2/H3), meta_title MOET beginnen met focus keyword.
  Fixt 3 van de 4 RankMath-errors die nieuwe blogs eerder rapporteerden.
* **Field-name discipline in user prompt**: KRITIEK-blok dat de AI dwingt
  exacte ACF field-namen te gebruiken inclusief suffixen (`titel_content`,
  `tekst_content` etc.) en niet af te korten in repeaters.
* **Layout role-hints + diversiteits-regel**: prompt geeft per beschikbare
  layout een uitleg WANNEER hij zinvol is (cta/quote/video/case_detail/etc.)
  via regex-pattern matching op naam. Plus expliciete eis: minstens 4
  verschillende layouts per blog van 5-7 blocks, geen 3+ identieke achter
  elkaar. Doorbreekt AI-bias richting "veilige" tekst-only blogs.
* Nieuwe filter `db_ai_debug_write_blocks` (default false) voor opt-in
  diagnose-logging van write_blocks_to_post → update_field flow.

= 1.1.9 =
* Algemene instellingen fors uitgebreid: nieuwe tabs Bedrijfsinformatie
  (naam, branche, diensten, USP's, concurrenten), Doelgroep (bezwaren,
  frustraties, aankoopcriteria, taalniveau) en Anti-generiek toggles
  (AI mag mening / praktijkvoorbeelden / nadelen benoemen). Alle algemene
  velden worden in de AI system prompt geïnjecteerd zodat output beter
  past bij bedrijfscontext en doelgroep.
* Generator-flow herwerkt naar 5-staps wizard: Upload → Keyword →
  Basisinfo → Specifiek → Genereer. Stap 3+4 zijn optioneel en geven
  per-blog overrides: type content (blog/landing/FAQ/comparison/case/
  service), funnel-fase, awareness-niveau, must-include, must-avoid,
  "wat beter dan top-3 Google".
* Zoekwoordenonderzoeken zijn nu opslaan + herbruiken: nieuwe Settings-tab
  "Zoekwoorden" om onderzoeken eenmalig te uploaden en te beheren (CPT
  db_ai_kwo, niet publiek). Generator-stap 1 toont een dropdown van
  opgeslagen onderzoeken naast de bestaande directe upload als fallback.
  Naam-veld vult automatisch met de bestandsnaam, blijft aanpasbaar.
* Settings-page herwerkt van wizard-stappen naar pure tabs (klikbare
  tabs, sticky save-bar, geen "Vorige/Volgende"-knoppen meer) — past
  beter bij niet-sequentiële configuratie. Generator behoudt de wizard-
  flow want daar is volgorde wél betekenisvol.
* Nieuwe filter: `db_ai_reference_post_types` (al sinds 2.1, nu
  gedocumenteerd in DB_AI_Blog_Input helper class).
* Architectuur: DB_AI_Style_Profile injecteert algemene Settings in
  system prompt; nieuwe DB_AI_Blog_Input class injecteert per-blog input
  in user prompt. Onderscheid is bewust — algemene context is constant,
  per-blog instructies overrulen bij conflict.

= 1.1.8 =
* Updater: eenmalige cache-purge per versie-bump. Wist `update_plugins`
  transient + PUC's eigen option-cache wanneer `DB_AI_VERSION` is gewijzigd.
  Voorkomt `PCLZIP_ERR_BAD_FORMAT` na het vervangen van een release-asset
  op GitHub (nieuwe asset_id maakt oude gecachede download URL ongeldig).
  Zelfgenezend — geen wp-config of phpMyAdmin tweaks meer nodig wanneer
  een asset op een release wordt vervangen.

= 1.1.7 =
* Test release voor verificatie van de auto-update flow na handmatige
  installatie van 1.1.6. Geen functionele wijzigingen sinds 1.1.6.

= 1.1.6 =
* Settings-page: GitHub auto-update sectie + velden verwijderd. Token en
  repo-URL worden nu uitsluitend uit `wp-config.php` constants gelezen
  (`DB_AI_GITHUB_TOKEN`, `DB_AI_GITHUB_REPO_URL`). Auto-update gedrag
  ongewijzigd, schoner instellingenscherm voor eindgebruikers.

= 1.1.5 =
* Compatibility: getest met WordPress 7.0 (was 6.9). Geen code-wijzigingen,
  alleen `Tested up to` header bijgewerkt zodat de "niet getest met deze
  versie" waarschuwing verdwijnt.

= 1.1.4 =
* Test release voor verificatie van de auto-update flow met release-asset zip.
  Geen functionele wijzigingen sinds 1.1.3.

= 1.1.3 =
* **Fix auto-update voor private GitHub repos**: enableReleaseAssets() is nu
  aan in de updater. Source-tarball-download van GitHub werkt onbetrouwbaar
  voor private repos (auth-header wordt niet doorgegeven, geeft "Download
  mislukt. Not Found"). Per release moet nu een `digitale-bazen-ai-module*.zip`
  bestand als asset worden geüpload — zie DEPLOYMENT.md.

= 1.1.2 =
* Re-release om auto-update flow te testen. Geen functionele wijzigingen sinds 1.1.1.

= 1.1.1 =
* GitHub repo URL en Personal Access Token kunnen nu ook via Instellingen →
  AI Module → "GitHub auto-update" worden ingesteld. Constants in wp-config.php
  winnen nog steeds als die gedefinieerd zijn. Scheelt code-edits per klant-site
  voor multi-site distributie.
* Token wordt gemaskeerd weergegeven (••••••XYZW) en niet teruggetoond, zelfde
  pattern als de andere API keys.

= 1.1.0 =
* **Site-agnostisch ACF integratie**: niet meer hardcoded op één field group key.
  Settings-page (Instellingen → AI Module) heeft nu een ACF integratie sectie
  met dropdowns: "Welke ACF field group?" + "Welk flex field?". Plugin
  auto-detecteert alle field groups met flex content op activatie.
* Block-layout checkboxes worden nu dynamisch gegenereerd vanuit de gekozen
  field group, met de ACF-labels als display-naam. Werkt op elke site
  ongeacht naming conventions.
* AI system prompt + structuur-sectie zijn nu generiek: geen hardcoded
  layout-namen meer (banner/veelgestelde_vragen). AI ziet de beschikbare
  layouts en kiest zelf de juiste op basis van de layout-spec.
* Auto-detectie van ACF `link` velden als "always empty" — voorkomt dat de
  AI bogus URLs verzint.
* Nieuwe filters: `db_ai_field_group_key`, `db_ai_flex_field_name`,
  `db_ai_always_empty_fields` (met `$context` parameter).
* Dependency-check op activatie is gerelaxed: vereist alleen nog "ten minste
  één ACF field group met flex content", niet meer een specifieke key.

= 1.0.0 =
* Eerste stable release. Bundelt V1 (volledige generatie-flow), V2 (Excel
  import wizard met SheetJS + Settings-page voor API keys + test-endpoints
  verwijderd), V2.1 (Tone of Voice & content-context: brand voice,
  site-context, stijlregels, referentie-posts), V2.2 (block-layout
  checkboxes in Settings + AI bepaalt zelf aantal blocks op basis van
  topic-complexiteit).
* GitHub-based auto-updates via plugin-update-checker library.

= 0.1.0 =
* Initial V1 build (pre-release): CSV importer, AI generation, ACF flex
  write, image sideload, RankMath mapping, FAQ JSON-LD, logger + rate
  limiter.
