# Async Generator — Refactor Plan

> **Status**: ontwerp — nog geen code geschreven. Goedkeuren of redirect voordat we beginnen.
>
> **Doel**: synchrone `db_ai_generate` AJAX vervangen door een async job-queue zodat lange operaties (blog-generatie, en straks bulk + per-block + outline-first) niet meer botsen op host-timeouts. Tegelijk leggen we de fundering waar alle V3-backlog features op leunen.

---

## 1. Doel + scope

### Wat lossen we op
- **Hard timeouts op productie** (504 / FastCGI idle / Cloudflare 100s / PHP `max_execution_time`). De browser krijgt nu een fout terwijl PHP nog vrolijk wacht op Anthropic. Niet meer.
- **Foundation voor V3-features** die allemaal langlopende of meerstaps-orchestratie nodig hebben (zie sectie 8).
- **Echte progress-reporting**: server publiceert tussenstand, JS leest die. Eindelijk weg van de fake-curve.

### Wat we expliciet NIET oplossen in deze refactor
- **Eén AI-call die alleen al langer duurt dan de host-timeout**. Async-architectuur dwingt PHP niet door host-grenzen heen. Als Sonnet 4.6 op een 30s-host 45s nodig heeft voor één call, faalt die worker-run nog steeds. Daarvoor blijven we afhankelijk van **streaming + keep-alive flushing** of een Haiku-fallback (sectie 4.4).
- **Per-blog rate limiting herzien**. De huidige `DB_AI_Rate_Limiter` blijft; we passen alleen aan wanneer de "slot-reservering" gebeurt (bij dispatch, niet bij start).
- **Bulk + per-block regeneratie features zelf**. Die bouwen later op deze infra, maar zitten niet in deze sprint.

---

## 1B. Harde eis: behavior-parity (NIET-onderhandelbaar)

**Alle bestaande functionaliteit blijft 1-op-1 werken.** De user is expliciet zeer tevreden over de huidige flow en wil geen enkele regressie. Dit is een harde acceptatie-eis, geen nice-to-have.

### Waarom dit haalbaar is
De refactor zit op de **transport/orchestratie-laag**, niet op de business-logic:
- `DB_AI_Post_Creator::create_from_keyword()` behoudt z'n exacte logica. We voegen alleen `report_progress()`-calls toe en roepen 'm aan vanuit een job-worker ipv direct vanuit de AJAX-handler.
- `DB_AI_ACF_Mapper`, validators, providers, `DB_AI_Image_Service`, `DB_AI_SEO_Mapper`, `DB_AI_FAQ_Schema`, `DB_AI_Internal_Links`, `DB_AI_External_Links`, `DB_AI_Rankmath_Bridge` — **volledig onaangeraakt**. Die kennen de generatie-mechaniek niet eens.
- Settings, wizard-UI, power-word lijst, prompts — onaangeraakt.

### Regressie-checklist (aflopen vóór "klaar" gemeld wordt)
Een via de async-flow gegenereerde blog moet identiek zijn aan de huidige sync-output:

- [ ] Draft aangemaakt onder CPT `blog`, status `draft`
- [ ] Alle ACF flex-blocks correct gevuld (titel/tekst/images per layout)
- [ ] Featured image + per-block afbeeldingen gedownload + gesideload
- [ ] RankMath velden (`rank_math_focus_keyword`, `_title`, `_description`)
- [ ] FAQ JSON-LD injectie werkt op posts met `veelgestelde_vragen` block
- [ ] Interne links geplaatst (indien aan) + orphan-cleanup
- [ ] Externe link-suggesties opgeslagen in `_db_ai_external_link_suggestions`
- [ ] RankMath bridge feedt nog steeds de analyzer (subkop/density/links checks groen)
- [ ] Power-word + getal in titel + meta_title
- [ ] Alle `_db_ai_*` audit-meta keys aanwezig
- [ ] Rate limiting blokkeert na de dag-limiet
- [ ] Logger-entry in `wp_db_ai_generations`
- [ ] 3-staps wizard + "Geavanceerd" toggle ongewijzigd
- [ ] Quota-teller klopt na generatie
- [ ] Settings-pagina volledig ongewijzigd

Pas als élk vinkje door een echte test in de browser gehaald is (niet alleen lint/type-check), is de fase af.

---

## 2. Architectuur

### Componenten

```
┌─────────────┐    POST db_ai_generate     ┌────────────────────┐
│  Browser /  │ ─────────────────────────► │  AJAX dispatcher   │
│  JS poller  │ ◄───────── job_key ─────── │ (DB_AI_Ajax)       │
└─────────────┘                            └──────────┬─────────┘
       │                                              │
       │ GET db_ai_job_status                         │ DB_AI_Job_Queue::dispatch()
       │ (elke 2-3s)                                  │  · INSERT in wp_db_ai_jobs
       ▼                                              │  · as_enqueue_async_action()
┌─────────────────────┐                               │
│ Status endpoint     │                               ▼
│ (DB_AI_Ajax)        │                  ┌────────────────────────┐
└─────────┬───────────┘                  │  Action Scheduler      │
          │                              │  worker (loopback)     │
          ▼                              └──────────┬─────────────┘
   reads wp_db_ai_jobs                              │
                                                    │ DB_AI_Job_Queue::run()
                                                    ▼
                                       ┌────────────────────────────┐
                                       │ DB_AI_Post_Creator         │
                                       │  (refactored — reports     │
                                       │   progress per stap)       │
                                       └────────────────────────────┘
                                                    │
                                                    │ $job->update_progress($pct, $label)
                                                    ▼
                                              wp_db_ai_jobs
```

### Lifecycle

`queued` → `running` → `done` | `failed`

Monotonic. Geen resurrectie. Job-row wordt nooit herstart vanuit `done`/`failed` — een nieuwe poging = nieuwe job.

---

## 3. Sleutel-beslissingen

### 3.1 Job-runner: **Action Scheduler indien aanwezig + WP-Cron fallback**

**Waarom**: WP-Cron draait alleen wanneer er traffic is. MKB-sites met lage traffic hebben tot 12u latency. Action Scheduler heeft een eigen loopback-loop die continu draait, plus admin UI, retries en deduplicatie.

**Bevinding tijdens fase 1 (2026-05-28)**: Action Scheduler is **al aanwezig op deze site** — RankMath bundelt 'm (`seo-by-rank-math/vendor/woocommerce/action-scheduler/`), en RankMath is de SEO-integratie van deze plugin. We hoeven dus **niets te bundelen** (geen +1MB). Aanpak: AS gebruiken als `function_exists('as_enqueue_async_action')` true is, anders terugvallen op `wp_schedule_single_event()` + `spawn_cron()`. Beide triggeren dezelfde `db_ai_run_job` action → één code-pad voor uitvoering.

Dit is robuuster dan hard-bundelen: werkt op elke site, geen versie-conflicten, geen plugin-bloat. Als een site géén AS heeft (geen RankMath/WooCommerce), draait de fallback prima voor het lage volume van een blog-generator.

**Janitor + cleanup**: aparte WP-Cron events (`db_ai_sweep_jobs` hourly, `db_ai_cleanup_jobs` daily) — onafhankelijk van AS, want die moeten ook draaien op sites zonder AS. Opgeruimd bij plugin-deactivatie.

### 3.2 Job-store: **eigen tabel** `wp_db_ai_jobs`, niet `wp_options`

Transients/options gaan corrupt onder concurrency en hebben geen indexering. We hebben al `wp_db_ai_generations` voor post-mortem logging — die houden we apart (logger = audit, jobs = runtime state). Schema in sectie 4.1.

### 3.3 Migratie: **hard switch**, geen feature flag

Bestaande synchrone flow gaat weg. Reden: zodra de V3-features (bulk, per-block) erin gaan, kunnen die NIET sync werken. Dan zou de codebase twee paden hebben, dubbel onderhoud, complexere tests. We snijden 'm in één keer om.

Sites die updaten naar deze versie krijgen automatisch de nieuwe flow. Eerste-keer gebruikers merken niets (URL is hetzelfde, JS is anders).

### 3.4 Rate-limit reservering: **bij dispatch**, niet bij start

Huidige `can_generate()` check zit in de AJAX-handler vóór provider-call. Met async moeten we de slot reserveren bij dispatch (anders kan een gebruiker 10 jobs queue'en en alle limits omzeilen). Bij job-failure: slot teruggeven.

---

## 4. Interfaces

### 4.1 DB schema

```sql
CREATE TABLE {$prefix}db_ai_jobs (
  id           BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_key      VARCHAR(64)         NOT NULL,        -- UUID, publiek identifier
  user_id      BIGINT(20) UNSIGNED NOT NULL,
  job_type     VARCHAR(40)         NOT NULL,        -- 'generate_blog' (later: 'regenerate_block', 'bulk_batch')
  status       VARCHAR(20)         NOT NULL,        -- queued | running | done | failed
  progress     TINYINT  UNSIGNED   DEFAULT 0,       -- 0-100
  stage_label  VARCHAR(150)        DEFAULT '',
  payload      LONGTEXT            NOT NULL,        -- json: input voor de runner
  result       LONGTEXT            NULL,            -- json: { post_id, edit_link, warnings, tokens, ... }
  error_code   VARCHAR(80)         NULL,            -- bv. 'ai_error', 'validation_error', 'timeout'
  error_msg    TEXT                NULL,
  created_at   DATETIME            NOT NULL,
  started_at   DATETIME            NULL,
  completed_at DATETIME            NULL,
  KEY job_key_idx   (job_key),
  KEY user_id_idx   (user_id),
  KEY status_idx    (status),
  KEY created_idx   (created_at)
) {$wpdb->get_charset_collate()};
```

### 4.2 PHP API

```php
final class DB_AI_Job_Queue {
    /**
     * Spin een nieuwe job op. Returnt het job_key zodat de UI kan pollen.
     *
     * @throws WP_Error  bij rate-limit-hit of insert-failure
     */
    public static function dispatch( string $job_type, array $payload, int $user_id ): string;

    /**
     * Lees status — voor poll-endpoint. Capability-check op user_id zit IN deze helper.
     */
    public static function get_status( string $job_key, int $current_user_id ): array;

    /**
     * Wordt aangeroepen door Action Scheduler hook `db_ai_run_job`.
     * Resolved het job-type naar de juiste runner-class.
     */
    public static function run( string $job_key ): void;

    /**
     * Update voortgang vanuit een runner. Mutates wp_db_ai_jobs.
     */
    public static function report_progress( string $job_key, int $pct, string $stage_label ): void;
}
```

### 4.3 AJAX endpoints

| Action | Method | Returns | Doel |
|---|---|---|---|
| `db_ai_generate` | POST | `{ job_key, status: 'queued' }` | Dispatch een blog-generate job. Vervangt huidige sync flow. |
| `db_ai_job_status` | GET | `{ status, progress, stage_label, result?, error_code?, error_msg? }` | Poll-endpoint, elke 2-3s door JS. |

### 4.4 AI-call binnen worker-timeout

Action Scheduler worker draait als loopback HTTP-request → onderworpen aan PHP-FPM `max_execution_time` van de host. Sonnet 4.6 piekt soms op 50s.

**Mitigaties**, opt-in volgorde:
1. **`set_time_limit(0)` in worker** — werkt op de meeste shared hosts, faalt op managed-WP-hosts met hard php.ini lock
2. **Streaming met flushing** — Anthropic API ondersteunt streaming. We accumuleren chunks server-side, flushen elke ~3s output naar de loopback-connectie zodat upstream timers resetten. Total worker-tijd blijft hetzelfde, maar veel servers killen alleen op idle (geen data) — flush voorkomt dat.
3. **Haiku 4.5 fallback** — Settings toggle "Snelle modus" of host-detect: gebruik Haiku ipv Sonnet als de host-timeout krap is. Haiku doet 5-15s voor een blog, fits in elke 30s-host.

Voor implementatie nu: alleen #1 inbouwen. #2 en #3 als follow-ups, beslissen op basis van productie-meetwaardes na deze refactor.

---

## 5. JS rewrite

`generateBlog()` wordt:

```javascript
generateBlog() {
  POST db_ai_generate { ... payload ... }
    → { job_key }
  startPolling(job_key);
}

startPolling(job_key) {
  const interval = setInterval(2500, async () => {
    const res = await GET db_ai_job_status?job_key=...
    updateProgressBar(res.progress, res.stage_label);
    if (res.status === 'done')   { clearInterval(interval); renderResult(res.result); }
    if (res.status === 'failed') { clearInterval(interval); showError(res); }
  });
}
```

Stop-conditions:
- Polling stopt automatisch bij `done`/`failed`
- Bij tab-close: polling stopt vanzelf (JS afgebroken). Job blijft draaien en is later in de admin (job-historie of Action Scheduler UI) zichtbaar.
- Max poll-duur: 10 minuten. Daarna stop client-side met "Job duurt onverwacht lang, check zelf in admin". Server-side blijft job lopen, geen abort.

---

## 6. Progress-reporting refactor in Post_Creator

Huidige `create_from_keyword()` heeft 11 stappen (zie PROJECT_BRIEF sectie 13). We voegen `$job->report_progress()`-calls in op zinvolle checkpoints:

| Stap | Stage label | %  |
|---|---|---|
| Start | "Zoekwoord-context verzamelen" | 2 |
| Pre-AI (links pool builden) | "Interne links zoeken" | 8 |
| AI-call gestart | "Generator schrijft je blog" | 12 |
| AI-respons binnen | "Generator schrijft je blog" | 50 |
| Output valideren | "Output valideren" | 55 |
| Insert post | "Blog aanmaken" | 60 |
| Walk block images (per image +5%) | "Afbeelding 1/5 ophalen" | 65→90 |
| Featured image | "Coverfoto kiezen" | 92 |
| ACF write | "Blocks invullen" | 95 |
| SEO + meta | "Klaar..." | 98 |
| Klaar | "Klaar!" | 100 |

Image-fetching procentages incrementeel zodat de bar daadwerkelijk meebeweegt over de ~15s die het kost.

---

## 7. Implementatie-fases

### Fase 1 — Job-infrastructure (foundation) ✅ AFGEROND 2026-05-28
- ✅ DB-schema `wp_db_ai_jobs` + migration via `DB_AI_Logger`-style dbDelta (eigen versie-optie `db_ai_jobs_db_version`)
- ✅ `DB_AI_Job_Queue` class: dispatch / get_status / run / report_progress / mark_done / mark_failed
- ✅ Runner-abstractie: AS indien aanwezig, anders WP-Cron single-event fallback
- ✅ Rate-limit reservering bij dispatch (in-flight jobs tellen mee via `can_dispatch()`)
- ✅ Janitor (`sweep_stuck_jobs` — running zonder heartbeat > 5 min → failed) + cleanup-cron (done > 30d, failed > 7d)
- ✅ Handler-registry (`register_handler()`) zodat fase 2 het `generate_blog`-type kan inpluggen
- ✅ Wiring in bootstrap + activation + deactivation (cron-cleanup) + maybe_upgrade
- ⏳ Nog niet gewired in de generate-flow — dat is fase 2. Geen user-zichtbare wijziging, nul regressie-risico.

### Fase 2 — Async generate flow ✅ AFGEROND + GETEST 2026-05-28
- ✅ `db_ai_generate` AJAX → dispatch-only (returnt job_key)
- ✅ Nieuwe `db_ai_job_status` AJAX poll-endpoint (capability + ownership check)
- ✅ Worker-handler `run_generate_blog_job` geregistreerd op job-type `generate_blog`
- ✅ `DB_AI_Post_Creator` voorzien van optionele progress-reporter (setter) + 8 checkpoint-calls; generatie-logica volledig onveranderd
- ✅ `mark_failed` uitgebreid met data-param zodat validation_errors naar de UI bubbelen
- ✅ Bugfix: DB_AI_Ajax wordt nu altijd geïnstantieerd (niet admin-gated) zodat de worker-handler óók in de Action Scheduler / WP-Cron request beschikbaar is

### Fase 3 — JS rewrite + UI ✅ AFGEROND + GETEST 2026-05-28
- ✅ `generateBlog()` → POST dispatch + `pollJobStatus()` elke 2,5s
- ✅ Progress bar leest echte server-progress (fake curve vervangen door `showGenerateProgress()`)
- ✅ Failure-states tonen `error_msg` + validation_errors; 10-min poll-vangnet
- ✅ Nieuwe i18n-keys (progressQueued/Done/Failed, jobTimeout)

**Getest door user**: volledige flow werkt, behavior-parity bevestigd (blocks, images, SEO, FAQ, links, quota allemaal correct).

### Fase 4 — Cleanup + admin
- Job-cleanup cron: `done` > 30 dagen weg, `failed` > 7 dagen weg
- Admin page "Generator job-historie" (kan eerst gewoon Action Scheduler's eigen UI gebruiken)
- Eventueel: bell-icoon in admin-bar met "X jobs running"

**Geschatte effort**: 0.5-1 dag

**Totaal**: 2.5-4 dagen werk verspreid, exclusief productie-testing en eventueel #2/#3 mitigaties.

---

## 8. Wat dit ontgrendelt

Na deze refactor liggen alle V3-features open zonder nog een keer architectuur-werk:

| Feature | Hoe het op deze infra leunt |
|---|---|
| **Bulk-generatie** (5-10 blogs uit één KWO) | Dispatch N jobs ineens; AS draait ze sequentieel of parallel. UI toont een lijst met progress per job. |
| **Per-block regeneratie** | Nieuw `job_type = 'regenerate_block'`, payload = post_id + block_index. Hergebruikt 80% van de pipeline. |
| **Outline-first flow** | Twee jobs: eerst `job_type = 'generate_outline'` (snel), user keurt goed in een review-UI, dan `job_type = 'expand_outline'` met de goedgekeurde structuur. |
| **Update-bestaande-blog** | `job_type = 'update_post'`, payload = post_id + update-instructie. Diff-aware. |
| **Echte progress-bar** | Al gedaan in fase 3. |
| **Notificaties bij done** | Job-table heeft `completed_at` + `user_id` — admin-notice op next-page-load is triviaal toe te voegen. Email kan via dezelfde hook. |

---

## 9. Open vragen — beantwoorden vóór coding

1. **Bundelen we Action Scheduler of vereisen we 'm als dependency?** Mijn voorstel: bundelen (geen install-friction voor de klantsites). Bevestig of dat OK is gezien de +1MB.
2. **Wat doen we met jobs die "vastlopen"?** Voorbeeld: worker is gestart maar Anthropic geeft geen antwoord, PHP-timeout kapt de worker af zonder job-status-update. Job blijft op `running` hangen. Voorstel: heartbeat-veld + janitor-cron die jobs > 5 min in `running` zonder progress-update markeert als `failed (timeout)`. Akkoord?
3. **Browser tab-close midden in job — wat is UX?** Voorstel: job blijft draaien, draft verschijnt vanzelf onder Blogs als 't gelukt is. Geen email/notification (kunnen we later toevoegen). Akkoord?
4. **Backwards-compat met huidige post-meta keys (`_db_ai_*`)?** Niets verandert daarin — die zijn audit-data. Geen actie nodig.
5. **Mag de oude sync-code helemaal weg, of behouden als feature-flagged fallback?** Mijn voorstel: helemaal weg. Eén code-pad. Bevestig.

---

## 10. Wat is GEEN onderdeel van deze refactor

- **Quality-scoring / similarity checks** — apart traject (zie "leert tool van eigen posts"-vraag)
- **Image preview + selectie** — losse feature, los te schipperen na async-infra
- **Streaming UI met tokens-while-typing** — kan later op deze infra bouwen via Server-Sent Events
- **Externe link advisor wijzigingen** — staat los van generation-pipeline

---

## 11. Beslissingsmoment

Vóór ik begin met fase 1 wil ik bevestiging op:

- [ ] Architectuur-shape akkoord (Action Scheduler + custom job-table + AJAX poll)?
- [ ] Hard switch (geen sync-fallback) akkoord?
- [ ] Open vragen 1-3 beantwoord (bundelen AS / vastlopers / tab-close UX)?
- [ ] Versie-strategie: dit wordt v2.0.0 (MAJOR — architectuur-change). Of toch v1.5.0 omdat externe API/behavior niet breekt? Ik leun naar v2.0.0 zodat de migratie-impact zichtbaar is. Bevestig.

Zodra deze checks groen staan, begin ik fase 1.
