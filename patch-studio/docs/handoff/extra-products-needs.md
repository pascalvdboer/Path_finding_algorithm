# Handoff: wat de extra producten (naamstroken + cross-sell) nodig hebben in bestaande code

Opgesteld door de architect van `docs/extra/SPEC-EXTRA.md` (17-11-2026). De bouwers "nametape" en "crosssell" vullen enkel hun eigen
sectie onderaan aan. Alles hieronder is **klein** en **achterwaarts compatibel**; zonder deze wijzigingen werken de modules in de
dev-pagina's en tests, maar niet (volledig) in WordPress.

Prioriteit: **P1** = zonder dit draait de module niet in WordPress · **P2** = nodig voor correcte prijs/flow · **P3** = mooi meegenomen.

## Voor core (`includes/class-plugin.php`, `uninstall.php`, `admin/class-settings-page.php`)

1. **P1 — componenten registreren.** Voeg toe aan `Plugin::COMPONENTS` (niet aan `CORE_COMPONENTS`, ze hebben WooCommerce nodig):
   ```php
   'BorduurPatchStudio\Nametape\Module',
   'BorduurPatchStudio\Crosssell\Module',
   ```
   De autoloader vindt ze al (`includes/nametape/class-module.php`, `includes/crosssell/class-module.php`). Beide hebben `register()`
   en `activate()` / `deactivate()` (product aanmaken, cron `bps_nt_cleanup` plannen/afmelden). Bestaat de klasse niet, dan slaat
   `boot()` ze nu al over.
2. **P2 — uninstall.** Bij deïnstalleren ook verwijderen: opties `bps_nametape_settings`, `bps_nametape_product_id`,
   `bps_nametape_page_id`, `bps_crosssell_settings`, `bps_cs_stats`; cron `bps_nt_cleanup` afmelden. Bij "Alles verwijderen": ook map
   `uploads/patch-studio/nametape/` en de producten met meta `_bps_nametape_product` of `_bps_cs_product` naar de prullenbak.
3. **P3 — tabs filterbaar.** `Settings_Page::tabs()` door `apply_filters( 'bps_settings_tabs', $tabs )` halen (en de render/save-routing
   een callback per tab laten toestaan: `['label','intro','reset','render' => callable, 'save' => callable]`). Dan verhuizen de eigen
   beheerpagina's "Patch Studio · Naamstroken" en "Patch Studio · Cross-sell" naar tabs. Tot dan staan ze als eigen submenu onder WooCommerce.
4. **P3 — statusblok.** Filter `bps_status_checks` (`array $checks` met `['label','ok','message','link']`) zodat de modules hun status
   (product bestaat, geen € 45-fee op naamstroken, gekoppelde dragers koopbaar) in het bestaande statusblok tonen.

## Voor de build (`scripts/build.mjs`, `package.json`)

5. **P1 — twee build-entries** in `entries`:
   ```js
   { in: 'nametape/index.js',  out: 'patch-studio-nametape.js',  globalName: 'BPSNametape' },
   { in: 'crosssell/index.js', out: 'patch-studio-crosssell.js', globalName: 'BPSCrossSell' },
   ```
   en in `BUDGET`: `'patch-studio-nametape.js': 30 * 1024`, `'patch-studio-crosssell.js': 25 * 1024` (gzip).
   (Ontbreekt de map nog, dan slaat de build ze al over.)
6. **P2 — PHP-tests opnemen**: `"test:php": "php tests/pricing/run-php.php && php tests/nametape/run-php.php && php tests/crosssell/run-php.php"`.
   `npm test` pikt de node-tests al op via de glob.

## Voor woo (`includes/woo/class-cart.php`, REST `/cart`)

7. **P2 — pool uitbreidbaar.** In `Cart::pool_qty()` het resultaat door een filter halen:
   `return (int) apply_filters( 'bps_cart_pool_qty', $pool, $items );`
   De modules tellen er naamstroken (als `tiers.pooledWithPatches`) en patches in cross-sell-regels bij, zodat de patchstaffel over alles
   samen klopt. Zonder dit tellen naamstroken/cross-sell wel de patches mee, maar de patchregels niet omgekeerd.
8. **P2 — fee-hook zichtbaar maken.** Geef in `Cart::add_fees()` op het einde `do_action( 'bps_cart_fees_added', $cart, $charged_groups )`
   (of maak een publieke `Cart::charged_fee_groups(): array`), zodat cross-sell de borduurkaart enkel toevoegt als er geen `bps`-regel meer is,
   zonder op fee-id's te moeten raden. (Terugval nu: zelfde fee-id `bps-fee-<group>`; WooCommerce weigert een dubbele id.)
9. **P3 — `/cart`-antwoord.** `cartItemKey` meesturen (staat in SPEC §6.2, controleer of het er is) en het design-`token` (security-4 kan
   een nieuw token geven) — de UI geeft beide door aan cross-sell.

## Voor ui (`assets/src/ui/flows.js`, `assets/src/ui/*` lege staat, `assets/css`)

10. **P1 — cross-sell openen vóór doorverwijzen.** In `order()` na een geslaagde `api.addToCart`, vóór de melding/redirect:
    ```js
    let cs = 'none';
    if (window.BPSCrossSell && config.extras && config.extras.crosssell && config.extras.crosssell.enabled) {
      try { cs = await window.BPSCrossSell.open({ root: ctx.root, designId: up.designId, token: res.token || up.token, ref: up.ref, cartUrl: res.cartUrl }); }
      catch (e) { cs = 'none'; }
    }
    // daarna het bestaande gedrag; als cs !== 'none' niet automatisch doorverwijzen (de klant koos al in het paneel)
    ```
    `open()` lost altijd op (max. 4 s als er geen kaarten komen), nooit een throw die de bestelling blokkeert.
11. **P2 — rijker event.** `bps:added-to-cart`-detail uitbreiden met `token`, `cartItemKey`, `technique`, `sizeCm`, `qty`, `redirect`
    (bool). Wordt door cross-sell als terugval gebruikt.
12. **P2 — knop naar naamstroken.** In de lege staat (stap 0, onder "Start met tekst"): als `config.extras.nametape.enabled` en
    `config.extras.nametape.pageUrl`, een secundaire link
    NL "Alleen een naam of ID? **Naamstroken met klittenband** →" / FR "Seulement un nom ou un ID ? **Bandes nominatives avec scratch** →".
    (`extras` wordt door de modules zelf via `bps_public_config` toegevoegd; ontbreekt het, dan geen knop.)
13. **P3 — lettertype.** Als de eigenaar een stencil/militair borduurlettertype bevestigt: OFL-woff2 in `assets/fonts/` + regel in
    `LICENSES.txt` (ui is eigenaar van `assets/fonts/**`).

## Voor render (optioneel)

14. **P3** — `drawGarment` (en eventueel `placePatch`) exporteren via `render/index.js` en in `main.js` beschikbaar maken als
    `window.BPS.render = { drawGarment, placePatch }`. Cross-sell gebruikt die enkel als `useStudioRenderer` aan staat; eigen mockups
    blijven de standaard.

## Voor production (optioneel, fase 2)

15. **P3** — Digitale proef voor naamstroken: de proefmail/goedkeuringsflow ook laten werken voor orderregels met `_bps_nt_batch`
    (bijlage: de printpagina `admin-post.php?action=bps_nt_print` als PDF of link). V1 gebruikt de printpagina handmatig als proef.

## Voor de eigenaar (geen code)

16. Conditional Extra Fees: controleer dat de categorie `naamstroken` en de verborgen laser/kaderproducten **niet** onder de regel
    "Borduurkaart en Instelkosten" vallen.
17. Optioneel een verzendklasse "brievenbus" aanmaken en in de naamstrook-instellingen kiezen.

---

## Van nametape
_(bouwer vult aan: concrete aannames, gevonden afwijkingen, extra wensen)_

## Van crosssell
_(bouwer vult aan)_
