# V3.0.0 — Wijzigingen & bouwopdracht

> **✅ STATUS: GEBOUWD & GERELEASED (2026-06-11).** Deze bouwopdracht is volledig uitgevoerd: v3.0.0 (Plan-laag + menu) en v3.1.0 (aanbevelingen-paneel + pillar-fix) staan live. Dit document is nu historisch — de **as-built** stand (incl. uitbreidingen t.o.v. deze spec: blog-companions, update-in-place, batched analyse, export/import, aanbevelingen, inklapbare clusters) staat in **PROJECT_BRIEF.md §0P**. De per-deelstap-acceptatiecriteria hieronder zijn allemaal gehaald.

> **Voor de AI-assistent in VS Code.** Dit document vertelt je precies wat er in `PROJECT_BRIEF.md` is veranderd voor de v3.0.0 MAJOR, en wat je moet bouwen. Lees dit eerst, dan de genoemde brief-secties. De plugin stond op v2.2.2 (volledig werkend) en gaat naar **v3.0.0**.

---

## Wat is v3.0.0 in één zin

Een **eigen top-level admin-menu "Generator"** (submenu's Creatie / Plan / Instellingen) **plus** een **strategische Plan-laag** die het zoekwoordenonderzoek omzet in een contentplan (clusters → pillars → supporting posts) vóórdat er een blog wordt geschreven. Menu en Plan-laag horen bij elkaar en vormen samen de MAJOR.

---

## A. Wat is gewijzigd in PROJECT_BRIEF.md (alleen documentatie, nog geen code)

De brief is geüpdatet; de **code moet nog volgen** (zie deel B). Gewijzigde/nieuwe secties:

| Sectie | Wat erin staat |
|---|---|
| **§0** (build status) | Versie → v3.0.0. Afwijkingstabel rij 2 (menu-locatie) bijgewerkt. |
| **§0N** (nieuw) | Menu-herstructurering: top-level "Generator" + 3 submenu's, gewijzigde filters, redirect oude slugs. |
| **§0O** (nieuw) | Plan-laag — inhoudelijke achtergrond: de pijplijn, classificatie-regels, pillar-regel, **funnel-brug** (`angle`/`funnel_target`/`bridge` — informatieve keywords inkaderen richting een dienst), data-opslag, automatiseringstrap. |
| **§1** | "Wat bouwen we" verwijst nu naar het top-level menu i.p.v. "Blogs → AI Blog Genereren". |
| **§2** | wp-config-comment: "Generator → Instellingen → API-keys". |
| **§4** (folder-boom) | Toegevoegd: `class-db-ai-plan-page.php`, `class-db-ai-planner.php`, `plan.js/.css`. Settings/admin-page comments bijgewerkt. File-count 28 → 30. |
| **§14 Stap 1** | Menu-code in build-order vervangen door de top-level structuur. |
| **§14 Stap 8** (nieuw) | **DE BOUWOPDRACHT** — opgesplitst in 8a/8b/8c/8d met acceptatiecriteria per deelstap. |
| **§16** (hooks) | `db_ai_admin_menu_parents` (meervoud) → deprecated; nieuwe `db_ai_admin_menu_parent` + `_icon`/`_position`/`_capability`. |
| **§17** (backlog) | v3.0.0 toegevoegd; resterende Plan-automatisering (trap 2/3) blijft V3-backlog. |
| **§18** (vaste beslissingen) | Menu-regel bijgewerkt naar top-level. |

---

## B. Wat je moet BOUWEN (de opdracht)

**De volledige spec staat in §14 Stap 8 van de brief.** Werk strikt in volgorde 8a → 8b → 8c → 8d, stop en test na elke deelstap. Hieronder de kern, zodat je weet wat je oppakt:

### 8a — Menu-herstructurering (§0N)
Bouw eerst alleen het menu om, nog geen Plan-inhoud.
- `DB_AI_Admin_Page`: van `add_submenu_page('edit.php?post_type=blog', ...)` → top-level `add_menu_page('db-ai', ...)` + Creatie-submenu op dezelfde slug.
- `DB_AI_Settings`: menu-parent van `options-general.php` → `db-ai` (slug `db-ai-settings`). Velden/opslag ONGEWIJZIGD.
- Redirect-shim (`admin_init`) van oude slugs naar nieuwe.
- Filter-rename (`db_ai_admin_menu_parents` → deprecated no-op; nieuw `db_ai_admin_menu_parent`).

### 8b — `DB_AI_Planner` (de strateeg, los testbaar, GEEN UI)
- Eén AI-call (bestaande `DB_AI_Anthropic_Provider`) op de hele keyword-lijst.
- Output = strikte JSON per keyword: `intent` / `cluster` / `role` (pillar|supporting|—) / `pillar_ref` / `bundled_keywords` / **`angle`** / **`funnel_target`** / **`bridge`** / `reason`.
- Classificatie-prioriteit: plaatsnaam → lokaal; vraag/kosten → informatief; laten maken/bureau → commercieel.
- Pillar bundelt synoniemen op één pagina; supporting = wezenlijk andere deelvraag.
- **Funnel-brug (zie §0O):** per informatief keyword bepaalt de planner een inkaderende `angle` + een `funnel_target` (dienst/pillar) + een eerlijke `bridge`. Zo wordt een ogenschijnlijk los keyword (bv. "snelheid website testen") een dienst-voedende post i.p.v. een platte how-to. Forceer geen brug naar een irrelevante dienst → dan `funnel_target: null`.
- Defensief parsen (strip ```json, valideer schema).

### 8c — Opslag + Plan-overzicht UI (`DB_AI_Plan_Page`)
- Plan-velden als post-meta op `db_ai_kwo` (`_db_ai_intent`, `_db_ai_cluster`, `_db_ai_role`, `_db_ai_pillar_ref`, `_db_ai_angle`, `_db_ai_funnel_target`, `_db_ai_bridge`, `_db_ai_status`, `_db_ai_post_id`, `_db_ai_bundled`).
- "Analyseer onderzoek"-knop draait de planner via de **job-queue** (§0F), nieuw `job_type: 'plan'`.
- UI gegroepeerd per cluster (pillar bovenaan, supporting eronder); kolom **angle** is inline bewerkbaar; aparte sectie voor niet-blog keywords.
- **Pillar-eerst**: supporting-"Genereer"-knoppen disabled tot de pillar gegenereerd is.

### 8d — Plan-context → Creatie (koppeling)
- "Genereer" vanuit Plan geeft `cluster` / `role` / `pillar_ref` / **`angle`** / **`funnel_target`** / **`bridge`** / `avoid_overlap` mee via het bestaande `DB_AI_Blog_Input` (§0I) — **raak `DB_AI_Post_Creator` NIET aan**. De `angle`/`bridge` sturen de invalshoek; `funnel_target`/`pillar_url` sturen interne link + CTA.
- Na generatie: plan-status → `gegenereerd` + koppel post-id; bij publish → `gepubliceerd`.
- Automatiseringsniveau v3.0.0 = **"Geassisteerd"** (mens kiest de rij, kan de angle bijsturen).

---

## C. Harde randvoorwaarden (niet schenden)

1. **Bestaande generatie-logica blijft ongewijzigd.** De Plan-laag voegt alleen prompt-*context* toe via `DB_AI_Blog_Input`. `DB_AI_Post_Creator` raak je niet aan.
2. **Draft-only blijft.** Geen autonome publicatie. De publiceer-klik blijft menselijk, ook in de Plan-flow.
3. **Geen Composer / geen build-step.** Native PHP + vanilla JS, conform §3/§18.
4. **Eén provider (Anthropic).** Gebruik `DB_AI_Anthropic_Provider` voor de classificatie-call; geen nieuwe provider.
5. **Async voor zware calls.** Classificatie van een grote lijst draait via de job-queue (§0F), niet synchroon.
6. **Incrementeel.** Bouw 8a→8b→8c→8d in volgorde, commit en stop na elke deelstap, wacht op feedback. Begin met 8a (menu) want dat is los van de Plan-logica en meteen testbaar.

---

## D. Suggestie voor versie-bumps tijdens de bouw

Omdat dit een MAJOR is met deelstappen, kun je tijdens het bouwen patch/minor-bumps gebruiken en pas bij afronding van 8d de `Stable tag` naar `3.0.0` zetten:
- 8a klaar → `2.3.0-dev` of `3.0.0-beta.1` (menu staat)
- 8b klaar → planner getest los
- 8c klaar → plan zichtbaar + opgeslagen
- 8d klaar → koppeling werkt → **bump naar `3.0.0`** in header, `DB_AI_VERSION`, en `readme.txt` changelog.

Werk de `readme.txt`-changelog bij per deelstap (dat is de gezagvolle per-patch historie), en de §0N/§0O + §4-boom in de brief zodra de code afwijkt van de spec.