# AI-backend (Patch Studio) — wat er gebouwd is

Bouwer `ai-backend` · 18-11-2026 · contract: `docs/ai/SPEC-AI-DIENSTEN.md` (§3–§6, §8, §9, §12, §13).
Alles staat in `plugin/borduur-patch-studio/includes/ai/` en registreert zich met hooks. Geen bestaand bestand gewijzigd.
Tot de integratie (`docs/handoff/ai-services-needs.md` §Architectuur 1) laadt niemand de module.

## Bestanden

| Bestand | Rol |
|---|---|
| `class-module.php` | Bootstrap: hooks, install (`bps_activate`), dagelijkse cron `bps_ai_cleanup` (03:40), `config.ai` in `bps_public_config`, script `bps-ai` registreren (nooit enqueuen), `store()`, `legal()`, `prompt_cfg()`, admin-log (50 regels, geredigeerd), `uninstall()` |
| `class-schema.php` | Tabellen `bps_ai_owners/jobs/costs` (+ `lots/ledger` met dezelfde SQL als commerce), db-versie `bps_ai_db_version` |
| `class-store.php`, `class-store-wpdb.php` | Opslagcontract + `$wpdb`-implementatie (alles via `prepare/insert/update`) |
| `class-ai-settings.php` | Instellingen: code < `ai-providers.json` < `ai-pricing.json` < optie `bps_ai_settings` (gedeeld met commerce; enkel eigen sleutels schrijven), grenzen (`BOUNDS`) |
| `class-secrets.php` | Sleutels: constante `BPS_OPENAI_API_KEY`/`BPS_RECRAFT_API_KEY` wint, anders `sodium_crypto_secretbox` in `bps_ai_secrets` (autoload nee); `redact()` |
| `class-identity.php` | Eigenaar = HMAC-sleutel (`u:`/`e:`/`a:`/`x:`), cookie `bps_ai_sid`, IP enkel als HMAC, samenvoegen anoniem → e-mail → account |
| `class-credits-gateway.php` | Brug naar `Services\Credits` (commerce). Zonder commerce: test = onbeperkte testrondes, live = 0 (402) |
| `class-jobs.php` | Validatie, idempotentie, brief, moderatie, toelating, rondes, jobrijen en alle JSON-vormen (JobResponse, LocalRefine, ChooseResponse, StatusResponse, history) |
| `class-job-runner.php` | Atomaire claim, provider + 1× terugval, transparantie-terugval, opslag, kostlog, terugboekingen, time-outs, Action Scheduler of inline |
| `class-rest.php` | `/ai/status · generate · jobs/{id} · cancel · refine · choose · image · history` |
| `class-limits.php` | Ratebuckets, min. interval, max per dag, dag/maand/hard plafond met reserveringen, misbruikpauze, alertmail |
| `class-cost-log.php` | Kostlog (geen persoonsgegevens), dag/maandtotalen (sitetijdzone, 60 s cache), CSV |
| `class-moderation.php` | Laag 2a: OpenAI `omni-moderation-latest` op de gezuiverde tekst (gratis; storing blokkeert nooit) |
| `class-image-store.php` | Privé-opslag `uploads/patch-studio/ai/{jjjj}/{mm}/`, `.htaccess`+`index.php`, AI-markering (PNG iTXt + XMP, SVG `<metadata>`), C2PA-origineel bewaard, ondertekende URL's (1 u) |
| `class-design-link.php` | `bps_design_created` → `_bps_ai` op het ontwerp (enkel eigen, gekozen voorstel); `bps_design_ordered` → op het orderitem |
| `class-cleanup.php` | Retentie: verlopen jobs + beelden, geweigerde beschrijvingen na 30 d → null, `ip_hash` na 30 d, time-outs, kavels laten vervallen |
| `class-privacy.php` | Exporter/eraser (additief) + privacytekst (04 §6.7) |
| `class-admin.php` | **WooCommerce → Patch Studio AI**: tabs Aan/uit · AI-dienst · API-sleutels · Limieten & kosten · Bewaren; extra tabs via filter `bps_ai_admin_sections` |
| `class-messages.php` | Klantmeldingen NL (je) / FR (vous), foutvorm `{code, message, data:{status}}` |
| `providers/*` | `Provider` (interface), `Provider_Result`, `Provider_Error`, `Provider_Registry`, `Http_Provider` (basis), `Mock_Provider`, `Openai_Provider`, `Recraft_Provider` |
| `config/ai-providers.json`, `config/ai-mock.json`, `config/ai-mock/*.svg` | Providers, prijzen (`verified:false`), moderatie-mapping; 10 eigen borduurbare voorbeeldtekeningen |

## Providers (onderzoek `01-apis.md`)

- **Standaard `openai`** (`gpt-image-2`, kwaliteit medium, 1024², transparant, `moderation:"auto"`, geen `user`-veld,
  geen `input_fidelity`). Kost uit `usage` (tokens × prijs), anders schatting (`estimated=1`).
  Weigert het model transparantie (400 op `background`) → 1× opnieuw met stofkleur als achtergrond.
- **Terugval `recraft`** (V4 Vector, SVG) — enkel bij `rate_limit/timeout/unavailable/auth` en enkel met sleutel.
  **Beleidsweigeringen gaan nooit naar de terugval.** Ook vectoriseren na "Gebruik dit" (optioneel, 15 s).
- **`mock`** — geen netwerk/sleutel/kost; modus `test` gebruikt altijd de mock. Kiest sjablonen op de Engelse
  brief-onderwerpen, kleurt met de briefkleuren, vorm als kader, tekst bij `textMode=ai`, n verschillende varianten,
  `edit()` = zichtbaar dikkere lijnen/omgewisselde kleuren. Testhaken (enkel test of `WP_DEBUG`) in de beschrijving:
  `[[mock:fail]]`, `[[mock:policy]]`, `[[mock:slow]]`, `[[mock:timeout]]` (worden uit de prompt gehaald).
- Nieuwe provider: filter `bps_ai_providers` (id → klasse die `Provider` implementeert).

## Job-verloop

`POST /ai/generate` → validatie (400) → idempotent op `clientRequestId` → misbruikpauze (423) → 1 lopende job (409)
→ `Prompt_Engine::brief()` (lokale filter: geweigerd = 200 blocked, geen ronde) → moderatie-API (idem) →
`Limits::admit` (429/503, reservering van de geschatte kost) → `Credits::reserve` (402) → job `queued` → Action
Scheduler. `GET /ai/jobs/{id}` draait de job inline als hij > 3 s wacht (of zonder Action Scheduler), en zet te lange
jobs op `failed/timeout` met terugboeking. Elke mislukking, weigering door de provider en annulering = ronde terug.

## Privacy en veiligheid

- Naar de AI: enkel de Engelse prompt uit de gezuiverde beschrijving (+ bij verfijnen ons eigen beeld). Nooit naam,
  e-mail, IP, cookie, order- of klant-ID; `safety_id` (HMAC) enkel als `ai-legal.json defaults.sendSafetyIdentifier`.
- Sleutels nooit in REST/JS/HTML/logs/CSV; foutmeldingen via `Secrets::redact()`; ruwe providerboodschappen enkel in
  het admin-log.
- Gasten: `X-BPS-Token` (bestaande `/token`), ingelogd: `X-WP-Nonce`; origin-check; job van een ander = 404.
- Beelden enkel via HMAC-ondertekende URL (`exp` ≤ 1 u 10 min), `X-Content-Type-Options: nosniff`, SVG met strikte CSP.

## Hooks die de module aanbiedt

`bps_ai_providers` · `bps_ai_admin_sections` · `bps_ai_settings` (filter op de samengevoegde instellingen) ·
`bps_ai_status` · `bps_ai_packs` · `bps_ai_credits` (rondes-backend) · `bps_ai_token_ok` · `bps_ai_allowed_origins` ·
`bps_ai_rate_limit` · acties `bps_ai_job_created`, `bps_ai_job_done`, `bps_ai_design_chosen`, `bps_ai_owner_merged`,
`bps_ai_settings_updated`, `bps_ai_cleanup_done`.

## Tests

- `php tests/ai/backend/run-backend-php.php` — 35 scenario's (mock, OpenAI over nep-HTTP, volledige jobflow met de
  mock, idempotentie, 402/409/423/429/503, terugval/beleid, transparantie-terugval, time-outs, gelijktijdigheid,
  annuleren, budget + reservering + alertmail, kostberekening, sleutels, markering met CRC-controle, verfijnen,
  kiezen + design-link, samenvoegen, status, publieke config, REST-permissies, opruiming, privacy, admin, NL/FR).
- `tests/ai/backend.test.js` (in `npm test`): config-integriteit, "geen sleutels", `php -l` op elk AI-bestand, en
  roept de PHP-tests aan.
- Rooktest in de lokale WordPress (SQLite + WooCommerce + echte `Services\Credits`): tabellen, gratis ronde, generate →
  done (3 voorstellen) → kiezen, 402 zonder rondes, REST 404 voor andermans job, status `no-store`, samenvoegen
  (UPDATE IGNORE op SQLite), opruiming en privacy-export: groen.

## Afwijkingen van de spec (bewust)

1. **Annuleren tijdens het tekenen geeft de ronde ook terug** (spec: enkel in de wachtrij). De klant krijgt dan niets,
   dus het kost niets (§1.1 regel 6); misbruik wordt begrensd door min. interval, max per dag en het budget.
2. `bps_ai_owners.anon_granted_at` + index `ip_hash` (extra kolom; idempotente gratis anonieme ronde zonder commerce).
3. `ai-providers.json default = openai` (spec-voorbeeld: `mock`): modus `test` forceert altijd de mock en de
   standaardmodus is `off`, dus live betekent echt AI.
4. Kostlog-bewerking `test` (verbindingstest) naast `generate|edit|vectorize|moderate`.
5. Blocked-antwoorden zijn een JobResponse (`status:"blocked"`) mét `blocked:true, code, message` bovenaan.
6. StatusResponse heeft extra `testMode` en `testNotice` ("Testmodus: dit zijn voorbeeldontwerpen, geen echte AI.").

## Open punten

- Eigenaar: OpenAI-sleutel (bij voorkeur in `wp-config.php`), OpenAI-DPA, prijzen van OpenAI en Recraft nakijken
  (`verified`), wie krijgt de budgetmails, live zetten via de beheerpagina.
- Integratie: zie `docs/handoff/ai-services-needs.md` §"ai-backend" (component, `source.ai` in de payload, test-script).
- `Prompt_Adapter` (prompt-team) wordt automatisch gebruikt zodra `includes/ai/class-prompt-adapter.php` bestaat;
  tot dan maakt `Jobs::understood()` een eenvoudige samenvatting.
