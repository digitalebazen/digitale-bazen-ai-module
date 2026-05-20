# Deployment & Release Workflow

Deze plugin distribueert via een **private GitHub repo + auto-updates**. Klantsites
checken automatisch op nieuwe versies en tonen "Update available" in de WP admin.
Met één klik halen ze de nieuwste release op.

---

## Eenmalige setup (developer-kant)

### 1. GitHub repo aanmaken

1. Maak een **private** repo aan op GitHub onder de Digitale Bazen organisatie:
   `https://github.com/DigitaleBazen/digitale-bazen-ai-module`
2. Push de huidige plugin folder als initial commit op de `main` branch:
   ```sh
   cd wp-content/plugins/digitale-bazen-ai-module/
   git init -b main
   git add .
   git commit -m "Initial commit — v1.0.0"
   git remote add origin git@github.com:DigitaleBazen/digitale-bazen-ai-module.git
   git push -u origin main
   ```
3. Tag de eerste release:
   ```sh
   git tag -a v1.0.0 -m "v1.0.0 — first stable release"
   git push origin v1.0.0
   ```
4. Maak op GitHub onder **Releases** een release aan vanaf tag `v1.0.0`. Geef
   een changelog-omschrijving mee. Dit is wat klant-sites zien als "What's new".

### 2. Default repo URL in code aanpassen

Open `includes/class-db-ai-updater.php` en pas zo nodig de constante aan:

```php
public const DEFAULT_REPO_URL = 'https://github.com/DigitaleBazen/digitale-bazen-ai-module/';
```

Dit is de fallback URL als een site geen `DB_AI_GITHUB_REPO_URL` constant zet.

### 3. Personal Access Token (PAT) genereren

Per klant-site is een GitHub PAT nodig om de private repo te lezen. Best practice:
**één gedeelde "deploy" PAT** met alleen leesrechten op deze repo.

1. GitHub → Settings → Developer settings → **Fine-grained personal access tokens**
2. **Generate new token**
3. Resource owner: `DigitaleBazen` (de organisatie)
4. Repository access: **Only select repositories** → kies `digitale-bazen-ai-module`
5. Permissions → Repository permissions:
   - **Contents: Read-only** (genoeg voor releases ophalen)
   - **Metadata: Read-only** (automatisch)
6. Set expiration: 1 jaar (of langer als je dit goed bijhoudt)
7. **Generate token** en kopieer hem direct (eenmaal getoond)

Bewaar deze PAT in 1Password / bitwarden / iets gedeeld voor het team. Bij rotatie
moet hij op alle klantsites tegelijk vervangen worden — kalender-reminder zetten
op de expiry-datum.

---

## Per klant-site installeren (eerste keer)

### 1. Plugin uploaden

Optie A — Handmatig (eerste keer, simpelste):
1. Zip de plugin folder lokaal: `cd wp-content/plugins/ && zip -r digitale-bazen-ai-module.zip digitale-bazen-ai-module/ -x "*.DS_Store" "digitale-bazen-ai-module/.git/*"`
2. WP admin → Plugins → Nieuwe plugin → Bestand uploaden → kies zip → installeer + activeer

Optie B — Via SSH/SFTP: kopieer de hele `digitale-bazen-ai-module/` folder naar `wp-content/plugins/`, activeer in admin.

### 2. Constants in wp-config.php

Voeg toe tussen regel 84 en 88 van `wp-config.php`:

```php
// Auto-update vanuit GitHub
define( 'DB_AI_GITHUB_REPO_URL', 'https://github.com/DigitaleBazen/digitale-bazen-ai-module/' );
define( 'DB_AI_GITHUB_TOKEN',    'github_pat_xxxxxxxxxxxxxxxx' );

// API keys (kunnen ook via Instellingen → AI Module)
// define( 'DB_AI_ANTHROPIC_API_KEY', 'sk-ant-...' );
// define( 'DB_AI_PEXELS_API_KEY',    '...' );
```

De `_GITHUB_TOKEN` is verplicht voor private repos. Zonder krijgt de site
401-errors van de GitHub API en blijven updates onzichtbaar.

### 3. Verifieer dat updates werken

WP admin → Plugins → **Op updates controleren** (rechtsboven). Als de huidige
versie achterloopt op de laatste GitHub release moet er een "Update available"
banner verschijnen onder de plugin.

---

## Workflow: nieuwe versie uitrollen

Voor elke release (bugfix, feature, etc.):

### 1. Code wijzigingen lokaal

Werk op een feature-branch:
```sh
git checkout -b feature/internal-linking
# ... code wijzigen ...
git commit -am "Add internal linking"
git push origin feature/internal-linking
# PR mergen naar main
```

### 2. Versie bumpen

**SemVer:** `MAJOR.MINOR.PATCH`
- **PATCH** (1.0.0 → 1.0.1): bugfix, geen gedragsverandering
- **MINOR** (1.0.1 → 1.1.0): nieuwe feature, backwards-compatible
- **MAJOR** (1.1.0 → 2.0.0): breaking change (settings-formaat anders, capability vereisten anders, etc.)

Bump op **drie plekken**:
1. `digitale-bazen-ai-module.php` → header `Version: 1.1.0`
2. `digitale-bazen-ai-module.php` → `define( 'DB_AI_VERSION', '1.1.0' );`
3. `readme.txt` → `Stable tag: 1.1.0` én changelog entry toevoegen

Commit dit naar `main`:
```sh
git checkout main
git pull
# ... bump versie in 3 plekken ...
git commit -am "Bump version to 1.1.0"
git push
```

### 3. Tag + release

```sh
git tag -a v1.1.0 -m "v1.1.0 — internal linking feature"
git push origin v1.1.0
```

Ga naar GitHub → Releases → **Draft a new release** → kies tag `v1.1.0` → vul
de release notes (changelog) → Publish.

### 4. Klantsites updaten

**Zonder iets te doen:** elke klantsite ziet binnen ~12 uur de update verschijnen.

**Forceer direct:** WP admin → Plugins → "Op updates controleren" knop.

Klik dan op **Update Now** bij de plugin. Klaar.

---

## Troubleshooting

### "Update available" verschijnt niet op een klantsite

1. Controleer in de PHP error log of er API-errors van GitHub binnenkomen
2. Test de PAT: `curl -H "Authorization: Bearer $TOKEN" https://api.github.com/repos/DigitaleBazen/digitale-bazen-ai-module/releases/latest` — moet JSON teruggeven, geen 401/404
3. Controleer of `DB_AI_GITHUB_REPO_URL` en `DB_AI_GITHUB_TOKEN` daadwerkelijk geladen zijn (bijvoorbeeld via een tijdelijke `var_dump(defined('DB_AI_GITHUB_TOKEN'));`)
4. Cache van WordPress: forceer met admin → Plugins → "Op updates controleren"

### PAT is verlopen

Genereer nieuwe PAT, vervang in `wp-config.php` op alle klantsites. Best practice:
hou een kalenderitem 30 dagen vóór expiry voor rotatie.

### Een release was per ongeluk gepubliceerd

Verwijder de tag + release op GitHub. De update-checker pakt hem niet meer op.
Klantsites die al hadden geupdatet moeten handmatig downgraden door een eerdere
zip terug te uploaden.

### Wel naar main pushen, niet als release publishen

Standaard hangt de update-checker af van **getagde releases**, niet de tip van main.
Dus dagelijks werk op main triggert geen update notificatie. Pas als je een nieuwe
tag pusht + GitHub release maakt, zien de sites de update. Dit is bewust gedrag.

---

## Bestanden die NIET in de repo horen

Voeg een `.gitignore` toe met:

```
.DS_Store
*.zip
node_modules/
vendor/composer/
```

De `vendor/plugin-update-checker/` folder MOET wél gecommit worden — die wordt
runtime geladen door de plugin.

`assets/vendor/xlsx.full.min.js` óók committen — runtime asset.
