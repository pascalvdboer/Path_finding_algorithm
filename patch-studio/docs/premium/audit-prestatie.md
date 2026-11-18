# Audit "prestatie": Patch Studio (17-11-2026)

Auditor: prestatie. Er is geen code gewijzigd. Alle cijfers komen uit metingen op de lokale testsite
(`tests/e2e/start-wp.sh`: WordPress + WooCommerce 11.3, SQLite, `php -S`, thema hello-elementor), uit
Lighthouse 12 (mobiel, gesimuleerde throttling, Chrome headless) en uit Playwright met 4x CPU-throttling.

`php -S` comprimeert niet. Voor realistische Lighthouse-cijfers zette ik er een kleine Brotli-proxy voor
(alleen in de scratchpad, nu weer gestopt).

## Wat al goed is (gemeten)

| Controle | Resultaat |
|---|---|
| Pagina's zonder studio (`/`, `/shop/`) | 0 Patch Studio-requests, 0 bytes CSS/JS, geen inline config |
| Cart/checkout zonder studio | geen Patch Studio-assets. Alleen de WC dependency-registry noemt onze URL's (zie prestatie-12) |
| Studiopagina cachebaar | geen `Set-Cookie`, geen nonce in de HTML. De token komt pas via `GET /token` (no-store) |
| Script | `defer`, in de footer, `data-cfasync="false"`. Uitgesloten van WP Rocket Delay JS. RUCSS-safelist aanwezig |
| Elementor | `get_style_depends()`/`get_script_depends()` aanwezig, dus de CSS staat in `<head>` |
| Lighthouse mobiel (Brotli) | studio **98** (FCP 1,9 s · LCP 2,0 s · TBT 50 ms · CLS 0 · 195 KiB). Home 99 (FCP 1,6 s · 72 KiB). Desktop studio 98 |
| TTFB (gem. van 15, zonder page cache) | home 87 ms · shop 85 ms · studio 107 ms (+20 ms voor config + render) |
| Opties | `bps_settings` 6 B (alleen afwijkingen), tellers niet-autoload, geen weesbestanden op schijf (91 bestanden gecontroleerd) |
| Cron | `bps_cleanup` dagelijks om 03:17 ingepland, batch van 50, vervolgbatch na 5 min |

## Bevindingen

| id | ernst | titel |
|---|---|---|
| prestatie-1 | hoog | Preview-PNG's van 1 à 1,7 MB worden overal getoond (winkelmand, mini-cart, mails) |
| prestatie-2 | hoog | Frontend-GET schrijft een autoload-optie (`bps_studio_page_id`) |
| prestatie-3 | middel | Instellingen opslaan leegt de cache van de hele site |
| prestatie-4 | middel | JS-bundel 115 kB gzip > budget 100 kB. Build eindigt met exit 2. NL+FR altijd samen |
| prestatie-5 | middel | 40 kB inline config in elke studio-HTML |
| prestatie-6 | middel | Store API-cartcall bij elke studiolading (niet cachebaar) |
| prestatie-7 | middel | Lange main-thread-taken tot 626 ms tijdens analyse/render (INP) |
| prestatie-8 | middel | Elke toevoeging aan de winkelmand bewaart origineel + preview opnieuw. Geen ontdubbeling, oude drafts blijven 30 dagen |
| prestatie-9 | laag | Hiaten in WP Rocket- en optimizer-compatibiliteit (CDN-worker, inline JSON, andere optimizers) |
| prestatie-10 | laag | Opruiming hangt af van WP-Cron en bezoekers. Geen Action Scheduler, geen status |
| prestatie-11 | laag | Onnodige DB-writes en cache-invalidatie (`alloptions`, rate-limit-transients, autoload-groei) |
| prestatie-12 | laag | Werk bij elke request op pagina's zonder studio (maybe_ensure, filemtime, admin-handle op frontend) |
| prestatie-13 | laag | Demo-pagina: Lighthouse 80, FCP 3,6 s door 830 kB alles-inline HTML |
| prestatie-14 | laag | Geen prestatie-regressietest in `tests/run-all.sh` |

---

### prestatie-1 · hoog · Preview-PNG's van 1 à 1,7 MB worden overal getoond
**Bestanden:** `includes/class-uploads.php` (`store_preview`, `reencode_png`, `ensure_dirs`), `includes/woo/class-cart.php` (`thumbnail`, `store_api_images`), `templates/emails/*.php`, `templates/admin/emails/*.php`, `includes/woo/class-designs.php` (`delete_files`)

**Probleem (gemeten):** 54 previews, mediaan **1,04 MB**, max **1,74 MB** (1200×1200 RGBA-PNG, zlib-niveau 7). Samen 55 MB,
tegenover 7,6 MB voor alle originelen. Dezelfde URL dient als winkelmandthumbnail (300×300, `loading="eager"` + `skip-lazy`,
ook in de mini-cart/Elementor menu-cart op elke pagina), als Store API-afbeelding (blokwinkelmand), in de klantmails en in
de proefmail. Op 4G kost één patch in de mini-cart al ±1,7 MB.

De her-encodering kost ook **633 ms CPU** per `POST /designs`, ook als het bestand al 1200 px is. Dat vertraagt
"In winkelmand".

Ter vergelijking, dezelfde afbeelding: WebP 1200 q82 = 144 kB. **WebP 300 px = 22 kB (78x kleiner)**. JPEG 600 px op wit = 72 kB.
Afgeleiden maken kost 40 ms.

**Voorstel:**
1. Hou de PNG als productiemaster (fiche, proef). Maak in `store_preview` meteen afgeleiden:
   `<naam>-300.webp` (winkelmand, mini-cart, Store API, met `srcset` 1x/2x via `-600.webp`) en `<naam>-600.jpg` op witte
   achtergrond voor mails (Outlook ondersteunt geen WebP). Bewaar ze in de meta `_bps_preview['sizes']`. Laat
   `delete_files()` ze mee verwijderen.
2. `thumbnail()`/`store_api_images()` gebruiken `-300.webp`. Templates gebruiken `-600.jpg`. Fallback naar de PNG als de afgeleide ontbreekt.
3. `reencode_png`: niet opnieuw samplen als de afmeting al ≤ 1200 is (alleen kopiëren naar een nieuw canvas om metadata
   te strippen). Gebruik zlib-niveau 6 met `imagepng`-filter `PNG_ALL_FILTERS`.
4. `.htaccess` van de previews: `Cache-Control: public, max-age=31536000, immutable` (de namen zijn willekeurig en
   onveranderlijk). Zet hetzelfde in de nginx-snippet van readme/zelftest.
5. WP-CLI `wp bps previews regenerate` voor bestaande ontwerpen.

**Acceptatie:** winkelmand en mini-cart laden per patchregel ≤ 40 kB beeld (DevTools, transfer). De mailafbeelding is ≤ 100 kB.
De preview-header geeft `max-age=31536000`. Extra servertijd van `POST /designs` voor de preview ≤ 250 ms (gemeten zoals
hierboven). Geen enkele frontendpagina laadt nog een `previews/*.png`.

### prestatie-2 · hoog · Frontend-GET schrijft een autoload-optie
**Bestanden:** `includes/frontend/class-shortcode.php` (`remember_studio_page`), `includes/woo/class-product.php` (`studio_url`)

**Probleem (aangetoond):** `render()` doet `update_option( 'bps_studio_page_id', $id, true )` zodra de huidige pagina
verschilt. Test: een tweede pagina met de studio (via een gesynchroniseerd patroon) bezocht, waardoor de optie op 131 kwam.
Daarna `/patch-studio/` bezocht en de optie stond terug op 15. Elke afwisselende weergave = UPDATE op `wp_options` +
invalidatie van de hele `alloptions`-cache (Redis/Memcached) voor alle bezoekers.

Dat gebeurt meteen bij een NL- en een FR-pagina (WPML/Polylang, de site is tweetalig) of bij landingspagina's met
`[patch_studio techniek="…"]`. Zo'n schrijfactie bij een leesverzoek hoort niet thuis in een betaalde plugin. Ook "terug naar de
studio"-links springen dan willekeurig tussen de pagina's.

**Voorstel:** schrijf nooit tijdens frontend-rendering. Bepaal de studiopagina(s) bij `save_post`/`elementor/editor/after_save`
(scan `post_content` op de shortcode/het blok en `_elementor_data` op de widgetnaam). Bewaar ze als
lijst `bps_studio_pages` (`[lang => id]`, autoload uit). Zet in Instellingen een keuzelijst "Hoofdpagina van de studio" die voorgaat.
`studio_url()` kiest per taal (`apply_filters( 'wpml_object_id' )`/`pll_get_post`).

**Acceptatie:** 1.000 afwisselende GET's op twee studiopagina's geven **0** `UPDATE/INSERT` op `wp_options` (SAVEQUERIES of
Query Monitor). De optiewaarde blijft gelijk. Een unit-/e2e-test bewijst dit.

### prestatie-3 · middel · Instellingen opslaan leegt de cache van de hele site
**Bestanden:** `includes/class-plugin.php` (`purge_page_cache`, hooks op `bps_settings_updated` en deactivatie), `includes/class-settings.php` (`update`, `reset_paths`)

**Probleem:** elke opslag of reset van de instellingen roept `rocket_clean_domain()` / `wp_cache_clear_cache()` / `w3tc_flush_all()` / `litespeed_purge_all` op.
De hele cache gaat weg (home, categorieën, alle producten), terwijl de config alleen in de studiopagina('s) staat. Gevolg:
hogere TTFB voor iedereen en een preload-storm na elke prijsaanpassing. De eigenaar past prijzen vaak aan.

**Voorstel:** purge alleen de URL's met de studio (lijst uit prestatie-2, plus het verborgen product):
`rocket_clean_post( $id )`, `do_action( 'litespeed_purge_post', $id )`, `w3tc_flush_post( $id )`, `wpsc_delete_post_cache( $id )`,
plus `do_action( 'bps_purge_studio_cache', $ids )` voor andere caches. Volledige purge alleen via een knop "Hele sitecache legen"
of als de studiolijst leeg is. Bij deactivatie: zelfde gerichte purge.

**Acceptatie:** met WP Rocket actief blijft na het opslaan van instellingen het cachebestand van `/` en `/shop/` staan (mtime
ongewijzigd), terwijl dat van de studiopagina weg is. Een unittest met stubs telt **0** aanroepen van `rocket_clean_domain`.

### prestatie-4 · middel · JS-bundel boven budget, build eindigt met exit 2, NL+FR altijd samen
**Bestanden:** `scripts/build.mjs`, `includes/class-plugin.php` (`register_assets`), `assets/src/advice/messages.js`, `assets/src/*/messages.js`; lazy-loading vraagt `main.js`/`ui/**` (handoff)

**Probleem (gemeten):** `npm run size`: `patch-studio.js` **115,3 kB gzip** (332 kB raw), budget 100 kB ("TE GROOT").
SPEC D6 mikte op < 60 kB. De productiebuild eindigt met exit 2. Samenstelling (esbuild-metafile, bytes in de output): ui 152 kB,
render 69 kB, advice 65 kB (waarvan `advice/messages.js` 37 kB), pricing 15 kB, analysis 14 kB, colors 14 kB. Elke `messages.js`
bevat **nl én fr**, terwijl een bezoeker er maar één nodig heeft. Lighthouse: 165 kB van 222 kB is "unused JavaScript" bij de eerste lading.

**Voorstel:**
1. Bouw per taal: `patch-studio.nl.js` en `patch-studio.fr.js`. Een esbuild-plugin vervangt de imports `*/messages.js` door een
   variant met één taal (of `define: { __BPS_LOCALE__ }` + tree-shaking). PHP registreert de handle op basis van `Config::public_locale()`.
2. Daarna (handoff UI): ESM met `splitting: true` en dynamische `import()` van render/advice zodra er een bestand gekozen is.
   De skeleton staat al server-side klaar, dus de eerste weergave hangt er niet van af.
3. De budgetcheck blijft hard. Zet het budget per taalbundel op 90 kB en het doel op 75 kB.

**Acceptatie:** `npm run build` geeft exit 0. `npm run size` geeft per taalbundel ≤ 90 kB gzip. Lighthouse
"unused-javascript" voor de Patch Studio-bundel ≤ 80 kB bij de eerste lading.

### prestatie-5 · middel · 40 kB inline config in elke studio-HTML
**Bestanden:** `includes/class-settings.php` (`public_config`), `includes/frontend/class-shortcode.php` (`render`)

**Probleem (gemeten):** `<script type="application/json" class="bps-config">` = **39,6 kB** (threads 11,1 kB, options 11,0 kB,
techniques 8,9 kB, fees 1,4 kB, promises 1,3 kB, plus interne `_doc`, `_threadsNote`, `_fabricsNote`). Zo groeit de HTML van
26 kB naar 68 kB. Dat raakt TTFB/FCP en de cachegrootte. Met twee studio's op één pagina staat alles er twee keer in. Labels staan
er in beide talen.

**Voorstel:** in `public_config()`: alle sleutels met `_` weghalen, alleen labels van de actieve taal meegeven (`{nl,fr}` →
string), garens beperken tot de velden die de client gebruikt (code, hex, naam). De config één keer per pagina uitgeven
(`<script id="bps-config-<hash>">`, instances verwijzen ernaar met `data-bps-config`). Optioneel: het statische deel als
gecachet `uploads/patch-studio/config-<settings-hash>.json` met `<link rel="preload">`.

**Acceptatie:** inline JSON ≤ 20 kB met de standaardinstellingen. Een tweede shortcode op dezelfde pagina voegt ≤ 1 kB toe. Raw HTML van de
studiopagina ≤ 50 kB op de testsite. Bestaande unit-/e2e-tests blijven groen.

### prestatie-6 · middel · Store API-cartcall bij elke studiolading
**Bestanden:** `assets/src/ui/app.js` (r. ~713, `api.storeCart()`), `assets/src/ui/api.js` (handoff UI), `config/defaults.json` (`features.cartPoolHint: true`)

**Probleem (gemeten in Playwright):** elke studiolading doet meteen `GET /wp-json/wc/store/v1/cart`, ook voor een nieuwe
bezoeker met een lege winkelmand. Dat is een volledige, niet-cachebare WP+WC-bootstrap per bezoeker (lokaal 50–70 ms,
op gedeelde hosting met plugins vaak 300–800 ms) en maakt een `Cart-Token` aan. Zo gaat het voordeel van de pagecache voor
een deel verloren.

**Voorstel:** alleen opvragen als de cookie `woocommerce_items_in_cart` bestaat (lezen kan in JS) en pas na de eerste
interactie of `requestIdleCallback`. PHP kan ook `features.cartPoolHint` standaard uitzetten tot een product in de mand de
staffel echt beïnvloedt.

**Acceptatie:** een nieuwe bezoeker zonder cookies doet **0** requests naar `/wp-json/` tot zijn eerste actie (Playwright
request-log). Met een patch in de mand: precies 1.

### prestatie-7 · middel · Lange main-thread-taken tijdens analyse/render (INP)
**Bestanden:** `assets/src/render/**`, `assets/src/ui/**` (handoff), `assets/src/analysis/analyzer.js`

**Probleem (gemeten, Playwright + 4x CPU-throttle):** het eigen platte logo uploaden duurt 1,56 s tot de analyse klaar is, de gsm-foto
van de eigenaar 2,36 s. Er waren **18 long tasks, max 626 ms, samen 2,2 s**. De analyse draait in de worker, maar render, contour en
stylize lopen op de main thread. Op middenklasse-gsm's geeft dat een slechte INP en haperend slepen of schalen.

**Voorstel (handoff render/UI):** zwaar renderwerk naar de worker (OffscreenCanvas waar het kan) of in stukken van ≤ 50 ms
(scheduler.yield/`setTimeout 0`). Hertekenen bij slepen/schuifbalk gebundeld via `requestAnimationFrame` op lage
resolutie, met de volledige render pas bij loslaten. De textuur-/fieldcache hergebruiken.

**Acceptatie:** met 4x CPU-throttle duurt na de upload geen enkele long task langer dan 200 ms. INP bij het slepen van de grootte-schuifbalk
≤ 200 ms (Event Timing API in Playwright). Analyse van `reference/eigenaar-foto-patch.jpg` ≤ 2 s.

### prestatie-8 · middel · Geen ontdubbeling van uploads, oude drafts blijven 30 dagen
**Bestanden:** `includes/rest/class-routes.php` (`designs`, `cart`), `includes/class-uploads.php` (`store_original`), `includes/woo/class-designs.php`, `includes/woo/class-cart.php`

**Probleem:** elke "In winkelmand", "Bewaar & mail" of "Offerte" maakt een nieuw ontwerp aan, met een nieuw origineel (tot 25 MB) en een nieuwe preview.
Op de testsite staat één origineel er 26 keer en één preview 17 keer (md5). Past de klant zijn ontwerp in de winkelmand
aan, dan blijft het vorige draft-ontwerp 30 dagen staan (`orphanDays`). Opslag en back-ups groeien zo veel sneller dan
het aantal bestellingen.

**Voorstel:** sla de `sha256` van het origineel op (`_bps_original_sha256`) en hergebruik een bestaand privébestand
(referentietelling in meta, pas verwijderen als niemand het nog gebruikt). Vervangt een cart-item een eerder draft-ontwerp
van dezelfde sessie, verwijder dat draft dan meteen. Toon in het statusblok de totale grootte van `uploads/patch-studio`.

**Acceptatie:** hetzelfde bestand 5x toevoegen geeft 1 origineel op schijf. Een ontwerp in de winkelmand bewerken laat 0
extra draft-ontwerpen achter. Het statusblok toont de opslag in MB.

### prestatie-9 · laag · Hiaten in WP Rocket- en optimizer-compatibiliteit
**Bestanden:** `includes/class-plugin.php` (rocket-filters, `script_loader_tag`), `includes/class-settings.php` (assets.workerUrl)

**Probleem:**
(a) De inline `<script type="application/json" class="bps-config">` staat niet in `rocket_delay_js_exclusions`. Volgens de
    WP Rocket-logica worden niet-JS-types normaal overgeslagen, maar een uitsluiting kost niets.
(b) De WP Rocket CDN-optie herschrijft ook URL's in de inline JSON. Komt `workerUrl` op een CDN-host, dan faalt `new Worker()`
    cross-origin en hangt de fallback (`fetch` → Blob) af van CORS op de CDN. Dan is de analyse trager of faalt ze.
(c) Er zijn geen uitsluitingen voor andere veelgebruikte optimizers (LiteSpeed `data-no-optimize`/`data-no-defer`, SiteGround Optimizer,
    Perfmatters, FlyingPress) en geen test die de filters controleert.

**Voorstel:** `bps-config` toevoegen aan de delay- en defer-uitsluitingen. Worker altijd same-origin: `rocket_cdn_reject_files`
`/borduur-patch-studio/assets/dist/(.*)worker(.*).js` plus de vergelijkbare filters van LiteSpeed en W3TC. Bij `bps-app` ook
`data-no-optimize="1" data-no-defer="1"` zetten. De bijbehorende filters voor LiteSpeed/SGO/Perfmatters/FlyingPress toevoegen. Een PHP-unittest controleert
dat elke filter de juiste patronen teruggeeft. Een handmatige testmatrix (met WP Rocket: alle opties aan) komt in readme.txt.

**Acceptatie:** de unittest is groen. Met WP Rocket (Delay JS + RUCSS + CDN aan) werkt upload → analyse → winkelmand zonder
consolefouten en wordt de worker van de eigen host geladen (Network-tab).

### prestatie-10 · laag · Opruiming hangt af van WP-Cron en bezoekers
**Bestanden:** `includes/woo/class-cleanup.php`, `includes/admin/class-settings-page.php` (statusblok)

**Probleem:** `bps_cleanup` draait via WP-Cron, dus in de request van een willekeurige bezoeker. Met een pagecache komen er
weinig PHP-requests, waardoor de opruiming uitloopt. Een batch = tot 4 × 50 `wp_delete_post` met bestandsverwijdering in één
bezoekersrequest. Er is geen tijdslimiet, geen log en geen zichtbare "laatste run".

**Voorstel:** gebruik Action Scheduler (zit in WooCommerce): `as_schedule_recurring_action` dagelijks, vervolgbatches met
`as_enqueue_async_action`, een tijdsbudget per batch (bv. 20 s) in plaats van een vast aantal, resultaten naar `wc_get_logger()`
(bron `patch-studio`), en "laatste opruiming: datum, aantallen" in het statusblok. Readme: advies `DISABLE_WP_CRON` + echte cron.

**Acceptatie:** met 500 vervallen ontwerpen is alles binnen 1 uur weg zonder dat een bezoekersrequest meer dan 1 s langer duurt.
Het statusblok toont de laatste run. WooCommerce → Status → Scheduled Actions toont `bps_cleanup`.

### prestatie-11 · laag · Onnodige DB-writes en cache-invalidatie
**Bestanden:** `includes/woo/class-designs.php` (`next_ref`), `includes/rest/class-guard.php` (`rate_limit`), `includes/class-settings.php` (autoload)

**Probleem:** `next_ref()` wist bij elk nieuw ontwerp of elke offerte `alloptions` uit de objectcache, hoewel `bps_ref_seq`/`bps_quote_seq`
niet-autoload zijn. Met Redis moet daardoor elke volgende request de volledige autoload-set opnieuw laden. `rate_limit()` schrijft
bij elke gelimiteerde call 2 rijen in `wp_options` (transient + timeout) per IP, bucket en tijdslot, zonder persistente objectcache.
`bps_settings` is altijd autoload, terwijl het alleen op studio-, cart- en adminpagina's nodig is. Bij grote overrides (bv. een aangepast
garenpalet, tot ±15 kB) komt dat mee in elke request van de site.

**Voorstel:** in `next_ref()` alleen `wp_cache_delete( $option, 'options' )` en `notoptions` wissen, niet `alloptions`. Rate-limit:
`wp_cache_incr`/`wp_cache_add` met een eigen groep als `wp_using_ext_object_cache()`, anders transients (zoals nu). `bps_settings`
op autoload `false` (WP 6.6+: `'auto'`) zodra prestatie-12 het lezen op elke request heeft weggehaald.

**Acceptatie:** met de Redis-objectcache wordt bij `POST /designs` de sleutel `options:alloptions` niet verwijderd (redis-cli
MONITOR). Met objectcache maken 100 quote-calls 0 `wp_options`-writes. `bps_settings` zit niet in `wp_load_alloptions()`.

### prestatie-12 · laag · Werk bij elke request op pagina's zonder studio
**Bestanden:** `includes/woo/class-product.php` (`maybe_ensure` op `init`), `includes/class-plugin.php` (`register_assets`, `asset_version`)

**Probleem (gemeten):** bij elke request (ook home, REST, admin-ajax, cron) draaien `Product::maybe_ensure()`
(config opbouwen uit 2 JSON-bestanden van samen 52 kB + `get_post_type`), `Cleanup::schedule()` en `register_assets()` (4x `filemtime`).
Gemeten: **1,8 ms en 2 queries** per request. `bps-admin` wordt ook op de frontend geregistreerd en staat zo in de
inline dependency-registry van WooCommerce op cart en checkout.

**Voorstel:** `maybe_ensure` alleen in `is_admin()`, bij activatie/upgrade en vlak voor add-to-cart, of hoogstens 1x per 12 u
(transient). Registreer `bps-admin` alleen in `admin_enqueue_scripts`. Gebruik als assetversie `BPS_VERSION` + de build-hash uit een manifest
dat `scripts/build.mjs` schrijft (`assets/dist/manifest.json`), in plaats van `filemtime` per request.

**Acceptatie:** Query Monitor op `/` toont voor component "borduur-patch-studio" 0 queries en < 0,5 ms. De cart-HTML bevat
geen `patch-studio-admin.js` meer.

### prestatie-13 · laag · Demo-pagina: Lighthouse 80, FCP 3,6 s
**Bestanden:** `scripts/build-demo.mjs`, `dist/patch-studio-demo.html` (van het andere team, dus handoff)

**Probleem (gemeten, Brotli):** de demo is één HTML van 830 kB met JS, CSS en fonts inline (base64). Lighthouse mobiel geeft **80**:
FCP 3,6 s, LCP 3,7 s, TBT 130 ms, 702 KiB. De eerste weergave wacht op het hele document. Dit is de pagina die de eigenaar laat zien.

**Voorstel (build-demo):** de JS als apart, uitgesteld bestand (of inline maar onderaan, na de zichtbare skeleton), alleen het
koptekstfont preloaden en de andere fonts als `font-display: swap`-bestanden, en de voorbeeldafbeeldingen lazy laden.

**Acceptatie:** Lighthouse mobiel op de demo ≥ 90, FCP ≤ 2,0 s.

### prestatie-14 · laag · Geen prestatie-regressietest
**Bestanden:** `tests/run-all.sh`, nieuw `tests/perf/*` (bv. `tests/e2e/09-perf.e2e.cjs`)

**Probleem:** alleen de gzip-budgetten worden bewaakt. Geen enkele test controleert dat pagina's zonder studio niets laden, wat de
config- en previewgrootte is, of hoe Lighthouse en long tasks evolueren. Daardoor bleef de overschrijding van het budget (prestatie-4) onopgemerkt.

**Voorstel:** een perf-scenario met Playwright en Lighthouse (programmatic, met de Brotli-proxy uit deze audit) dat controleert:
`/` en `/shop/` hebben 0 Patch Studio-requests, de inline config ≤ 20 kB, de winkelmandthumbnail ≤ 40 kB, studio Lighthouse mobiel ≥ 90,
geen long task > 200 ms na de upload, en 0 `wp_options`-writes bij een frontend-GET (via een mu-plugin die writes telt).

**Acceptatie:** `bash tests/run-all.sh` bevat een stap "perf" die faalt als een van die drempels overschreden wordt.

## Reproduceren
- `npm run size` geeft de bundelgroottes.
- Lighthouse: `npx lighthouse@12 <url> --only-categories=performance --chrome-flags="--headless=new --no-sandbox"` met
  `CHROME_PATH=/usr/bin/google-chrome`, achter een Brotli-proxy (`php -S` comprimeert niet. Zonder proxy: studio 75 / LCP 5,1 s).
- Previewgroottes: `ls -laS <wp>/wp-content/uploads/patch-studio/previews`.
- prestatie-2: twee pagina's met `[patch_studio]` om beurten openen en telkens `wp option get bps_studio_page_id` bekijken.
