# Handoff: wat naamstroken + cross-sell nodig hebben in bestaande code

Stand **18-11-2026, afwerking**. Beide modules zijn af en getest. Ze staan volledig in eigen mappen
(`includes/nametape/**`, `includes/crosssell/**`, `assets/src/nametape/**`, `assets/src/crosssell/**`,
`config/nametape*.json`, `config/crosssell.json`). **Zonder de P1-punten hieronder draaien ze enkel in
`dev/nametape.html`, `dev/crosssell.html` en de tests, niet in WordPress.** Alle wijzigingen zijn klein en
achterwaarts compatibel: elke module controleert zelf of een hook/klasse bestaat.

Prioriteit: **P1** = zonder dit niet live · **P2** = nodig voor juiste prijs/flow · **P3** = mooi meegenomen.
Eigenaar van het bestand staat tussen haakjes. Regelnummers verschuiven (andere teams werken mee): zoek op het
**ankerstuk** dat telkens vermeld is.

Achtergrond: `docs/extra/SPEC-EXTRA.md` (bindend), `docs/extra/nametape-notes.md`, `docs/extra/crosssell-notes.md`,
eigenaar-rapport `docs/extra/RAPPORT.md`.

---

## 0. Teststand bij overdracht (18-11)

| Wat | Commando | Resultaat |
|---|---|---|
| Node-tests eigen modules | `node --test tests/nametape/*.test.js tests/crosssell/*.test.js` | **118/118 ok** |
| PHP naamstroken | `php tests/nametape/run-php.php` | **102 ok, 0 fout** |
| PHP cross-sell (pure klassen + `Lines`) | `php tests/crosssell/run-php.php` | **209 ok** |
| PHP cross-sell WordPress-laag (stubs) | `php tests/crosssell/run-wp.php` | **17 ok** |
| Playwright naamstroken (gsm 390 px, desktop, FR) | `NODE_PATH="$(npm root -g)" node tests/nametape/e2e-mobile.mjs` | **alles ok** |
| Playwright cross-sell-paneel | `NODE_PATH="$(npm root -g)" node tests/crosssell/e2e-panel.mjs` | **alles ok** |
| `php -l` alle eigen PHP-bestanden | — | geen fouten |
| Bundelgrootte (esbuild, minify, iife, gzip) | — | nametape **28,2 kB** (budget 30), cross-sell **21,4 kB** (budget 25) |
| Volledige `npm test` | `npm test` | 343 ok / 19 fout / 3 todo: **alle 19 fouten zitten in `accuracy:*`, `demo:*`, `components:*`** (analyse/render-team, werk in uitvoering), geen enkele in nametape/crosssell |
| `npm run test:php` (pricing) | — | 652 checks, 0 fout |

Kleine aanpassing tijdens afwerking: `Nametape\Admin::status_checks()` geeft nu naast `message` ook `detail`
mee, omdat `Settings_Page::status_checks()` en `views/tab-algemeen.php` het veld `detail` lezen (zie punt 4).

---

## 1. Overzicht (afvinklijst voor de integrator)

| # | Prio | Bestand (eigenaar) | Wat | Status zonder |
|---|---|---|---|---|
| 1 | **P1** | `includes/class-plugin.php` (core) | 2 klassen in `Plugin::COMPONENTS` | niets registreert zich |
| 2 | **P1** | `scripts/build.mjs` (build) | 2 build-entries + budget | bron laadt als ES-module (nametape) / cross-sell blijft uit |
| 3 | **P1** | `assets/src/ui/flows.js` (ui) | `BPSCrossSell.open()` in `order()` | cross-sell-paneel verschijnt nooit na "In winkelmand" |
| 4 | P3 | `includes/admin/class-settings-page.php` (core) | filter `bps_status_checks` | status van de modules niet in het statusblok |
| 5 | P2 | `package.json` (build) | `test:php` + e2e-scripts uitbreiden | modules-tests niet in CI/`run-all.sh` |
| 6 | P2 | `includes/woo/class-cart.php` (woo) | filter `bps_cart_pool_qty` | patchstaffel telt naamstroken / patches op petten niet mee in de patchregels |
| 7 | P2 | `uninstall.php` (core) | opties + producten van de modules opruimen | restanten na deïnstallatie |
| 8 | P2 | `assets/src/ui/flows.js` (ui) | `bps:added-to-cart`-detail met `token` | terugvalpad van cross-sell inactief |
| 9 | P3 | `assets/src/ui/panels.js` + `messages.js` (ui) | link "Naamstroken met klittenband" in lege staat | naamstroken enkel via eigen pagina vindbaar |
| 10 | P3 | `includes/woo/class-cart.php` (woo) | actie `bps_cart_fees_added` | niet nodig, cross-sell heeft een werkende terugval |
| 11 | P3 | `includes/admin/class-settings-page.php` (core) | filter `bps_settings_tabs` | modules houden een eigen submenu onder WooCommerce |
| 12 | P3 | `includes/admin/class-order-production.php` (production) | fiche ook voor regels met `_bps_cs_design_id`; proefmail voor `_bps_nt_batch` | zie §2.12 |
| 13 | P3 | `assets/src/render/index.js` + `main.js` (render) | `window.BPS.render = { drawGarment, placePatch }` | cross-sell gebruikt eigen mockups (standaard) |
| 14 | P3 | `assets/fonts/**`, `LICENSES.txt` (ui) | stencil-/militair lettertype als de eigenaar dat bevestigt | 5 standaardfonts blijven |

---

## 2. Precies wat er moet gebeuren

### 2.1 (P1, core) Componenten registreren — `includes/class-plugin.php`

Anker: `const COMPONENTS = array(`. Toevoegen **aan `COMPONENTS`, niet aan `CORE_COMPONENTS`** (beide hebben WooCommerce nodig):

```php
	const COMPONENTS = array(
		'BorduurPatchStudio\Settings',
		'BorduurPatchStudio\Admin\Settings_Page',
		'BorduurPatchStudio\Woo\Integration',
		'BorduurPatchStudio\Rest\Routes',
		'BorduurPatchStudio\Frontend\Shortcode',
		'BorduurPatchStudio\Admin\Order_Production',
		'BorduurPatchStudio\Nametape\Module',   // naamstroken (docs/extra/SPEC-EXTRA.md §1)
		'BorduurPatchStudio\Crosssell\Module',  // zelfde ontwerp, meer producten (§2)
	);
```

- De autoloader vindt ze al (`includes/nametape/class-module.php`, `includes/crosssell/class-module.php`).
- Beide `register()` hangen zelf aan `bps_activate` / `bps_deactivate` (door `Plugin` al uitgezonden): product
  "Naamstroken op maat" aanmaken, cron `bps_nt_cleanup` plannen/afmelden. `deactivate_site()` hoeft niets extra.
- Opgelet: de `foreach ( self::COMPONENTS ... )` rond regel 156 (deactivatie) loopt nu ook over deze twee; beide
  hebben een statische `deactivate()`, dus dat is in orde.
- Zonder WooCommerce registreren ze niets (getest in `tests/crosssell/run-wp.php`).
- Getest alternatief tot dit gebeurt: `add_filter( 'bps_components', fn( $c ) => array_merge( $c, [ ...beide... ] ) );`

### 2.2 (P1, build) Build-entries — `scripts/build.mjs`

Anker: `const BUDGET = {` en `const entries = [`.

```js
const BUDGET = { 'patch-studio.js': 135 * 1024, 'patch-studio-worker.js': 25 * 1024, 'patch-studio-admin.js': 15 * 1024,
  'patch-studio-nametape.js': 30 * 1024, 'patch-studio-crosssell.js': 25 * 1024 };

const entries = [
  { in: 'main.js', out: 'patch-studio.js', globalName: 'BPS' },
  { in: 'analysis/worker.js', out: 'patch-studio-worker.js', globalName: undefined },
  { in: 'admin/index.js', out: 'patch-studio-admin.js', globalName: 'BPSAdmin' },
  { in: 'nametape/index.js', out: 'patch-studio-nametape.js', globalName: 'BPSNametape' },
  { in: 'crosssell/index.js', out: 'patch-studio-crosssell.js', globalName: 'BPSCrossSell' },
];
```

- **CSS niet in de bundel**: beide modules laden hun stijl zelf uit `assets/src/<module>/<module>.css`.
- Cross-sell: zolang `assets/dist/patch-studio-crosssell.js` ontbreekt, zet `Crosssell\Module::public_config()`
  `extras.crosssell.enabled = false` en laadt er niets. **Na integratie dus `npm run build` draaien en `assets/dist/` meeleveren.**
- Naamstroken: zonder bundel laadt `Nametape\Shortcode` de bronbestanden als `type="module"` (werkt, iets trager).
  De printpagina `admin-post.php?action=bps_nt_print` gebruikt dezelfde bundel/bron.
- `index.js` van cross-sell zet zelf `window.BPSCrossSell` (iife-guard), dus `globalName` en de guard botsen niet.

### 2.3 (P1, ui) Cross-sell openen na "In winkelmand" — `assets/src/ui/flows.js`

Anker: `async function order(resultEl)`, net na `const res = await api.addToCart(...)` en de bestaande `emit(...)`.
Huidige code: na de melding volgt `if (redirect && res.cartUrl) setTimeout(() => { location.href = res.cartUrl; }, 600);`.

```js
      const res = await api.addToCart({ designId: up.designId, token: up.token, rightsConsent: rightsConsent() });
      const s = store.get();
      emit(ctx.root, EV.ADDED_TO_CART, { designId: up.designId, ref: up.ref, token: up.token, cartItemKey: res.cartItemKey,
        cartUrl: res.cartUrl, cartCount: res.cartCount, technique: s.technique, sizeCm: s.sizeCm, qty: s.qty });   // punt 8
      // … bestaande track / wc_fragment_refresh / melding ongewijzigd …

      // NIEUW, vlak vóór de redirect-regel:
      let cs = 'none';
      const csCfg = config.extras && config.extras.crosssell;
      if (window.BPSCrossSell && csCfg && csCfg.enabled) {
        try {
          cs = await window.BPSCrossSell.open({ root: ctx.root, designId: up.designId, token: up.token, ref: up.ref, cartUrl: res.cartUrl });
        } catch (e) { cs = 'none'; }
      }
      if (cs === 'none' && redirect && res.cartUrl) setTimeout(() => { location.href = res.cartUrl; }, 600);
```

Contract van `open()`:
- lost **altijd** op met `'added' | 'declined' | 'none'`, gooit nooit; time-out `openTimeoutMs` (4 s) als er geen kaarten komen;
- `'none'` = geen paneel getoond (uit, al eens getoond voor dit ontwerp, eerder "Nee, bedankt", geen passend product) → bestaand gedrag;
- `'added'` / `'declined'` = klant koos al in het paneel; het paneel heeft zelf een knop "Naar winkelmand". **Niet automatisch doorverwijzen.**
- `ctx.busy` blijft tijdens het paneel `true`; dat is gewenst (geen dubbele bestelling). De bestaande `finally` zet het terug.
- `config.extras.crosssell` komt via `bps_public_config` (prio 20) van de module zelf; ontbreekt het, dan gebeurt er niets.
- `/cart` geeft geen `token` terug; `up.token` (uit de upload) is het juiste token. Geeft security later een nieuw token
  terug, gebruik dan `res.token || up.token`.

### 2.4 (P3, core) Statusblok filterbaar — `includes/admin/class-settings-page.php`

Anker: `public static function status_checks(): array {` … `return $checks;`. Laatste regel vervangen door:

```php
		return (array) apply_filters( 'bps_status_checks', $checks );
```

Vorm van een rij (bestaand): `['ok' => bool|null, 'label' => string, 'detail' => string, 'link' => ?string, 'link_label' => ?string]`.
`Nametape\Admin::status_checks()` levert `label`, `ok`, `detail` (+ `message`, zelfde tekst) en `link`. Cross-sell toont zijn status
(op de eigen beheerpagina) en hangt hier niet aan. Wil core `link_label` verplicht: default "Bekijk" gebruiken als het ontbreekt.

### 2.5 (P2, build) Tests opnemen — `package.json` (+ `tests/run-all.sh` pikt `test:php` al op)

```json
"test:php": "php tests/pricing/run-php.php && php tests/nametape/run-php.php && php tests/crosssell/run-php.php && php tests/crosssell/run-wp.php",
"test:extra:e2e": "node tests/nametape/e2e-mobile.mjs && node tests/crosssell/e2e-panel.mjs"
```

`npm test` vindt de node-tests al via de glob. De e2e-scripts starten zelf een statische server en eindigen met 0 als
Playwright ontbreekt; Playwright staat globaal, dus aanroepen met `NODE_PATH="$(npm root -g)"`. Ze schrijven de
schermafdrukken naar `docs/extra/screenshots/`.

### 2.6 (P2, woo) Pool uitbreidbaar — `includes/woo/class-cart.php`

Anker: `private static function pool_qty( array $items ): int {`. De laatste regel `return $pool;` vervangen door:

```php
		return (int) apply_filters( 'bps_cart_pool_qty', $pool, $items );
```

- `Nametape\Cart::pool_filter( $pool, $items )` hangt er al aan (prio 10): telt naamstroken bij de patchstaffel als
  `nametape.tiers.pooledWithPatches = true` (standaard aan; eigenaar beslist).
- Cross-sell rekent de pool voor zijn eigen regels al zelf (patchregels + patches op dragers). Zonder de filter zien
  woo's patchregels de naamstroken en de patches op petten/hoodies niet: de staffel kan daar één trap lager uitvallen
  (klant betaalt dan iets te veel, nooit te weinig).
- `pool_qty()` wordt enkel in de mand gebruikt (3 aanroepen in `Woo\Cart`), niet in `Pricing`; `tests/pricing/run-php.php` blijft groen.

### 2.7 (P2, core) Deïnstallatie — `uninstall.php`

Anker: `delete_option( 'bps_db_version' );` (altijd) en de `foreach ( array( 'bps_product_id', 'bps_ref_seq', 'bps_quote_seq' ) …` (bij "Alles verwijderen").

Altijd (naast `wp_clear_scheduled_hook( 'bps_cleanup' );`):
```php
	wp_clear_scheduled_hook( 'bps_nt_cleanup' );
	foreach ( array( 'bps_nametape_settings', 'bps_crosssell_settings', 'bps_cs_stats' ) as $option ) {
		delete_option( $option );
	}
```
Enkel bij `$wipe_all`:
```php
	// Verborgen producten van de modules (naamstroken + laser/kader/PVC), naar de prullenbak (bestellingen blijven leesbaar).
	foreach ( array( '_bps_nametape_product', '_bps_cs_product' ) as $meta ) {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => $meta, 'meta_compare' => 'EXISTS' ) );   // waarde verschilt: '1' resp. item-sleutel
		foreach ( $ids as $id ) { wp_trash_post( (int) $id ); }
	}
	foreach ( array( 'bps_nametape_product_id', 'bps_nametape_page_id' ) as $option ) {
		delete_option( $option );
	}
```
- Map `uploads/patch-studio/nametape/` valt al onder de bestaande `bps_uninstall_rmdir( …/patch-studio )`.
- Transient `bps_nt_product_lock` valt al onder de bestaande `_transient_bps_%`-opruiming.
- De naamstrookpagina (optie `bps_nametape_page_id`) is door de eigenaar gepubliceerde inhoud: **niet** verwijderen, enkel de optie.
- Ordermeta (`_bps_nt_*`, `_bps_cs_*`) blijft, zoals bij de bestaande module.

### 2.8 (P2, ui) Rijker `bps:added-to-cart`-event — `assets/src/ui/flows.js`

Zie de `emit(...)` in 2.3: `token`, `cartItemKey`, `technique`, `sizeCm`, `qty` toevoegen. Cross-sell luistert hierop als terugval
(`assets/src/crosssell/index.js`, anker `addEventListener('bps:added-to-cart'`) en opent het paneel **alleen** als `designId` en
`token` aanwezig zijn en de studio `open()` niet al zelf aanriep (dubbele opening wordt voorkomen met `wasShown(designId)`).
Na 2.3 is dit enkel nog nodig voor andere pagina's/thema's die het event uitzenden.

### 2.9 (P3, ui) Link naar naamstroken in de lege staat — `assets/src/ui/panels.js` + `assets/src/ui/messages.js`

Anker in `panels.js`: de knop met `'data-bps-action': 'text-only'` ("Nog geen ontwerp? Start met tekst"). Direct erachter:

```js
        (config.extras && config.extras.nametape && config.extras.nametape.enabled && config.extras.nametape.pageUrl)
          ? h('a.bps-btn.bps-btn--ghost.bps-btn--small', { href: config.extras.nametape.pageUrl, 'data-bps-action': 'nametape' },
              icon('type'), t('ui.empty.nametape'))
          : null,
```

`messages.js` (NL-blok naast `ui.empty.textOnly`, FR-blok idem):
```js
    'ui.empty.nametape': 'Alleen een naam of ID? Naamstroken met klittenband →',
    'ui.empty.nametape': 'Seulement un nom ou un ID ? Bandes nominatives avec scratch →',   // FR
```
`extras.nametape = { enabled, pageUrl }` komt van `Nametape\Module::public_config()`; `enabled` is pas `true` als er een
gepubliceerde naamstrookpagina is (knop "Maak de naamstrookpagina aan" op WooCommerce › Patch Studio · Naamstroken).
Bestaat het niet, dan geen link. Geen extra JS-afhankelijkheid.

### 2.10 (P3, woo) Fee-hook — `includes/woo/class-cart.php`

Anker: einde van `public static function add_fees( $cart ): void {`. Toevoegen als laatste regel:
```php
		do_action( 'bps_cart_fees_added', $cart, array_keys( $charged ) );
```
Niet strikt nodig: `Crosssell\Lines::fees()` voegt de borduurkaart enkel toe als er **geen** `bps`-regel van die fee-groep in de
mand staat, met hetzelfde id `bps-fee-<groep>` (of `bps-fee-<groep>-<design_id>` bij `fees.scope = design`); WooCommerce weigert
een dubbel fee-id. Legacy-producten (Conditional Extra Fees) volgen dezelfde regel als woo (`woo.skipFeeIfLegacyInCart`).
Eigen fee PVC-sleutelhanger: id `bps-cs-pvc-mold`. Naamstroken hebben géén borduurkaart (optionele eigen instelkost, standaard uit).

**Prijs excl./incl.:** `Woo\Cart::set_prices()` zet de prijs excl. btw; naamstroken (prio 25) en cross-sell (prio 30) doen hetzelfde
in `woocommerce_before_calculate_totals`. Gaat de winkel ooit "prijzen incl. btw invoeren", dan moeten de drie samen aangepast worden.

### 2.11 (P3, core) Tabs filterbaar — `includes/admin/class-settings-page.php`

Anker: `public static function tabs(): array { return array(`. Het resultaat door `apply_filters( 'bps_settings_tabs', $tabs )`
halen en per tab optioneel `'render' => callable` en `'save' => callable` toestaan in de render/save-routing (`handle_save()`,
de view-switch). Dan kunnen "Patch Studio · Naamstroken" en "Patch Studio · Cross-sell" tabs worden. **Tot dan staan ze als eigen
submenu onder WooCommerce** (werkt, getest). Geen haast.

### 2.12 (P3, production) Productiefiche en proefmail — `includes/admin/class-order-production.php`

- Cross-sell: ordermeta heet bewust **`_bps_cs_design_id`**, niet `_bps_design_id` (die laatste laat `Woo\Order::mark_ordered()`,
  `has_design()` en de fiche een pet-regel als patchregel behandelen). De fiche krijgt nu al via `bps_fiche_sections` een sectie
  "Hetzelfde ontwerp ook op" bij de patchregel van hetzelfde ontwerp. **Gat:** houdt de klant enkel petten (patchregel verwijderd),
  dan toont geen fiche die regels. Voorstel: in `fiche()` ook regels met `_bps_cs_design_id` aanbieden; ontwerp-PNG via
  `_bps_cs` → `preview_url`.
- Naamstroken: de proefmail/goedkeuring ook laten werken voor regels met `_bps_nt_batch` (JSON-snapshot) en `_bps_nt_preview`
  (bestandsnaam in `uploads/patch-studio/nametape/`); bijlage = printpagina `admin-post.php?action=bps_nt_print&order=…&item=…`
  (nonce + `edit_shop_orders`). Pure helpers: `Nametape\Production::rows()/style()/csv()`. V1: de eigenaar gebruikt de
  printpagina handmatig als proef (knop bij de bestelregel).

### 2.13 (P3, render) Studio-renderer delen — `assets/src/render/index.js`, `assets/src/main.js`

`drawGarment` (en eventueel `placePatch`) exporteren en in `main.js` `window.BPS.render = { drawGarment, placePatch }` zetten.
Cross-sell gebruikt dat alleen als `crosssell.useStudioRenderer = true`; de eigen mockups blijven de standaard (≤ 25 ms per kaart).

### 2.14 (P3, ui) Lettertype

Als de eigenaar een stencil/militair borduurlettertype bevestigt dat ook in zijn borduursoftware zit: OFL-woff2 in
`assets/fonts/` + regel in `LICENSES.txt`, daarna een rij in `config/nametape.json` → `fonts[]` (`file` = bestandsnaam).

---

## 3. Rooktest na integratie (± 15 min, lokale WP)

1. Plugin opnieuw activeren → product "Naamstroken op maat" bestaat (verborgen, categorie `naamstroken`), cron `bps_nt_cleanup` gepland.
2. WooCommerce › Patch Studio · Naamstroken → "Maak de naamstrookpagina aan" → pagina met `[patch_studio_naamstroken]`.
3. Pagina op gsm-breedte: 1 naam → stijl → "In winkelmand" → mand toont **€ 5,45 incl.**, aantal vergrendeld, **geen € 45-fee**.
4. 25 namen plakken → € 4,90/st incl. (staffel −10 %). Bestelling plaatsen → ordernotitie, knoppen CSV + productielijst bij de regel.
5. WooCommerce › Patch Studio · Cross-sell → "Maak ontbrekende producten aan" (leder, kaders, PVC).
6. Studio: 25 geborduurde patches 8 cm → "In winkelmand" → paneel met pet, hoodie, muts; niets aangevinkt; "Nee, bedankt" even groot.
   Pet toevoegen → mand: aparte regel, borduurkaart **niet** dubbel. Esc sluit het paneel, focus terug.
7. Studio met een foto → paneel toont kader + lederen sleutelhanger, **geen** PVC. 50 PVC-patches → PVC-sleutelhanger met mal € 80 excl.
8. Algemeen-tab: statusblok toont de regels "Naamstroken · …" (na 2.4).
9. Conditional Extra Fees: controleer dat categorie `naamstroken` en de verborgen laser/kader/PVC-producten **niet** onder
   "Borduurkaart en Instelkosten" vallen (anders komt er € 45 bij).

---

## 4. Bekende beperkingen

- **Blokkenmand:** de mandregel "Ook op pet, muts of in een kader?" hangt aan `render_block_woocommerce/cart-items-block`; de React-mand
  kan die HTML bij hydratatie vervangen. De klassieke mand (`woocommerce_after_cart_table`) is het geteste pad. Gebruikt de site de
  blokkenmand: kleine `ExperimentalOrderMeta`-slotfill (ui) die `window.BPSCrossSell.openFromCart()` aanroept.
- **Herbestelling met dezelfde borduurkaart:** de site belooft dat, de kassa heeft er nog geen mechanisme voor (geldt ook voor cross-sell).
- **Screenshots** staan in `docs/extra/screenshots/` (opdracht), niet `docs/screenshots/extra/` zoals SPEC §5 zegt.

---

## 5. Voor de eigenaar (geen code)

Zie `docs/extra/RAPPORT.md` §5 voor de beslissingen. Technisch belangrijk vóór livegang:
1. Conditional Extra Fees: categorie `naamstroken` en de verborgen laser/kader/PVC-producten uitsluiten van de € 45-regel.
2. Optioneel verzendklasse "brievenbus" aanmaken en kiezen in de naamstrook-instellingen (`woo.shippingClassSlug`).
3. Na activeren één keer "Maak ontbrekende producten aan" (cross-sell) en de prijzen van leder/kader/PVC nakijken.
