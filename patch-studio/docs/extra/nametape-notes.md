# Naamstroken — notities van de bouwer (18-11-2026)

Bindend: `docs/extra/SPEC-EXTRA.md` §1, §3–§5. Wat in bestaande code nodig is: `docs/handoff/extra-products-needs.md` ("Van nametape").

## Bestanden
| Pad | Wat |
|---|---|
| `includes/nametape/class-module.php` | Component: registreert Cart, Product, Order_Tools, Admin (beheer), Shortcode + Rest (enkel als `enabled`), `bps_public_config` → `extras.nametape`, cron. Zonder WooCommerce: niets. |
| `class-config.php` | `nametape.json` + optie `bps_nametape_settings`, public config van de pagina. |
| `class-fit.php`*, `class-pricing.php`*, `class-validator.php`* | Pure tweelingen van `fit.js` / `pricing.js` (vectors). |
| `class-production.php`* | Snapshot → batch, productierijen (letterhoogte mm, status, bloedgroepstrook), stijl, CSV (BOM, `;`, CSV-injectie-veilig). |
| `class-settings-schema.php`* | Sanering beheerformulier + minimale diff t.o.v. `nametape.json` (lijsten worden enkel opgeslagen als ze echt wijzigen). |
| `class-product.php` | Verborgen product "Naamstroken op maat", eigen categorie, directe "In winkelmand" geweigerd. |
| `class-cart.php` | Eén regel per lijst, absolute lijnprijs (prio 25), fee, aantal vergrendeld (klassiek + Store API), weergave, ordermeta, ordernotitie. |
| `class-rest.php` | `POST /nametape/quote`, `POST /nametape/cart` (multipart, GD-hercodering preview), `GET /nametape/cart/{key}`. |
| `class-shortcode.php` | `[patch_studio_naamstroken formaat="" stof="" compact="no"]`. |
| `class-order-tools.php` | Knoppen bij de bestelregel, `admin-post` `bps_nt_csv` / `bps_nt_print` (nonce + `edit_shop_orders`), cron `bps_nt_cleanup`. |
| `class-admin.php`, `views/admin-page.php` | WooCommerce › Patch Studio · Naamstroken (status, testquote, pagina aanmaken, alle instellingen, herstel). |
| `views/print.php` | Productielijst / digitale proef (A4, afdrukken als PDF). |
| `assets/src/nametape/*.js`, `nametape.css` | Front-end; `print.js` tekent de productielijst met dezelfde renderer. |

\* puur (laadbaar met `BPS_TESTING`).

## Tests
- `npm test` (fit 49, parse 20, pricing 31 checks), `php tests/nametape/run-php.php` (102: vectors, validator, productie/CSV, instellingen).
- `node tests/nametape/e2e-mobile.mjs`: 390 × 844 en 360 × 740 (ook toetsenbord-simulatie), payload in 3 schermen, 25 namen met 2 te
  lange → 2 rode rijen → één klik "Kies smalle letter (lost 2 namen op)" lost alles op, FR volledig, desktop. Schermafdrukken in
  `docs/extra/screenshots/nametape-*.png`.
- WordPress (handmatig, lokale testsite, module tijdelijk via `bps_components`): pagina → winkelmand via REST → Store API: lijn € 4,50 excl.
  + € 0,95 btw, aantal 1 vergrendeld, geen fee; bestelling met "POLITIE" → ordernotitie; CSV-download en productielijst; beheerpagina bewaren
  zonder wijziging → lege optie. Testdata daarna verwijderd.

## Aannames
- Letterhoogte = hoogte van een hoofdletter (cap height), zoals de passingstoets rekent; staat zo op de productielijst.
- Productielijst gebruikt de config van het moment van afdrukken voor labels/kleuren; de bestelde keuzes (id's) komen uit de snapshot.
- Een uitgeschakelde module laat bestaande winkelmandregels en bestellingen werken; enkel pagina en REST verdwijnen.
- Instellingen-pagina is in het Nederlands (eigenaar); klantteksten NL + FR.
- Geen nep-schaarste of reviews; het tegenstuk-vinkje staat nooit vooraf aan; beloftes (proef, levertijd) enkel als de eigenaar ze invult.

## Open voor de eigenaar
Prijzen per formaat, instelkost (uit), staffel samen met patches (aan), stoffen op voorraad (camo/reflecterend uit), borduurlettertypes,
brievenbus-verzendklasse, Conditional Extra Fees-uitsluiting van de categorie `naamstroken`.

## Review correctheid (18-11)
- REST `/nametape/*`: ook een JSON-body wordt nu begrensd (413 boven ~260 kB), niet enkel het multipart-veld.
- "Namen wijzigen" (replace): de oude lijst wordt pas verwijderd nádat de nieuwe in de mand staat; de prijscontext laat de oude regel weg.
- `check_cart_items`: een lijst die valideert maar geen prijs krijgt (zou € 0 kosten) blokkeert de kassa met de link "Namen wijzigen".
- Ordermeta `_bps_nt_unit_excl`/`_bps_nt_line_excl`: valt terug op een verse serverprijs als de prijs in dit verzoek nog niet berekend was.
- `parse.js`: geplakte invoer wordt op 512 kB afgekapt (maxRows blijft 250).
- Cross-sell: "naam achterop" vraagt exact één naam per stuk over alle maatregels samen (normalize + mandcontrole op het batchtotaal).

## Gebruiksgemak-review (18-11, Playwright 390×844 + 1280×860)
- 10 namen: plakken ≈ 15 s, met de hand typen ≈ 45–60 s → haalbaar binnen 1 minuut.
- Opgelost: getypte namen in het lijstveld werden na 400 ms pauze al overgenomen en het veld klapte dicht (2e naam verving de 1e). Nu: typen = niets overnemen; plakken neemt meteen over; knop "Gebruik deze namen" of "Verder" neemt de lijst over. Een al getypte naam staat al als eerste regel in het lijstveld.
- Opgelost: op scherm 3 was "In winkelmand" grijs zonder zichtbare reden (vinkje stond buiten beeld). Nu toont de balk wat ontbreekt en brengt een tik op de knop (aria-disabled) je naar het vinkje of de rode strook; daarna toont de balk het totaal.
- Copy: "Meerdere namen? Typ of plak je lijst", in lijstmodus "Namen toevoegen"; uitleg één naam per regel.
