# Audit "productisering": klaar als commercieel product?

Datum: 2026-11-18 · Auditor: productisering · Scope: `plugin/borduur-patch-studio` (PHP, config, build-zip), met de BRIEF-beslissing
"premium-kwaliteit" als maatstaf: geen hardgecodeerde merkgegevens, readme.txt met changelog, versiebeheer, opruimen bij deïnstalleren,
systeemstatus, logboek en een optionele licentie- en updateclient die standaard uit staat.
Er is geen code gewijzigd. Wat de klant-UI nodig heeft, staat in `docs/handoff/premium-ui-needs.md`.

## Wat al goed is
- **Geen telefoon-naar-huis.** Ik vond geen enkele uitgaande request behalve de AI-providers (enkel in modus test/live met sleutel) en
  de loopback-zelftest van de uploads naar de eigen site (`class-uploads.php:423`). Dat is de juiste basis voor een licentieclient.
- Contactgegevens, beloftes, productnaam en bewaartermijnen staan al in de instellingen (`contact.*`, `promises.*`, `woo.productName`).
  Lege velden geven een neutrale zin.
- `uninstall.php` ondersteunt multisite, verwijdert bestanden enkel binnen `uploads/patch-studio` (met realpath-controle) en wist
  ontwerpen enkel als de beheerder "Alles verwijderen" aanvinkt.
- PHP-versiecontrole bij het laden, HPOS- en blocks-compatibiliteit gedeclareerd, `bps_activate`/`bps_deactivate`-hooks, en een
  statusblok op het tabblad Algemeen.

## Gemeten (18-11-2026)
- `npm test`: 353 tests, 349 geslaagd, 3 todo, 1 fout (`performance: ≤ 50 ms per render`, timing-gevoelig, valt onder het renderer-team).
  `npm run test:php`: 47 vectoren, 652 checks, 0 fouten.
- Eigen WordPress 7.1.4 + WooCommerce 11.3.0 (kopie in mijn scratchpad, poort 8937, via `tests/e2e/start-wp.sh` met `BPS_WP_DIR`).
  Daarop: AI-schema geïnstalleerd, AI-, naamstrook- en cross-sell-opties gezet, `deleteDataOnUninstall = true`, en daarna
  `uninstall.php` uitgevoerd. **Bleef achter:** 9 opties (waaronder `bps_ai_secrets`), 5 tabellen `wp_bps_ai_*` en de cron `bps_ai_cleanup`.
- Studio-HTML (`/patch-studio/`): **16 interne `_note`/`_openPoint`/`_doc`-velden** gaan mee naar elke bezoeker, plus `priceSource`
  ("kassa borduurservicebelgie.be 16-11-2026") en de uitgeschakelde testimonial ("Jasper").
- `readme.txt`: bestaat niet. De zip `dist/borduur-patch-studio.zip` heeft geen readme, changelog of licentiebestand (enkel `assets/fonts/LICENSES.txt`).
- WordPress.org kent geen plugin met slug `borduur-patch-studio` (API: "Plugin not found"). Er is ook geen `Update URI`-header.
- Licentie, updates, Site Health en het WooCommerce-statusrapport: 0 treffers in de code. Logging: 1× `wc_get_logger`, 1× `error_log`,
  en een aparte AI-log in een optie.

## Overlap met andere audits
php-kwaliteit-8 (uninstall: `bps_studio_page_id`, `woocommerce_bps_*_settings`), woo-compat-12 (headers), security-12 (veiligheidseisen
voor de licentieclient), admin-ux-5/7/8 (import/export, modulebeheer, merkinstellingen). Hieronder vul ik die aan en verwijs ik ernaar.
Ik herhaal ze niet.

## Overzicht

| id | ernst | onderwerp |
|---|---|---|
| productisering-1 | hoog | Geen licentie- en updateclient |
| productisering-2 | hoog | Deïnstalleren laat de AI-sleutel, 5 tabellen en de AI-cron achter |
| productisering-3 | hoog | Geen readme.txt, changelog of licentiebestand |
| productisering-4 | hoog | Fabriekswaarden zijn de contactgegevens van borduurservicebelgie.be, plus vaste "bel of WhatsApp ons" en "Kalmthout" |
| productisering-5 | middel | Huisstijl vast in code: kleuren, sitespecifieke Elementor-variabele, pluginnaam |
| productisering-6 | middel | Interne notities, prijsbron en testimonial lekken naar elke bezoeker |
| productisering-7 | middel | Versiebeheer: vier handmatige versienummers, geen migratiekader |
| productisering-8 | middel | Statusblok te mager voor support: niet in Site Health of het WC-statusrapport |
| productisering-9 | middel | Geen centraal logboek |
| productisering-10 | middel | Modules (AI, naamstroken, cross-sell, diensten) niet centraal geregistreerd, geen modulebeheer |
| productisering-11 | middel | Release-pijplijn: zip zonder versiecontrole, testpoort of checksum |
| productisering-12 | laag | Externe verbindingen niet gedocumenteerd (wp.org-eis), geen toestemmingsmodel |
| productisering-13 | laag | Geen regressietests voor productisering |

---

### productisering-1 · hoog · Geen licentie- en updateclient
**Bestanden:** `borduur-patch-studio.php` (header), nieuw: `includes/licensing/*`, `includes/admin/views/tab-licentie.php`
**Probleem:** de BRIEF vraagt een optionele licentie- en updateclient, standaard uit, zodat verkoop via de eigen WooCommerce-winkel later
kan. Die is er niet: geen sleutelveld, geen `Update URI`, geen hook op `update_plugins_{hostname}` of `plugins_api`. Zonder `Update URI` zou
WordPress ook een wp.org-plugin met dezelfde slug als update kunnen aanbieden, mocht die ooit verschijnen.
**Voorstel:**
1. Header `Update URI: https://borduurservicebelgie.be/patch-studio-updates` (constante `BPS_UPDATE_URL`, overschrijfbaar in
   wp-config, nooit een admin-veld; zie security-12).
2. `Licensing\Client` met een driver-interface (`activate`, `deactivate`, `check`, `update_info`) en twee drivers:
   (a) **LMFWC-proxy**: een klein companion-plugin op de winkel praat intern met License Manager for WooCommerce. Zo staan de
   WC-REST-sleutels nooit in de verdeelde plugin. (b) **WooCommerce API Manager**-compatibel (`wc-api/wc-am-api`, met `instance`,
   `product_id` en `api_key`). Kies de driver met een constante.
3. Updates gaan via de native filter `update_plugins_{hostname}` (WP 5.8+), in het formaat van plugin-update-checker
   (`version`, `package`, `tested`, `requires_php`, `sections.changelog`). Het pakket wordt ondertekend en via `upgrader_pre_download`
   gecontroleerd (security-12, punt 3).
4. Standaard **uit**: zonder sleutel geen enkele request, ook geen ping. Pas na een expliciete klik op "Activeer licentie" en een
   aangevinkte toestemming ("we sturen je domein, de pluginversie en je sleutel naar …") wordt er verbonden. Een geweigerde of verlopen
   licentie schakelt **nooit** functies uit in de winkel: alleen updates en support stoppen. Dat is eerlijk, en een kassa valt nooit stil.
5. Tabblad "Licentie & updates": status, gemaskeerde sleutel, laatste controle, knoppen Deactiveren en "Nu controleren".
**Acceptatie:** (a) met een lege sleutel registreert een `pre_http_request`-spion 0 requests naar `BPS_UPDATE_URL` over 10 admin-pageloads
en een cronrun; (b) een PHP-test met een gemockte driver laat bij een geldige sleutel en een hogere versie een update zien in
`get_site_transient('update_plugins')->response['borduur-patch-studio/borduur-patch-studio.php']`; (c) een verlopen licentie: de studio en de
winkelmand werken nog (e2e 01 en 03 groen); (d) `grep -c "Update URI" borduur-patch-studio.php` = 1.

### productisering-2 · hoog · Deïnstalleren laat de AI-sleutel, 5 tabellen en de AI-cron achter
**Bestanden:** `uninstall.php`, `includes/ai/class-schema.php:179` (`drop()` bestaat maar wordt nooit aangeroepen),
`includes/services/class-credits-store-wpdb.php:95` (`uninstall()`, idem), `includes/ai/class-module.php` (`CRON`, `RUN_ACTION`, `LOG_OPTION`)
**Probleem:** gemeten op een testsite, mét "Alles verwijderen" aan. Na `uninstall.php` bleven `bps_ai_secrets` (de versleutelde
API-sleutel van OpenAI of Recraft), `bps_ai_settings`, `bps_ai_db_version`, `bps_ai_log`, `bps_nametape_settings`,
`bps_nametape_product_id`, `bps_crosssell_settings`, `bps_svc_products` en `bps_studio_page_id` achter, plus de tabellen
`wp_bps_ai_owners/jobs/costs/lots/ledger` en de geplande cron `bps_ai_cleanup`. Ook de Action Scheduler-acties `bps_ai_run_job` worden niet
opgeruimd. Een betaalde plugin die een geheime sleutel en een kredietadministratie achterlaat, is een privacy- en vertrouwensprobleem
(aanvulling op php-kwaliteit-8).
**Voorstel:**
- **Altijd** verwijderen: alle cronhooks (`bps_cleanup`, `bps_ai_cleanup`), `as_unschedule_all_actions('', [], 'bps-ai')` (de groep uit
  `Module::AS_GROUP`), `bps_ai_log`, `bps_ai_*`-transients en `bps_ai_secrets`. Een sleutel hoort niet te overleven als de plugin weg is.
- **Enkel bij "Alles verwijderen"**: `Schema::drop()`, `Credits_Store_Wpdb::uninstall()`, de module-opties en de naamstrook- en
  dienstproducten die de plugin zelf maakte (enkel als ze post meta `_bps_*_product` dragen).
  Zonder volledige wis blijven de krediettabellen staan, want klanten hebben voor die kredieten betaald.
- Eén register `Plugin::owned_data()` (opties, patronen, tabellen, hooks, AS-groepen, CPT's en productmarkeringen) dat zowel
  `uninstall.php` als de systeemstatus (productisering-8) gebruikt. Elke module voegt zich toe via een filter of een statische lijst, zodat
  een nieuwe module niet vergeten wordt.
- Het vinkje "Alles verwijderen" vermeldt ook AI-geschiedenis en kredieten.
**Acceptatie:** op de e2e-site (WooCommerce actief, AI-schema geïnstalleerd, alle modules één keer opgeslagen): met "Alles verwijderen"
geeft `SELECT option_name FROM wp_options WHERE option_name LIKE '%bps%'` 0 rijen, `SHOW TABLES LIKE '%bps%'` 0 rijen,
`wp cron event list | grep bps_` 0 rijen, en `as_get_scheduled_actions(['group'=>'bps-ai'])` een lege lijst. Zonder "Alles verwijderen":
`bps_ai_secrets` is weg en de 5 tabellen bestaan nog.

### productisering-3 · hoog · Geen readme.txt, changelog of licentiebestand
**Bestanden:** nieuw: `plugin/borduur-patch-studio/readme.txt`, `CHANGELOG.md` (repo), `plugin/borduur-patch-studio/license.txt`; `scripts/build-zip.sh`
**Probleem:** SPEC §4 noemt `readme.txt` (polish-fase), maar het bestand ontbreekt. Er is geen changelog en geen GPL-tekst in de zip.
`README.md` is een handleiding in het Nederlands voor de eigenaar, niet het standaardformaat dat WordPress, updateservers (het
changelog-tabblad in "Details bekijken") en kopers verwachten.
**Voorstel:** een `readme.txt` in wordpress.org-formaat (validator: wordpress.org/plugins/developers/readme-validator) met
`=== Patch Studio ===`, `Contributors`, `Tags`, `Requires at least: 6.4`, `Tested up to: 7.1`, `Requires PHP: 8.1`,
`WC requires at least`, `WC tested up to: 11.3`, `Stable tag: 1.0.0`, `License: GPLv2 or later`, en de secties Description, Installation,
FAQ (WP Rocket, nginx-regels uit README.md), Screenshots, **External services** (zie productisering-12), Privacy, Changelog en Upgrade Notice.
Het changelog volgt Keep a Changelog met SemVer. `readme.txt` is de bron voor de update-JSON (productisering-1). Een Engelse readme is de
standaard. De Vlaamse eigenaarshandleiding blijft `README.md` of verhuist naar `docs/`.
**Acceptatie:** `unzip -l dist/borduur-patch-studio.zip` toont `readme.txt` en `license.txt`. De readme-validator geeft 0 fouten. Een script
controleert `Stable tag` == `Version:` (header) == `BPS_VERSION` == de bovenste changelog-versie (zie productisering-7/11).

### productisering-4 · hoog · Fabriekswaarden zijn de contactgegevens van borduurservicebelgie.be, plus vaste "bel of WhatsApp ons" en "Kalmthout"
**Bestanden:** `config/defaults.json:640, 643-650, 653-661`; `includes/rest/class-guard.php:146`; `includes/nametape/class-shortcode.php:188`;
`assets/src/nametape/messages.js:115,248`; klant-UI (handoff): `assets/src/ui/assistant.js:42`, `assets/src/ui/panels.js:65`
**Probleem:** de BRIEF wil instellingen met standaardwaarden voor borduurservicebelgie.be. De waarden zijn instelbaar, maar ze liggen vast
in `defaults.json`: telefoon `0491 06 32 01`, WhatsApp `32491063201`, `info@borduurservicebelgie.be`, Kalmthout,
"gemaakt op eigen machines in Kalmthout" en een echte testimonial ("Jasper"). Bij elke andere installatie (staging, koper, demo) gaan
klantvragen dus meteen naar het gsm-nummer van de eigenaar. Een koper die de testimonial aanzet, toont bovendien de review van een
ander bedrijf, en dat is precies wat de BRIEF verbiedt (geen nep-reviews). Daarnaast staat "bel of WhatsApp ons gerust" vast in PHP- en
JS-teksten, ook als het WhatsApp-veld leeg is. De klant-UI valt terug op `'Kalmthout'` als `contact.city` leeg is, waardoor een leeg
veld niet neutraal wordt.
**Voorstel:**
- Splits de merkgegevens uit `defaults.json` naar `config/brand-profile.json` (contact, `promises.fromOneText`, testimonial,
  `priceSource`), te laden via de filter `bps_brand_profile`. De zip voor de eigen shop bevat het profiel van borduurservicebelgie.be.
  Een verkoopzip (`BPS_DIST=generic`) bevat een leeg profiel. Zonder contact wordt de knop "Hulp nodig?" naar `contact.quoteUrl` getoond.
- De testimonial gaat uit het verdeelde profiel en mag alleen tonen als de beheerder hem zelf ingevuld heeft (veld `source`, verplicht).
- Admin-melding: is de host van `home_url()` niet `borduurservicebelgie.be` en zijn de contactgegevens nog de fabriekswaarden, dan toont het
  beheer "Vul je eigen contactgegevens in", met een link naar het tabblad Teksten.
- Maak teksten voorwaardelijk: `Messages::help_line()` geeft "bel ons op {phone}", "stuur ons een WhatsApp", "mail ons" of een neutrale
  zin, naargelang de ingevulde velden. Gebruik die in `class-guard.php:146` en in de noscript van de naamstroken.
  Klant-UI: zie de handoff (geen `'Kalmthout'`-fallback meer).
**Acceptatie:** `grep -rnE "Kalmthout|32491063201|0491 ?06|info@borduurservicebelgie" plugin/borduur-patch-studio --include=*.php --include=*.js --exclude-dir=dist`
geeft 0 treffers buiten `config/brand-profile.json`. Met een leeg profiel bevat de studio-HTML geen telefoonnummer, geen "WhatsApp" en geen
plaatsnaam. Met een leeg `contact.whatsapp` geeft de 429-melding van `/designs` geen "WhatsApp". De melding verschijnt op een site met host
`example.test` en niet op `borduurservicebelgie.be`.

### productisering-5 · middel · Huisstijl vast in code: kleuren, sitespecifieke Elementor-variabele, pluginnaam
**Bestanden:** `assets/css/patch-studio.css:10-34`, `assets/src/nametape/nametape.css:3`, `assets/src/crosssell/crosssell.css:8`,
`borduur-patch-studio.php:3,10`, `includes/class-settings.php` (public config)
**Probleem:** de merkkleur is `var(--e-global-color-8bf8b31, #21A9E2)`. `8bf8b31` is de ID van een globale kleur in de Elementor-kit van
deze ene site, en elders bestaat die ID niet. De CTA-, navy- en inktkleuren staan vast in CSS, zonder instelling. De pluginnaam
"Patch Studio – Borduurservice België" en de auteur staan in de header. Bij een koper oogt de studio dus als borduurservicebelgie.be
(aanvulling op admin-ux-8).
**Voorstel:** een nieuwe settings-sectie `brand` (`accent`, `cta`, `ctaHover`, `ink`, `radius`, `fontFamily: inherit|…`, optioneel `logoId`)
met fabriekswaarden van borduurservicebelgie.be. De shortcode zet ze als inline `style="--bps-accent:…"` op de studio-root. Een optie
"Elementor-kleur overnemen" laat de beheerder een globale kleur kiezen uit de actieve kit, in plaats van de vaste ID. Een contrastcontrole
(WCAG AA, 4.5:1) gebeurt bij het opslaan. Header: `Plugin Name: Patch Studio`. De vermelding "voor Borduurservice België" gaat naar
Description/Author. De CSS-wijziging in `assets/css` en `assets/src/ui` is voor het UI-team (handoff).
**Acceptatie:** `grep -rn "8bf8b31" plugin/borduur-patch-studio --include=*.css --include=*.js --exclude-dir=dist` = 0. Een accentkleur `#C2185B`
instellen maakt de primaire knop in de studio die kleur (`getComputedStyle` in een Playwright-test). Een accent met contrast < 4.5:1
op wit wordt geweigerd met een melding.

### productisering-6 · middel · Interne notities, prijsbron en testimonial lekken naar elke bezoeker
**Bestanden:** `includes/class-settings.php` (`public_config`, rond r. 270-300), `config/defaults.json`
**Probleem:** de studio-HTML bevat 16 interne velden (`_doc`, `_note` ×7, `_openPoint` ×6, `_fabricsNote`, `_threadsNote`), met teksten als
"Woo Discount Rules op de site: 10/25/50/100 = 5/10/15/20 …", "Constanten uit 07 §11.2; kalibreren …", `priceSource: "kassa borduurservicebelgie.be 16-11-2026"`
en de volledige testimonial terwijl `enabled:false`. Dat is onprofessioneel in de broncode van een betaalde plugin, het toont concurrenten
interne keuzes, en het maakt de HTML (die in de pagecache staat) groter.
**Voorstel:** `public_config()` verwijdert recursief elke sleutel die met `_` begint, plus `priceSource`. Een uitgeschakeld blok
(`testimonial`, `deadline`) gaat mee als `{enabled:false}` zonder inhoud. Zet dit in één whitelist- of strip-functie met unit-test.
**Acceptatie:** `curl -s <studio> | grep -cE '"_(note|openPoint|doc|fabricsNote|threadsNote)"|priceSource|Jasper'` = 0. Een PHP-test
op `public_config()` vindt geen sleutel die met `_` begint, op welke diepte ook.

### productisering-7 · middel · Versiebeheer: vier handmatige versienummers, geen migratiekader
**Bestanden:** `borduur-patch-studio.php:5,9,23-24`, `package.json:3`, `config/defaults.json:3`, `includes/class-plugin.php:168-179`,
`includes/ai/class-schema.php:21-54`
**Probleem:** de versie staat op vier plaatsen (header, `BPS_VERSION`, `package.json`, `defaults.json`), zonder controle. De geïnstalleerde
pluginversie wordt niet bewaard, alleen `bps_db_version = 1` en apart `bps_ai_db_version`. `maybe_upgrade()` heeft geen stappen per versie
en geen hook. Instellingen-overrides (`bps_settings`, schema `defaults.json → schema`) hebben geen migratie als een sleutel hernoemd wordt.
`WC tested up to: 9.9`, terwijl WooCommerce 11.3 de huidige versie is: WooCommerce toont dan "niet getest" in het pluginoverzicht
(zie woo-compat-12). `Requires Plugins: woocommerce` ontbreekt.
**Voorstel:** `BPS_VERSION` is de enige bron. `scripts/bump-version.mjs <x.y.z>` werkt header, `Stable tag`, `package.json` en het
changelog bij. Optie `bps_version` (autoload uit). `Plugin::maybe_upgrade()` voert een geordende lijst `Upgrades::steps()`
(`'1.1.0' => callable`) idempotent uit, met een lock (`add_option` op `bps_upgrade_lock`), daarna `do_action('bps_upgraded', $from, $to)`.
Modules (AI-schema, credits) haken daarop in, in plaats van elk een eigen versiemechaniek te hebben. Settings krijgen
`Settings::migrate(array $overrides, int $from_schema)`. Headers: `WC tested up to: 11.3`, `Requires Plugins: woocommerce`.
**Acceptatie:** `node scripts/check-version.mjs` faalt als één van de vier bronnen afwijkt, en zit in `tests/run-all.sh`. Een PHP-test
met `bps_version = 0.9.0` voert stap `1.0.0` precies één keer uit, ook bij twee parallelle aanroepen. Na de upgrade is
`get_option('bps_version') === BPS_VERSION`.

### productisering-8 · middel · Statusblok te mager voor support: niet in Site Health of het WC-statusrapport
**Bestanden:** `includes/admin/class-settings-page.php:633-722` (`status_checks()`), nieuw: `includes/admin/class-system-status.php`
**Probleem:** het statusblok toont WooCommerce, het product, de ontwerppagina, de uploads-zelftest, de JS-bundel, de prijsmodule, WP Rocket
en Elementor. Voor support ontbreken: plugin-, db- en AI-schemaversie, PHP, WP en WC, `memory_limit`, `upload_max_filesize`,
`post_max_size` tegenover `woo.maxUploadMb`, GD/Imagick, cron (laatste `bps_cleanup`-run, `DISABLE_WP_CRON`), Action Scheduler-
achterstand voor `bps-ai`, de AI-modus en of er een sleutel is (ja/nee, nooit de sleutel), actieve modules, licentiestatus, en de laatste
10 fouten uit het logboek. Er is geen integratie met **Gereedschap › Sitestatus** (`site_status_tests`, `debug_information`) of met
**WooCommerce › Status** (`woocommerce_system_status_report`), en geen knop "Kopieer voor support". Een premium plugin heeft dat, zodat de
eigenaar of een koper in één klik een supportrapport stuurt.
**Voorstel:** een klasse `System_Status` levert één datastructuur. Die wordt getoond (1) als sectie "Patch Studio" in het WC-statusrapport,
(2) via `debug_information` in Site Health, met 3 tests via `site_status_tests` (uploads beschermd, cron loopt, uploadlimiet ≥ maxUploadMb),
en (3) op een subpagina "Systeemstatus" met de knop "Kopieer rapport" (platte tekst, zonder geheimen of persoonsgegevens).
**Acceptatie:** WC › Status › "Get system report" bevat de kop `### Patch Studio ###` met minstens plugin-, db- en AI-schemaversie, de
AI-modus, "API-sleutel: ja/nee" en de laatste cleanup-run. Site Health › Info heeft een sectie "Patch Studio". Met `upload_max_filesize=2M`
en `maxUploadMb=25` geeft Site Health een "aanbevolen"-melding. Het gekopieerde rapport bevat geen string die op een sleutel lijkt
(regex `sk-[A-Za-z0-9]{10,}` = 0).

### productisering-9 · middel · Geen centraal logboek
**Bestanden:** `includes/rest/class-routes.php:408-412` (enige `wc_get_logger`), `includes/class-settings.php:591` (`error_log`),
`includes/ai/class-module.php:274-293` (eigen log in optie `bps_ai_log`, 50 regels)
**Probleem:** logging is versnipperd: één WC-logregel (bron `patch-studio`), één `error_log` en een aparte AI-log in een optie, die bij elke
regel de hele optie herschrijft. Belangrijke gebeurtenissen worden niet gelogd: het (opnieuw) aanmaken van het product, het mislukken van de
uploads-zelftest, aantallen van de cleanup, mislukte e-mails, mislukte proef-e-mails, geweigerde SVG's en rate-limit-blokkades. Een
beheerder kan niet zien wat er misliep, en er is geen debug-schakelaar.
**Voorstel:** `Logger::log(string $level, string $event, array $context = [])` stuurt naar `wc_get_logger()` met bron
`borduur-patch-studio` (zichtbaar in WC › Status › Logs, met de standaard rotatie van WooCommerce), en zonder WooCommerce naar
`error_log` als `WP_DEBUG_LOG` aan staat. Een instelling `admin.logLevel` (`error` standaard, `info`, `debug`). Context gaat altijd door
`Secrets::redact()`, en e-mailadressen en IP's worden gehasht. De AI-module stapt over op deze logger, de optie `bps_ai_log` verdwijnt.
Een link "Logboek bekijken" (naar WC-logs met filter op de bron) staat op de statuspagina.
**Acceptatie:** `grep -rn "error_log(\|wc_get_logger()" includes | grep -v class-logger.php` = 0. Na een uploads-zelftest die faalt
(map niet schrijfbaar) staat er een regel `error … uploads_selftest_failed` in het WC-logbestand `borduur-patch-studio-*.log`.
Met logLevel `error` worden `info`-events niet geschreven (PHP-test met een fake logger). Er bestaat geen optie `bps_ai_log` na een upgrade.

### productisering-10 · middel · Modules (AI, naamstroken, cross-sell, diensten) niet centraal geregistreerd, geen modulebeheer
**Bestanden:** `includes/class-plugin.php:19-32` (`COMPONENTS`), `includes/ai/class-module.php:38`, `includes/nametape/class-module.php:32`,
`includes/crosssell/*`, `includes/services/*`
**Probleem:** tijdens de audit verwees `Plugin::COMPONENTS` alleen naar Settings, Settings_Page, Woo\Integration, Rest\Routes, Shortcode en
Order_Production. `AI\Module::register()` en `Nametape\Module::register()` worden nergens vanuit de bootstrap aangeroepen
(`grep -rn "Module::register" includes/class-plugin.php` = 0). De modules zijn dus verdeelde maar inactieve code, of ze worden elk op een
eigen manier geactiveerd. Een product heeft één plek waar de beheerder modules aan- en uitzet, met afhankelijkheden (AI vereist
WooCommerce voor kredieten). Uitgezette modules laden dan geen code, routes, cron of assets (aanvulling op admin-ux-7).
**Voorstel:** een moduleregister `Plugin::modules()` met `id`, `class`, `label`, `requires` (`woocommerce`, `ai-keys`…) en `default`
(studio aan; naamstroken, cross-sell en diensten aan voor de eigen shop; **AI standaard uit**). Instelling `modules.{id}.enabled`.
Activeren en deactiveren roept `Module::activate()` en `deactivate()` aan (schema, cron). Het register voedt de systeemstatus en
`owned_data()` (productisering-2/8). Filter `bps_modules` voor uitbreidingen.
**Acceptatie:** met `modules.ai.enabled=false` geeft `GET /wp-json/` geen enkele route onder `bps/v1/ai`, is er geen cron `bps_ai_cleanup`
en zit `patch-studio-ai*.js` niet in de pagina. Aanzetten maakt de tabellen en de cron aan. Het tabblad Algemeen toont per module een
schakelaar met status.

### productisering-11 · middel · Release-pijplijn: zip zonder versiecontrole, testpoort of checksum
**Bestanden:** `scripts/build-zip.sh`, `tests/run-all.sh`
**Probleem:** `build-zip.sh` controleert syntaxis en verplichte bestanden (goed), maar niet: dat de versies gelijk zijn, dat `readme.txt` en
het changelog bestaan, dat de tests groen zijn, of de zip vrij is van ontwikkelbestanden (`config/ai-mock/*` en `ai-mock.json` gaan mee naar
productie). Het controleert ook niet op `_note`-velden. De zip heet altijd `borduur-patch-studio.zip`, zonder versie, en er komt geen checksum
of handtekening uit, terwijl de updateclient (productisering-1) die nodig heeft.
**Voorstel:** `build-zip.sh --release` voert `check-version` uit, plus `npm test`, `npm run test:php` en de readme-validator, en stopt bij
een fout. De output is `dist/borduur-patch-studio-<versie>.zip` + `.sha256` (+ `.sig` met Ed25519 zodra de licentieclient er is). Een
optie `BPS_DIST=own|generic` kiest het merkprofiel (productisering-4). De mock-assets alleen meenemen als AI-testmodus nodig is (of ze
documenteren als bewuste keuze).
**Acceptatie:** `bash scripts/build-zip.sh --release` met afwijkende versies of één falende test eindigt met exit ≠ 0 en maakt geen zip.
Een geslaagde run maakt `dist/borduur-patch-studio-1.0.0.zip` en een `.sha256` die klopt (`sha256sum -c`).

### productisering-12 · laag · Externe verbindingen niet gedocumenteerd (wp.org-eis), geen toestemmingsmodel
**Bestanden:** `includes/woo/class-privacy.php:33-52`, `includes/ai/providers/*`, `includes/class-uploads.php:423`, (toekomstig) licentieclient
**Probleem:** de plugin praat (in AI-modus) met OpenAI en Recraft, de uploads-zelftest doet een loopback naar de eigen site, en straks komt er
een licentieserver bij. Geen van die verbindingen staat gedocumenteerd voor de beheerder (welke data, wanneer, link naar de voorwaarden van
de dienst). De privacytekst (`wp_add_privacy_policy_content`) noemt ze niet. Voor een commercieel product, en voor elke readme in
wp.org-stijl, is een sectie "External services" verplicht.
**Voorstel:** readme-sectie "External services" en een paragraaf in de privacytekst: per dienst het doel, de gegevens (prompt, geüploade
afbeelding, domein, versie), het moment (enkel na activeren door de beheerder) en links naar de voorwaarden en het privacybeleid. Het
tabblad AI en het tabblad Licentie tonen dezelfde zin naast de schakelaar die de verbinding aanzet. Alle externe hosts staan in één
constante-lijst `Plugin::external_hosts()`, die de systeemstatus toont.
**Acceptatie:** `readme.txt` bevat `== External services ==` met OpenAI, Recraft en de licentieserver. De voorgestelde privacytekst
(Instellingen › Privacy › Gids) noemt dezelfde diensten. Een PHP-test controleert dat elke host in `wp_remote_*`-aanroepen onder
`includes/` (behalve `home_url`) in `external_hosts()` staat.

### productisering-13 · laag · Geen regressietests voor productisering
**Bestanden:** nieuw: `tests/productisering/*.test.js` of `tests/productisering/run.php`, `tests/run-all.sh`
**Probleem:** niets bewaakt de eisen hierboven. Een nieuwe module met een eigen optie, een hardgecodeerd telefoonnummer, een vergeten
versienummer of een `_note` die naar de browser gaat, glipt er onopgemerkt door. Dat is hoe de huidige achterstand (productisering-2/4/6)
ontstond.
**Voorstel:** een kleine testsuite: (1) een grep-wacht op merkstrings buiten het merkprofiel, (2) check-version, (3) een PHP-test op
`public_config()` (geen `_`-sleutels), (4) een e2e-stap "uninstall": activeren, alle modules aan, "Alles verwijderen", `uninstall.php`,
daarna 0 `bps`-opties, -tabellen en -cron, (5) een `pre_http_request`-spion: 0 externe requests bij standaardinstellingen.
Alles hangt in `tests/run-all.sh`.
**Acceptatie:** `bash tests/run-all.sh` voert de 5 checks uit. Een opzettelijke regressie (bv. `"_note"` terug in de public config, of
`Kalmthout` in een PHP-string) laat de suite falen.
