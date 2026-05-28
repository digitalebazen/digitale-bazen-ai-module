# Outline-first generatie — Plan

> **Status**: ontwerp goedgekeurd 2026-05-28 (aparte knop, uitgebreide review, kop + 1-regel samenvatting). Bouwen in 3 fases.
>
> **Doel**: naast de bestaande één-klik "Genereer blogpost" een tweede flow waarin de AI eerst alleen de *structuur* (outline) genereert, de redacteur die bewerkt, en pas daarna de volledige blog wordt uitgeschreven. Bouwt op de async job-queue (v2.0.0).

---

## 1. Flow

```
Knop "Genereer met outline-review"
  → job_type generate_outline  (snel, ~5-10s)
  → review-paneel: secties bewerken / layout wisselen / toevoegen / verwijderen / herordenen
  → knop "Schrijf volledige blog"
  → job_type expand_outline    (volle content volgens goedgekeurde outline)
  → zelfde pipeline als nu: images, ACF write, RankMath SEO, FAQ, links, meta
```

De gewone "Genereer blogpost" knop + flow blijft **volledig ongewijzigd** (behavior-parity).

## 2. Datavormen

### Outline (output van fase 1)
```json
{
  "post_title_suggestion": "string",
  "focus_keyword": "string",
  "outline": [
    { "acf_fc_layout": "banner", "titel": "string", "summary": "1 regel — waar gaat deze sectie over" },
    { "acf_fc_layout": "tekst_met_afbeelding", "titel": "string", "summary": "string" }
  ]
}
```
- `acf_fc_layout` moet uit de toegestane layouts komen (zelfde `db_ai_allowed_layouts` filter).
- `summary` is puur voor de redacteur — gaat als sturing mee naar fase 2, komt niet letterlijk in de blog.

### Approved outline (input van fase 2)
Zelfde shape, na bewerking door de redacteur. Fase 2 schrijft de volle content en MOET de opgegeven layouts + volgorde + koppen respecteren.

## 3. Interfaces

### Provider (`DB_AI_Provider` interface — 2 nieuwe methodes)
```php
public function generate_outline( string $main_keyword, array $secondary_keywords, array $context );
public function expand_outline( string $main_keyword, array $secondary_keywords, array $approved_outline, array $context );
```
Beide providers (Anthropic + OpenAI) implementeren. **Refactor**: de HTTP-call + JSON-parse uit `generate_blog()` wordt geëxtraheerd naar een gedeelde private `call_api( $system_prompt, $user_prompt )` zodat alle drie de methodes 'm hergebruiken. `generate_blog()` gedraagt zich daarna identiek.

### ACF Mapper
- `validate_outline_output( $output ): array` — checkt outline-shape (allowed layouts, titel non-empty).
- `get_output_schema_example()` + `validate_ai_output()` blijven voor de expand-fase (zelfde volledige blog-JSON).

### Post_Creator (refactor, behavior-preserving)
- Split `create_from_keyword()` in: AI-call + `build_post_from_ai_output( $ai_output, ... )` (de gedeelde pipeline stap 2-11).
- Nieuw `create_from_outline( $main_keyword, $secondary, $user_id, $approved_outline, $blog_input )` → roept `expand_outline()` aan + dezelfde `build_post_from_ai_output()`.

### Job-queue (2 nieuwe job_types)
- `generate_outline` → handler roept `provider->generate_outline()` aan, slaat de outline op als job-result (geen post).
- `expand_outline` → handler roept `Post_Creator::create_from_outline()` aan met de approved outline uit de payload → maakt de draft.

### AJAX
- `db_ai_generate_outline` → dispatch generate_outline job → job_key (poll via bestaande `db_ai_job_status`).
- `db_ai_expand_outline` → dispatch expand_outline job met approved outline → job_key.

## 4. Review-UI (uitgebreid)

Verschijnt in stap 3 nadat de outline-job klaar is. Per sectie een rij:
- **Layout** — dropdown van toegestane layouts (uit gelokaliseerde lijst)
- **Kop** — tekst-input
- **Samenvatting** — read-only regel (context)
- **Acties** — omhoog / omlaag / verwijderen
- Onderaan: **"+ Sectie toevoegen"** en **"Schrijf volledige blog"**

JS verzamelt de bewerkte outline → `db_ai_expand_outline`.

## 5. Fases

### Fase 1 — Backend outline-generatie
- Provider `call_api()` extractie + `generate_outline()` (beide providers + interface)
- Outline system/user prompt + `validate_outline_output()`
- `generate_outline` job-type + handler + `db_ai_generate_outline` AJAX
- **Testbaar**: dispatch outline-job, poll, krijg outline-JSON terug

### Fase 2 — Backend expand
- Provider `expand_outline()`
- Post_Creator split + `create_from_outline()`
- `expand_outline` job-type + handler + `db_ai_expand_outline` AJAX
- **Testbaar**: gegeven een outline → volledige draft met alle bestaande features

### Fase 3 — Frontend
- Tweede knop "Genereer met outline-review" in stap 3
- Review-paneel (layout-dropdown, reorder, add/remove)
- JS: generateOutline → poll → renderOutlineReview → expandOutline → poll → result
- **Testbaar**: end-to-end

## 6. Behavior-parity
De bestaande één-klik flow blijft ongemoeid. De Post_Creator-pipeline wordt alleen gesplitst (geen logica-wijziging). Outline-first is puur additief: nieuwe knop, nieuwe job_types, nieuwe provider-methodes.

## 7. Open punten
- **Rate-limit**: telt een outline+expand als 1 of 2 generaties? Voorstel: outline is gratis (geen post), expand telt als 1 — net als de gewone flow. Dispatch van expand reserveert het slot.
- **Vervallen outline**: als de redacteur het tabblad sluit na de outline maar vóór expand, gebeurt er niets (geen post). Outline-job-result verloopt via de normale cleanup-cron.
