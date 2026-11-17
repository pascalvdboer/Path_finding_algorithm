# Handoff — testenv (lokale WordPress + WooCommerce, e2e, run-all)

## Gebouwd
| Bestand | Inhoud |
|---|---|
| `tests/e2e/start-wp.sh` | Idempotent: downloadt WordPress (latest), `sqlite-database-integration`, `woocommerce`, thema `hello-elementor` (zoals de live site), WP-CLI 2.11 en nl_BE/fr_BE-taalpakketten (via curl); installeert met SQLite (geen MySQL), symlinkt `plugin/borduur-patch-studio`, activeert, configureert de winkel en start `php -S 127.0.0.1:8899` (6 workers). Subcommando's `start · stop · restart · status · reset · url · wp <args>`. |
| `tests/e2e/lib/wp-setup.php` | Winkel volgens SPEC §10: BE/EUR, btw 21 % (BE+NL, ook op verzending), prijzen excl. ingevoerd / incl. getoond, komma als decimaal, zone "België en Nederland" met gratis vanaf € 150 (eerst) + vast tarief 10,00, cheque + overschrijving, pagina's `/patch-studio/` (`[patch_studio]`), `/winkelmand/`, `/afrekenen/` (klassiek of blokken), coming-soon uit. |
| `tests/e2e/lib/router.php` | Router voor `php -S` (permalinks); blokkeert `uploads/patch-studio/private/` zoals `.htaccess` op Apache. |
| `tests/e2e/lib/mu-bps-test-env.php` | Must-use plugin (enkel testsite): vangt alle mails op als JSON in `wp-content/bps-mails/`, geen Woo-wizard/marketing. |
| `tests/e2e/lib/harness.cjs`, `lib/order-check.cjs` | Gedeelde helpers: Scenario (PASS/FAIL/SKIP, exit 0/1/77, JSON-rapport + screenshots), console-/HTTP-foutbewaking (externe requests afgebroken: site is offline), axe-core, geldparser, studio-stappen, kassa klassiek + blokken, WP-CLI-checks van orders. |
| `tests/e2e/01…07-*.e2e.cjs` | (1) pagina laden + config + token + axe, (2) logo uploaden + analyse + advies (vlak logo én eigenaar-ronde-patch), (3) winkelmand + prijscontrole UI = `/cart` = `/quote` = mand = order + kassa **overschrijving** + ordermeta + ordermail, (4) tweede bestelling **cheque** met gratis verzending, (5) productiefiche in admin + download origineel (identiek bestand, geweigerd zonder login) + printfiche + digitale proef versturen → klant keurt goed → status + ordernotitie, (6) offerte-aanvraag (geweven) + mails + adminlijst, (7) gsm 390 px: sticky balk, geen horizontale scroll, axe per stap, touch targets ≥ 44 px, bestellen via de balk. |
| `tests/e2e/run-e2e.sh` | Start/controleert de site, wist rate-limit-transients, draait de scenario's (filter: `run-e2e.sh 03 05`), faalt ook bij nieuwe PHP-notices van de plugin in `debug.log`. |
| `tests/run-all.sh` | build (+budget) → `npm test` → `npm run test:php` → `php -l` (plugin + tests) → syntax van de testscripts → e2e. `--no-e2e`, `--fail-fast`; exit = eerste fout. |
| `tests/e2e/fixtures/logo-flat.png` (+ `make-fixtures.cjs`) | Deterministisch vlak 3-kleurenlogo (zonder beeldbibliotheek gemaakt). |

## Gebruik
```bash
bash tests/e2e/start-wp.sh            # http://127.0.0.1:8899/patch-studio/  · admin / patchstudio-local-2026 (tests/e2e/.env.example)
bash tests/e2e/run-e2e.sh             # alle scenario's; screenshots + JSON in <wp>/e2e-artifacts/
BPS_WP_CHECKOUT=block bash tests/e2e/run-e2e.sh 03 04   # zelfde met de blok-kassa (zet pagina's om; daarna gewoon start-wp.sh voor klassiek)
bash tests/run-all.sh                 # alles
```
Andere instellingen via `tests/e2e/.env` (zie `.env.example`): poort, map, thema, HPOS aan/uit.

## Resultaat van de laatste run (17-11-2026)
- e2e klassieke kassa: 01–06 **groen**; 07 faalt enkel op `assets/fonts/sora-700.woff2` 404 (lettertypes ontbreken, al gemeld door ui).
- e2e blok-kassa (03, 04): groen. Geen PHP-notices van de plugin.
- run-all: unit, PHP-vectors, `php -l` groen; **build faalt op het budget** (`patch-studio.js` ± 94 kB gzip > 60 kB, al gemeld door ui).

## Voor de integrator
1. Lettertypes in `assets/fonts/` (config.fonts[].file) toevoegen, anders blijft 07 rood (console-404).
2. Budget van `patch-studio.js` beslissen (SPEC §5.3) — anders blijft run-all rood op de build.
3. WooCommerce kiest standaard de **eerste** verzendmethode; de testsite zet "Gratis verzending" bovenaan, en test 04 controleert dat gratis vooraf geselecteerd is. Raad de eigenaar dezelfde volgorde aan (woo open punt 3).
4. Opgemerkt (geen fout van de testomgeving): voor de ronde illustratie van de eigenaar zegt de adviesbox "Wij zouden het sublimeren" terwijl de tekst eronder geweven / geborduurd na vereenvoudiging aanraadt — kop en uitleg mogen elkaar niet tegenspreken (advice/ui).
5. Hello Elementor zonder Elementor: het aantal-veld in de klassieke mand is smal ("1(") — thema-css, niet de plugin.

## Afwijkingen van SPEC
- Scenario's zijn plain Node-scripts (`*.e2e.cjs`, CommonJS via `tests/e2e/package.json`) i.p.v. `@playwright/test`: geen extra dependency, en ze matchen niet met `npm test` (`*.test.js`). axe-core staat als devDependency in `tests/e2e/package.json` (wordt automatisch geïnstalleerd).
- Testsite staat op `WP_HTTP_BLOCK_EXTERNAL` en `DISABLE_WP_CRON` (offline, snel); cron/cleanup wordt dus niet automatisch getest.
