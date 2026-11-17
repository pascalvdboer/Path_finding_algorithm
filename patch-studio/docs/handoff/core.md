# Handoff — core

## Gebouwd
- `borduur-patch-studio.php`: header (SPEC §4.1), constanten (+ `BPS_DB_VERSION`), PHP-versiecheck, autoloader volgens SPEC-regel, HPOS/blocks-compat, activatie/deactivatie, `Plugin::boot` op `plugins_loaded` prio 20.
- `includes/class-plugin.php`: componenten (filter `bps_components`; zonder WooCommerce enkel Settings + Settings_Page), activate/deactivate per component (+ multisite netwerk), `bps_activate`/`bps_deactivate`, textdomain op `init`, assets `bps-app`/`bps-style`/`bps-admin` (versie = BPS_VERSION-filemtime), `Plugin::worker_url()`, WP Rocket (delay-js, RUCSS, exclude_js), `data-cfasync="false" data-jetpack-boost="ignore"` op bps-app, cache-purge (WP Rocket, Super Cache, W3TC, LiteSpeed) op `bps_settings_updated`, notices (WooCommerce ontbreekt, "klaar voor gebruik"), link "Instellingen".
- `includes/class-settings.php`: exact de SPEC §4.2-API. `public_config()` getest **identiek** aan JS `buildPublicConfig()` (op rest/assets/page/strings na). Extra publieke helpers: `overrides()`, `reset_paths(array $paths)` (wildcards `*`), `last_notices()`, `default_options()` (PHP-tweeling van JS), `label()`, `same()`, `diff()`, `array_get()`, `public_locale()`, `flush()`.
  - `update()` saneert tegen de vorm van defaults.json (types, enums, min/max, hex/url/e-mail), merget in de huidige overrides en bewaart enkel het verschil. Coherentieregels: `mode:'order'` vereist een prijs voor elke maat (anders terug naar offerte + melding), minQty veelvoud van qtyStep, staffels gesorteerd + start 1/0 %, percent-opties −90..90 % **en het % in het optielabel wordt mee aangepast** (eerlijkheid), cartMode altijd `own_product`, defaultFabric moet bestaan.
  - `strings`-override: plat `{sleutel: tekst}` of `{nl:{…}, fr:{…}}`; geen UI (via filter `bps_config` of `Settings::update(['strings'=>…])`).
- `includes/admin/class-settings-page.php` + `views/tab-*.php` + `assets/settings.{css,js}`: WooCommerce › Patch Studio (zonder Woo: Instellingen › Patch Studio), 5 tabs, "Herstel standaard" per tab, "Snel aan de slag" + knop **"Maak de ontwerppagina aan"** (pagina "Ontwerp je patch" met `[patch_studio]`), voorbeeldtabel via `Pricing::quote` (1/10/25/50/100 × 8 cm), kassa-check tegen legacy-variaties (verborgen als die producten niet bestaan), statusblok (Woo, product, pagina, `Uploads::self_test()`, JS-bundle, Pricing, WP Rocket, Elementor), live "klant betaalt € x incl. btw", decimale komma, niet-bewaard-waarschuwing.
- `uninstall.php`: altijd opties/transients/cron weg; met "Alles verwijderen" ook CPT-posts, product, `uploads/patch-studio`, tellers. Multisite.

## Afwijkingen van SPEC
- `config/defaults.json`: nieuwe privésleutel `admin: { deleteDataOnUninstall: false }` (al voorzien in `PRIVATE_KEYS`, wordt gestript uit de publieke config).
- Productzichtbaarheid: Settings_Page zet `catalog_visibility` van het product mee bij `bps_settings_updated` (woo hoeft dit niet te doen).

## Voor de integrator / woo
- `Woo\Integration` bestaat nog niet; zonder die klasse wordt bij activatie geen product gemaakt. `Integration::activate()` moet het product aanmaken.
- Lijsten (`options`, `fabrics`, `tiers.steps`, `threadsDisabled`) worden als geheel bewaard (deep_merge vervangt lijsten).

## Testen
- `php -l` op alle core-bestanden: OK. Lokale WP 7.1 + Woo + SQLite (`scratchpad/core-wp`): Playwright bewaart elk tabblad onveranderd → optie blijft `[]`; wijzigingen/reset/meldingen/mobiel 390 px (geen horizontale scroll) getest; geen PHP-notices van de plugin.

## Open
- `.pot` + fr_BE `.po/.mo` (polish-fase; geen wp-cli in de omgeving, `languages/` bestaat).
- `readme.txt`, `wpml-config.xml` (polish-fase).
