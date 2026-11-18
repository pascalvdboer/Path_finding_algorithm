# Audit tests & CI — Patch Studio (tests-ci)

Datum: 2026-11-18 · Scope: testkwaliteit (PHP, JS-unit, e2e), CI-workflow, release-proces. Er is geen code gewijzigd.
Let op: andere teams werkten tijdens de audit in dezelfde boom (o.a. `includes/ai/`, `includes/services/`, `assets/src/ui/`).

## Wat er gedraaid is

| Commando | Resultaat |
|---|---|
| `npm test` (node 22.23) | 353 tests: 350 pass, 0 fail, 3 todo, 0 skipped. Duur ±59 s (vooral de nauwkeurigheidscases en de browsertests). |
| `node --test --experimental-test-coverage "tests/**/*.test.js"` | Totaal 77 % regels / 84 % branches, maar alleen voor bestanden die een test importeert. Nooit geïmporteerd: `admin/qr.js`, `admin/production.js`, `nametape/ui.js` (1186 regels), `nametape/render.js`, `nametape/state.js`, `crosssell/panel.js`, `crosssell/mockups.js`, `crosssell/index.js`. Laag: `ui/derive.js` 55 %, `ui/state.js` 51 % (branches 48 %). |
| `npm run test:php` | 47 vectoren, 652 checks, 0 fouten (enkel Pricing). |
| `php tests/nametape/run-php.php` | 83 ok, 0 fout. Wordt door geen enkele runner gestart. |
| `php tests/crosssell/run-php.php`, `php tests/ai/run-prompt-php.php` | 181 checks ok, 444 checks ok (via `npm test`). |
| `bash tests/e2e/run-e2e.sh` (lokale WP + WooCommerce, SQLite) | 8/8 scenario's PASS, geen PHP-notices. |
| Handmatige REST-proeven op de test-site | Ongeldig token: 403 `bps_bad_token` (correct, maar geen test). |
| 12× parallel `Designs::next_ref()` via WP-CLI | 12 unieke nummers (0001–0012) op SQLite. Er bestaat geen geautomatiseerde test en geen MySQL-variant. |
| `bash scripts/build-zip.sh` | **Mislukt**: `PHP Fatal error: Cannot redeclare BorduurPatchStudio\Services\Rest::credits()` in `includes/services/class-rest.php:244`. Werk in uitvoering van een ander team, maar niets houdt dit tegen: er is geen CI. |
| `.github/workflows/`, `composer.json`, `phpunit.xml*`, `phpcs.xml*`, `phpstan.neon*`, eslint-config, release-script | Bestaan niet (ook niet in de repo-root `/home/user/Path_finding_algorithm`). |

## Samenvatting

De bestaande tests zijn op hun terrein sterk: JS/PHP-pariteit voor prijzen, naamstroken, cross-sell en AI-prompts, nauwkeurigheidscases voor de analyse, en acht e2e-scenario's in een echte WooCommerce met prijscontrole tot in de bestelling. Voor een betaalde plugin ontbreekt het fundament eromheen:
(1) er is geen CI en geen release-script, (2) PHP-code buiten de pure rekenmotoren (winkelmand, uploads, REST, SVG-sanitizer, krediet/AI-kosten, productie) heeft nul unit-tests, terwijl de spec en code-commentaar tests beloven die niet bestaan, (3) de e2e test alleen het gelukkige pad: geen te grote of kapotte uploads, geen verlopen token, geen rate-limit, geen gelijktijdige bestellingen, geen naamstroken/cross-sell/AI, en (4) de testomgeving is niet reproduceerbaar (hardgecodeerd sessiepad, "latest"-downloads, ongepinde Playwright, browsertests die stil overslaan).

---

## Bevindingen

### tests-ci-1 — Geen CI-workflow: niets bewaakt build, tests en zip (kritiek)
**Bestanden:** (ontbreekt) `/home/user/Path_finding_algorithm/.github/workflows/ci.yml`; `tests/run-all.sh`, `scripts/build-zip.sh`
**Probleem:** Er bestaat geen enkele workflow. `run-all.sh` draait alleen als iemand eraan denkt. Bewijs: tijdens deze audit faalt `build-zip.sh` op een fatale PHP-fout (`Cannot redeclare ...Services\Rest::credits()`) en niets meldt dat. De git-root is `/home/user/Path_finding_algorithm`, dus de workflow moet daar staan met `working-directory: patch-studio`.
**Voorstel:** `.github/workflows/ci.yml` met `on: push/pull_request` (paths `patch-studio/**`), `concurrency` (cancel-in-progress) en deze jobs:
- `js`: matrix node 20 en 22 → `npm ci` → `npm run build` (inclusief size budget) → lint (zie tests-ci-5) → `npm test` met `BPS_REQUIRE_BROWSER=1` (tests-ci-10) na `npx playwright install --with-deps chromium`.
- `php`: matrix PHP 8.1 t/m 8.4 (`shivammathur/setup-php`, met pcov) → `composer install` → `php -l` → phpcs → phpstan → `npm run test:php` (alle runners, tests-ci-4) → phpunit unit (tests-ci-2).
- `php-integration`: service `mysql:8` → wp-phpunit-suite.
- `e2e`: `needs: [js, php]` → `tests/e2e/run-e2e.sh` met een matrix (tests-ci-12) → upload van `e2e-artifacts/` (screenshots, JSON, php-notices) als artifact `if: always()`.
- `zip`: `needs: [e2e]` → `scripts/build-zip.sh` → installatietest (tests-ci-14) → upload van `dist/borduur-patch-studio.zip`.

Gebruik cache voor npm, composer en de WP/WC-downloads. Zet branch protection op `main` met de jobs als verplichte checks.
**Acceptatie:** Een PR met een PHP-syntaxfout, een falende unit-test of een ontbrekend runtimebestand in de zip krijgt een rode check binnen 15 minuten. Een schone PR is groen. De zip staat als artifact bij elke groene run op `main`.

### tests-ci-2 — Geen PHP-unit-testframework: de WordPress-kant is ongetest (hoog)
**Bestanden:** `includes/woo/class-cart.php` (899 r.), `includes/class-uploads.php`, `includes/woo/class-svg-sanitizer.php`, `includes/rest/class-guard.php`, `includes/rest/class-routes.php`, `includes/woo/class-designs.php`, `includes/woo/class-order.php`, `includes/woo/class-quotes.php`, `includes/woo/class-privacy.php`, `includes/woo/class-cleanup.php`, `includes/class-settings.php`, `includes/admin/class-order-production.php` (2283 r.), `includes/services/class-credits.php`, `includes/ai/class-limits.php`, `includes/ai/class-cost-log.php`, `includes/ai/class-credits-gateway.php`, `includes/ai/class-moderation.php`
**Probleem:** De PHP-tests zijn losse scripts (`run-php.php`) die enkel pure rekenklassen testen (Pricing, naamstroken fit/prijs, cross-sell, AI-prompt). Er is geen PHPUnit, geen Brain Monkey en geen wp-phpunit. Daardoor zijn de veiligheids- en geldkritische delen alleen indirect gedekt via 8 e2e-scenario's (gelukkig pad): SVG-XSS-filter, tokencontrole, rate-limit, IP-detectie achter Cloudflare, prijsherberekening in de winkelmand (fail-open naar 0, zie audit-php-kwaliteit), proeftokens, AVG-export/-wissen, opruimen, kredieten en AI-kostenlimieten.
**Voorstel:** In de projectroot (buiten de zip) een dev-`composer.json` met `phpunit/phpunit:^10`, `brain/monkey:^2`, `yoast/phpunit-polyfills`, `wp-phpunit/wp-phpunit` en `mockery/mockery`. Twee suites:
- `tests/php/unit/` (Brain Monkey, geen database), te beginnen met: `Svg_Sanitizer` (vectoren: `<script>`, `on*`, `javascript:` in `href`/`xlink:href`, externe `<use>`, `<foreignObject>`, entity-expansie/billion laughs, `data:`-URL's), `Guard` (token geldig/ongeldig/leeg, rate-limit grens N en N+1, CF-IP vertrouwd/niet vertrouwd, IPv6), `Settings`-sanitizers (negatief, NaN, buiten bereik), `Uploads::max_bytes()` en `validate_file()` (alle `UPLOAD_ERR_*`-codes), `Credits` (nooit negatief, idempotente boeking), `Limits`/`Cost_Log` (dag-/maandplafond).
- `tests/php/integration/` (wp-phpunit + WooCommerce, MySQL in CI): winkelmandprijs = `Pricing::quote`, gemanipuleerde prijs in de cart-item-data wordt genegeerd, `next_ref` per jaar, de levenscyclus van een proef (versturen, goedkeuren, vervalst token, vorige ronde), privacy-exporter/-eraser, cleanup-cron.

Voeg `composer test` toe aan `run-all.sh` en CI, en meet dekking met pcov.
**Acceptatie:** `composer test` draait ≥ 150 PHPUnit-tests groen. Line coverage (pcov, clover) is ≥ 90 % voor `class-svg-sanitizer.php` en `class-guard.php`, en ≥ 60 % voor `includes/woo/`, `includes/rest/`, `class-uploads.php`, `includes/services/` en `includes/ai/` (zonder providers). CI faalt onder die drempels.

### tests-ci-3 — Beloofde tests bestaan niet: commentaar en spec verwijzen naar lege of ontbrekende bestanden (hoog)
**Bestanden:** `includes/services/class-services.php:15` ("checked against tests/services/offer-vectors.json"), `includes/services/class-text.php:14` ("tests/services scan this file"), `includes/ai/class-store.php:14` ("in-memory implementation (tests/ai/backend/)"), `docs/ai/SPEC-AI-DIENSTEN.md:110,944,946`, `docs/handoff/ai-services-needs.md:131`; `tests/services/php/wp-stubs.php` (wees), `tests/ai/backend/` (leeg)
**Probleem:** `tests/services/offer-vectors.json`, `tests/services/run-services-php.php`, `tests/services/*.test.js`, `tests/ai/run-ai-php.php`, `tests/ai/php/*` en `tests/ai/e2e/*` bestaan niet. `wp-stubs.php` wordt door niets geladen. Dit gaat over geld (coupons, kredieten, D4-prijs, idempotente fulfilment) en over de eerlijkheidsregel van de eigenaar ("geen nep-schaarste"), die volgens het commentaar automatisch bewaakt wordt maar dat niet is. Een lezer of reviewer denkt dat deze code getest is.
**Voorstel:** Maak de beloofde tests (of zet ze onder PHPUnit uit tests-ci-2): offer-vectoren met JS↔PHP-pariteit zoals bij cross-sell, een in-memory `Store` voor de AI-backend, een scan van `class-text.php` en `messages.js` op verboden woorden (`nog maar`, `laatste kans`, `beperkt`, `afteller`, `seulement`, `dernière chance`, ...). Voeg daarnaast een metatest `tests/meta/referenced-tests.test.js` toe: elk pad `tests/...` dat in `includes/**` of `assets/src/**` genoemd wordt, moet bestaan en door een runner gestart worden.
**Acceptatie:** `node --test tests/meta` is groen en faalt als een verwezen testbestand ontbreekt. `tests/ai/backend/` en `tests/services/` bevatten tests die in `npm test` of `composer test` meedraaien. De eerlijkheidsscan faalt aantoonbaar als je "Nog maar 2 plaatsen!" in een klanttekst zet.

### tests-ci-4 — PHP-pariteit van de naamstroken wordt nooit gedraaid (hoog)
**Bestanden:** `tests/nametape/run-php.php`, `package.json` (`test:php`), `tests/run-all.sh`, `tests/nametape/*.test.js`
**Probleem:** `includes/nametape/class-fit.php` en `class-pricing.php` claimen "verified with tests/nametape/*-vectors.json in Node and PHP". De PHP-kant (`run-php.php`, 83 checks, nu groen) wordt echter door `npm test`, `npm run test:php` en `run-all.sh` niet gestart. Een prijsafwijking tussen voorbeeld (JS) en winkelmand (PHP) blijft dus onopgemerkt. Bij cross-sell en Pricing is dit wel geregeld via een node-test die PHP opstart.
**Voorstel:** Laat `test:php` alle runners draaien via één `tests/php/run-vectors.sh` (pricing, nametape, crosssell, ai-prompt, later services en ai). Voeg ook een `tests/nametape/parity.test.js` toe naar het model van `crosssell.test.js` (spawn `php run-php.php --dump` en vergelijk). In CI moet "php niet beschikbaar" een fout zijn, geen skip.
**Acceptatie:** Een opzettelijke wijziging van 1 cent in `includes/nametape/class-pricing.php` laat zowel `npm test` als `npm run test:php` falen. `run-all.sh` toont een aparte regel per PHP-vectorrunner.

### tests-ci-5 — Geen lint en statische analyse in de keten: PHPCS, PHPStan en ESLint ontbreken (hoog)
**Bestanden:** (ontbreekt) `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `eslint.config.js`; `tests/run-all.sh` (enkel `php -l`, `bash -n`, `node --check`)
**Probleem:** De BRIEF eist WordPress Coding Standards. audit-php-kwaliteit telde 352 WPCS-errors en 120 PHPStan-meldingen op level 5, met echte nullfouten. Voor JavaScript (±7000 regels in de gebundelde modules buiten ui/render) is er geen linter: ongebruikte imports, per ongeluk globale variabelen en `==`-fouten worden niet gevangen. Zonder baseline kan CI dit niet stap voor stap afdwingen.
**Voorstel:** Neem de config uit audit-php-kwaliteit-1 over (prefixes, text domain, custom capabilities, exclude `languages/*.l10n.php`). Zet PHPStan op level 5 met `phpstan-baseline.neon`, zodat bestaande meldingen geregistreerd zijn en nieuwe meteen falen. Voeg ESLint 9 (flat config, `@eslint/js` recommended + `no-unused-vars`, `eqeqeq`, `no-implicit-globals`, browser- en worker-globals) toe als `npm run lint`, plus `composer lint` en `composer analyse`. Draai ze in `run-all.sh` en in CI (job `php` en `js`), met `--report=checkstyle` en `cs2pr` voor annotaties in de PR.
**Acceptatie:** `npm run lint`, `composer lint` en `composer analyse` bestaan en zijn groen op `main` (met baseline). Een nieuwe ongebruikte variabele in `assets/src/pricing/index.js` of een nieuwe `$_POST`-toegang zonder sanitize laat CI falen met een annotatie op de juiste regel. De baseline krimpt per release (gerapporteerd aantal).

### tests-ci-6 — E2e test geen foutpaden bij uploaden en sessies (hoog)
**Bestanden:** `tests/e2e/*.e2e.cjs` (geen enkele test op `bps_too_big`, `bps_bad_token`, `bps_rate`, 403/413/429), `includes/rest/class-routes.php:298-335`, `includes/class-uploads.php:460-537`, `includes/rest/class-guard.php:40-53,125-150`
**Probleem:** Alle 8 scenario's volgen het gelukkige pad. Niet getest:
- (a) een origineel groter dan `woo.maxUploadMb` (de 413-tak);
- (b) een request groter dan PHP `post_max_size`: PHP leegt dan `$_POST` en `$_FILES`. `create_design()` valideert eerst `design` en geeft dan de algemene melding "We konden je ontwerp niet goed lezen" in plaats van "groter dan X MB". Dit volgt uit de code (payload-validatie vóór de grootte-check); handmatig zag ik bij een request zonder geldige payload dezelfde algemene 400;
- (c) een kapot of vermomd bestand (willekeurige bytes als `.png`, een PDF met beeld-mime, een SVG met `<script>`);
- (d) een verlopen of ongeldig token: de server geeft correct 403 `bps_bad_token` ("We proberen het meteen opnieuw"), maar de retry-logica van de UI is nergens getest;
- (e) de rate-limit (429) en de klanttekst daarbij;
- (f) netwerkfout of afbreken halverwege het uploaden;
- (g) een vervalste of verouderde proeflink.
**Voorstel:** Nieuw scenario `09-upload-errors.e2e.cjs` met `page.route()` voor (d) en (f) (eerste `/designs` krijgt 403 `bps_bad_token`, daarna moet de UI één keer een nieuw token halen en slagen; bij een `abort()` verschijnt een vriendelijke foutmelding met opnieuw-knop en blijft de studio bruikbaar). Fixtures `big-30mb.png` (gegenereerd in de test, niet in git), `random.png`, `evil.svg`. Doe (e) met `add_filter('bps_rate_limit', fn()=>2)` via de mu-plugin onder een testvlag. Voeg (g) toe aan scenario 05. Voor (b): start php -S in een aparte stap met `post_max_size=1M` en verwacht `bps_too_big`. Pas als dat faalt de volgorde in `create_design()` aan, of detecteer een lege body met `CONTENT_LENGTH > post_max_size`.
**Acceptatie:** `run-e2e.sh 09` is groen en controleert per geval de HTTP-status, de foutcode én de zichtbare NL-tekst. Voor de te grote upload toont de UI "groter dan 25 MB" (geen algemene fout), er staat geen restbestand in `uploads/patch-studio/` en er komen geen PHP-notices.

### tests-ci-7 — Gelijktijdigheid is niet getest: refnummers, voorraad aan kredieten, parallelle bestellingen (gemiddeld)
**Bestanden:** `includes/woo/class-designs.php:267-298` (`next_ref` compare-and-swap), `includes/woo/class-quotes.php`, `includes/services/class-credits*.php`, `includes/ai/class-limits.php`, `tests/e2e/start-wp.sh:88-90`
**Probleem:** `next_ref()` gebruikt bewust een compare-and-swap "zodat twee gelijktijdige uploads nooit hetzelfde nummer krijgen". Kredieten en AI-limieten moeten dubbel uitgeven voorkomen. Geen enkele test oefent dit. Mijn handmatige proef (12 parallelle WP-CLI-aanroepen) gaf 12 unieke nummers, maar alleen op SQLite. Productie draait MySQL/MariaDB met een objectcache, en daar spelen `alloptions`-caching en isolatieniveaus mee.
**Voorstel:** (1) Een PHPUnit-integratietest (MySQL in CI) die met `pcntl_fork` of 20 parallelle `wp eval`-processen `next_ref` aanroept en uniekheid controleert, ook rond de jaarwissel (gemockte `gmdate`). (2) E2e `10-concurrency.e2e.cjs`: 5 browsercontexten doen tegelijk een volledige checkout (`Promise.all`), daarna zijn er 5 orders met unieke `PS-`- en ordernummers en klopt de prijs per order. 20 gelijktijdige `POST /designs` geven 20 unieke refs. 10 gelijktijdige AI-generaties met saldo 3 geven precies 3 successen en saldo 0, nooit negatief. (3) Draai de e2e minstens in één CI-combinatie op MySQL 8 in plaats van SQLite.
**Acceptatie:** De concurrency-test draait in CI op MySQL 8 en is 20 keer na elkaar groen (`--repeat 20` in een nightly-job). Een bewust kapotgemaakte CAS (de `AND option_value = %s` weghalen) laat de test falen.

### tests-ci-8 — Grote productdelen hebben geen e2e: naamstroken, cross-sell, AI-studio, diensten (gemiddeld)
**Bestanden:** `tests/e2e/` (0 verwijzingen naar nametape, crosssell, ai, services); `includes/nametape/class-rest.php`, `class-cart.php`; `includes/crosssell/`; `includes/ai/class-rest.php`; `includes/services/class-rest.php`; `config/ai-mock.json`
**Probleem:** De eigenaar koos naamstroken en cross-sell als extra producten, en de betaalde AI-ontwerpstudio is een verdienmodel. Geen van deze flows wordt end-to-end getest: winkelmand, prijs-staffel, CSV-lijst van namen, cross-sell-lijn op dezelfde ontwerp-ref, AI-generatie via de mock-provider met kredietaftrek, en de diensten als WooCommerce-producten.
**Voorstel:** Scenario's `11-nametape` (CSV met 5 namen, waarvan 1 te lang: de inline-fout verschijnt, 4 regels in de mand, prijs = `/nametape/quote` = order), `12-crosssell` (ontwerp, dan "ook als sleutelhanger", tweede lijn met dezelfde design-ref en correcte korting voor reeds gedigitaliseerd), `13-ai-studio` (mock-provider uit `config/ai-mock.json`: gratis proef, aankoop van een kredietpakket via bacs, generatie, saldo − 1, voorstel opent in de studio met borduurcheck; geweigerde prompt toont een eerlijke uitleg) en `14-services` (dienst verschijnt enkel als hij relevant is, zoals "vectoriseren" bij een foto). Elk met axe en de controle op console-fouten.
**Acceptatie:** Vier nieuwe scenario's draaien groen in `run-e2e.sh`. Elk controleert prijs UI == REST == mand == order en kan zelfstandig draaien (zie tests-ci-13). De AI-test maakt geen enkele echte netwerkaanroep (bewezen met `page.route('**/api.openai.com/**', r => r.abort())` en een assert dat er 0 aanroepen waren).

### tests-ci-9 — JS-unit-dekking heeft gaten en geen drempel (gemiddeld)
**Bestanden:** `assets/src/admin/qr.js` (312 r.), `assets/src/admin/production.js`, `assets/src/nametape/ui.js` (1186 r.), `assets/src/nametape/render.js`, `assets/src/nametape/state.js`, `assets/src/crosssell/panel.js`, `assets/src/crosssell/mockups.js` (695 r.), `assets/src/crosssell/index.js`; `package.json`
**Probleem:** Deze modules worden door geen enkele test geïmporteerd en tellen dus niet eens mee in de dekkingscijfers. `ui/derive.js` (55 %) en `ui/state.js` (51 %, branches 48 %) bevatten de prijs- en stapafleiding van de studio. `qr.js` is een zelfgeschreven QR-encoder op de productiefiche: een fout daarin geeft een onleesbare code in het atelier. Er is geen dekkingsdrempel, dus dekking kan ongemerkt dalen.
**Voorstel:** `tests/admin/qr.test.js`: encodeer 50 strings (URL's, UTF-8 met é/ë, maximale lengte per versie) en decodeer met `jsqr` (devDependency) tot een identieke roundtrip. Unit-tests voor `nametape/state.js` en `render.js` (jsdom of OffscreenCanvas in de bestaande Playwright-harness), en voor de pure delen van `crosssell/mockups.js`. Voeg in `package.json` toe: `"test:coverage": "node --test --experimental-test-coverage --test-coverage-lines=80 --test-coverage-branches=70 --test-coverage-exclude='tests/**' \"tests/**/*.test.js\""` (Node ≥ 22.8). Laat het andere team de ui/render-dekking zelf verhogen (voor `ui/**` en `render/**` niet door dit team te wijzigen).
**Acceptatie:** `npm run test:coverage` faalt onder 80 % regels / 70 % branches. Elk bestand onder `assets/src/{admin,nametape,crosssell,pricing,colors,advice,analysis,shared}` staat in het rapport. `qr.js` heeft een roundtrip-test met ≥ 50 gevallen.

### tests-ci-10 — Browsertests en Playwright zijn niet gepind en slaan stil over (gemiddeld)
**Bestanden:** `tests/render/harness.mjs:18-22`, `tests/demo/demo.browser.test.js:18-19`, `tests/analysis/browser.test.js`, `tests/e2e/run-e2e.sh:16-21`, `tests/e2e/package.json`, `package.json`
**Probleem:** Playwright wordt gezocht in de globale node_modules van de machine (`/opt/node-tools`, `/opt/node22/lib`) en staat in geen enkel `package.json`. Ontbreekt Playwright of Chromium, of is de demo niet gebouwd, dan worden de render-, demo- en browseranalyse-tests als *skip* gemeld en is `npm test` toch groen. In CI zou zo een kapotte renderer ongemerkt doorglippen. De Playwright-versie verschilt per machine, en screenshots en timingbudgetten verschillen mee.
**Voorstel:** Voeg `playwright` (exacte versie) toe als devDependency in de root en in `tests/e2e`, en installeer in CI met `npx playwright install --with-deps chromium`. Laat de harnassen bij `process.env.BPS_REQUIRE_BROWSER === '1'` (of `CI`) falen in plaats van overslaan. Laat `demo.browser.test.js` de demo zelf bouwen (of maak `npm test` afhankelijk van `npm run demo`). Laat CI na `npm test` controleren dat de TAP-uitvoer `# skipped 0` bevat.
**Acceptatie:** `CI=1 npm test` op een machine zonder Chromium faalt met een duidelijke melding. `npm ls playwright` toont één gepinde versie. De CI-log van de job `js` toont `# skipped 0`.

### tests-ci-11 — `engines: node >=20` klopt niet met het testcommando (gemiddeld)
**Bestanden:** `package.json` (`"engines": { "node": ">=20" }`, `"test": "node --test \"tests/**/*.test.js\""`)
**Probleem:** Globpatronen in `node --test` bestaan pas sinds Node 21. Op Node 20 (nog een gangbare LTS op hostings en in CI-images) wordt `tests/**/*.test.js` als letterlijk pad behandeld en draait de suite niet. Ook `--experimental-test-coverage`-drempels (tests-ci-9) vereisen Node ≥ 22.8. Er is geen `.nvmrc` en geen versiecontrole in CI.
**Voorstel:** Zet `"engines": { "node": ">=22.8" }` en voeg een `.nvmrc` (`22`) toe. Gebruik in CI `actions/setup-node` met `node-version-file: patch-studio/.nvmrc`. Wil je Node 20 blijven steunen, schrijf dan `scripts/run-tests.mjs` die de bestanden zelf opzoekt (`fs.globSync` bestaat ook pas vanaf 22, dus gebruik een eigen recursieve walk) en neem Node 20 op in de matrix.
**Acceptatie:** De CI-matrix draait elke ondersteunde Node-versie uit `engines` en is groen, met hetzelfde aantal tests (353+) per versie. `npm test` op een niet-ondersteunde versie geeft een duidelijke `engines`-waarschuwing of fout (`engine-strict=true` in `.npmrc`).

### tests-ci-12 — E2e-omgeving is niet reproduceerbaar en dekt één combinatie (gemiddeld)
**Bestanden:** `tests/e2e/start-wp.sh:36,103-109`, `tests/e2e/.env.example:3`
**Probleem:**
- `BPS_WP_DIR` staat standaard op een hardgecodeerd scratchpad-pad van één Claude-sessie (`/tmp/claude-0/-home-user-.../scratchpad/wp`). Op een CI-runner of een andere ontwikkelaarsmachine is dat zinloos.
- WordPress, WooCommerce, SQLite-integratie en Hello Elementor worden als `latest` gedownload, dus dezelfde commit kan morgen anders testen.
- Standaard draait alleen één combinatie: klassieke kassa, HPOS aan, SQLite, laatste versies. De ondersteunde minima (WP 6.4, WC-minimum, PHP 8.1) en de blok-kassa/legacy-opslag worden niet automatisch gedekt (de README noemt beide kassa's als getest, maar dat was een handmatige run).
**Voorstel:** Zet de standaard op `WP_DIR="${BPS_WP_DIR:-${RUNNER_TEMP:-${TMPDIR:-/tmp}}/bps-wp}"`, met hetzelfde in `.env.example`. Voeg `BPS_WP_VERSION` en `BPS_WC_VERSION` toe (downloads `wordpress-<v>.zip` en `woocommerce.<v>.zip`) en pin ze in `tests/e2e/versions.env`. Laat Renovate/Dependabot ze bijwerken. CI-matrix (3 combinaties): `{min WP/WC, PHP 8.1, classic, HPOS 0}`, `{latest, PHP 8.3, block, HPOS 1}` en `{latest, PHP 8.4, classic, HPOS 1, MySQL 8}`. Cache de downloads op basis van een hash van `versions.env`.
**Acceptatie:** `grep -r "claude-0" tests/` geeft niets. `run-e2e.sh` werkt op een schone `ubuntu-latest`-runner zonder `.env`. De CI-log toont per combinatie de geïnstalleerde WP-, WC- en PHP-versie, en alle drie zijn groen.

### tests-ci-13 — E2e-harnas: scenario's hangen van elkaar af, overslaan telt als geslaagd, vaste wachttijden (gemiddeld)
**Bestanden:** `tests/e2e/lib/harness.cjs:7,119` (`state.json`), `tests/e2e/05-admin-fiche.e2e.cjs` (exit 77 zonder scenario 03), `tests/e2e/run-e2e.sh` (exit 0 bij SKIP), 21× `waitForTimeout` in `*.e2e.cjs` en `lib/harness.cjs`
**Probleem:** Scenario 05 leest het order-id van 03 uit `state.json`. `run-e2e.sh 05` alleen geeft daardoor SKIP en exit 0, dus groen zonder iets te testen. Er is geen retry, geen trace en geen JUnit-rapport. Op een tragere CI-runner zijn de 21 vaste `waitForTimeout`-wachttijden een bekende bron van wisselende uitkomsten (flakiness).
**Voorstel:** Maak elk scenario zelfvoorzienend: een `seedOrder()`-helper in `lib/` die via `wp eval` een design en een order aanmaakt, zodat 05 zonder 03 kan draaien. Voeg `BPS_E2E_STRICT=1` toe (standaard in CI), waarmee SKIP als FAIL telt. Schrijf JUnit-XML weg (`e2e-artifacts/junit.xml`) voor de CI-samenvatting, en bij een fout een Playwright-trace (`context.tracing.start/stop`). Vervang de vaste wachttijden door `locator.waitFor()` of polling (`expect.poll`). Overweeg op termijn de overstap naar `@playwright/test` (fixtures, projects voor mobiel en desktop, retries 1 enkel in CI met rapportage van flaky tests).
**Acceptatie:** `run-e2e.sh 05` op een verse site is groen zonder 03. `grep -c waitForTimeout tests/e2e` = 0 (of elke resterende regel heeft een commentaar met de reden). De volledige suite is 10 keer na elkaar groen in CI. Bij een fout staan `trace.zip` en `junit.xml` als artifact bij de run.

### tests-ci-14 — Geen release-script: versie, changelog, zip, checksum en installatietest zijn handwerk (hoog)
**Bestanden:** (ontbreekt) `scripts/release.sh`, `plugin/borduur-patch-studio/readme.txt`, `CHANGELOG.md`, `.github/workflows/release.yml`; `scripts/build-zip.sh`, `borduur-patch-studio.php:6,24`, `package.json:3`, `README.md:108-123`
**Probleem:** De versie staat op drie plekken (pluginheader `Version`, `BPS_VERSION`, `package.json`) zonder controle dat ze gelijk zijn. Er is geen `readme.txt` (Stable tag, Requires at least, Tested up to, Requires PHP, WC tested up to, Changelog). Die heeft een betaalde plugin en de toekomstige update-server nodig. De installatietest van de zip (installeren, activeren, deactiveren, verwijderen) is volgens de README met de hand gedaan. Het overzicht "Wat er getest is" is verouderd (216 tests, intussen 353). `build-zip.sh` faalt nu op een fatale fout, en er is geen checksum en geen tag.
**Voorstel:** Schrijf `scripts/release.sh <versie>` dat:
1. controleert dat de werkboom schoon is en de branch `main` is;
2. `tests/run-all.sh` volledig draait (stopt bij rood);
3. de versie op alle plekken bijwerkt (inclusief `Stable tag` en `@since`-controle) en daarna met een check bevestigt dat ze gelijk zijn;
4. de changelog-sectie uit `CHANGELOG.md` naar `readme.txt` kopieert;
5. `make-i18n.sh` en `build-zip.sh` draait;
6. een `sha256`-bestand maakt;
7. een installatietest doet op een verse testsite met WP-CLI: `plugin install dist/*.zip --activate`, dan studio-pagina 200 zonder notices, `deactivate`, `uninstall`, en daarna controleert dat er geen `bps_`-opties, -tabellen of -cronjobs meer zijn, behalve wat de instelling "data bewaren" bewust laat staan;
8. een `info.json`-manifest maakt voor de latere licentie/update-server (versie, download-URL, `requires`, `tested`, changelog-HTML).

Daarnaast een `release.yml` op tags `v*` die stappen 2 t/m 8 in CI doet en de zip, de sha256 en `info.json` als GitHub-release-assets publiceert. Een `tests/meta/version.test.js` faalt als de drie versies verschillen.
**Acceptatie:** `bash scripts/release.sh 1.0.1 --dry-run` doorloopt alle stappen zonder te taggen en levert `dist/borduur-patch-studio-1.0.1.zip`, `.sha256` en `info.json`. De installatietest faalt aantoonbaar als `uninstall.php` een optie laat staan. Een tag `v1.0.1` levert een GitHub-release met die drie assets.

### tests-ci-15 — Geen kwaliteitspoort voor vertalingen en voor testcode in de productie-zip (laag)
**Bestanden:** `languages/borduur-patch-studio.pot`, `languages/borduur-patch-studio-fr_BE.po`, `scripts/make-i18n.sh`; `includes/class-uploads.php:467-494` (`BPS_TESTING` omzeilt `is_uploaded_file`), `includes/services/class-credits-store-memory.php`, `scripts/build-zip.sh`
**Probleem:**
- Vertalingen: een benaderende telling vond ±240 van de ±920 unieke `__()`/`esc_html__()`-teksten in `includes/` en `templates/` die niet in de `.pot` staan, vooral nieuwe AI- en diensten-teksten. Franstalige klanten krijgen die dus in het Nederlands. Niets in de testketen controleert dat de `.pot`, `.po`, `.mo` en `.l10n.php` actueel en volledig zijn.
- Testcode in productie: de zip bevat test-only code. `Uploads::testing()` laat bij `BPS_TESTING` niet-geüploade bestanden toe, en de in-memory kredietenstore zit erin. Geen test bewaakt dat de zip of productie dit nooit activeert.
**Voorstel:**
- CI-stap `i18n`: installeer WP-CLI, draai `wp i18n make-pot` naar een tijdelijk bestand en vergelijk de msgid-sets met de gecommitte `.pot` (faal bij verschil). Tel met `msgattrib --untranslated` de lege `msgstr` in `fr_BE.po` (moet 0 zijn) en controleer dat de `.mo` en `.l10n.php` jonger zijn dan de `.po`.
- Testcode: breid `build-zip.sh` uit met een inhoudscheck (geen `tests/`, geen `*-memory.php` tenzij bewust, geen `define( 'BPS_TESTING'`). Voeg een PHPUnit-test toe die bevestigt dat `store_original()` een niet-geüpload bestand weigert zonder `BPS_TESTING`. Verplaats de memory-stores liefst naar `tests/`.
**Acceptatie:** CI faalt als er een nieuwe `__()`-tekst zonder bijgewerkte `.pot` of zonder FR-vertaling is toegevoegd. `unzip -l dist/borduur-patch-studio.zip | grep -E "tests/|memory"` geeft niets (of enkel goedgekeurde uitzonderingen die in het script staan). De uploadtest zonder `BPS_TESTING` is groen.

---

## Prioriteit (voorstel)
1. tests-ci-1 (CI) + tests-ci-5 (lint/analyse met baseline) + tests-ci-4 (naamstroken-pariteit aansluiten): klein werk, groot effect.
2. tests-ci-2 + tests-ci-3 (PHPUnit, beloofde tests voor geld en veiligheid).
3. tests-ci-6 + tests-ci-7 (foutpaden en gelijktijdigheid), tests-ci-14 (release).
4. Rest: tests-ci-8 t/m 13 en 15.

Voor de klant-UI is geen handoff nodig. Bij tests-ci-6(d)/(f) kan blijken dat de UI een retry of melding mist; dat hoort dan in `docs/handoff/premium-ui-needs.md`, zodra de test het aantoont.
