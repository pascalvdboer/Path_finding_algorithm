# Cross-sell — notities van de bouwer (18-11-2026)

Bindend: `docs/extra/SPEC-EXTRA.md` §2. Wat in bestaande code nodig is: `docs/handoff/extra-products-needs.md`, sectie "Van crosssell".

## Wat er staat

| Laag | Bestanden | Rol |
|---|---|---|
| Puur (JS = PHP, gedeelde vectors) | `fit`, `rules`, `pricing`, `offers`, `messages` (+ PHP `class-lines.php`) | pastoets, kaartkeuze, prijs per stuk, kaarten, teksten NL/FR; `Lines`: verzoek normaliseren, mandcontext, eigen fees |
| WordPress | `class-module`, `class-service`, `class-cart`, `class-rest`, `class-cart-prompt`, `class-stats`, `class-admin`, `class-products`, `class-config` | hooks, kaarten met echte WooCommerce-prijzen, gekoppelde mandregels, REST, mandregel, tellers, beheerpagina |
| Front-end | `index.js` (`open`, `openFromCart`, `mount(el, ctx)`), `panel.js`, `mockups.js`, `api.js`, `cart-prompt.js`, `crosssell.css` | `<dialog>`-paneel (gsm: bottom sheet, desktop: 420 px rechts), canvas-mockups van het echte patchbeeld |
| Dev | `dev/crosssell.html` | 4 voorbeeldontwerpen, NL/FR, paneel of inline, maand; "Toevoegen" toont de payload voor `POST /crosssell/cart` |

`mount(el, ctx)`: `ctx.offers` (serverantwoord) of `ctx.design` + `cfg/main/catalog/patchUnit` (offline). Met `ctx.dialog: true`
opent het paneel als modale dialog en lost op met `'added' | 'declined'`.

## Prijs en eerlijkheid
- Kaartprijs = per stuk incl. btw, **inclusief patch en aanbrengen** (kader: incl. patch; leder/PVC: incl. btw), met opbouw
  ("Pet € 18,10 · patch € 4,16 · aanbrengen € 4,11"). De server rekent opnieuw bij toevoegen (`Lines::normalize` + `Offers::card_price`)
  en bij elke mandberekening (`Cart::set_prices`, prio 30, absoluut). Getest: mandprijs = kaartprijs (`run-wp.php`).
- Hoodie in maten: staffel op het totaal van die toevoeging (`batch`), zoals in het paneel.
- Borduurkaart: nooit dubbel (zelfde fee-id als woo; enkel als er geen patchregel van die groep meer is). PVC-mal sleutelhanger één
  keer per bestelling, gedeeld met een PVC-patch **enkel** met `sharedMold` (standaard uit).
- Niets vooraf aangevinkt, "Nee, bedankt" even groot als "Toevoegen", geen aftelklok/schaarste/"meest gekozen" (Playwright + tekst-test).
- Laser/kader/PVC-producten zijn niet los koopbaar zonder ontwerp (`woocommerce_add_to_cart_validation`); gewone petten wel.
- Pastoets opnieuw vóór de kassa: ontwerp weg, item uit of `hide` → regel weg met uitleg; PVC-minimum/stappen → foutmelding.

## Tests (alle groen op 18-11)
- `node --test tests/crosssell/*.test.js` (18): vectors fit/rules/price (incl. 02 §3b), kaarten per voorbeeldontwerp, JS = PHP, WP-laag.
- `php tests/crosssell/run-php.php` (208 controles, incl. `Lines`), `php tests/crosssell/run-wp.php` (17, WordPress/WooCommerce-stubs).
- `NODE_PATH="$(npm root -g)" node tests/crosssell/e2e-panel.mjs` (31): kaarten (25 st. → pet, hoodie, muts; foto → kader, leder, pet,
  geen PVC; PVC 50 → pet, PVC, hoodie), echte modale dialog, Esc = Nee bedankt + focus terug, mockups ≤ 25 ms, FR zonder NL-resten,
  geen horizontale scroll op 390 px.
- `php -l` op alle eigen PHP-bestanden.

Schermafdrukken in `docs/extra/screenshots/`: `crosssell-gsm-paneel.png`, `crosssell-gsm-paneel-onder.png`,
`crosssell-gsm-hoodie-maten.png`, `crosssell-gsm-fr-pvc.png`, `crosssell-desktop-paneel.png`, `crosssell-desktop-foto-kader.png`.

## Aannames en open punten
- Mockup-performantie: de preview wordt vóór het openen gedecodeerd (`img.decode()`) en de lasergravure vooraf berekend; zonder dat
  kostte de eerste lederkaart met een grote JPEG ± 40 ms.
- Ordermeta `_bps_cs_design_id` i.p.v. `_bps_design_id` (zie handoff: anders behandelt productie de pet als patchregel).
- Blokkenmand: de mandregel is best-effort (zie handoff). Klassieke mand werkt.
- Bedragen (aanbrengen, leder, kader, PVC) zijn [eigenaar]-standaarden uit `config/crosssell.json`, aanpasbaar op de beheerpagina.

## Gebruiksgemak-review (18-11)
- Verleidelijk maar niet opdringerig: niets vooraf aangevinkt, "Nee, bedankt" even groot, geen schaarste. In orde.
- Aangepast: de knop zegt nu hoeveel stuks er bijkomen ("Voeg 25 stuks toe" i.p.v. "Toevoegen"), zodat "Samen € 659,25" geen verrassing is. JS + PHP-teksten gelijk (cs.addN).
