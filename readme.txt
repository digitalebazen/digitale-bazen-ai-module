=== Digitale Bazen AI Module ===
Contributors: digitalebazen
Tags: ai, blog, generator, seo, acf, rankmath
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.4
License: Proprietary

Genereer SEO-blogposts met AI op basis van zoekwoordenonderzoek.

== Description ==

Onder **Berichten → AI Blog Genereren** (en onder Blogs CPT) krijgen redacteuren
een 1-klik flow:

1. Upload xlsx/xls/csv/ods met zoekwoordenonderzoek (verplichte kolom-mapping
   naar `Zoekwoord` via een wizard met auto-suggesties).
2. Kies een hoofdzoekwoord.
3. De plugin roept een AI provider aan (Anthropic Claude of OpenAI), valideert
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
    * `DB_AI_ANTHROPIC_API_KEY` of `DB_AI_OPENAI_API_KEY`
    * `DB_AI_PEXELS_API_KEY` (verplicht)
    * `DB_AI_UNSPLASH_API_KEY` (optioneel — fallback)

Optionele constants:

* `DB_AI_PROVIDER` — `anthropic` of `openai`
* `DB_AI_GITHUB_REPO_URL` — voor auto-update vanuit een eigen GitHub repo
* `DB_AI_GITHUB_TOKEN` — Personal Access Token voor private GitHub repo

== Filters ==

* `db_ai_post_type`, `db_ai_admin_menu_parents`
* `db_ai_anthropic_model`, `db_ai_anthropic_max_tokens`
* `db_ai_openai_model`, `db_ai_openai_temperature`, `db_ai_openai_max_tokens`
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
