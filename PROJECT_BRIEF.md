# Digitale Bazen AI Module — WordPress Plugin

> **Voor de AI-assistent**: Dit document is een complete, zelfstandige werkopdracht. Lees het volledig voordat je begint. Alle architectuur-, naming- en scope-beslissingen zijn vastgesteld en mogen NIET aangepast worden zonder expliciete bevestiging van de developer. Werk strikt incrementeel volgens sectie 14 ("Build Order"). Stop na elke bouwstap en wacht op feedback.

---

## 0. Build Status (V1 — afgerond 2026-05-20)

V1 is **end-to-end werkend**. Een redacteur kan: CSV uploaden → keyword kiezen → dry-run preview (optioneel) → "Genereer" → draft staat 30-60s later onder Blogs met featured image, in-body afbeeldingen, RankMath SEO velden en `_db_ai_*` audit-meta. FAQ JSON-LD wordt site-breed geïnjecteerd op élke post met een `veelgestelde_vragen` block.

Alle 7 bouwstappen uit sectie 14 zijn gerealiseerd. Per stap acceptatiecriteria gehaald en handmatig getest.

### Afwijkingen van de originele brief (bevestigd, in code verwerkt)

| # | Onderwerp | Origineel | Werkelijk | Reden |
|---|---|---|---|---|
| 1 | post_type voor gegenereerde drafts | `post` | `blog` (CPT) | Site draait redactioneel op `blog` CPT (geregistreerd via ACF post-types UI, slug `blog`, label "Blogs"). Bevestigd 2026-05-19. |
| 2 | Admin menu locatie | Berichten | Berichten **én** Blogs | User wilde submenu op beide plekken zichtbaar. Filterbaar via `db_ai_admin_menu_parents`. |
| 3 | AI provider (V1) | OpenAI gpt-4o | Anthropic `claude-sonnet-4-6` | User had geen OpenAI billing maar wel Anthropic. OpenAI-provider staat nog in codebase; switch via `DB_AI_PROVIDER` constant. Bevestigd 2026-05-19. |
| 4 | ACF field group toepassingen | `page`, `post`, `project` | + `blog` | Field group bleek in DB ook aan `blog` gekoppeld. Code leest dynamisch dus geen aanpassing nodig — alleen sectie 5 inhoudelijk gecorrigeerd. |

### Operationele gotcha's (gedocumenteerd voor toekomstige debugging)

- **MAMP FastCGI default `-idle-timeout = 30s`** — kills langlopende AI calls. Fix: `-idle-timeout 180` toegevoegd aan `FastCgiServer` directive in `/Applications/MAMP/conf/apache/httpd.conf`. Productie (Nginx/Apache met PHP-FPM) heeft analoog: `fastcgi_read_timeout` / `FcgidIOTimeout`.
- **ChatGPT Plus ≠ OpenAI API** — Plus abonnement geeft geen API-credits. Vereist apart pay-as-you-go billing op platform.openai.com.

---

## 0B. V2 Iteratie (2026-05-20)

V1 was end-to-end werkend; V2 voegde 3 user-gedreven verbeteringen toe:

### A. Test-surface verwijderd

- AJAX endpoints weg: `wp_ajax_db_ai_dry_run_generate`, `wp_ajax_db_ai_test_image_search`
- UI: dry-run-stap en image-test-stap uit de template, ~250 JS-regels eruit
- Reden: V1 testte de provider + image service via aparte knoppen; in V2 zijn die niet meer nodig nu de hele pipeline stabiel draait via de "Genereer" knop. Endpoints kunnen later weer toegevoegd worden achter een `WP_DEBUG`-gate als dat ooit nodig is.

### B. Excel/CSV import wizard

- Upload-veld accepteert nu `.xlsx`, `.xls`, `.csv`, `.ods`
- Client-side parsing via **SheetJS Community Edition** (`assets/vendor/xlsx.full.min.js`, 0.20.3, MIT, ~952 KB). Geen Composer/PHP-deps, voldoet aan brief sectie 3.
- Auto-detectie van header-rij (scant eerste 10 rijen, vangt Google Ads preamble-rijen op)
- Auto-mapping met synoniemen — onder andere: `Campagne` → `Pagina`, `Advertentiegroep` → `Onderwerp`, `Volume`/`Vertoningen-context` etc.
- Mapping-panel wordt **altijd getoond** met preview van eerste 5 rijen (geen auto-skip). User klikt 1× "Apply" om te bevestigen. Reden: real-world reports (Google Ads exports) zien er rommelig uit — preview is genuinely useful om issues voor upload te spotten.
- Tijdens CSV-bouw:
  - `Totaal:` / `Total:` rijen worden geskipt (Google Ads summary rows)
  - Match-type wrappers strippen: `[bruidsmode]` → `bruidsmode`, `"phrase match"` → `phrase match`, `+modified` → `modified`
- "Download lege template (.csv)" knop genereert een CSV met de exacte target-headers + 1 voorbeeldrij

### C. Settings page voor API keys

- Nieuwe class: `DB_AI_Settings` met Settings API onder **Instellingen → AI Module** (cap `manage_options`)
- Opslag: option `db_ai_settings` (1 array met `provider`, `*_key` velden)
- **wp-config.php constants winnen altijd** — corresponderend veld wordt disabled met "Ingesteld via wp-config.php" badge. Dit ondersteunt staging/productie scheiding.
- Keys worden niet teruggetoond — bestaande key wordt als `••••••XYZW` placeholder weergegeven. Leeg laten = behouden. `autocomplete="new-password"` om browser auto-fill te voorkomen.
- Helper-API: `DB_AI_Settings::get_api_key( 'anthropic' )` / `get_provider()` / `is_constant_defined( ... )`. Alle consumers (Ajax::resolve_provider, Image_Service::search_pexels/unsplash) gebruiken deze helper.
- "Instellingen" knop toegevoegd naast de titel van de generator-page voor 1-klik navigatie.
- `uninstall.php` verwijdert ook `db_ai_settings` option.

---

## 0C. V2.1 Iteratie — Tone of Voice & Content-context (2026-05-20)

User-feedback na V2-testing: gegenereerde blogs hadden generieke toon, gebruikten Claude-tells (em-dashes, overdreven adjectieven) en hielden geen rekening met business-context (bv. concurrenten noemen voor een retailer die net niet wil).

### Wat is toegevoegd

Nieuwe sectie "Tone of voice & content" op de Settings-page met 4 freeform/multi-select velden — alle optioneel, lege Settings = oude generieke gedrag:

1. **Merkstem (`tone_of_voice`)** — textarea voor brand voice beschrijving. Bv: *"Warm en uitnodigend zonder klef te worden. Spreek aan met 'je'. Korte zinnen waar het kan."*
2. **Site-context (`site_context`)** — textarea voor bedrijf + doelgroep + expliciet **"WAT NIET TE DOEN"** lijstje. Cruciaal voor blokkeer-instructies (geen concurrenten, geen lege beloftes, geen producten die je niet verkoopt). Concrete don'ts werken veel beter dan algemene zinnen.
3. **Stijlregels (`style_rules`)** — textarea voor uitvoeringsregels. Bv: *"Geen em-dashes (—). Max 25 woorden per zin. Vermijd 'fantastisch'/'geweldig'/'uniek'. Korte alinea's: max 4 zinnen."*
4. **Referentie-posts (`reference_post_ids`, max 5)** — multi-select uit laatste 80 published pages/posts/blogs. Plugin extract eerste ~600 chars per gekozen post en injecteert als few-shot voorbeelden. Sterkste signaal voor toon-matching want concrete tekst zegt meer dan een beschrijving.

### Architectuur

- **Nieuwe helper-class `DB_AI_Style_Profile`** (`includes/class-db-ai-style-profile.php`) leest settings + extract referentie-text en geeft 1 geformatteerd tekstblok terug via `get_prompt_addition()`.
- **Tekst-extractie strategie** (in `extract_post_text()`): probeert eerst `apply_filters('the_content', $post->post_content)`. Als dat <80 chars geeft (typisch bij ACF flex sites waar `post_content` leeg is), walks de helper recursief door `paginacontent` flex en verzamelt alle string-waarden >30 chars uit wysiwyg/tekst/repeater velden. Werkt dus zowel voor Gutenberg/classic als voor pure ACF-sites.
- **Provider integratie**: zowel `DB_AI_Anthropic_Provider` als `DB_AI_OpenAI_Provider` hebben hun bestaande `build_system_prompt()` gesplitst in `base_system_prompt()` + appendix. De appendix is altijd `DB_AI_Style_Profile::get_prompt_addition()`.
- **Filter**: `db_ai_reference_post_types` (default `['page', 'post', 'blog']`) bepaalt welke post-types in de selector verschijnen.

### Geïnjecteerde prompt-structuur

Na de standaard system-prompt komt het volgende blok (alleen niet-lege secties):

```
---

MERKSTEM:
{tone_of_voice}

SITECONTEXT (bedrijf, doelgroep, WAT NIET TE DOEN):
{site_context}

EXTRA HUISSTIJL-REGELS (volg STRIKT):
{style_rules}

VOORBEELDEN VAN GEWENSTE SCHRIJFSTIJL — match toon, ritme en zinslengte:

=== Voorbeeld 1 — "{post_title}" ===
{eerste 600 chars}

=== Voorbeeld 2 — "{...}" ===
{...}
```

Token-overhead: typisch +500-2000 tokens op system prompt afhankelijk van hoeveel velden ingevuld zijn. Acceptabel binnen het 200K context window van Claude Sonnet 4.6.

### Iteratie-tip voor gebruikers

Start met **site_context** — dat heeft de grootste merkbare impact omdat het zowel positief (wie ben je) als negatief (wat niet) stuurt. **Reference-posts** is de tweede grootste hefboom, want concrete tekst beïnvloedt de AI sterker dan beschrijvingen. **Style rules** is een verfijnings-laag voor specifieke AI-tells (em-dashes, overdreven adjectieven).

---

## 1. Projectoverzicht

### Wat bouwen we

Een WordPress plugin (`digitale-bazen-ai-module`) die in het admin paneel onder **Berichten → AI Blog Genereren** een interface aanbiedt waarmee redacteuren in één klik een complete, SEO-geoptimaliseerde blogpost-draft kunnen genereren op basis van een hoofdzoekwoord uit een zoekwoordenonderzoek (CSV).

De gegenereerde post:
- Wordt opgeslagen als **draft** (nooit direct gepubliceerd)
- Bestaat uit **ACF Flexible Content blocks** uit de bestaande field group `paginacontent`
- Heeft **featured image** + per block een afbeelding (via Pexels/Unsplash API)
- Heeft **RankMath SEO** velden ingevuld
- Heeft **FAQ JSON-LD schema** geïnjecteerd in `<head>` (door de plugin zelf)
- Heeft **post meta** die markeert dat het AI-gegenereerd is

### Wat bouwen we NIET (out of scope)

- Geen frontend changes (alleen admin)
- Geen wijzigingen aan bestaande ACF field groups
- Geen wijzigingen aan het theme
- Geen automatische publicatie
- Geen automatische categorie/tag selectie (V1)
- Geen internal linking (V1)
- Geen automatische externe links (V1)
- Geen multi-step UI (V1 = 1-klik flow)

---

## 2. Naming Conventions

| Onderdeel | Conventie | Voorbeeld |
|---|---|---|
| Plugin folder | `digitale-bazen-ai-module` | – |
| Plugin slug | `digitale-bazen-ai-module` | – |
| Constante prefix | `DB_AI_` | `DB_AI_VERSION`, `DB_AI_PLUGIN_DIR` |
| Class prefix | `DB_AI_` | `DB_AI_Plugin`, `DB_AI_Admin_Page` |
| Function prefix | `db_ai_` | `db_ai_get_acf_layouts()` |
| Hook prefix | `db_ai_` | `db_ai_before_generate`, `db_ai_after_post_created` |
| AJAX actions | `db_ai_*` | `db_ai_generate_post`, `db_ai_parse_csv` |
| Nonce names | `db_ai_*_nonce` | `db_ai_generate_nonce` |
| Post meta keys | `_db_ai_*` | `_db_ai_generated`, `_db_ai_keyword` |
| Option keys | `db_ai_*` | `db_ai_rate_limit_count_USERID_DATE` |
| Text domain | `digitale-bazen-ai-module` | – |
| JS namespace | `dbAi` | `window.dbAi = {...}` |
| CSS class prefix | `db-ai-` | `.db-ai-keyword-select` |

### wp-config.php constants (door developer aan te maken)

```php
define( 'DB_AI_OPENAI_API_KEY', 'sk-...' );
define( 'DB_AI_PEXELS_API_KEY', '...' );
define( 'DB_AI_UNSPLASH_API_KEY', '...' ); // optioneel
```

Plugin controleert deze bij activatie en toont admin notice als ze ontbreken.

---

## 3. Stack & dependencies

### Vereist
- WordPress 6.0+
- PHP 7.4+
- ACF Pro (met field group `group_5da97023a084d` geactiveerd)
- RankMath SEO (free of Pro)

### Geen externe dependencies
- **Geen Composer** — alle code native PHP
- **Geen build-step voor JS** — vanilla JS, geen bundler
- **LESS** wordt gecompileerd door bestaande theme-pipeline van de developer (NIET door plugin)

### Bundled vendor JS (single-file, geen npm)
- **SheetJS Community Edition 0.20.3** (MIT) — `assets/vendor/xlsx.full.min.js`, ~952 KB. Pinned version, gepinned via release URL: `https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js`. Toegevoegd in V2 voor de Excel-import wizard. Alleen geladen op de generator-page via `wp_enqueue_script`, niet site-breed.

### Externe APIs (HTTP via `wp_remote_*`)
- Anthropic API — `https://api.anthropic.com/v1/messages` (V1 default)
- OpenAI API — `https://api.openai.com/v1/chat/completions` (fallback / configureerbaar)
- Pexels API — `https://api.pexels.com/v1/search`
- Unsplash API — `https://api.unsplash.com/search/photos`

---

## 4. Plugin folder structuur (V2 actueel, 2026-05-20)

```
digitale-bazen-ai-module/
├── digitale-bazen-ai-module.php                       # Plugin bootstrap, header, requires
├── PROJECT_BRIEF.md                            # Dit document
├── readme.txt                                  # WP plugin readme
├── uninstall.php                               # Drop tabel + cleanup options (incl. db_ai_settings)
├── includes/
│   ├── class-db-ai-plugin.php                  # Singleton, init, activation, db-version check
│   ├── class-db-ai-admin-page.php              # Submenu (Berichten + Blogs) + asset enqueue + nonce localize
│   ├── class-db-ai-settings.php                # V2: Settings API page onder Instellingen + helpers voor keys
│   ├── class-db-ai-style-profile.php           # V2.1: TOV/context/rules + referentie-post text extractie
│   ├── class-db-ai-ajax.php                    # AJAX endpoints (parse_csv, generate)
│   ├── class-db-ai-keyword-importer.php        # CSV parsing + grouping + secundaire keywords
│   ├── class-db-ai-acf-mapper.php              # Dynamic field group inlezen + layout spec + validator + write_blocks_to_post
│   ├── class-db-ai-image-service.php           # Pexels → Unsplash fallback + download_url + media_handle_sideload
│   ├── class-db-ai-seo-mapper.php              # RankMath meta velden
│   ├── class-db-ai-post-creator.php            # Orchestrator (11 stappen volgens sectie 13)
│   ├── class-db-ai-faq-schema.php              # FAQPage JSON-LD op wp_head (site-breed)
│   ├── class-db-ai-logger.php                  # Custom DB tabel `wp_db_ai_generations` + dbDelta
│   ├── class-db-ai-rate-limiter.php            # 10/dag/user, telt uit logger tabel
│   └── providers/
│       ├── interface-db-ai-provider.php        # DB_AI_Provider interface
│       ├── class-db-ai-openai-provider.php     # OpenAI gpt-4o (configureerbaar via Settings)
│       └── class-db-ai-anthropic-provider.php  # Anthropic claude-sonnet-4-6 (default)
├── assets/
│   ├── admin.js                                # Vanilla JS — Excel/CSV wizard + mapping + generate
│   ├── admin.css                               # Plain CSS, spinner, status, mapping-table, generate-card
│   └── vendor/
│       └── xlsx.full.min.js                    # V2: SheetJS CE 0.20.3 (MIT) voor client-side xlsx/csv parsing
└── templates/
    └── admin-page.php                          # 3-staps markup: Upload (+ mapping) → Keyword → Genereer
```

**Status:** alle bestanden bestaan. PHP-lint schoon op PHP 7.4 voor alle 19 PHP files.

---

## 5. Bestaande ACF Field Group (lezen, niet wijzigen!)

Field group key: `group_5da97023a084d`
Field group naam: `Paginacontent`
Toegepast op: `page`, `post`, `project`, `blog`  *(gecorrigeerd 2026-05-19 — `blog` was in originele brief vergeten)*
Hoofdveld: `paginacontent` (flexible_content)

### Layouts in scope voor AI-generatie

#### Layout: `banner`

| Veld | Type | Required | Choices/Default |
|---|---|---|---|
| `weergave` | select | – | `hero` / `alternatief` / `alleen-tekst` — AI gebruikt altijd `hero` |
| `subtitel` | text | – | – |
| `titel` | text | – | – |
| `tekst` | wysiwyg | – | – |
| `button` | link | – | leeg laten |
| `button_2` | link | – | leeg laten |
| `afbeelding` | image | – | attachment ID |
| `mobiele_afbeelding` | image | – | leeg laten (fallback naar `afbeelding`) |

#### Layout: `tekst_met_afbeelding`

| Veld | Type | Required | Choices/Default |
|---|---|---|---|
| `subtitel` | text | – | – |
| `titel` | text | – | – |
| `tekst` | wysiwyg | – | – |
| `button` | link | – | leeg laten |
| `button_2` | link | – | leeg laten |
| `positie` | select | – | `links` / `rechts` — AI alterneert |
| `afbeelding` | image | – | attachment ID |
| `kleurenthema` | select | ✅ | `wit` / `blauw` — AI gebruikt altijd `wit` |

#### Layout: `tekst_weergaves`

| Veld | Type | Required | Choices/Default |
|---|---|---|---|
| `weergave` | select | – | `tekst-standaard` / `tekst-standaard-kolom` / `tekst-standaard-gecentreerd` / `tekst-alternatief` — AI gebruikt `tekst-standaard` |
| `subtitel` | text | – | – |
| `titel` | text | – | – |
| `tekst` | wysiwyg | – | – |
| `tekst_kolom_2` | wysiwyg | – | – |
| `button` | link | – | leeg laten |
| `button_2` | link | – | leeg laten |

#### Layout: `usps`

| Veld | Type | Required | Choices/Default |
|---|---|---|---|
| `weergave` | select | – | `standaard` / `uitgelicht` — AI gebruikt `standaard` |
| `subtitel_usp` | text | – | **let op: afwijkende naam!** |
| `titel` | text | – | – |
| `tekst` | wysiwyg | – | – |
| `usps` | repeater | – | sub_fields: |
| └── `icoon_content` | image | – | **leeg laten in V1** |
| └── `titel_content` | text | ✅ | – |
| └── `tekst_content` | wysiwyg | ✅ | – |

#### Layout: `veelgestelde_vragen` (FAQ — dubbele nesting!)

| Veld | Type | Required | Notes |
|---|---|---|---|
| `subtitel` | text | – | – |
| `titel` | text | – | – |
| `tekst` | wysiwyg | – | – |
| `onderwerpen` | repeater | – | sub_fields: |
| └── `onderwerp_titel` | text | – | – |
| └── `vragen` | repeater | – | sub_fields: |
| &nbsp;&nbsp;&nbsp;&nbsp;└── `vraag` | text | ✅ | – |
| &nbsp;&nbsp;&nbsp;&nbsp;└── `antwoord` | wysiwyg | ✅ | – |

**Belangrijk**: voor blogs gebruikt de AI typisch **1 onderwerp** met 5-8 vragen. Voor langere blogs eventueel 2-3 onderwerpen.

#### Layout: `fotogalerij` (optioneel — AI mag overslaan)

| Veld | Type | Required | Notes |
|---|---|---|---|
| `subtitel` | text | – | – |
| `titel` | text | – | – |
| `tekst` | wysiwyg | – | – |
| `afbeeldingen` | repeater | – | sub_fields: |
| └── `afbeelding` | image | ✅ | attachment ID |

### Layouts NIET te gebruiken door AI

`module_overzicht`, `module_slider`, `partners`, `slider`, `tekst_met_formulier`, `videos`, `testblok`, `tweede_testblok`.

### Belangrijk: Dynamisch inlezen

`class-db-ai-acf-mapper.php` MOET de field group dynamisch inlezen via `acf_get_field_group('group_5da97023a084d')` en `acf_get_fields(...)`. **Hardcode geen veldnamen** behalve de in-scope layout names (in een whitelist) en de "altijd default" select values.

Reden: als de developer een veld toevoegt aan een layout, breekt de plugin niet. Bij het schrijven naar ACF gebruik je `update_field()` of `update_sub_field()` met de juiste field keys (NIET names) voor maximale betrouwbaarheid.

```php
// Voorbeeld: lees alle layouts uit
$field_group = acf_get_field_group( 'group_5da97023a084d' );
$fields = acf_get_fields( $field_group );
$flex_field = $fields[0]; // 'paginacontent'
$layouts = $flex_field['layouts']; // keyed by layout key
```

---

## 6. CSV Input Format (zoekwoordenonderzoek)

> **V2 update (2026-05-20):** de upload accepteert nu ook `.xlsx`, `.xls`, `.ods` via client-side SheetJS parsing. Niet-passende kolomnamen worden via een mapping-wizard naar het onderstaande schema vertaald. Het PHP-endpoint (`db_ai_parse_csv`) blijft uitsluitend CSV accepteren — de browser converteert eerst.

### Verwachte structuur

Tab- of komma- of puntkomma-separated. Auto-detectie van delimiter door de eerste regel te sniffen op meest voorkomend separator-teken (uit `;`, `,`, `\t`).

### Verplichte kolom

- `Zoekwoord` (case-insensitive match, trim whitespace)

### Optionele kolommen (worden gebruikt als aanwezig)

- `Maandelijks volume` (numeriek, integer)
- `Pagina` (string, groepering)
- `Onderwerp` (string, **gebruikt voor secundaire keyword selectie**)
- `Concurrentie` (Laag / Normaal / Hoog)
- `CPC Laag`, `CPC hoog` (numeriek, kommaformaat NL accepteren)

### Voorbeelddata

```csv
Zoekwoord;Maandelijks volume;Pagina;Onderwerp;Concurrentie
Online Marketing Bureau;2900;Online Marketing;Bedrijf;Normaal
Marketing bureau Eindhoven;260;Online Marketing;Bedrijf;Normaal
Website laten maken;8100;Wordpress;Website;Hoog
Webshop laten maken;3600;Wordpress;Webshop;Normaal
```

### Parser-logica

```
class DB_AI_Keyword_Importer {
    public function parse_csv( string $file_path ): array;
    // Returns: [
    //   'rows' => [ ['zoekwoord' => '...', 'volume' => 8100, 'pagina' => 'Wordpress', 'onderwerp' => 'Website', ...], ... ],
    //   'grouped' => [
    //     'Wordpress' => [
    //       'Website' => [ ...rows... ],
    //       'Webshop' => [ ...rows... ],
    //     ],
    //   ],
    // ]
    
    public function get_secondary_keywords( array $rows, string $main_keyword ): array;
    // Find the row matching $main_keyword, get its 'onderwerp', return all OTHER rows with same 'onderwerp' (just zoekwoord strings)
}
```

---

## 7. AI Provider abstractie

> **V1 status:** Interface + 2 implementaties beschikbaar — OpenAI en Anthropic (zie sectie 7B). Selectie via `DB_AI_PROVIDER` constant of fallback op aanwezige API-key. Actieve default in V1 = Anthropic.

### Interface

```php
interface DB_AI_Provider {
    /**
     * Generate a blog post structure from a keyword.
     * 
     * @param string $main_keyword
     * @param array  $secondary_keywords  Plain string array
     * @param array  $context  ['target_length' => 1500, 'tone' => '...', etc.]
     * @return array|WP_Error  Validated structure matching the JSON spec in section 8
     */
    public function generate_blog( string $main_keyword, array $secondary_keywords, array $context ): array|WP_Error;
    
    /**
     * Return the model identifier used (e.g. 'openai:gpt-4o-2024-08-06')
     */
    public function get_model_identifier(): string;
    
    /**
     * Return tokens used in the last call.
     */
    public function get_last_token_usage(): int;
}
```

### OpenAI implementatie

**Model**: `gpt-4o` (vast in V1, configureerbaar via filter `db_ai_openai_model` in latere versies)

**Endpoint**: `POST https://api.openai.com/v1/chat/completions`

**Request body**:
```json
{
  "model": "gpt-4o",
  "messages": [
    { "role": "system", "content": "<SYSTEM_PROMPT — see section 9>" },
    { "role": "user", "content": "<USER_PROMPT — see section 9>" }
  ],
  "response_format": { "type": "json_object" },
  "temperature": 0.7,
  "max_tokens": 8000
}
```

**Headers**:
```
Authorization: Bearer <DB_AI_OPENAI_API_KEY>
Content-Type: application/json
```

**HTTP timeout**: 120 seconds (generatie kan lang duren — gebruik `'timeout' => 120` in `wp_remote_post()`).

**Response parsing**:
```php
$body = json_decode( wp_remote_retrieve_body( $response ), true );
$content = $body['choices'][0]['message']['content']; // JSON string
$parsed = json_decode( $content, true );
$tokens = $body['usage']['total_tokens'];
```

### 7B. Anthropic implementatie (V1 actieve default)

Toegevoegd op 2026-05-19. Endpoint `https://api.anthropic.com/v1/messages`, raw HTTP via `wp_remote_post` (geen Composer/SDK).

**Headers:**
```
x-api-key: <DB_AI_ANTHROPIC_API_KEY>
anthropic-version: 2023-06-01
content-type: application/json
```

**Request body** (default — filterbaar via `db_ai_anthropic_model`, `db_ai_anthropic_max_tokens`):
```json
{
  "model": "claude-sonnet-4-6",
  "max_tokens": 8000,
  "system": "<system prompt — sectie 9>",
  "messages": [{ "role": "user", "content": "<user prompt — sectie 9>" }]
}
```

**Response parsing:**
- `content[0].text` bevat de JSON string (eerste `text`-type block — skipt thinking blocks indien aanwezig)
- Claude wraps soms in ` ```json ... ``` `; provider stript markdown-fences automatisch via regex
- Tokens = `usage.input_tokens + usage.output_tokens`

**Provider selectie** (in `DB_AI_Ajax::resolve_provider()`):
1. `DB_AI_PROVIDER` constant (waarden `anthropic` of `openai`) wint
2. Anders: Anthropic als `DB_AI_ANTHROPIC_API_KEY` gedefinieerd
3. Anders: OpenAI fallback als `DB_AI_OPENAI_API_KEY` gedefinieerd

---

## 8. AI JSON Output Specification

De AI MOET exact dit JSON-formaat teruggeven. De validator in `class-db-ai-acf-mapper.php` weigert alles wat hier van afwijkt.

```json
{
  "post": {
    "title": "string, 40-70 chars, bevat hoofdzoekwoord",
    "slug": "string, kebab-case, NL, max 70 chars",
    "excerpt": "string, 120-160 chars, samenvatting"
  },
  "seo": {
    "focus_keyword": "string, exact het hoofdzoekwoord",
    "meta_title": "string, max 60 chars, focus keyword vooraan",
    "meta_description": "string, max 155 chars, focus keyword + CTA"
  },
  "featured_image": {
    "query": "string, ENGELSE zoekterm voor Pexels (bv. 'modern office workspace')",
    "alt": "string, NEDERLANDSE alt-tekst met focus keyword waar logisch"
  },
  "blocks": [
    {
      "acf_fc_layout": "banner",
      "weergave": "hero",
      "subtitel": "string, kort, 2-5 woorden",
      "titel": "string, H1-style, bevat focus keyword",
      "tekst": "string HTML, 1-2 paragrafen <p>...</p>",
      "afbeelding": { "query": "english search term", "alt": "Nederlandse alt-tekst" }
    },
    {
      "acf_fc_layout": "tekst_met_afbeelding",
      "positie": "links | rechts",
      "kleurenthema": "wit",
      "subtitel": "string",
      "titel": "string, H2",
      "tekst": "string HTML met <p>, <strong>, <ul><li>, <a> toegestaan",
      "afbeelding": { "query": "english search term", "alt": "Nederlandse alt-tekst" }
    },
    {
      "acf_fc_layout": "tekst_weergaves",
      "weergave": "tekst-standaard",
      "subtitel": "string",
      "titel": "string, H2",
      "tekst": "string HTML, eerste kolom",
      "tekst_kolom_2": "string HTML, tweede kolom"
    },
    {
      "acf_fc_layout": "usps",
      "weergave": "standaard",
      "subtitel_usp": "string",
      "titel": "string, H2",
      "tekst": "string HTML, intro voor USPs",
      "usps": [
        {
          "titel_content": "string, korte USP titel (3-6 woorden)",
          "tekst_content": "string HTML, 1-2 zinnen <p>...</p>"
        }
      ]
    },
    {
      "acf_fc_layout": "veelgestelde_vragen",
      "subtitel": "string",
      "titel": "string, H2 (bv. 'Veelgestelde vragen')",
      "tekst": "string HTML, korte intro",
      "onderwerpen": [
        {
          "onderwerp_titel": "string, bv. 'Algemeen' (mag leeg)",
          "vragen": [
            {
              "vraag": "string, eindigt op '?'",
              "antwoord": "string HTML, 1-3 zinnen <p>...</p>"
            }
          ]
        }
      ]
    }
  ]
}
```

### Validatieregels (in `class-db-ai-acf-mapper.php::validate_ai_output()`)

1. Top-level keys aanwezig: `post`, `seo`, `featured_image`, `blocks`
2. `blocks` is een non-empty array
3. Elk block heeft een `acf_fc_layout` die in whitelist staat
4. Per layout: alle **required** velden uit sectie 5 zijn aanwezig en niet-leeg
5. Select-velden hebben een toegestane choice (uit sectie 5)
6. Repeaters zijn arrays met min. 1 item
7. Image-objecten hebben `query` (non-empty) en `alt` (non-empty)
8. `seo.meta_title` ≤ 60 chars, `seo.meta_description` ≤ 155 chars (soft warning, geen error)
9. `seo.focus_keyword` matcht het input hoofdzoekwoord (case-insensitive)

Bij falen: return `WP_Error` met `code: 'db_ai_invalid_ai_output'` en `data: ['validation_errors' => [...]]`.

---

## 9. Prompt Templates

### System Prompt

```
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
```

### User Prompt template

```
Schrijf een Nederlandse blogpost over: "{MAIN_KEYWORD}"

Secundaire keywords om natuurlijk te verwerken: {SECONDARY_KEYWORDS_COMMA_LIST}

Gewenste structuur (mag in deze volgorde, maar je mag varianten toevoegen):
1. banner (intro, hero)
2. 2-4× tekst_met_afbeelding (body, alternerend positie links/rechts)
3. Optioneel 1× tekst_weergaves OF usps (voor variatie)
4. veelgestelde_vragen (5-8 vragen, 1 onderwerp)

Beschikbare blok-layouts en hun exacte veldspec:
{LAYOUT_SPEC_JSON}

Geef antwoord als één JSON-object volgens deze exacte structuur:
{OUTPUT_SCHEMA_JSON}
```

`{LAYOUT_SPEC_JSON}` en `{OUTPUT_SCHEMA_JSON}` worden runtime ingevuld door `DB_AI_ACF_Mapper::build_layout_spec_for_prompt()` op basis van de dynamisch ingelezen field group.

---

## 10. Image Service

### Class: `DB_AI_Image_Service`

```php
class DB_AI_Image_Service {
    /**
     * Search image, download, sideload to media library, return attachment ID.
     * 
     * @param string $query    English search term
     * @param string $alt_text Dutch alt text
     * @param int    $post_id  Post to attach to (0 = unattached)
     * @return int|WP_Error    Attachment ID
     */
    public function find_and_import( string $query, string $alt_text, int $post_id = 0 ): int|WP_Error;
}
```

### Provider-volgorde

1. Probeer **Pexels** (`GET https://api.pexels.com/v1/search?query={query}&per_page=5&orientation=landscape`)
   - Header: `Authorization: {DB_AI_PEXELS_API_KEY}`
2. Bij `WP_Error` of lege results: probeer **Unsplash** (als `DB_AI_UNSPLASH_API_KEY` defined)
   - `GET https://api.unsplash.com/search/photos?query={query}&per_page=5&orientation=landscape`
   - Header: `Authorization: Client-ID {DB_AI_UNSPLASH_API_KEY}`
3. Bij beide leeg: return `WP_Error('db_ai_no_image_found', ...)`

### Selectie

Eerste resultaat (beide APIs sorteren op relevantie). In V2 eventueel score-based selectie of preview.

### Download & sideload

```php
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$tmp = download_url( $image_url, 60 );
if ( is_wp_error( $tmp ) ) return $tmp;

$file_array = [
    'name'     => sanitize_file_name( $query . '-' . wp_generate_password( 6, false ) . '.jpg' ),
    'tmp_name' => $tmp,
];

$attachment_id = media_handle_sideload( $file_array, $post_id );
if ( is_wp_error( $attachment_id ) ) {
    @unlink( $tmp );
    return $attachment_id;
}

update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
update_post_meta( $attachment_id, '_db_ai_source_url', $source_page_url );
update_post_meta( $attachment_id, '_db_ai_photographer', $photographer_name );
update_post_meta( $attachment_id, '_db_ai_source_provider', 'pexels' ); // or 'unsplash'

return $attachment_id;
```

### Featured image: soft fallback

In `DB_AI_Post_Creator::create_post()`:

1. Probeer featured image via `featured_image.query` uit AI output
2. Bij falen: gebruik eerste block-afbeelding die wel gelukt is (recycle attachment ID)
3. Bij falen + geen block-afbeeldingen: post wordt aangemaakt zonder featured image, admin notice in post meta `_db_ai_warnings` (array)

---

## 11. RankMath SEO Mapping

### Class: `DB_AI_SEO_Mapper`

```php
class DB_AI_SEO_Mapper {
    /**
     * Write RankMath meta fields for a post.
     * 
     * @param int   $post_id
     * @param array $seo  ['focus_keyword' => ..., 'meta_title' => ..., 'meta_description' => ...]
     * @return void
     */
    public function apply( int $post_id, array $seo ): void;
}
```

### Meta keys

```php
update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $seo['focus_keyword'] ) );
update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $seo['meta_title'] ) );
update_post_meta( $post_id, 'rank_math_description', sanitize_text_field( $seo['meta_description'] ) );
// rank_math_robots: niet zetten, default = index,follow
```

---

## 12. FAQ JSON-LD Schema Injectie

### Class: `DB_AI_FAQ_Schema`

Werkt voor **alle** posts met een `veelgestelde_vragen` block — niet alleen AI-gegenereerde. Bonus feature voor de hele site.

### Hook

```php
add_action( 'wp_head', [ $this, 'inject_faq_schema' ], 20 );
```

### Logica

```php
public function inject_faq_schema(): void {
    if ( ! is_singular() ) return;
    
    $post_id = get_queried_object_id();
    $blocks = get_field( 'paginacontent', $post_id );
    if ( empty( $blocks ) || ! is_array( $blocks ) ) return;
    
    $faq_items = [];
    foreach ( $blocks as $block ) {
        if ( ( $block['acf_fc_layout'] ?? '' ) !== 'veelgestelde_vragen' ) continue;
        foreach ( ( $block['onderwerpen'] ?? [] ) as $onderwerp ) {
            foreach ( ( $onderwerp['vragen'] ?? [] ) as $vraag ) {
                if ( empty( $vraag['vraag'] ) || empty( $vraag['antwoord'] ) ) continue;
                $faq_items[] = [
                    '@type' => 'Question',
                    'name'  => wp_strip_all_tags( $vraag['vraag'] ),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => wp_strip_all_tags( $vraag['antwoord'] ),
                    ],
                ];
            }
        }
    }
    
    if ( empty( $faq_items ) ) return;
    
    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $faq_items,
    ];
    
    echo "\n<script type=\"application/ld+json\">\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n</script>\n";
}
```

---

## 13. Post Creator Orchestration

### Class: `DB_AI_Post_Creator`

De "conductor" die alle services aan elkaar knoopt.

```php
class DB_AI_Post_Creator {
    public function __construct(
        DB_AI_Provider $ai_provider,
        DB_AI_ACF_Mapper $acf_mapper,
        DB_AI_Image_Service $image_service,
        DB_AI_SEO_Mapper $seo_mapper,
        DB_AI_Logger $logger
    );
    
    /**
     * Main orchestration method.
     * 
     * @return int|WP_Error  Post ID on success
     */
    public function create_from_keyword( string $main_keyword, array $secondary_keywords, int $user_id ): int|WP_Error;
}
```

### Stappen in `create_from_keyword()`

```
1. Call $ai_provider->generate_blog() → ai_output
2. Validate ai_output via $acf_mapper->validate_ai_output() → throw WP_Error on fail
3. wp_insert_post() with:
   - post_title = ai_output.post.title
   - post_name = ai_output.post.slug (sanitized)
   - post_excerpt = ai_output.post.excerpt
   - post_status = 'draft'
   - post_type = 'blog'   // CPT op deze site (bevestigd 2026-05-19; was 'post' in originele brief)
   - post_author = $user_id
4. For each block in ai_output.blocks:
   - If block has image fields with {query, alt} objects:
     - Call $image_service->find_and_import() for each
     - Replace {query, alt} object with attachment ID
   - If block has nested image fields (e.g. fotogalerij.afbeeldingen): same recursion
5. Featured image:
   - Try $image_service->find_and_import( ai_output.featured_image )
   - On fail: pick first successful block image (track during step 4)
   - set_post_thumbnail()
6. Call $acf_mapper->write_blocks_to_post( $post_id, $transformed_blocks )
7. Call $seo_mapper->apply( $post_id, ai_output.seo )
8. update_post_meta( $post_id, '_db_ai_generated', 1 )
   update_post_meta( $post_id, '_db_ai_generated_at', current_time('mysql', true) )
   update_post_meta( $post_id, '_db_ai_keyword', $main_keyword )
   update_post_meta( $post_id, '_db_ai_secondary_keywords', implode(',', $secondary_keywords) )
   update_post_meta( $post_id, '_db_ai_model', $ai_provider->get_model_identifier() )
   update_post_meta( $post_id, '_db_ai_tokens_used', $ai_provider->get_last_token_usage() )
9. $logger->log_generation( $post_id, $user_id, $main_keyword, $tokens, $warnings )
10. do_action( 'db_ai_after_post_created', $post_id, ai_output, $user_id )
11. return $post_id
```

### ACF schrijven (kritiek!)

Voor flexible content moet je **field keys** gebruiken, niet alleen names. Zo werkt het:

```php
// In DB_AI_ACF_Mapper::write_blocks_to_post()
$prepared = []; // ACF flex format
foreach ( $blocks as $block ) {
    $layout_name = $block['acf_fc_layout'];
    $row = [ 'acf_fc_layout' => $layout_name ];
    
    // Walk fields of this layout, map values
    $layout_fields = $this->get_layout_fields( $layout_name );
    foreach ( $layout_fields as $field ) {
        $name = $field['name'];
        if ( ! isset( $block[ $name ] ) ) continue;
        // sanitize per type, see below
        $row[ $name ] = $this->sanitize_value( $field, $block[ $name ] );
    }
    $prepared[] = $row;
}
update_field( 'paginacontent', $prepared, $post_id );
```

### Sanitization per type

| Type | Functie |
|---|---|
| text | `sanitize_text_field()` |
| wysiwyg | `wp_kses_post()` |
| select | check tegen `choices`, fallback naar `default_value` |
| image | int cast (attachment ID) |
| link | leeg array `['title' => '', 'url' => '', 'target' => '']` als leeg |
| repeater | recursie over sub_fields |

---

## 14. Build Order — IMPLEMENTATIE VOLGORDE

> **Werk strikt incrementeel. Stop na elke stap, commit, en wacht op feedback.**
>
> **V1 status (2026-05-20): ✅ alle 7 stappen klaar.**

### Stap 1: Plugin skeleton + admin menu  ✅ klaar

**Bestanden**:
- `digitale-bazen-ai-module.php` (plugin header, constants, autoload)
- `includes/class-db-ai-plugin.php` (init, singleton)
- `includes/class-db-ai-admin-page.php` (menu registratie + lege pagina)
- `templates/admin-page.php` (placeholder markup)

**Plugin header**:
```php
/**
 * Plugin Name: Digitale Bazen AI Module
 * Description: Genereer SEO-blogposts met AI op basis van zoekwoordenonderzoek.
 * Version:     0.1.0
 * Author:      Digitale Bazen
 * Text Domain: digitale-bazen-ai-module
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */
```

**Constants**:
```php
define( 'DB_AI_VERSION', '0.1.0' );
define( 'DB_AI_PLUGIN_FILE', __FILE__ );
define( 'DB_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DB_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
```

**Menu**:
```php
add_submenu_page(
    'edit.php',                          // Parent = Berichten
    'AI Blog Genereren',                 // Page title
    'AI Blog Genereren',                 // Menu title
    'publish_posts',                     // Capability
    'db-ai-generator',                   // Slug
    [ $this, 'render_admin_page' ]       // Callback
);
```

**Activatie check**:
- Check of ACF Pro actief is (`class_exists('ACF')` of `function_exists('get_field')`)
- Check of field group `group_5da97023a084d` bestaat
- Bij ontbreken: deactiveer plugin met `wp_die()` of admin notice

**Acceptatiecriteria**:
- Plugin activeert zonder errors
- Menu-item "AI Blog Genereren" verschijnt onder Berichten
- Klik op menu toont placeholder pagina ("Hier komt de AI generator")
- Bij ontbreken van ACF of field group: duidelijke melding

---

### Stap 2: CSV uploader + parser + keyword selector UI  ✅ klaar

**Bestanden toevoegen**:
- `includes/class-db-ai-keyword-importer.php`
- `includes/class-db-ai-ajax.php` (registreer `wp_ajax_db_ai_parse_csv`)
- `assets/admin.js` (CSV upload via FormData → AJAX)
- `assets/admin.css`

**AJAX endpoint**: `wp_ajax_db_ai_parse_csv`
- Nonce check: `check_ajax_referer( 'db_ai_admin', 'nonce' )`
- Capability: `current_user_can( 'publish_posts' )`
- Receive `$_FILES['csv']`, validate MIME (text/csv, application/vnd.ms-excel, text/plain)
- Parse via `DB_AI_Keyword_Importer::parse_csv()`
- Return JSON met `grouped` structuur

**UI**:
- File input `<input type="file" accept=".csv">`
- Op upload: AJAX call → toon select met `<optgroup>` per Pagina, daarbinnen `<option>` per zoekwoord
- Format option: `"{Zoekwoord} ({Volume})"`
- Op select: toon hoofdzoekwoord + auto-detected secundaire keywords (read-only preview)
- "Genereer" knop (disabled tot keyword geselecteerd)

**Acceptatiecriteria**:
- Upload CSV → preview verschijnt
- Select dropdown gegroepeerd per Pagina/Onderwerp
- Bij selectie hoofdzoekwoord: secundaire keywords zichtbaar (zelfde Onderwerp)
- Foutmeldingen bij ongeldige CSV (missende `Zoekwoord` kolom etc.)

---

### Stap 3: OpenAI Provider + ACF Mapper (validatie + dry-run)  ✅ klaar

> Bij implementatie ook Anthropic provider toegevoegd (zie sectie 7B) — actieve default in V1.

**Bestanden toevoegen**:
- `includes/providers/interface-db-ai-provider.php`
- `includes/providers/class-db-ai-openai-provider.php`
- `includes/class-db-ai-acf-mapper.php`

**Eerst zonder post-creatie**: AJAX endpoint `db_ai_dry_run_generate` retourneert raw AI JSON na validatie. Zo kunnen we de prompt en de validator tunen voordat we writes naar de DB doen.

**Implementeren**:
- `DB_AI_ACF_Mapper::get_layout_spec_for_prompt()` — bouwt het LAYOUT_SPEC_JSON deel
- `DB_AI_ACF_Mapper::validate_ai_output()` — alle checks uit sectie 8
- `DB_AI_OpenAI_Provider::generate_blog()` — request + response parsing

**Acceptatiecriteria**:
- Bij dry-run wordt OpenAI aangeroepen
- JSON komt geldig terug en passeert validator
- Bij validatiefout: duidelijke foutmelding met welk veld faalde
- Token usage zichtbaar in response

---

### Stap 4: Image Service  ✅ klaar

**Bestanden toevoegen**:
- `includes/class-db-ai-image-service.php`

**Test endpoint** (tijdelijk): `db_ai_test_image_search` met query parameter, return attachment ID + URL.

**Acceptatiecriteria**:
- Pexels search werkt
- Unsplash fallback werkt (test door Pexels key tijdelijk leeg)
- Sideload werkt, attachment heeft alt-text gezet
- Source meta opgeslagen

---

### Stap 5: SEO Mapper + FAQ Schema  ✅ klaar

**Bestanden toevoegen**:
- `includes/class-db-ai-seo-mapper.php`
- `includes/class-db-ai-faq-schema.php`

**Hook FAQ schema in init**:
```php
add_action( 'wp_head', [ new DB_AI_FAQ_Schema(), 'inject_faq_schema' ], 20 );
```

**Acceptatiecriteria**:
- Manueel een post met `veelgestelde_vragen` block aanmaken
- View source van frontend toont JSON-LD `FAQPage` schema
- Test met Google Rich Results Test → valid

---

### Stap 6: Post Creator (orchestration)  ✅ klaar

**Bestanden toevoegen**:
- `includes/class-db-ai-post-creator.php`
- `includes/class-db-ai-logger.php`
- `includes/class-db-ai-rate-limiter.php`

**Logger DB schema** (in plugin activation):
```sql
CREATE TABLE {$wpdb->prefix}db_ai_generations (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT(20) UNSIGNED NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    keyword VARCHAR(255) NOT NULL,
    model VARCHAR(100),
    tokens_used INT UNSIGNED,
    status VARCHAR(20),
    error_message TEXT,
    warnings TEXT,
    created_at DATETIME NOT NULL,
    INDEX (user_id),
    INDEX (created_at)
) {$wpdb->get_charset_collate()};
```

**Rate limiting**: 10 per user per day, gebruik transients of count uit logger table.

**Acceptatiecriteria**:
- Volledige flow werkt: CSV upload → keyword select → genereer → draft verschijnt in Berichten
- Draft heeft titel, excerpt, alle blocks correct gevuld
- Featured image gezet
- RankMath velden ingevuld
- Post meta keys aanwezig
- Logger heeft entry
- Rate limit blokkeert na 10 generaties

---

### Stap 7: Polish  ✅ klaar

- Loading state in admin UI (CSS spinner in `.db-ai-status.is-loading`)
- Error states (API key mist, provider down, rate limit, validatiefouten, image fail) — alle endpoints geven gestructureerde error response
- Success state: link naar de nieuwe draft + preview
- Warnings (featured image fallback etc.) zichtbaar in success-card én in `_db_ai_warnings` post meta
- `uninstall.php` — drop tabel + verwijder option/transients, **bewaart** user-data (posts, attachments, meta)
- `readme.txt` — V1 minimal
- Quota counter ("X van 10 generaties vandaag gebruikt") in admin UI, updatet na elke run

---

## 15. Security checklist

- [ ] Alle AJAX endpoints: `check_ajax_referer()` + `current_user_can('publish_posts')`
- [ ] CSV upload: file size limit (1MB), MIME check, geen executie van bestand
- [ ] `wp_kses_post()` op alle AI-gegenereerde HTML
- [ ] `sanitize_text_field()` op alle plain-text velden
- [ ] `sanitize_title()` op slug
- [ ] `absint()` op alle integer inputs
- [ ] Geen API keys ooit terug naar frontend
- [ ] `esc_html()` / `esc_attr()` / `esc_url()` op alle output in templates
- [ ] Nonce velden in alle admin forms

---

## 16. Hooks (extensibility)

Filters en actions die V1 daadwerkelijk biedt:

```php
// Filters — providers
apply_filters( 'db_ai_openai_model', 'gpt-4o' );
apply_filters( 'db_ai_openai_temperature', 0.7 );
apply_filters( 'db_ai_openai_max_tokens', 8000 );
apply_filters( 'db_ai_anthropic_model', 'claude-sonnet-4-6' );
apply_filters( 'db_ai_anthropic_max_tokens', 8000 );

// Filters — prompts
apply_filters( 'db_ai_system_prompt', $system_prompt );
apply_filters( 'db_ai_user_prompt', $user_prompt, $main_keyword, $secondary_keywords );

// Filters — gedrag
apply_filters( 'db_ai_rate_limit_per_day', 10 );
apply_filters( 'db_ai_image_orientation', 'landscape' );
apply_filters( 'db_ai_allowed_layouts', [ 'banner', 'tekst_met_afbeelding', 'tekst_weergaves', 'usps', 'veelgestelde_vragen', 'fotogalerij' ] );
apply_filters( 'db_ai_post_type', 'blog' );
apply_filters( 'db_ai_admin_menu_parents', [ 'edit.php', 'edit.php?post_type=blog' ] );
apply_filters( 'db_ai_reference_post_types', [ 'page', 'post', 'blog' ] ); // V2.1 — post types in reference-posts selector

// Actions
do_action( 'db_ai_before_generate', $main_keyword, $secondary_keywords, $user_id );
do_action( 'db_ai_after_ai_response', $ai_output, $main_keyword );
do_action( 'db_ai_after_post_created', $post_id, $ai_output, $user_id );
do_action( 'db_ai_generation_failed', $error, $main_keyword, $user_id );
```

**Niet (meer) geïmplementeerd in V1** — staan in originele brief maar niet gebruikt:
- `db_ai_target_word_count` (lengte zit hardcoded in system prompt — wijzig daar)

---

## 17. Backlog

### V2 — afgerond (2026-05-20)
- ✅ Excel/CSV import wizard met SheetJS + mapping-stap (zie sectie 0B.B)
- ✅ Settings page voor API keys (zie sectie 0B.C) — wp-config constants winnen nog steeds
- ✅ Test-endpoints (dry-run, image-test) verwijderd uit productieflow

### V2.1 — afgerond (2026-05-20)
- ✅ Tone of voice + site-context + style rules + referentie-posts in Settings → appended aan system prompt (zie sectie 0C)

### V3 — backlog (nog niet ingepland)
- Multi-step generatie (outline → approve → write)
- Per-block regeneratie (alleen FAQ opnieuw als die zwak is)
- Image preview met handmatige selectie (3-5 thumbnails per slot, redacteur kiest)
- Categorie/tag automatisch via AI
- Internal linking met bestaande URLs (SEO-impact)
- Externe link suggesties
- Gemini provider (Anthropic Claude is in V1 al toegevoegd naast OpenAI)
- Bulk generatie van meerdere posts
- Onderbroken/paused rijen filteren in Excel-wizard (checkbox in mapping-UI)
- Streaming UI tijdens generatie (tokens live tonen i.p.v. spinner)
- Per-generatie TOV-overrides (in plaats van alleen Settings-default)

---

## 18. Vaste beslissingen (NIET aanpassen zonder herziening)

- Plugin name & prefix: `digitale-bazen-ai-module` / `DB_AI_`
- Geen Composer / externe deps
- Native vanilla JS in admin (geen jQuery dependency)
- CSV input (geen Excel parsing)
- Pexels primair, Unsplash fallback
- Draft mode altijd
- Post type: `blog` (CPT) — bevestigd 2026-05-19, wijkt af van origineel `post`
- Admin submenu: zichtbaar onder Berichten én Blogs
- Capability: `publish_posts`
- Provider (tijdelijk): Anthropic Claude (`claude-sonnet-4-6`) — bevestigd 2026-05-19. Opus 4.7 startte ook werkend maar liep tegen MAMP's 30s FastCGI idle-timeout. Sonnet 4.6 is sneller (~15-25s), goedkoper (~$0.05-0.10/blog) en ruim voldoende voor blogs. OpenAI is geen optie tot user billing aan heeft op platform.openai.com. OpenAI-provider blijft in codebase, selectie via `DB_AI_PROVIDER` constant of fallback op aanwezige API-key. MAMP `FastCgiServer -idle-timeout 180` toegevoegd in `/Applications/MAMP/conf/apache/httpd.conf` zodat ook zwaardere modellen passen.
- API keys in `wp-config.php`
- Auteur = huidige user
- ACF layouts dynamisch ingelezen
- FAQ JSON-LD injectie door plugin
- `usps[].icoon_content` leeg in V1
- Categorie/tags handmatig achteraf
- 1200-1800 woorden target
- Geen internal/external auto-linking in V1