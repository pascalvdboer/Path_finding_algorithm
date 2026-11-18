# SPEC — AI-ontwerpstudio en diensten (Patch Studio)

Architect · 17-11-2026 · **bindend contract** voor de bouwers `ai-backend`, `commerce`, `prompt`, `frontend` en `tests`.
Bron: `docs/BRIEF.md` ("WENSEN VAN DE EIGENAAR (17-11)"), `docs/SPEC.md`, `docs/STUDIO-REDESIGN.md` en het onderzoek
`docs/ai/01-apis.md` (providers), `02-prompting.md` (prompt-engine), `03-verdienmodel-ux.md` (rondes, pakketten,
diensten, UX), `04-juridisch.md` (AI Act, herroeping, GDPR, btw).

> **Eis nummer 1 is gebruiksvriendelijkheid.** Een leek maakt op zijn gsm in 2 minuten een AI-ontwerp en zet het op
> een patch, zonder uitleg. Elke beslissing hieronder die meer keuzes of meer schermen vraagt, is fout.
> **Eis nummer 2 is eerlijkheid.** Geen nep-reviews, geen aftelklokken, geen "nog 2 aan deze prijs", geen vooraf
> aangevinkte vakjes, geen "gratis" dat later betaald wordt (04 §4.6, SPEC §8).

Wijzigingen aan dit contract: alleen via de architect, met een regel in §17 (changelog). Namen van routes, events,
tabellen, klassen en config-sleutels in deze spec zijn **bevroren** zodra de eerste bouwer ze gebruikt.

---

## 0. Beslissingen in één oogopslag

| # | Beslissing | Waarom |
|---|---|---|
| A1 | Eenheid voor de klant: **"ronde"** (FR **"essai"**). 1 ronde = 1× "Maak voorstellen" (3 voorstellen) of 1× "Pas aan" via AI (2 versies). Nooit "credits/tokens" in klanttekst. Intern heet het `rounds`. | 03 §2 |
| A2 | Standaard **3 voorstellen per ronde** (instelbaar 2–4), **2 per verfijning**. | `ai-prompts.json params.n=3` (kost ± € 0,15/ronde), scherm past op gsm in 1 rij van 3 of 2×2 |
| A3 | Gratis: **1 ronde anoniem**, **+2 na e-mailbevestiging**, **+5 per betaalde patchbestelling**. Pakketten **10 rondes € 4,95** en **25 rondes € 9,95**, bedrag **100 % terug als korting** op de eerste patchbestelling ≥ € 50 binnen 12 maanden. Alles instelbaar. | 03 §3–4 |
| A4 | **Prompt alleen server-side** (PHP, bestaande `Prompt_Engine`). De browser stuurt ruwe velden, nooit een prompt of brief. | 02 §2, security |
| A5 | **Asynchrone jobs**: `POST` maakt een job (202), de browser pollt `GET /ai/jobs/{id}`. Uitvoering via Action Scheduler (zit in WooCommerce) met **inline terugval** bij de eerste poll als de job na 3 s nog in de wachtrij staat. | Generatie duurt 10–40 s; PHP-/proxy-timeouts vermijden zonder extra infrastructuur |
| A6 | **Provider-abstractie** met `mock` (standaard, geen sleutel), `openai` (live, `gpt-image-2`), `recraft` (terugval + optionele vectorisatie). Beleidsweigeringen gaan **nooit** naar een terugvalprovider. | 01 §Advies |
| A7 | **Sleutels**: constante in `wp-config.php` (voorrang) of versleutelde optie `bps_ai_secrets` (autoload nee). Nooit in REST, JS, HTML, logs of exports. | opdracht, 01 §Privacy |
| A8 | Naar de provider gaat **alleen de gezuiverde ontwerpomschrijving** (+ bij verfijnen ons eigen gegenereerd beeld). Geen naam, e-mail, IP, order-/klant-ID. Klantuploads gaan **nooit** naar de AI (v1). | 04 §6.2 |
| A9 | Rondes = **kavels** (`lots`) + **append-only grootboek** (`ledger`). Saldo wordt berekend, nooit los opgeslagen. Verbruik: gratis eerst, daarna de kavel die het eerst vervalt. | Boekhouding 10 jaar, herroeping per kavel, eenvoudig te auditen |
| A10 | Pakketten en diensten = **gewone, virtuele WooCommerce-producten** met meta-vlag, aangemaakt/gesynchroniseerd door `commerce`. Prijs is de bron in onze instellingen (excl. btw, net als de rest van de beheerpagina), naar Woo gesynchroniseerd. | Woo blijft de kassa, btw, facturen, betaalmethodes |
| A11 | Verrekening = **persoonlijke Woo-coupon** per pakketaankoop, automatisch toegepast in de mand als die een patch bevat en het minimum haalt. | 03 §4.2; boekhoudkundig een handelskorting (04 §7, **[BOEKHOUDER]**) |
| A12 | Front-end = **apart, lui geladen bundel** `patch-studio-ai.js` (`window.BPSAI`), pas gedownload bij de eerste tik op "Nog geen logo?". Diensten-logica = kleine ESM-module die de studio importeert (geen eigen bundel). | Hoofdbundel zit al boven budget (premium-ui-needs prestatie-4) |
| A13 | De AI-module **praat met de studio via events en één `ctx`-object**, nooit via interne studio-imports. Het gekozen beeld komt als `File` in het event `bps:ai:design:chosen`; de studio voert het door de bestaande `ctx.handleFile()` (analyse + borduurcheck). | Ontkoppeling studio-team ↔ AI-team |
| A14 | Eigen tabellen met eigen db-versie (`bps_ai_db_version`), eigen opties (`bps_ai_settings`, `bps_ai_secrets`, `bps_svc_products`), eigen cron (`bps_ai_cleanup`). Niets in `bps_settings`. | Geen botsingen met premium-team (`Settings::LIMITS/ENUMS`) |
| A15 | Modus `off` (standaard na installatie) · `test` (mock-provider, alleen zichtbaar voor beheerders, geen pakketverkoop) · `live`. | Veilig aanzetten |

---

## 1. Klantflow (gsm eerst)

### 1.1 Principes
1. **Eén tekstvak** is genoeg. Vorm, kleuren en stijl zijn optioneel en staan dicht onder "Meer opties" (gsm) of als
   3 chips met "Laat ons kiezen" als standaard. Wat de studio al weet (vorm, techniek, maat, stofkleur) wordt
   automatisch meegestuurd; de klant hoeft niets dubbel te kiezen.
2. **De patch blijft in beeld.** Op gsm staat het voorbeeld bovenaan (sticky, krimpt als het toetsenbord opent via
   `visualViewport`); voorstellen tonen we **op de echte patch-render** zodra de klant er één aantikt.
3. **Eén duidelijke primaire knop per scherm**, minstens 48 px hoog, onderaan binnen duimbereik.
4. **Nooit werk verliezen**: voorstellen van de laatste 30 dagen blijven bewaard (per browser, of per e-mail/account)
   en zijn terug te zien via "Mijn voorstellen".
5. **Rondeteller altijd zichtbaar** in gewone woorden ("1 gratis ronde", "Nog 7 rondes"); de knop zegt wat hij kost
   ("Maak 3 voorstellen · 1 ronde"). Geen bevestigingspopup vóór een ronde.
6. **Fouten kosten niets**: weigering, storing, time-out of annuleren vóór de start = ronde automatisch terug, en dat
   zeggen we ook.

### 1.2 Schermen en toestanden

```
 [A] Ingang (lege studio)     [B] Beschrijven             [W] Wachten               [C] Voorstellen               [E] In de studio
 ┌────────────────────────┐   ┌────────────────────────┐  ┌──────────────────────┐  ┌──────────────────────────┐   canvas toont het ontwerp,
 │ ⬆ Ik heb een logo      │   │ (patch-voorbeeld)      │  │ (patch-voorbeeld)    │  │ (gekozen voorstel groot  │   borduurcheck + prijs live,
 │ ✨ Nog geen logo?       │──▶│ Wat wil je op je patch?│─▶│ ░░ ░░ ░░  3 skeletten│─▶│  op de patch)            │──▶ gewone flow: maat, aantal,
 │    Laat AI iets maken  │   │ [tekstvak, 400 tekens] │  │ "We tekenen 3        │  │ [1✓] [2!] [3✓]           │   bestellen. AI-paneel blijft
 │    1 gratis ronde      │   │ Tekst op de patch (opt)│  │  voorstellen (±15 s)"│  │ Gemaakt met AI           │   bereikbaar via tab Ontwerp
 │ Aa Alleen tekst        │   │ ▸ Meer opties          │  │ [Annuleren]          │  │ Pas aan… [         ][→]  │   ("✨ Nieuwe voorstellen")
 └────────────────────────┘   │ [Maak 3 voorstellen]   │  └──────────────────────┘  │ [Gebruik dit ontwerp]    │
                              │ 1 ronde · geen merken  │                            └──────────────────────────┘
                              └────────────────────────┘                                      │ (verfijnen = [W] → [C])
        rondes op? ─────────────────────────▶ [P] Pakket-sheet  /  [M] E-mail (+2 gratis)  ◀────┘
```

| Toestand | Gsm (≤ 640 px) | Desktop (≥ 1025 px) | Primaire actie |
|---|---|---|---|
| A Ingang | kaart in de lege toestand van de studio | idem + knop "✨ AI" in de gereedschapsbalk | "Nog geen logo? Laat AI iets maken" |
| B Beschrijven | bottom sheet tot 85 % hoogte | AI-paneel op de plaats van de assistent-kolom | "Maak 3 voorstellen" |
| W Wachten | 3 skeletkaarten met rustige steek-animatie (`prefers-reduced-motion`: statisch) | idem | "Annuleren" (secundair) |
| C Voorstellen | rij van 3 (of 2×2 bij 4), tik = groot op de patch, swipe tussen voorstellen | hover = op canvas, klik = kiezen | "Gebruik dit ontwerp" |
| D Verfijnen | tekstveld "Pas aan…" onder de voorstellen | idem | pijlknop |
| E Studio | AI-sheet sluit; canvas toont het ontwerp | AI-paneel sluit; assistent komt terug met borduuradvies | (studio) |
| M E-mail | sheet: e-mailveld → 6-cijferige code → +2 rondes | idem in paneel | "Stuur code" / "Bevestig" |
| P Pakket | sheet met 2 pakketten (bovenste voorgeselecteerd **als keuze**, geen vinkje), terugbetaalregel ernaast, "Betaal € 4,95" | idem | "Betaal" (Woo-kassa in nieuw tabblad; studio pollt saldo) |
| X Geweigerd | rustige melding + "Upload je eigen logo" (bij merk/kopie) + "Beschrijf je eigen idee" | idem | "Beschrijving aanpassen" |

### 1.3 Doorlooptijd-doel (gsm, 4G)
Tik "Nog geen logo?" → sheet open < 300 ms (bundel lui geladen ≤ 25 kB gzip, wordt al **prefetch**ed bij idle als de
modus `live` is) → tikken (± 30 s) → voorstellen (mock < 2 s, live ± 10–25 s) → "Gebruik dit" → ontwerp op canvas
< 1,5 s. Totaal ruim onder 2 minuten.

### 1.4 Toegankelijkheid
- Sheet = `role="dialog"`, `aria-modal`, focus-trap, Esc/terugknop sluit, focus terug naar de opener.
- Statuswijzigingen (wachten, klaar, fout, saldo) via één `aria-live="polite"`-regio.
- Voorstellen = `role="radiogroup"` met pijltjestoetsen; elk voorstel heeft een alt-tekst uit `brief.understood`
  ("Voorstel 2: wolf met bijl, rond, zwart en rood").
- Label "Gemaakt met AI" als **DOM-tekst** op elke tegel (niet in het beeld), contrast ≥ 4,5:1.
- Doelgroottes ≥ 44 × 44 px, alles bruikbaar met toetsenbord, geen informatie alleen via kleur (✓/! + tekst).

---

## 2. Bestandseigendom per bouwer

Niemand schrijft in bestanden van een andere bouwer. Nodig je iets van een ander: vraag het via de architect of zet het
in `docs/handoff/ai-services-needs.md`. **Bestaande bestanden buiten deze lijst blijven onaangeroerd** (zie §16).

| Bouwer | Bestanden (alles nieuw tenzij vermeld) |
|---|---|
| **ai-backend** | `includes/ai/class-module.php` (bootstrap, hooks, install), `class-schema.php` (tabellen, dbDelta), `class-ai-settings.php`, `class-secrets.php`, `class-jobs.php` (job-repository + runner), `class-job-runner.php`, `class-rest.php` (alle `/ai/*`-routes), `class-limits.php` (rate-limits, budget, misbruik), `class-cost-log.php`, `class-image-store.php` (opslag, ondertekende URL's, AI-markering), `class-moderation.php`, `class-cleanup.php`, `class-privacy.php` (exporter/eraser AI), `class-admin.php` (beheerpagina-schil + tab AI), `class-identity.php` (eigenaar: user/e-mail/anoniem), `providers/class-provider.php` (interface), `providers/class-provider-result.php`, `providers/class-provider-error.php`, `providers/class-provider-registry.php`, `providers/class-mock-provider.php`, `providers/class-openai-provider.php`, `providers/class-recraft-provider.php`; `config/ai-providers.json`, `config/ai-mock.json` + `config/ai-mock/*.svg` |
| **commerce** | `includes/services/class-module.php`, `class-credits.php` (kavels + grootboek: domein), `class-credits-store.php` (interface) + `class-credits-store-wpdb.php`, `class-email-gate.php`, `class-products.php` (pakket- en dienstproducten aanmaken/synchroniseren), `class-checkout.php` (toestemmingsvakjes, ordermeta, mail), `class-fulfilment.php` (order betaald → kavels, coupons, gratis rondes per bestelling), `class-order-credit.php` (coupon-verrekening), `class-withdrawal.php` (Mijn account "Ontwerprondes" + herroepknop), `class-services.php` (catalogus, regels, dienstregels in de mand, spoedprijs), `class-rest.php` (`/ai/credits*`, `/ai/email*`, `/ai/checkout`, `/services/*`), `class-admin-sections.php` (secties Pakketten & korting, Diensten); `config/ai-pricing.json`, `config/ai-services.json` |
| **prompt** | **Kiest `includes/ai/` (server-side)**: bestaande `includes/ai/class-ai-prompt-engine.php` (klasse `BorduurPatchStudio\AI\Prompt_Engine`), bestaande `config/ai-prompts.json`, bestaande `tests/ai/run-prompt-php.php`, `tests/ai/prompt-vectors.json`, `tests/ai/prompt-config.test.js`. Geen JS-prompt in de browser. Nieuw: `includes/ai/class-prompt-adapter.php` (vertaalt REST-invoer + studio-context → `brief()`-invoer, en `brief` → publieke "zo begrepen we je"-velden) |
| **frontend** | `assets/src/ai/index.js` (bundel-entry, `window.BPSAI`), `ai/panel.js`, `ai/store.js` (toestandsmachine), `ai/api.js` (REST-client), `ai/mock-api.js` (in-browser nep-API voor demo/dev/tests), `ai/poll.js`, `ai/sheets.js` (pakket-, e-mail-, toestemmingssheet), `ai/messages.js` (NL+FR), `ai/ai.css` (eigen, gescoped `.bps-ai`, ingeladen door de bundel); `assets/src/services/index.js` (pure `evaluate()` + `card()`), `services/messages.js` (NL+FR), `services/api.js` |
| **tests** | `tests/ai/*.test.js`, `tests/ai/run-ai-php.php` (+ `tests/ai/php/*`), `tests/services/*.test.js`, `tests/services/run-services-php.php`, `tests/ai/e2e/*.spec.js` (Playwright tegen `mock-api` en tegen WP met mock-provider) |
| **architect** | deze spec, `docs/handoff/ai-services-needs.md` (sectie "Architectuur") |

Klassenamen volgen de bestaande autoloader (`borduur-patch-studio.php`): `BorduurPatchStudio\AI\Job_Runner` →
`includes/ai/class-job-runner.php`, `BorduurPatchStudio\AI\Providers\Mock_Provider` →
`includes/ai/providers/class-mock-provider.php`, `BorduurPatchStudio\Services\Credits` →
`includes/services/class-credits.php`. Interface: `BorduurPatchStudio\AI\Providers\Provider` →
`providers/class-provider.php` (autoloader zoekt `class-*`; dus **niet** `interface-provider.php` maar
`class-provider.php`). Uitzondering: `Prompt_Engine` zit in `class-ai-prompt-engine.php` (bestaand) en wordt met
`require_once` geladen door `AI\Module`.

Codestijl: WordPress Coding Standards, PHP ≥ 8.1-syntax toegestaan zoals de rest, `defined( 'ABSPATH' ) || exit;`,
text domain `borduur-patch-studio`, alle SQL via `$wpdb->prepare`, alle invoer gesanitized, alle uitvoer ge-escaped.
JS: ES2019, geen dependencies, geen globals behalve `window.BPSAI`.

---

## 3. Datamodel

### 3.1 Eigenaar (identiteit) — `ai-backend` (`Identity`)

Een **eigenaar** is wie rondes heeft en jobs maakt. Sleutel `owner_key` = `CHAR(66)` (prefix van 2 tekens + 64 hex), nooit een ruwe ID of e-mail:

| Soort | `owner_key` | Wanneer |
|---|---|---|
| Account | `'u:' . hash_hmac('sha256', 'u|' . $user_id, wp_salt('auth'))` | ingelogd |
| E-mail | `'e:' . hash_hmac('sha256', 'e|' . strtolower(trim($email)), wp_salt('auth'))` | gast die zijn e-mail bevestigde |
| Anoniem | `'a:' . hash_hmac('sha256', 'a|' . $sid, wp_salt('auth'))` | cookie `bps_ai_sid` |

- Cookie `bps_ai_sid`: 32 hex, `HttpOnly`, `Secure`, `SameSite=Lax`, pad `/`, 30 dagen; enkel gezet bij de eerste
  AI-actie (strikt noodzakelijk voor een gevraagde dienst, geen toestemming nodig; vermeld in privacytekst 04 §6.7).
- **Samenvoegen**: bevestigt een anonieme bezoeker zijn e-mail, of logt hij in, dan worden kavels en jobs van de
  zwakkere sleutel overgezet naar de sterkere (anoniem → e-mail → account) in één transactie, met grootboekregels
  `merge_out`/`merge_in`. Gratis anonieme rondes tellen maar één keer (geen +1 per nieuw cookie: zie limiet IP).
- Tabel `{prefix}bps_ai_owners` houdt de koppelingen bij (nodig voor mails, coupons, privacyexport):

```sql
CREATE TABLE {prefix}bps_ai_owners (
  owner_key     CHAR(66)     NOT NULL,
  kind          VARCHAR(8)   NOT NULL,            -- user|email|anon
  user_id       BIGINT UNSIGNED NULL,
  email         VARCHAR(190) NULL,                -- enkel bij kind=email/user, nooit naar de AI
  email_verified_at DATETIME NULL,
  merged_into   CHAR(66)     NULL,
  ip_hash       CHAR(64)     NULL,                -- HMAC(ip, wp_salt('nonce')), enkel voor anon-limiet, 30 d
  created_at    DATETIME     NOT NULL,
  last_seen_at  DATETIME     NOT NULL,
  PRIMARY KEY (owner_key), KEY user_id (user_id), KEY email (email(64)), KEY merged_into (merged_into)
);
```

### 3.2 Rondes — `commerce` (`Credits`, opslag via `Credits_Store`)

```sql
CREATE TABLE {prefix}bps_ai_lots (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_key     CHAR(66)    NOT NULL,
  source        VARCHAR(16) NOT NULL,   -- free_anon|free_email|free_order|pack|admin|goodwill
  rounds_total  INT         NOT NULL,
  rounds_used   INT         NOT NULL DEFAULT 0,
  order_id      BIGINT UNSIGNED NULL,   -- bij pack/free_order
  order_item_id BIGINT UNSIGNED NULL,
  pack_id       VARCHAR(32) NULL,       -- ai_pack_10 …
  price_incl    DECIMAL(12,4) NULL,     -- betaald bedrag (voor herroeping/coupon)
  consent_at    DATETIME    NULL,       -- beide vakjes (04 §4.2); NULL = betaalde rondes pas na usable_from
  usable_from   DATETIME    NOT NULL,   -- = created_at, of +14 d zonder toestemming
  expires_at    DATETIME    NOT NULL,   -- pack: +24 mnd (min 12); free_anon: +30 d; free_email/order: +12 mnd
  status        VARCHAR(12) NOT NULL DEFAULT 'active', -- active|expired|withdrawn|refunded|merged
  created_at    DATETIME    NOT NULL,
  PRIMARY KEY (id), KEY owner (owner_key, status, expires_at), KEY order_id (order_id)
);

CREATE TABLE {prefix}bps_ai_ledger (       -- append-only, nooit UPDATE/DELETE behalve privacy-anonimisering
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_key  CHAR(66)    NOT NULL,
  lot_id     BIGINT UNSIGNED NULL,
  delta      INT         NOT NULL,         -- + toekenning/terugboeking, − verbruik/verval/herroeping
  kind       VARCHAR(16) NOT NULL,         -- grant|spend|refund|expire|withdraw|merge_in|merge_out|admin
  reason     VARCHAR(32) NOT NULL,         -- free_anon|free_email|free_order|pack_paid|generate|refine|job_failed|job_blocked|job_cancelled|withdrawal|service_stop|expiry|manual
  job_id     CHAR(26)    NULL,
  order_id   BIGINT UNSIGNED NULL,
  actor      BIGINT UNSIGNED NULL,         -- beheerder bij admin-correctie
  created_at DATETIME    NOT NULL,
  PRIMARY KEY (id), KEY owner (owner_key, created_at), KEY job (job_id), KEY lot (lot_id)
);
```

Regels (`Credits`, puur PHP, getest met een in-memory `Credits_Store`):

- `balance(owner)` → `{free:int, paid:int, paidLocked:int, total:int, nextExpiry:?string, lockedUntil:?string}`:
  som van `rounds_total − rounds_used` over kavels `active`, niet vervallen; `paidLocked` = pack-kavels met
  `consent_at IS NULL` en `usable_from > now` (bruikbaar na toestemming of na 14 d).
- `reserve(owner, n, job_id, reason)` → `lot_id` of `null`. **Atomair**: `UPDATE … SET rounds_used = rounds_used + n
  WHERE id = %d AND rounds_total - rounds_used >= n AND status='active'` (affected rows = 1), daarna ledger `spend`.
  Volgorde: `free_anon`, `free_email`, `free_order`, `goodwill`, `admin`, `pack` (vroegst vervallend eerst).
  Eén job neemt alle rondes uit **één** kavel.
- `refund(job_id)` → idempotent (niet als er al een `refund` met dat `job_id` bestaat): `rounds_used − n` op dezelfde
  kavel, ledger `refund`.
- `grant(owner, source, rounds, meta)` → nieuwe kavel + ledger `grant`. Idempotent op (`order_item_id`, `source`).
- `withdraw(lot_id)` → ongebruikt deel naar 0 (ledger `withdraw`), status `withdrawn`; bedrag terug =
  `price_incl × unused / total`, afgerond op 2 decimalen, **naar beneden** nooit — afronden op de cent (half-up).
- `expire()` (cron) → kavels voorbij `expires_at` → ledger `expire` voor het ongebruikte deel, status `expired`.
- **Gratis toekenningen** (via `commerce`, aangeroepen door `ai-backend` bij de eerste status-call):
  `free_anon` = `free.anonymous` (1) per eigenaar **én** max `limits.anonPerIpPerDay` (3) nieuwe anonieme
  toekenningen per `ip_hash` per 24 u; `free_email` = `free.withEmail` (2) eenmalig per bevestigd e-mailadres;
  `free_order` = `free.perOrder` (5) per betaalde bestelling met een patchproduct, voor de eigenaar van die bestelling.
- Gratis rondes worden **gepauzeerd** (niet afgenomen) als het dagbudget op is (§9).

### 3.3 Jobs en voorstellen — `ai-backend` (`Jobs`)

```sql
CREATE TABLE {prefix}bps_ai_jobs (
  id             CHAR(26)    NOT NULL,      -- willekeurig, base32 (Crockford), niet raadbaar; publieke job-id
  owner_key      CHAR(66)    NOT NULL,
  kind           VARCHAR(8)  NOT NULL,      -- generate|refine
  parent_id      CHAR(26)    NULL,          -- bij refine: de job van het gekozen voorstel
  parent_index   TINYINT     NULL,
  status         VARCHAR(10) NOT NULL,      -- queued|running|done|failed|blocked|cancelled
  client_req     CHAR(36)    NULL,          -- idempotentiesleutel van de browser
  input          LONGTEXT    NOT NULL,      -- JSON: gezuiverde REST-invoer (geen PII)
  brief          LONGTEXT    NULL,          -- JSON: Prompt_Engine::brief() (server-only)
  history        LONGTEXT    NULL,          -- JSON: verfijn-geschiedenis (max refineHistoryKeep)
  provider       VARCHAR(16) NULL,
  model          VARCHAR(48) NULL,
  n              TINYINT     NOT NULL,
  lot_id         BIGINT UNSIGNED NULL,      -- NULL = gratis lokale actie of geweigerd
  rounds         TINYINT     NOT NULL DEFAULT 0,
  result         LONGTEXT    NULL,          -- JSON: [{index, file, mime, w, h, sha1, bytes}]
  chosen_index   TINYINT     NULL,
  error_code     VARCHAR(32) NULL,
  attempts       TINYINT     NOT NULL DEFAULT 0,
  locale         VARCHAR(5)  NOT NULL,
  created_at     DATETIME    NOT NULL,
  started_at     DATETIME    NULL,
  finished_at    DATETIME    NULL,
  keep_until     DATETIME    NOT NULL,      -- retentie (§11)
  PRIMARY KEY (id), UNIQUE KEY owner_req (owner_key, client_req), KEY owner (owner_key, created_at),
  KEY status (status, created_at), KEY keep_until (keep_until)
);
```

- `input` bevat enkel de **gezuiverde** beschrijving (na `Prompt_Engine::sanitize`) en de keuzevelden. Bij `blocked`
  wordt de beschrijving na `retentionDays.refusedPromptLog` (30 d) vervangen door `null`; enkel `error_code` blijft.
- Beelden: `wp-content/uploads/patch-studio/ai/{yyyy}/{mm}/{job_id}-{index}.png` (map met `index.php` en
  `.htaccess` `Require all denied` / nginx-tip in admin), bestandsnaam bevat geen klantgegevens. Uitlevering enkel
  via ondertekende route (§4.2) of, na "Gebruik dit", als upload van het ontwerp via de bestaande `/designs`-flow.
- **AI-markering** (04 §1.2): `Image_Store::mark()` schrijft in elke PNG een `iTXt`-chunk
  `Description = ai-legal.json marking.pngTextComment` en XMP `Iptc4xmpExt:DigitalSourceType =
  marking.iptcDigitalSourceType`; de provider-PNG (met C2PA) wordt ongewijzigd bewaard als `{job}-{i}.orig.png`
  zolang het afgeleide ontwerp bestaat. Mock-SVG's krijgen hetzelfde in `<metadata>`.

### 3.4 Kostlog — `ai-backend` (`Cost_Log`)

```sql
CREATE TABLE {prefix}bps_ai_costs (       -- geen persoonsgegevens; onbeperkt bewaard
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  at            DATETIME    NOT NULL,
  job_id        CHAR(26)    NULL,
  provider      VARCHAR(16) NOT NULL,
  model         VARCHAR(48) NOT NULL,
  op            VARCHAR(12) NOT NULL,      -- generate|edit|vectorize|moderate
  images        TINYINT     NOT NULL DEFAULT 0,
  tokens_in     INT         NULL,
  tokens_out    INT         NULL,
  cost_usd_micro BIGINT     NOT NULL,      -- werkelijke kost (usage) of schatting als usage ontbreekt
  estimated     TINYINT(1)  NOT NULL DEFAULT 0,
  cost_eur_micro BIGINT     NOT NULL,      -- omgerekend met settings.usdToEur op dat moment
  duration_ms   INT         NOT NULL,
  outcome       VARCHAR(12) NOT NULL,      -- ok|policy|error|timeout
  paid          TINYINT(1)  NOT NULL DEFAULT 0, -- betaalde of gratis ronde (voor kengetallen)
  PRIMARY KEY (id), KEY at (at), KEY job (job_id)
);
```

Kostberekening: OpenAI `usage` → `tokens_in × prijs_in + tokens_out × prijs_out` uit `ai-providers.json`
(`pricing.usdPerMTokens`); ontbreekt `usage` → `estUsdPerImage × images`, `estimated=1`. Mock = 0.

### 3.5 E-mailcodes — `commerce` (`Email_Gate`)
Transient `bps_ai_code_{hmac(email)}` = `{hash: wp_hash_password(code), tries, exp}`; 6 cijfers, 15 min geldig,
max 5 pogingen, max 3 codes per e-mail per uur en `limits.emailCodesPerIpPerHour` (5) per IP-hash. Geen tabel.

### 3.6 Ordermeta (WooCommerce, HPOS-veilig via `$order->update_meta_data`)

| Meta | Waar | Inhoud | Eigenaar |
|---|---|---|---|
| `_bps_ai_pack` | orderitem pakket | `{packId, rounds, lotId, ownerKey}` | commerce |
| `_bps_ai_withdrawal_consent` | order | `{v, start:bool, ack:bool, at, locale, text_sha1}` (04 §4.2) | commerce |
| `_bps_ai_coupon` | orderitem pakket | coupon-code die erbij hoort | commerce |
| `_bps_ai_credit_used` | order (patch) | `{coupon, amount, packOrderId}` | commerce |
| `_bps_service` | orderitem dienst | `{id, designRef?, leadDays, startAfter?}` | commerce |
| `_bps_svc_start_after` | orderitem dienst | datum +14 d als het dienstvakje leeg bleef | commerce |
| `_bps_ai` | ontwerp-post / patch-orderitem | `{jobId, index, provider, model, legalVersion, humanFinish:false, review:null}` | ai-backend schrijft, premium-team toont |

---

## 4. REST-API

Namespace `patch-studio/v1` (bestaande `Routes::NS`). Alle AI-routes registreert `AI\Rest` / `Services\Rest` zelf op
`rest_api_init`. Antwoorden: JSON, `Cache-Control: no-store` behalve `GET /services/catalog`.

### 4.1 Authenticatie en bescherming
- **Ingelogd**: standaard WP-cookie + `X-WP-Nonce` (`wp_rest`).
- **Gast**: header `X-BPS-Token` met het token van de bestaande `GET /token` (gecontroleerd met
  `Rest\Guard::token_ok()`), plus cookie `bps_ai_sid`. Zonder token: 401 `bps_bad_token`.
- **Origin-check** zoals de bestaande guard (403 `bps_bad_origin`).
- Rate-limits: eigen buckets in `AI\Limits` (zelfde HMAC-IP-techniek als `Guard::rate_limit`, eigen
  transient-prefix `bps_ai_rl_`): `ai_write` 30/u per IP, `ai_poll` 120/min per IP, `ai_email` 5/u per IP.
- Foutvorm altijd: `{ "code": "bps_ai_…", "message": "<NL of FR volgens locale>", "data": { "status": 4xx, … } }`.

### 4.2 Routes

| Methode + pad | Eigenaar | Doel | Antwoord (200 tenzij vermeld) |
|---|---|---|---|
| `GET /ai/status` | ai-backend (+ commerce voor saldo) | Alles wat het paneel bij openen nodig heeft; kent gratis anonieme ronde toe bij eerste aanroep | `StatusResponse` |
| `POST /ai/generate` | ai-backend | Nieuwe ronde | 202 `JobResponse` · 200 `{blocked:true,…}` · 402 · 409 · 429 · 503 |
| `GET /ai/jobs/{id}` | ai-backend | Polling | `JobResponse` |
| `POST /ai/jobs/{id}/cancel` | ai-backend | Annuleren | `JobResponse` (status `cancelled`, ronde terug als nog `queued`) |
| `POST /ai/jobs/{id}/refine` | ai-backend | Verfijnen | 200 `LocalRefine` (gratis) · 202 `JobResponse` · 200 blocked |
| `POST /ai/jobs/{id}/choose` | ai-backend | "Gebruik dit ontwerp" (gratis) | `ChooseResponse` |
| `GET /ai/image/{id}/{index}` | ai-backend | Beeld (ondertekend: `?exp=&sig=`, 1 u geldig, `sig = hmac(id|index|exp, wp_salt('auth'))`) | `image/png` of `image/svg+xml`, `Cache-Control: private, max-age=3600` |
| `GET /ai/history` | ai-backend | Laatste 20 jobs (30 d) van deze eigenaar | `{jobs:[JobSummary]}` |
| `GET /ai/credits` | commerce | Saldo + kavels (voor "Mijn account" en het paneel) | `{balance, lots:[{source, total, used, expiresAt, locked}]}` |
| `POST /ai/credits/consent` | commerce | Beide vakjes alsnog geven (`legal.ai.pack_consent_later`) | `{balance}` |
| `POST /ai/email` | commerce | E-mail ingeven → code mailen | `{sent:true}` (ook als het adres al gebruikt is: geen account-enumeratie) |
| `POST /ai/email/verify` | commerce | Code bevestigen → +2 rondes, samenvoegen | `{balance, granted:int}` |
| `POST /ai/checkout` | commerce | Pakket in de mand en kassa-URL | `{checkoutUrl, packId}` |
| `GET /services/catalog` | commerce | Publieke dienstenlijst (ook in `public_config`) | `{services:[ServiceDef]}`, `Cache-Control: public, max-age=300` |
| `POST /services/cart` | commerce | Dienst in de mand (gekoppeld aan ontwerp-ref) | `{ok, cartUrl, cartCount, line:{key, label, priceIncl}}` |
| `POST /services/dismiss` | commerce | "Nee, dank je" onthouden per ontwerp (optioneel, server-side naast localStorage) | `{ok}` |

### 4.3 Schema's

```jsonc
// POST /ai/generate — body
{
  "clientRequestId": "6f1c…(uuid v4)",     // verplicht; zelfde id = zelfde job (dubbel tikken)
  "description": "een wolf met een bijl voor onze motorclub, rond, zwart-rood", // 3..400 tekens
  "text": "MC WOLVES 1998",                // optioneel, max 60
  "lang": "nl",                            // nl|fr
  "style": "auto",                         // auto|badge|mascot|vintage|minimal|military|club|typographic
  "shape": "auto",                         // auto|circle|oval|rect|rounded|shield|contour
  "maxColors": null,                       // null = slim kiezen, anders 1..8
  "textMode": "auto",                      // auto|studio|ai|none
  "context": {                             // door de studio aangevuld, niet door de klant getypt
    "technique": "embroidered", "sizeMm": 80, "fabric": "black", "edge": "merrow"
  }
}
```

```jsonc
// StatusResponse
{
  "mode": "live",                          // off|test|live  (test enkel voor beheerders zichtbaar)
  "providerLabel": "OpenAI",               // voor legal.ai.provider_notice; "Voorbeeldontwerpen (test)" bij mock
  "n": 3, "refineN": 2,
  "costs": { "generate": 1, "refine": 1, "choose": 0 },
  "balance": { "free": 1, "paid": 0, "paidLocked": 0, "total": 1, "nextExpiry": null, "lockedUntil": null },
  "freePaused": false,                     // dagbudget gratis rondes op
  "emailGate": { "offer": 2, "done": false },  // +2 met e-mail; done = al gebruikt
  "packs": [ { "id": "ai_pack_10", "rounds": 10, "priceIncl": 4.95, "priceHtml": "€ 4,95" },
             { "id": "ai_pack_25", "rounds": 25, "priceIncl": 9.95, "priceHtml": "€ 9,95" } ],
  "orderCredit": { "percent": 100, "minArticleIncl": 50, "validMonths": 12 },
  "packsBuyable": true,                    // false in test-modus of als Woo ontbreekt
  "limits": { "descriptionMaxChars": 400, "refineMaxChars": 200, "textMaxChars": 60 },
  "pausedUntil": null,                     // misbruikpauze (ISO) of null
  "legalVersion": "2026-11-17.1",
  "openJob": null                          // lopende job-id van deze eigenaar (herstel na herladen)
}
```

```jsonc
// JobResponse
{
  "jobId": "01JD3W…", "kind": "generate", "status": "queued", // queued|running|done|failed|blocked|cancelled
  "pollAfterMs": 1500,
  "proposals": [                            // enkel bij done
    { "index": 0, "url": "https://…/wp-json/patch-studio/v1/ai/image/01JD3W…/0?exp=…&sig=…",
      "mime": "image/png", "w": 1024, "h": 1024, "alt": "Voorstel 1: wolf met bijl, rond, zwart en rood" }
  ],
  "understood": {                           // "Zo begrepen we je" (chips) — nooit de prompt
    "summary": "wolf met bijl · rond · zwart en rood · stijl club",
    "style": "club", "shape": "circle", "colors": ["#111111", "#c62828"], "maxColors": 4,
    "textMode": "studio", "text": "MC WOLVES 1998", "placement": "arc_bottom",
    "warnings": ["personal_data_removed"]
  },
  "balance": { "free": 0, "paid": 0, "paidLocked": 0, "total": 0 },
  "charged": 1,                             // rondes afgeboekt voor deze job (0 na terugboeking)
  "error": null                             // {code, message} bij failed/blocked; message in klanttaal
}
```

```jsonc
// POST /ai/jobs/{id}/refine — body          // LocalRefine (route=local, gratis)
{ "proposalIndex": 1,                        { "route": "local", "action": "reduce_colors",
  "instruction": "maak de wolf bozer",         "message": "Minder kleuren kan je gratis zelf kiezen…",
  "forceAi": false,                            "colors": null, "text": null, "shape": null,
  "clientRequestId": "…" }                     "aiOffer": { "cost": 1 } }   // knop "Toch met AI (1 ronde)"
```

```jsonc
// ChooseResponse  (POST /ai/jobs/{id}/choose {proposalIndex})
{
  "imageUrl": "https://…/ai/image/01JD3W…/1?exp=…&sig=…",  // PNG met transparante achtergrond (of SVG bij mock/vector)
  "mime": "image/png", "fileName": "ai-ontwerp-01JD3W-2.png",
  "svgUrl": null,                            // gevuld als vectorizer actief is (Recraft)
  "ai": { "jobId": "01JD3W…", "index": 1, "provider": "openai", "model": "gpt-image-2",
          "label": "Gemaakt met AI", "humanFinish": false, "legalVersion": "2026-11-17.1" },
  "hints": { "shape": "circle", "maxColors": 4, "colors": ["#111111","#c62828"], "textMode": "studio",
             "text": "MC WOLVES 1998", "placement": "arc_bottom", "background": "transparent" }
}
```

```jsonc
// ServiceDef (catalogus, publiek)
{ "id": "d2_redraw", "enabled": true, "kind": "product",   // product|line_option
  "label": "Laat ons je logo hertekenen", "short": "je krijgt ook het vectorbestand",
  "priceIncl": 24.95, "priceHtml": "€ 24,95", "leadDays": 3, "needsConsent": true,
  "triggers": ["low_res", "photo_of_logo", "sketch_only"], "freeAlternative": "Heb je een beter bestand (PDF/SVG/AI)? Upload dat." }
```

### 4.4 Statuscodes en foutcodes

| HTTP | `code` | Wanneer | Ronde |
|---|---|---|---|
| 200 | — (`blocked:true`, `code` = `brand|copy|person|official|hate|sexual|violence|drugs|artist|…` uit `ai-prompts.json safety`) | lokale filter of providerbeleid | **niet** afgeboekt / teruggeboekt |
| 400 | `bps_ai_invalid` | veld ontbreekt/te lang | — |
| 401 | `bps_bad_token` | gast zonder geldig token | — |
| 402 | `bps_ai_no_rounds` | saldo 0 (`data.emailGate`, `data.packs` mee) | — |
| 402 | `bps_ai_consent_needed` | enkel vergrendelde betaalde rondes | — |
| 403 | `bps_ai_off` | modus off (of test en geen beheerder) | — |
| 404 | `bps_ai_not_found` | job bestaat niet of is niet van deze eigenaar (nooit 403: geen lek) | — |
| 409 | `bps_ai_busy` | eigenaar heeft al een lopende job (`data.jobId`) | — |
| 429 | `bps_ai_slow_down` | min. 8 s tussen rondes / rate-limit (`data.retryAfter`) | — |
| 429 | `bps_ai_daily_limit` | max rondes per eigenaar per dag | — |
| 423 | `bps_ai_paused` | misbruikpauze na herhaalde weigeringen (`legal.ai.abuse_pause`) | — |
| 503 | `bps_ai_budget` | hard maandplafond of globale gelijktijdigheid; `data.freeOnly:true` als enkel gratis gepauzeerd is | — |
| job `failed` | `provider_error`, `timeout`, `provider_unavailable`, `storage` | — | **automatisch terug** |

---

## 5. Uitvoering van een job (ai-backend)

```
POST /ai/generate
  1 auth + rate-limit + modus + misbruikpauze + "1 lopende job per eigenaar"
  2 idempotentie: bestaat (owner, clientRequestId) → dat JobResponse teruggeven
  3 Prompt_Adapter::input()  → Prompt_Engine::brief()        ── blocked? → job status blocked, 200, geen ronde
  4 Moderation::check(description)  (optioneel, OpenAI omni-moderation, gratis)  ── flagged? → idem blocked
  5 Limits::admit(owner, paid?)     budget/dag/min-interval      ── 429/503
  6 Credits::reserve(owner, 1, job) ── null? → 402
  7 job queued (brief opgeslagen), as_enqueue_async_action('bps_ai_run_job', [jobId], 'bps-ai')
  8 202 JobResponse
Job_Runner::run(jobId)   (Action Scheduler, of inline bij poll — zie onder)
  a atomair claimen: UPDATE … SET status='running', started_at=now WHERE id=%s AND status='queued'
  b Prompt_Engine::build(brief) → prompt + params
  c Provider_Registry::for(kind)->generate()/edit()  (timeout settings.timeoutSec, standaard 60)
     Provider_Error retryable && !policy && fallback ingesteld → 1× fallbackprovider
     Provider_Error policy → status blocked, code provider_policy, Credits::refund
  d Image_Store::save() + mark()  per beeld; Cost_Log::add()
  e status done | failed (+ refund); Limits::release()
GET /ai/jobs/{id}
  - status queued en created_at > 3 s geleden → Job_Runner::run() inline (claim voorkomt dubbel werk)
  - running en started_at > settings.jobTimeoutSec (90) → failed/timeout + refund
```

- **Terugval zonder Action Scheduler** (Woo uit): altijd inline bij de eerste poll.
- **Gelijktijdigheid**: max `limits.globalConcurrent` (4) jobs `running`; daarboven blijft een job `queued` en de
  poll antwoordt met `pollAfterMs` 3000. Na `jobTimeoutSec` in de wachtrij: `failed`/`provider_unavailable` + refund.
- **Verfijnen**: `Prompt_Engine::refine(brief, instruction, cfg, history, forceAi)`.
  `route=local` → `LocalRefine` (geen job, geen ronde). `route=ai_edit` → nieuwe job `kind=refine`, `parent_id`,
  provider `edit()` met het opgeslagen beeld `{parent}-{index}.orig.png` + prompt + params; `history` bewaren.
  `route=blocked` → 200 blocked.
- **Kiezen**: zet `chosen_index`, verlengt `keep_until` naar `accountDesignsAfterLastOpen` als de eigenaar een
  e-mail/account heeft; optionele vectorisatie (Recraft, kost gelogd, gratis voor de klant) met 15 s timeout — lukt
  het niet, dan gewoon de PNG.

---

## 6. Provider-interface en mock

### 6.1 Interface (`BorduurPatchStudio\AI\Providers\Provider`)

```php
interface Provider {
	public function id(): string;                         // 'mock'|'openai'|'recraft'
	public function label(): string;                      // klantzichtbaar: 'OpenAI', 'Recraft', 'Voorbeeldontwerpen (test)'
	public function is_configured(): bool;                // sleutel aanwezig (mock: altijd true)
	/** @return array{transparent_bg:bool, vector_output:bool, edit:bool, edit_mask:bool, text_quality:int, max_n:int} */
	public function capabilities(): array;
	/** @param array{prompt:string, params:array, model:string, safety_id:?string} $req */
	public function generate( array $req ): Provider_Result;
	/** @param array{prompt:string, params:array, model:string, image_path:string, mask_path:?string, safety_id:?string} $req */
	public function edit( array $req ): Provider_Result;
	public function vectorize( string $image_path ): Provider_Result;   // optioneel; anders Provider_Error 'unsupported'
	public function estimate_usd( string $op, array $params ): float;   // voor budget-reservering
}
```

- `Provider_Result`: `images` = lijst van `{bytes:string, mime:string, w:int, h:int}`, `usage` =
  `{tokens_in:?int, tokens_out:?int, images:int}`, `cost_usd:float`, `estimated:bool`, `duration_ms:int`,
  `model:string`.
- `Provider_Error extends \RuntimeException`: `code` ∈ `policy|rate_limit|timeout|auth|bad_request|unavailable|
  unsupported|unknown`, `retryable():bool`, `is_policy():bool`. **Nooit** de ruwe providerboodschap aan de klant tonen
  (kan prompt bevatten); wel in de admin-log (zonder sleutel, zonder klantgegevens).
- `safety_id`: enkel als `ai-legal.json defaults.sendSafetyIdentifier=true` (standaard **uit**):
  `hash_hmac('sha256', owner_key, wp_salt('auth'))`.

### 6.2 OpenAI (`Openai_Provider`)
`POST https://api.openai.com/v1/images/generations` en `/v1/images/edits` (multipart) via `wp_remote_post`, header
`Authorization: Bearer <Secrets::get('openai')>`, `model` uit de instellingen (`gpt-image-2`, instelbaar
`gpt-image-2.5-flare`), `size/quality/background/output_format/n` uit `build()['params']`, `moderation: "auto"`,
**geen** `user`-veld (tenzij `safety_id`), **geen** `input_fidelity` (01 §OpenAI). `b64_json` decoderen. 400 met
`moderation_blocked`/`content_policy_violation` → `policy`. 429 → `rate_limit` (retryable), 5xx/timeout →
`unavailable`/`timeout` (retryable). Weigert het model `background: transparent` (400 op die parameter) → opnieuw
met `background=fabric` via `Prompt_Engine` (handoff prompt §4). `usage` → kostlog.

### 6.3 Recraft (`Recraft_Provider`)
Terugval voor `generate` (V4 Styles Vector, SVG) en `vectorize` ($0.01). Alleen actief als er een sleutel is.
Endpoints en prijzen in `ai-providers.json` met `verified:false` tot de eigenaar ze nakijkt (admin toont waarschuwing).

### 6.4 Mock (`Mock_Provider`) — standaard, voor tests en demo
- Geen netwerk, geen sleutel, kost 0, `label()` = "Voorbeeldontwerpen (test)".
- Leest `config/ai-mock.json`: een lijst sjablonen (`wolf`, `lion`, `eagle`, `mountain`, `anchor`, `skull`,
  `shield-crest`, `tree`, `paw`, `star`) met trefwoorden NL/FR/EN en een SVG-bestand in `config/ai-mock/`. De SVG's
  zijn **eigen, vlakke, borduurbare tekeningen** (max 6 kleuren, dikke lijnen, transparante achtergrond), gemaakt
  voor dit project — geen merken, geen bestaande logo's.
- `generate()`: kiest sjablonen op trefwoorden uit de **Engelse brief-onderwerpen** (val terug op deterministische
  keuze via `crc32(prompt)`), kleurt ze met `brief.colors` (vervangt `{{c1}}…{{c6}}`), zet de vorm (`circle`,
  `shield`, …) als kader, en bij `textMode=ai` de tekst als `<text>` langs de rand. Geeft `n` **verschillende**
  varianten (ander sjabloon of andere kleurvolgorde/kaderdikte). Mime `image/svg+xml`; als `settings.mock.png=true`
  en Imagick beschikbaar is, gerasterd naar PNG 1024².
- `edit()`: zelfde sjabloon als het ouderbeeld, met een zichtbare, voorspelbare wijziging (bv. dikkere lijnen,
  andere kleurvolgorde) zodat de flow testbaar is.
- Testhaken (alleen als `settings.mode = test` of `WP_DEBUG`): beschrijving bevat `[[mock:fail]]` → `unavailable`,
  `[[mock:policy]]` → `policy`, `[[mock:slow]]` → `usleep(settings.mock.slowMs)`. Standaard latency
  `settings.mock.latencyMs` = 1200 (realistisch gevoel in de demo).
- In het paneel staat bij mock altijd de regel "Testmodus: dit zijn voorbeeldontwerpen, geen echte AI." /
  "Mode test : ce sont des exemples, pas une vraie IA." — nooit doen alsof het echte AI is.

### 6.5 Registry
`Provider_Registry::primary()`, `::fallback()`, `::vectorizer()`, `::get(id)`; filter
`bps_ai_providers` (array id → class) zodat later Ideogram of Gemini erbij kan zonder kernwijziging.
Modus `test` forceert altijd `mock`, ongeacht de providerkeuze.

### 6.6 `config/ai-providers.json` (ai-backend)

```json
{
  "schema": 1,
  "default": "mock",
  "fallback": null,
  "vectorizer": null,
  "providers": {
    "mock":    { "label": "Voorbeeldontwerpen (test)", "estUsdPerImage": 0 },
    "openai":  { "label": "OpenAI", "models": ["gpt-image-2", "gpt-image-2.5-flare"], "model": "gpt-image-2",
                 "quality": "medium", "size": "1024x1024", "background": "transparent", "outputFormat": "png",
                 "moderation": "auto", "timeoutSec": 60,
                 "pricing": { "usdPerMTokens": { "textIn": 5, "imageIn": 8, "imageOut": 30 }, "estUsdPerImage": 0.05 },
                 "verified": false },
    "recraft": { "label": "Recraft", "model": "recraftv4_vector", "estUsdPerImage": 0.08, "vectorizeUsd": 0.01,
                 "timeoutSec": 45, "verified": false }
  },
  "moderation": { "enabled": true, "provider": "openai", "model": "omni-moderation-latest" }
}
```

---

## 7. Prompt-engine (prompt)

- **Keuze: server-side in `includes/ai/`** (bestaat en is getest). De browser kent alleen stijllabels (via
  `public_config.ai.styles`) en limieten.
- `Prompt_Adapter::input(array $rest, array $studio_ctx): array` → invoer voor `Prompt_Engine::brief()`:
  `description, lang, style, shape, technique, maxColors, text, textMode, background:'transparent', fabric, sizeMm,
  edge`. Valt `context` weg, dan standaarden uit `config/defaults.json` (via `Settings::get()`).
- `Prompt_Adapter::understood(array $brief, string $lang): array` → het `understood`-object uit §4.3, met
  menselijke labels en kleur-hex (garenkleuren worden in de studio pas Madeira-nummers via de bestaande kleurmodule).
- Wat de engine al doet en zo blijft: inkorten, tekst eruit halen, privacyzuivering (`privacy.patterns`), lokale
  veiligheidsfilter, NL/FR → Engels, stijl/vorm/kleur, borduurregels per techniek, verfijnen lokaal vs AI.
- Openstaande taken prompt: (1) `Prompt_Adapter`; (2) `refine()`-acties `open_*` matchen met de studio-acties in §10.4;
  (3) mock-trefwoorden afstemmen op de Engelse `subject`-woordenlijst; (4) vectoren voor 10 extra FR-zinnen.

---

## 8. Moderatie (drie lagen + misbruik)

| Laag | Wat | Eigenaar | Ronde |
|---|---|---|---|
| 1 Lokaal | `Prompt_Engine::safety()` op beschrijving + tekst + verfijninstructie (merken, kopie, personen, officiële emblemen, haat, seks, geweld, drugs, stijl van een levende kunstenaar) | prompt | nooit afgeboekt |
| 2a Moderatie-API | OpenAI `omni-moderation-latest` op de **gezuiverde** beschrijving (gratis, kost gelogd als 0). Uit te zetten in admin. | ai-backend | nooit afgeboekt |
| 2b Provider | `moderation:"auto"`; `policy`-fout → `blocked` | ai-backend | teruggeboekt |
| 3 Mens | Digitaliseerder kijkt elk AI-ontwerp na vóór de proef (vinkje in orderbeheer, premium-team, handoff Juridisch 3) | premium-team | — |

- Melding bij `brand`/`copy`: `ai-prompts.json safety.messages.*` + knop `legal.ai.own_logo_cta` (opent de gewone
  upload, event `bps:ai:upload:requested`).
- **Misbruikpauze**: ≥ `abusePauseRefusalsPerHour` (5) weigeringen per eigenaar of IP-hash per uur → 423
  `bps_ai_paused` voor `abusePauseMinutes` (60), met `legal.ai.abuse_pause` (verwijst naar gratis offerte).
- Weigeringen worden geteld per categorie (`bps_ai_refusal_counts` optie, per dag) voor de admin; de beschrijving zelf
  blijft max 30 dagen in de job (§11).

---

## 9. Limieten en kostbewaking (ai-backend, `Limits` + `Cost_Log`)

| Instelling (`bps_ai_settings.limits`) | Standaard | Gedrag |
|---|---|---|
| `dailyBudgetEur` | 10 | Bereikt (werkelijk + gereserveerd vandaag) → **gratis rondes gepauzeerd** (`freePaused:true`), betaalde werken door |
| `monthlyBudgetEur` | 150 | Mail naar `alerts.email` bij `alertAtPercent` (80 %) en bij 100 %; boven 100 % gratis rondes gepauzeerd |
| `monthlyHardCapEur` | 250 | Alles gepauzeerd: 503 `bps_ai_budget` ("De AI-studio is even niet beschikbaar. Je rondes blijven bewaard.") |
| `perOwnerPerDay` | 20 | Ook betaald; 429 `bps_ai_daily_limit` |
| `minSecondsBetween` | 8 | Per eigenaar én per IP-hash; 429 met `retryAfter` |
| `anonPerIpPerDay` | 3 | Max nieuwe gratis anonieme rondes per IP-hash per 24 u |
| `globalConcurrent` | 4 | Jobs tegelijk `running` |
| `jobTimeoutSec` | 90 | Daarna `failed`/`timeout` + refund |
| `emailCodesPerIpPerHour` | 5 | E-mailpoort |
| `usdToEur` | 0.93 | Omrekening kostlog; admin toont "laatst aangepast op" |

- **Reservering**: bij `admit()` wordt `estimate_usd()` × `usdToEur` als reservering opgeteld (transient
  `bps_ai_reserved_{jobId}`) zodat 20 gelijktijdige jobs het budget niet samen overschrijden; na afloop vervangen door
  de werkelijke kost.
- Budgetsom: `SUM(cost_eur_micro)` over `bps_ai_costs` voor vandaag/deze maand (site-tijdzone), 60 s gecachet.
- **Alertmail**: `wp_mail` naar `alerts.email` (standaard `admin_email`), enkel getallen (kost, aantal rondes,
  percentage), nooit klantgegevens. Maximaal 1 mail per drempel per maand (optie `bps_ai_alerts_sent`).
- **Kengetallen** (admin, uit `costs`, `jobs`, `ledger`, Woo-orders): rondes/dag, gratis vs betaald, % voorstellen
  gekozen, % gekozen → besteld (`_bps_ai` op orderitems), verkochte pakketten, ingewisselde coupons, kost per ronde,
  AI-kost per bestelling, weigeringen per categorie. Geen individuele klantlijsten.

---

## 10. Front-end module-API (frontend)

### 10.1 Laden
De studio (studio-team) laadt de bundel lui bij de eerste tik op een AI-ingang:

```js
// in de studio (handoff): 
const ai = await loadScriptOnce(config.ai.bundleUrl);          // zet window.BPSAI
const panel = window.BPSAI.mount(sheetOrColumnEl, ctx);       // één keer per studio-root
panel.open({ entry: 'empty-state' });                          // of 'toolbar', 'design-tab', 'history'
```

`config.ai` komt uit `public_config` via de filter `bps_public_config` (ai-backend voegt toe; geen wijziging aan
`Settings` nodig): `{ mode, bundleUrl, bundleVer, providerLabel, n, styles:[{id,label,hint}], limits, legal:{…strings},
prefetch:true }`. Modus `off` → `config.ai` ontbreekt en de studio toont geen AI-ingang.

### 10.2 `BPSAI.mount(el, ctx)` → `AiPanel`

```ts
type Ctx = {
  host: HTMLElement;                 // studio-root (.bps-studio): hier worden alle events op gedispatcht
  config: PublicConfig;              // volledige publieke config (config.ai, config.services, config.strings)
  locale: 'nl' | 'fr';
  api: { base: string; nonce?: string; token?: () => Promise<string> };  // base = rest url + 'patch-studio/v1'
  getStudio(): { shape?: string; technique: string; sizeMm: number; fabric?: string; edge?: string;
                 maxColors?: number; hasDesign: boolean };                // context voor de brief
  quickCheck?(file: File): Promise<{ status: 'ok'|'warn'|'bad'; reasons: string[] }>; // optioneel: borduurcheck per voorstel
  renderOnPatch?(src: string | null): void;  // optioneel: voorstel tijdelijk op de patch-render tonen (null = terug)
  openCheckout?(url: string): void;  // optioneel; standaard window.open(url, '_blank', 'noopener')
  mockApi?: boolean;                 // true = assets/src/ai/mock-api.js (demo, dev, node-tests)
};
type AiPanel = {
  open(opts?: { entry?: string; prefill?: string }): void;
  close(): void;
  refreshBalance(): Promise<void>;   // na terugkeer uit de kassa (focus/visibilitychange doet dit ook automatisch)
  destroy(): void;
  state(): Readonly<AiState>;        // voor tests
};
```

### 10.3 Events (CustomEvent op `ctx.host`, `bubbles:true`)

| Event | `detail` | Studio doet |
|---|---|---|
| `bps:ai:open` | `{ entry }` | (analytics) |
| `bps:ai:close` | `{ reason: 'user'|'chosen'|'upload' }` | assistent-kolom terug |
| `bps:ai:job:started` | `{ jobId, kind, n }` | — |
| `bps:ai:job:done` | `{ jobId, kind, count }` | — |
| `bps:ai:job:failed` | `{ jobId, code, refunded: true }` | — |
| `bps:ai:blocked` | `{ code, message }` | — |
| `bps:ai:preview` | `{ jobId, index, url }` of `{ url: null }` | tijdelijk op de patch tonen (als `renderOnPatch` ontbreekt) |
| **`bps:ai:design:chosen`** | `DesignChosen` (hieronder) | **`ctx.handleFile(detail.file)`**, `state.source.ai = detail.ai`, hints toepassen, tekstlaag bij `textMode=studio`, AI-badge tonen |
| `bps:ai:local:action` | `{ action: 'open_text'|'open_colors'|'reduce_colors'|'open_size'|'open_shape'|'open_arrange', text?, colors?, shape? }` | juist paneel openen / waarde zetten |
| `bps:ai:upload:requested` | `{ reason: 'brand'|'copy'|'user' }` | gewone upload openen |
| `bps:ai:credits:changed` | `{ balance }` | teller in de tabbalk (optioneel) |
| `bps:ai:pack:checkout` | `{ packId, url }` | — (analytics) |
| `bps:ai:service:suggested` | `{ serviceId: 'd1_finish', jobId, index }` | dienstkaart tonen via services-module |
| `bps:track` | `{ event: 'ai_*', props }` (bestaand analytics-event, enkel met toestemming) | — |

```ts
type DesignChosen = {
  file: File;                        // PNG (transparant) of SVG; name = ChooseResponse.fileName
  url: string;                       // ondertekende URL (1 u), voor wie liever zelf ophaalt
  source: 'ai';
  ai: { jobId: string; index: number; provider: string; model: string; label: string;
        humanFinish: false; legalVersion: string };
  hints: { shape?: string; maxColors?: number; colors?: string[]; textMode?: 'studio'|'ai'|'none';
           text?: string; placement?: string; background: 'transparent' };
};
```

Regels voor de studio bij `design:chosen`: hints enkel toepassen op velden die de klant nog niet zelf koos; bij
`textMode=ai` de vraag "Klopt de tekst «…»?" tonen (handoff prompt §5); het ontwerp-payload bewaart `source.ai`
zodat de server bij `/designs` en `/cart` `_bps_ai` kan zetten (ai-backend hookt op `bps_design_created`, controleert
dat `jobId` van dezelfde eigenaar is en `chosen_index` klopt, anders wordt `source.ai` genegeerd).

### 10.4 Toestandsmachine (`ai/store.js`)
`idle → describing → submitting → waiting(jobId) → proposals → (refining → waiting) → chosen`; zijpaden
`blocked`, `failed`, `needRounds(email|pack|consent)`, `paused`, `budget`. Elke toestand heeft precies één primaire
knop. Herstel: bij `open()` → `GET /ai/status`; `openJob` → meteen `waiting`. Polling: 1,5 s, na 10 s 3 s, stopt na
`jobTimeoutSec + 10` s met nette fout; pauzeert bij `document.hidden`.

### 10.5 Diensten-module (`assets/src/services/index.js`, ESM, door de studio geïmporteerd)

```ts
evaluate(signals: Signals, catalog: ServiceDef[], dismissed: string[]): Offer | null;  // puur, max 1 aanbod
card(el: HTMLElement, offer: Offer, ctx: { locale, api, host, designRef? }): { destroy() };
type Signals = {
  stage: 'analysis'|'proposals'|'price'|'checkout'|'thanks';
  analysis?: { minSidePx?: number; jpegNoise?: number; photoOfLogo?: boolean; sketch?: boolean };
  check?: { status: 'ok'|'warn'|'bad'; autoFixAvailable: boolean };
  isAi?: boolean; qty?: number; totalIncl?: number; technique?: string;
  neededBy?: string; normalDate?: string; rushDate?: string; selfEmbroider?: boolean;
};
type Offer = { serviceId: string; reason: string; priceHtml: string; freeAlternative?: string; cta: string };
```

Triggers (uit 03 §5.2, getallen in `ai-services.json`): D2 bij `minSidePx < 300` / `photoOfLogo` / hoge ruis;
D2b bij `sketch`; D1 bij `isAi && check.status !== 'ok' && !autoFixAvailable`; D4 enkel als ingeschakeld én
`neededBy < normalDate` (toont de nieuwe datum); D6 bij `qty ≥ 50 || totalIncl ≥ 250 || technique === 'pvc'`;
D3 bij `selfEmbroider`; D3b op `thanks`. Nooit vooraf aangevinkt, altijd met "Nee, dank je"; afwijzing onthouden
per ontwerp (localStorage `bps_svc_dismiss_{designRef}` in try/catch + optioneel `POST /services/dismiss`).
Events: `bps:svc:offer:shown {serviceId}`, `bps:svc:added {serviceId, line}`, `bps:svc:dismissed {serviceId}`.

---

## 11. WooCommerce: producten, kassa, verrekening (commerce)

### 11.1 Producten
- `Products::ensure()` maakt per ingeschakeld pakket en per dienst één **virtueel, niet-downloadbaar** product
  (`catalog_visibility = hidden` standaard; instelbaar "toon in de winkel" voor diensten D2/D3), btw-klasse standaard,
  meta `_bps_ai_pack = packId` of `_bps_service = serviceId`. ID's in optie `bps_svc_products` (`{id: productId}`).
  Draait bij het bewaren van de AI-/dienstinstellingen en via de knop "Producten nakijken" (herstelt verwijderde of
  gewijzigde producten; prijs uit onze instellingen wint, met melding).
- Directe add-to-cart van die producten buiten onze REST-routes is toegestaan voor pakketten (ook vanuit "Mijn
  account"), niet voor D4 (hangt aan een patchregel).
- **Spoed D4** (`kind=line_option`): eigen productregel "Spoedlevering" gekoppeld aan een patchregel via cart item data
  `_bps_linked_ref`; prijs berekend in `woocommerce_before_calculate_totals` (prio 20) = `max(minIncl, percent ×
  subtotaal van de gekoppelde patchregel(s))`; verdwijnt automatisch als de patchregel verdwijnt. D3b en D7 idem als
  gekoppelde regels (D7 € 0 vanaf `freeFromQty`). Zo hoeft `woo/class-cart.php` niet te wijzigen.

### 11.2 Kassa (klassiek + blokken) — teksten uit `config/ai-legal.json`
- Klassiek: `woocommerce_review_order_before_submit` (prio 25) toont `legal.ai.pack_consent_start` +
  `legal.ai.pack_consent_ack` (twee aparte, lege vakjes) als er een pakket in de mand zit, en `legal.svc.consent` bij
  D1/D2/D2b/D3/D5/D7. **Niet blokkerend.**
- Blokken: `woocommerce_register_additional_checkout_field` (Woo ≥ 8.9) met `location: 'order'`, type `checkbox`,
  `required: false`; verbergen als er geen pakket/dienst in de mand zit via `rules.hidden` waar ondersteund (Woo ≥
  9.9), anders zichtbaar met de tekst "Enkel van toepassing als je ontwerprondes koopt".
- `woocommerce_checkout_create_order` / `woocommerce_store_api_checkout_update_order_from_request`: ordermeta
  `_bps_ai_withdrawal_consent` en per dienst `_bps_svc_start_after`.
- Geen toestemming gegeven → rondes worden toch toegekend maar `paidLocked` tot +14 d of tot de klant ze in het paneel
  alsnog geeft (`POST /ai/credits/consent`, `legal.ai.pack_consent_later`).
- `woocommerce_email_after_order_table`: `legal.ai.pack_email` met de toestemmingstekst, versie, datum en uur.

### 11.3 Fulfilment
- `woocommerce_order_status_processing` + `_completed` + `woocommerce_payment_complete` → idempotent (vlag
  `_bps_ai_fulfilled` per orderitem): per pakketregel `Credits::grant(owner, 'pack', rounds × qty, …)` met eigenaar =
  account, anders e-mail van de bestelling (`'e:'`-sleutel, e-mail als bevestigd beschouwd: de klant betaalde ermee);
  daarna samenvoegen met het `bps_ai_sid` dat in de cart-sessie bewaard werd (`WC()->session` sleutel
  `bps_ai_owner`). Per bestelling met patchproduct: `free_order`.
- Terugbetaling/annulering van een pakketbestelling (`woocommerce_order_refunded`, `_cancelled`) → kavel herroepen voor
  het ongebruikte deel; coupon vervalt.

### 11.4 Verrekening als korting
- Per pakketregel één coupon: code `AI-` + 8 tekens (Crockford base32), `discount_type = fixed_cart`, bedrag =
  `price_incl × orderCredit.percent / 100` (Woo rekent coupons incl./excl. btw volgens de winkelinstelling: bedrag
  invoeren zoals de winkel prijzen invoert), `minimum_amount = minArticleIncl`, `usage_limit = 1`,
  `usage_limit_per_user = 1`, `email_restrictions = [e-mail]`, `date_expires = +validMonths`,
  `individual_use = false`, `product_ids` = patchproduct + extra-producten (via filter `bps_ai_credit_product_ids`),
  `exclude_product_ids` = alle pakket- en dienstproducten. Meta `_bps_ai_credit_coupon = 1`.
- **Automatisch toepassen**: `woocommerce_before_calculate_totals` (prio 30, 1× per request) – als de mand een
  patchproduct bevat, de klant (ingelogd of via `billing_email` in de sessie) een geldige AI-coupon heeft en er nog
  geen AI-coupon toegepast is → hoogste geldige coupon toepassen; nooit twee AI-coupons op één bestelling. Melding:
  "Je ontwerppakket komt terug: − € 4,95" / "Votre pack design vous est rendu : − 4,95 €".
- Gebruikt → ordermeta `_bps_ai_credit_used`.

### 11.5 Herroeping ("Mijn account" → "Ontwerprondes")
`add_rewrite_endpoint('ontwerprondes')` (FR-slug via filter), menu-item via `woocommerce_account_menu_items`, pagina
toont saldo, kavels, vervaldata, en per pakket binnen 14 dagen de knop `legal.ai.withdraw_button` →
samenvatting `legal.ai.withdraw_summary` (ongebruikt, bedrag) → bevestigen `legal.ai.withdraw_confirm` →
`wc_create_refund(['amount'=>…, 'order_id'=>…, 'line_items'=>…, 'refund_payment'=>true])` (lukt automatisch
terugbetalen niet: beheerder krijgt een taak-mail), coupon in prullenbak, mail `legal.ai.withdraw_email`.
Gasten: HMAC-getekende link in de pakketmail (`?bps_wd=orderId.exp.sig`, 30 dagen).
`flush_rewrite_rules` enkel bij db-versie-upgrade van de module.

---

## 12. Admin-instellingen

Eigen pagina **WooCommerce → Patch Studio AI** (`manage_woocommerce`), schil door `AI\Admin`, secties via filter
`bps_ai_admin_sections`. Zelfde stijl als de bestaande beheerpagina: gewone taal, eenheden, grenzen, geen JSON,
"Standaard terugzetten" per sectie. Opslag: optie `bps_ai_settings` (deep-merge over `ai-providers.json`,
`ai-pricing.json`, `ai-services.json`), sleutels apart in `bps_ai_secrets`.

| Tab / sectie | Velden | Eigenaar |
|---|---|---|
| **Aan/uit** | Modus `Uit / Test (voorbeeldontwerpen, enkel beheerders) / Live`; knop "Probeer zelf" (opent de studio in test); statusblok: sleutel ok?, laatste fout, kost vandaag/maand | ai-backend |
| **AI-dienst** | Provider (OpenAI / Recraft), model (keuzelijst), kwaliteit (laag/medium), voorstellen per ronde (2–4), versies per verfijning (1–2), terugvalprovider, vectoriseren na kiezen (aan/uit), moderatie-API (aan/uit), providernaam tonen aan klant (verplicht aan in live: AI Act), knop "Verbinding testen" (1 goedkope call, kost gelogd) | ai-backend |
| **API-sleutels** | OpenAI-sleutel, Recraft-sleutel: invoerveld type password, toont enkel "ingesteld · eindigt op …abcd", knop "Wissen". Staat er een constante `BPS_OPENAI_API_KEY` / `BPS_RECRAFT_API_KEY` in `wp-config.php`, dan is het veld vergrendeld met uitleg | ai-backend |
| **Limieten & kosten** | alle velden uit §9, alert-e-mail, kengetallen (laatste 30 dagen), kostlog als CSV-download (zonder persoonsgegevens) | ai-backend |
| **Rondes & pakketten** | gratis anoniem / met e-mail / per bestelling, e-mailbevestiging (aan), pakketten (aan/uit, rondes, prijs excl. btw met incl.-voorbeeld), geldigheid (12–36 mnd, min 12), terug als korting (0/50/100 %, minimum, geldigheid), herroeping ongebruikt (aan) | commerce |
| **Diensten** | per dienst D1–D7: aan/uit, label NL/FR, korte uitleg NL/FR, prijs excl. btw, levertijd, triggers (getallen), "toon in winkel"; D4/D5 standaard uit met uitleg | commerce |
| **Bewaren** | bewaartermijnen uit `ai-legal.json retentionDays` (min/max), "wis nu verlopen gegevens" | ai-backend |

Sleutelopslag (`Secrets`): constante wint; anders `sodium_crypto_secretbox` met sleutel
`sodium_crypto_generichash(wp_salt('auth') . 'bps-ai-secrets', '', 32)`; zonder sodium: opgeslagen zoals WP zelf
doet, met adminwaarschuwing. Nooit in `public_config`, REST, exports, logs, `bps_settings` of foutmeldingen
(foutmeldingen worden door `Secrets::redact()` gehaald).

---

## 13. Privacy en bewaren

- **Naar de provider**: enkel de Engelse prompt (gebouwd uit de gezuiverde beschrijving) en bij verfijnen ons eigen
  vorige beeld. Geen `user`-veld (standaard), geen IP, geen cookies, geen bestandsnamen van klanten.
- **Cleanup** (`bps_ai_cleanup`, dagelijks, eigen WP-Cron-event): jobs + beelden van eigenaars zonder bestelling na
  `guestPromptsAndProposals` (30 d); gekozen ontwerpen in een account na `accountDesignsAfterLastOpen` (365 d);
  gekozen ontwerpen bij een bestelling volgen het ontwerp-post (bestaande cleanup); `input.description` van geweigerde
  jobs na 30 d op `null`; `ip_hash` na 30 d op `null`; kavels laten vervallen; ledger en kostlog blijven
  (ledger 10 jaar, daarna weg; kostlog zonder persoonsgegevens).
- **Exporter/eraser**: `AI\Privacy` registreert extra items op `wp_privacy_personal_data_exporters/erasers` (additief):
  owners-rij, jobs (beschrijving, datum, status), beelden (links), kavels/ledger. Eraser: jobs + beelden wissen,
  owners-e-mail op `null`; ledger en orders blijven (wettelijke bewaarplicht) maar `owner_key` wordt geanonimiseerd
  naar `'x:'+hash` als er geen openstaand saldo is.
- **Privacytekst**: `wp_add_privacy_policy_content` met 04 §6.7 (ai-backend).

---

## 14. Klantteksten (NL "je" / FR "vous")

Juridische teksten: `config/ai-legal.json` (bestaand, niet dupliceren). Prompt-meldingen: `config/ai-prompts.json`.
UI-teksten: `assets/src/ai/messages.js` en `assets/src/services/messages.js` (zelfde vorm als de andere modules,
overschrijfbaar via `config.strings`). Basisset (frontend mag aanvullen, niet afwijken in toon):

| Sleutel | NL | FR |
|---|---|---|
| `ai.entry.title` | Nog geen logo? Laat AI iets maken | Pas encore de logo ? Laissez l'IA en créer un |
| `ai.entry.sub` | {free} gratis · meteen borduurbaar | {free} gratuit(s) · directement brodable |
| `ai.title` | Ontwerp met AI | Créer avec l'IA |
| `ai.prompt.label` | Wat wil je op je patch? | Que voulez-vous sur votre écusson ? |
| `ai.prompt.placeholder` | Bv. een leeuw met een kroon voor onze voetbalclub, blauw-wit | P. ex. un lion couronné pour notre club de foot, bleu et blanc |
| `ai.text.label` | Tekst op de patch (optioneel) | Texte sur l'écusson (facultatif) |
| `ai.more` | Meer opties | Plus d'options |
| `ai.auto` | Laat ons kiezen | Laissez-nous choisir |
| `ai.generate` | Maak {n} voorstellen | Créer {n} propositions |
| `ai.cost.one` | 1 ronde | 1 essai |
| `ai.free_left` | {n} gratis ronde(s) | {n} essai(s) gratuit(s) |
| `ai.left` | Nog {n} rondes | Encore {n} essais |
| `ai.wait` | Even geduld, we tekenen {n} voorstellen (± 15 s) | Un instant, nous dessinons {n} propositions (± 15 s) |
| `ai.cancel` | Annuleren | Annuler |
| `ai.understood` | Zo begrepen we je: | Voici ce que nous avons compris : |
| `ai.use` | Gebruik dit ontwerp | Utiliser ce design |
| `ai.refine` | Pas aan… (bv. "maak de wolf bozer") | Modifier… (p. ex. « rendez le loup plus féroce ») |
| `ai.refine.free` | Dit kan je gratis zelf in de studio. | Vous pouvez le faire gratuitement dans le studio. |
| `ai.refine.force` | Toch met AI (1 ronde) | Quand même avec l'IA (1 essai) |
| `ai.error` | Dat lukte niet, je ronde is teruggezet. Probeer het opnieuw. | Cela n'a pas fonctionné, votre essai a été recrédité. Réessayez. |
| `ai.timeout` | Dit duurt te lang. Je ronde is teruggezet. | Cela prend trop de temps. Votre essai a été recrédité. |
| `ai.slow_down` | Even wachten, over {s} seconden kan het weer. | Patientez un instant, c'est à nouveau possible dans {s} secondes. |
| `ai.daily_limit` | Je hebt vandaag het maximum aantal rondes gebruikt. Morgen kan het weer. | Vous avez utilisé le maximum d'essais pour aujourd'hui. Ce sera à nouveau possible demain. |
| `ai.free_paused` | Gratis rondes zijn vandaag even op. Betaalde rondes werken gewoon, of probeer morgen opnieuw. | Les essais gratuits sont épuisés pour aujourd'hui. Les essais payés fonctionnent, ou réessayez demain. |
| `ai.unavailable` | De AI-studio is even niet beschikbaar. Je rondes blijven bewaard. | Le studio IA est momentanément indisponible. Vos essais sont conservés. |
| `ai.email.title` | Krijg 2 extra rondes | Recevez 2 essais en plus |
| `ai.email.body` | Geef je e-mailadres, dan bewaren we ook je ontwerpen. We sturen het nooit naar de AI. | Indiquez votre e-mail, nous gardons aussi vos designs. Nous ne l'envoyons jamais à l'IA. |
| `ai.email.send` | Stuur code | Envoyer le code |
| `ai.email.code` | Vul de code uit je mail in | Saisissez le code reçu par e-mail |
| `ai.email.ok` | Gelukt! Je hebt {n} extra rondes. | C'est fait ! Vous avez {n} essais en plus. |
| `ai.pack.title` | Je gratis rondes zijn op | Vos essais gratuits sont épuisés |
| `ai.pack.kept` | Je ontwerpen blijven bewaard. | Vos designs restent conservés. |
| `ai.pack.option` | {n} rondes · {price} | {n} essais · {price} |
| `ai.pack.refund` | Je krijgt het bedrag terug als korting op je patches (vanaf € {min}, {months} maanden geldig). | Le montant vous est rendu en réduction sur vos écussons (dès {min} €, valable {months} mois). |
| `ai.pack.pay` | Betaal {price} | Payer {price} |
| `ai.pack.alt` | Liever niet? Gebruik een voorstel, upload je logo of laat onze ontwerper het maken. | Plutôt pas ? Utilisez une proposition, importez votre logo ou confiez-le à notre graphiste. |
| `ai.pack.back` | Klaar met betalen? Je rondes verschijnen hier vanzelf. | Paiement terminé ? Vos essais apparaissent ici automatiquement. |
| `ai.test_mode` | Testmodus: dit zijn voorbeeldontwerpen, geen echte AI. | Mode test : ce sont des exemples, pas une vraie IA. |
| `ai.history` | Mijn voorstellen | Mes propositions |
| `svc.no` | Nee, dank je | Non merci |
| `svc.add` | Voeg toe · {price} | Ajouter · {price} |
| `svc.added` | Toegevoegd aan je winkelmand. | Ajouté à votre panier. |
| `svc.why` | Waarom zie ik dit? | Pourquoi je vois ceci ? |
| `svc.d1` | Laat onze ontwerper dit afwerken · {price} — strakke lijnen, garenkleuren, klaar om te borduren. | Faites finaliser par notre graphiste · {price} — lignes nettes, couleurs de fil, prêt à broder. |
| `svc.d2.reason` | Je bestand is klein of wazig ({px} px). Wij bekijken het gratis bij je proef. Wil je zeker een strak resultaat? | Votre fichier est petit ou flou ({px} px). Nous le vérifions gratuitement lors de l'épreuve. Vous voulez être sûr d'un résultat net ? |
| `svc.d2` | Laat ons je logo hertekenen · {price} — je krijgt ook het vectorbestand. | Faites redessiner votre logo · {price} — vous recevez aussi le fichier vectoriel. |
| `svc.d4` | Spoed: levering rond {date} in plaats van {normalDate} · + {amount} | Express : livraison vers le {date} au lieu du {normalDate} · + {amount} |
| `svc.d6` | Zeker zijn vóór je {qty} stuks bestelt? Vraag een echt staal · {price}, terug bij je bestelling vanaf {refundQty} stuks. | Vous voulez être sûr avant de commander {qty} pièces ? Demandez un échantillon · {price}, remboursé à la commande dès {refundQty} pièces. |
| `svc.d3b` | Wil je je borduurbestand ook? · {price} | Vous voulez aussi votre fichier de broderie ? · {price} |

Verboden in alle teksten: "credits", "tokens", "beperkt aanbod", "nog maar", "populairst", sterren, aftelklokken
(test `tests/ai/copy-honesty.test.js` scant `messages.js`, `ai-services.json`, `ai-pricing.json`).

---

## 15. Config-bestanden (nieuw)

- `config/ai-providers.json` (ai-backend) — §6.6.
- `config/ai-mock.json` + `config/ai-mock/*.svg` (ai-backend) — §6.4.
- `config/ai-pricing.json` (commerce) — 03 §9 zonder `services`, plus: `"mode": "off"`, `"emailVerify": true`,
  `"packs": [{ "id", "rounds", "priceExcl", "enabled" }]` (prijzen excl. btw met 4 decimalen: 4.0909 / 8.2231),
  `"validityMonths": 24`, `"freeAnonValidDays": 30`, `"freeValidMonths": 12`, `"orderCredit"`, `"limits"` (§9),
  `"alerts": { "email": "" }`, `"usdToEur": 0.93`.
- `config/ai-services.json` (commerce) — D1–D7 uit 03 §5.1 met `enabled`, `kind`, `priceExcl`, `leadDays`,
  `needsConsent`, `label/short/freeAlternative {nl,fr}`, `triggers` met drempels, `offerRules {maxVisible:1,
  neverPreselected:true, rememberDismiss:true}`. D4 en D5 `enabled:false`.
- Elk bestand: `"schema": 1`, `"_doc"`, geen secrets, NL+FR voor elke klanttekst (test `config-bilingual.test.js`).

---

## 16. Integratie en grenzen

- **Bestaande code blijft onaangeroerd.** Alles registreert zich via hooks. De enige noodzakelijke wijzigingen in
  bestaande bestanden staan precies in `docs/handoff/ai-services-needs.md` §"Architectuur": (1) twee regels in
  `Plugin::COMPONENTS`, (2) build-entry `ai/index.js`, (3) studio-UI-haakjes (ingang, laden, events, `ctx`),
  (4) `test:php`-script, (5) premium-team: orderblok `_bps_ai`.
- Tot (1) gebeurd is, laadt niemand de module: veilig om parallel te bouwen. Tests laden de klassen rechtstreeks.
- Hooks die de module zelf gebruikt (bestaand): `bps_public_config`, `bps_design_created`, `bps_design_ordered`,
  `bps_settings_updated`, `bps_cleanup_done`, `bps_activate`, `bps_deactivate`. Nieuwe hooks die de module aanbiedt:
  `bps_ai_providers`, `bps_ai_admin_sections`, `bps_ai_credit_product_ids`, `bps_ai_job_done` (`$job`),
  `bps_ai_rounds_granted` (`$owner_key, $lot`), `bps_svc_offer_rules`.
- Uninstall: tabellen en opties van de module worden verwijderd door `AI\Module::uninstall()` — handoff: aanroep in
  `uninstall.php` (bestaand bestand, premium-team), enkel als "gegevens wissen bij verwijderen" aan staat.

### 16.1 Tests (tests)

| Test | Wat | Hoe |
|---|---|---|
| `tests/ai/run-ai-php.php` | Credits (reserve/refund idempotent, volgorde, verval, herroeping-bedrag, samenvoegen), Limits (budget, reservering, min-interval), Mock_Provider (n verschillende, kleuren, policy/fail-haken), Openai_Provider met nep-HTTP (foutmapping, geen `user`-veld, geen sleutel in fouten), Job_Runner (claim, terugval, timeout→refund, policy→geen fallback), Prompt_Adapter | PHP CLI met minimale WP-stubs (`tests/ai/php/wp-stubs.php`) en in-memory stores; geen netwerk |
| `tests/services/run-services-php.php` | Coupon-bedragen, auto-apply-keuze, D4-prijs, kassa-toestemmingsmeta, fulfilment-idempotentie | idem |
| `tests/ai/*.test.js` | `store.js` toestandsmachine, `api.js` foutmapping, polling/back-off, event-detail `design:chosen` (File + ai + hints), messages NL/FR compleet, copy-eerlijkheid, config-integriteit | `node --test`, `mock-api.js`, jsdom-vrij (eigen mini-DOM of enkel logica) |
| `tests/services/*.test.js` | `evaluate()` per trigger, max 1 aanbod, dismissed, nooit bij geen probleem | `node --test` |
| `tests/ai/e2e/*.spec.js` | Gsm 390×844 + desktop: ingang → beschrijven → 3 voorstellen → kiezen → ontwerp op canvas in < 2 min (mock); geweigerd merk → geen ronde af; rondes op → pakket-sheet | Playwright tegen demo met `mockApi` (geen WP nodig) |

Bridges: `tests/ai/ai-php.test.js` roept de PHP-runners aan als `php` bestaat (zelfde patroon als
`prompt-config.test.js`), zodat `npm test` alles draait.

### 16.2 Bouwvolgorde
1. **ai-backend** + **commerce**: `Credits` + `Credits_Store` (interface eerst, dag 1), schema, `Identity`,
   `Mock_Provider`, `Jobs`/`Job_Runner`, `/ai/status|generate|jobs|choose`. → end-to-end met mock.
2. **frontend** parallel tegen `mock-api.js` (zelfde schema's als §4.3).
3. **prompt**: `Prompt_Adapter`, refine-acties, mock-trefwoorden.
4. **commerce**: producten, kassa, fulfilment, coupon, herroeping, diensten.
5. **ai-backend**: OpenAI, Recraft, moderatie, budget, admin, cleanup, privacy.
6. **tests**: e2e en contracttests lopen mee vanaf stap 1.

---

## 17. Open punten (eigenaar) en changelog

Open (uit 03 §11 en 04, beslissen vóór `live`): richting "klein betalen, volledig terug" vs "gratis met e-mail";
dienstprijzen D1–D7; spoed leverbaar?; proefaanpassingen gratis (bepaalt D5); fysiek staal haalbaar?; wie krijgt de
budgetmails; btw-behandeling pakket + korting **[BOEKHOUDER]**; officiële providerprijzen nakijken (`verified`);
OpenAI-DPA aanvaarden; AV-artikel §4.7 en privacytekst §6.7 plaatsen.

| Datum | Wijziging |
|---|---|
| 17-11-2026 | Eerste versie (architect). |
