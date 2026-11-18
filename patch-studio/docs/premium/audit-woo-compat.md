# Audit woo-compat: WooCommerce-compatibiliteit (premium-niveau)

Auditor: woo-compat · datum: 17-11-2026 · geen code gewijzigd.

## Hoe getest
- Code gelezen: `includes/woo/*` (cart, order, product, emails, integration, privacy), `includes/class-pricing.php`,
  `includes/admin/class-order-production.php`, de hoofdplugin (header + HPOS-verklaring).
- Eigen testsite (los van de gedeelde site op :8899): `BPS_WP_DIR=<scratch>/wcwp BPS_WP_PORT=8977 tests/e2e/start-wp.sh`
  → WordPress + **WooCommerce 11.3.0**, SQLite, HPOS aan, klassieke kassa, BE 21 %.
- Scripts (WP-CLI `eval-file`) + echte HTTP-flow (`/token` → `POST /designs` → `POST /cart` → `wc-ajax=get_refreshed_fragments`
  en Store API `/wc/store/v1/cart`).
- Woo Discount Rules (gratis versie, wordpress.org) geïnstalleerd en de broncode van de filters nagelezen.
- `npm run test:php`: 47 vectoren, 652 checks, 0 fouten.

## Wat goed zit (geen bevinding)
- HPOS: verklaring `custom_order_tables` + `cart_checkout_blocks`; alle orderlees/schrijfacties via WC CRUD; orderlijstkolom voor HPOS én legacy.
- Blok-mand/kassa: Store API-afbeelding, item data, min/stap via `store_api_product_quantity_*`, knoplabel via `registerCheckoutFilters`, Store API add-item geweigerd.
- Woo Discount Rules: de twee gebruikte filters bestaan (v2: `DiscountCalculator.php:474`, `ManageDiscount.php:1457`, `Helper.php:113`) en sluiten onze regels uit van productkortingen, cartkortingen en hoeveelheidstelling: geen dubbele staffel op de lijn zelf.
- Fees: belast, standaardtarief, per groep of per ontwerp, legacy-skip.

## Gemeten resultaten (kern)
| Scenario | Studio-quote | WooCommerce |
|---|---|---|
| 10 × geborduurd 8 cm, prijzen **excl.** ingevoerd | unit 4,94 excl. | subtotaal 49,40 excl. ✔ |
| idem, prijzen **incl.** ingevoerd (`woocommerce_prices_include_tax=yes`) | unit 4,94 excl. | subtotaal **40,83** excl. (-17,4 %) ✘ |
| idem, klant btw-vrijgesteld (B2B, NL) | toont 126,32 incl. 21 % | totaal **104,40** ✘ (verschil getoond vs. betaald) |
| mini-cart-fragment op een nieuw verzoek | — | "10 × **€ 0,00**", subtotaal 59,77 ✘ |
| Store API `/cart` item prices | — | `regular_price: "0"`, `sale_price: "598"` (vreemd) |
| Voltooid-mail | — | bevat "Je krijgt eerst je digitale proef… Pas na jouw akkoord starten we de productie." ✘ |
| Opnieuw bestellen (voltooide order) | — | mand blijft leeg + fout "Een patch op maat stel je samen…" + "1 artikels … niet beschikbaar" ✘ |

## Bevindingen

### woo-compat-1 · KRITIEK · Prijs fout als de winkel prijzen incl. btw invoert
- Bestanden: `includes/woo/class-cart.php` (`set_prices`, r. 194-218), `includes/class-pricing.php` (`cart_unit_excl`).
- Probleem: `set_price()` krijgt altijd het bedrag **excl.** btw. Staat WooCommerce op "Prijzen ingevoerd inclusief btw" (veel Belgische B2C-winkels), dan rekent Woo dat bedrag als incl. → elke patch 17,4 % te goedkoop (gemeten: 49,40 → 40,83 excl.). Fees blijven wel correct (Woo-fees zijn altijd excl.), dus de mand is intern inconsistent. Er is geen controle of waarschuwing.
- Voorstel: in `set_prices()` `wc_prices_include_tax()` lezen en dan de excl.-prijs omrekenen met het **basistarief** van de producttaxklasse (`WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) )` + `WC_Tax::calc_exclusive_tax`-inverse, d.w.z. `unit × (1 + base rate)`), zodat Woo zelf de juiste excl./incl. afleidt (ook bij vrijstelling en `woocommerce_adjust_non_base_location_prices`). Daarnaast een gezondheidscheck in de admin (instellingen-tab) die waarschuwt als `config.vat.rate` ≠ het Woo-basistarief.
- Acceptatie: PHPUnit/WP-CLI-test met `prices_include_tax=yes` én `no`: voor 10 × geborduurd 8 cm is `WC()->cart->get_subtotal()` in beide gevallen 49,40 (±0,01) en `get_total()` identiek; `_bps_unit_excl` op de orderlijn = 4,94 in beide modi.

### woo-compat-2 · HOOG · Mini-cart / Elementor menu-cart toont € 0,00 per stuk
- Bestanden: `includes/woo/class-cart.php` (`register`, `set_prices`), `includes/woo/class-product.php` (`ensure`: regular price 0).
- Probleem: de prijs wordt enkel gezet in `woocommerce_before_calculate_totals`. Op verzoeken waar Woo de totalen niet herrekent (mini-cart-fragmenten, Elementor Pro Menu Cart, thema-headers, "toegevoegd"-popups) staat het product op de basisprijs 0: gemeten "10 × € 0,00" met subtotaal 59,77. Store API geeft `regular_price 0` / `sale_price 5,98`, wat kortingsplugins ("bereken vanaf reguliere prijs"), feeds en prijs-badges in de war brengt.
- Voorstel: dezelfde prijsberekening ook uitvoeren in `woocommerce_get_cart_item_from_session` (prio 20, per lijn) of in `woocommerce_cart_loaded_from_session` (hele mand, want de pool hangt van de mand af), en naast `set_price()` ook `set_regular_price()` (en `set_sale_price('')`) zetten.
- Acceptatie: na `POST /cart` geeft een nieuw `POST /?wc-ajax=get_refreshed_fragments` in `div.widget_shopping_cart_content` "10 × € 5,98" (incl. weergave); Store API-item heeft `regular_price == price`; e2e-check toegevoegd aan `tests/e2e/08-cart-edges.e2e.cjs` (of een nieuwe 09-test).

### woo-compat-3 · HOOG · "Opnieuw bestellen" werkt niet (herbestellen = kernscenario bij patches)
- Bestanden: `includes/woo/class-product.php` (`block_direct_add`), `includes/woo/class-order.php`, `includes/woo/class-cart.php`.
- Probleem: Woo's knop "Opnieuw bestellen" roept `woocommerce_add_to_cart_validation` aan; onze blokkering (prio 5) weigert het product zonder `bps`-data → mand leeg, twee foutmeldingen (gemeten). Clubs en bedrijven bestellen dezelfde patch vaak na; nu moeten ze opnieuw ontwerpen en betalen ze opnieuw borduurkaart/instelkosten.
- Voorstel: `woocommerce_order_again_cart_item_data` vullen vanuit de orderlijn (`_bps_design_id`, `_bps_payload`, `_bps_technique_id`, `_bps_size_cm`, `_bps_options`, `_bps_preview_url`); is het ontwerp opgeruimd, dan een nieuw `bps_design` aanmaken uit `_bps_payload` + preview (status `ordered`-kopie, verwijzing naar de oorspronkelijke ref). Validatie laten slagen als `bps` aanwezig is. Nieuwe instelling `fees.waiveOnReorder` (standaard aan): eenmalige kosten (borduurkaart/mal) niet opnieuw aanrekenen voor een herbestelling van hetzelfde ontwerp, met cartregel "Herbestelling van PS-2026-0007 – geen nieuwe borduurkaart nodig" / "Recommande de PS-2026-0007 – pas de nouvelle carte de broderie". Optioneel `woocommerce_valid_order_statuses_for_order_again` + "processing" zodat ook lopende orders herbesteld kunnen worden, en in Mijn account de preview tonen.
- Acceptatie: WP-CLI/e2e: voltooide order met 1 patchlijn → "Opnieuw bestellen" → mand bevat 1 lijn met dezelfde ref/techniek/maat/opties, prijs = serverprijs, geen foutmelding; met `waiveOnReorder` aan ontbreekt de fee "Borduurkaart en instelkosten"; werkt ook nadat `Cleanup` de bestanden verwijderde.

### woo-compat-4 · HOOG · Geannuleerde / terugbetaalde / mislukte orders stoppen de productie niet
- Bestanden: `includes/woo/class-order.php` (`classic_processed`, `mark_ordered`), `includes/admin/class-order-production.php` (`handle_proof_response`, `ajax_send_proof`).
- Probleem: het ontwerp wordt al `ordered` bij `checkout_order_processed` (ook bij onbetaalde overschrijving) en er is geen enkele hook op `cancelled`, `refunded`, `failed` of `woocommerce_order_refunded`. Gevolg: een proeflink van een geannuleerde of terugbetaalde order kan nog goedgekeurd worden ("we starten meteen met de productie"), de productiefiche toont geen waarschuwing, en het ontwerp blijft onder de lange bewaartermijn voor bestellingen.
- Voorstel: luisteren naar `woocommerce_order_status_cancelled|refunded|failed` en `woocommerce_order_fully_refunded` / `woocommerce_order_partially_refunded` (lijn volledig terugbetaald) → proefstatus `cancelled`, proeftokens ongeldig, ordernotitie, rode badge op fiche en orderlijstkolom. `handle_proof_response` en `ajax_send_proof` weigeren als de order niet in `wc_get_is_paid_statuses()` + `on-hold` zit, met klanttekst "Deze bestelling is geannuleerd. Vragen? Neem contact met ons op." / "Cette commande a été annulée. Une question ? Contactez-nous." Bij `failed` / automatisch geannuleerd onbetaald: ontwerpstatus terug naar `carted` (de rangregel in `set_status` moet hiervoor een expliciete uitzondering krijgen).
- Acceptatie: test: order → proef verstuurd → order op "Terugbetaald" → proeflink geeft 410 + bovenstaande tekst, ordernotitie aanwezig, fiche toont "Geannuleerd"; verzenden van een proef op die order geeft een AJAX-fout; bestaande e2e 05 blijft groen.

### woo-compat-5 · HOOG · Studioprijs gebruikt een vaste 21 %, Woo rekent anders (B2B btw-nummer, OSS, vrijstelling)
- Bestanden: `includes/class-pricing.php` (`compute`: `config.vat.rate`), `includes/rest/class-routes.php` (`/quote`, `/cart`-antwoord).
- Probleem: de getoonde incl.-prijs komt uit `config.vat.rate`, niet uit Woo's belastingtabellen of de klantstatus. Gemeten: btw-vrijgestelde klant (EU-btw-plugin zet `is_vat_exempt`) ziet in de studio 126,32 maar betaalt 104,40; een Franse consument onder OSS (20 %) ziet ook een verkeerd bedrag. `docs/research/08-juridisch-i18n-a11y.md` (r. 242) zegt zelf: "Toon geen bedrag dat de kassa niet waarmaakt."
- Voorstel: in de REST-quote het tarief bepalen met `WC_Tax::get_rates( '', WC()->customer )` (0 bij `WC()->customer->is_vat_exempt()`), dat als `vatRate` + `vatMode: 'incl'|'excl'|'exempt'` teruggeven, en het ui-team via `docs/handoff/premium-ui-needs.md` het label laten tonen ("excl. btw – btw verlegd" / "HTVA – autoliquidation"). Admin-gezondheidscheck: waarschuw als `config.vat.rate` ≠ BE-standaardtarief in Woo.
- Acceptatie: met een vrijgestelde klant is `quote.totals.inclVat` van `POST /cart` gelijk aan `WC()->cart->get_total('edit')` ± 0,01; met shipping-land FR en een 20 %-regel idem; standaardgeval BE blijft 126,32.

### woo-compat-6 · MIDDEL · Verzendschatting staat dubbel (config ↔ verzendzones), product heeft geen gewicht/verzendklasse
- Bestanden: `includes/class-pricing.php` (r. 436-458), `config/defaults.json` (`shipping`), `includes/woo/class-product.php` (`ensure`).
- Probleem: de studio rekent met `shipping.flat=10` en `freeFromInclVat=150` uit de eigen instellingen; Woo gebruikt de verzendzones. Past de eigenaar één kant aan, dan beloven studio en mand iets anders ("nog € 23 tot gratis verzending" klopt niet). Het product heeft geen gewicht en geen verzendklasse, dus gewichtsgebaseerde methodes (Sendcloud, bpost, Table Rate) rekenen 0 kg.
- Voorstel: drempel en vast tarief ophalen uit de Woo-zone die past bij het land van de klant (`WC_Shipping_Zones::get_zone_matching_package`, methodes `free_shipping.min_amount` en `flat_rate.cost`), met de config enkel als terugval; anders minstens een admin-melding bij verschil. Instellingen "Verzendklasse" en "Gewicht per stuk (g) per techniek" die `Product::ensure/repair` en `set_prices` (via `set_weight` op het cart-product) toepassen.
- Acceptatie: zet in Woo gratis vanaf € 200 → `/quote` geeft `remainingForFreeInclVat` op basis van 200 zonder de plugin-instelling te wijzigen; een mand met 100 patches van 8 g geeft `WC()->cart->get_cart_contents_weight()` = 0,8 kg; verzendklasse zichtbaar in het product.

### woo-compat-7 · MIDDEL · "Wat gebeurt er nu?"-blok staat ook in de voltooid-, factuur- en notitiemail
- Bestanden: `includes/woo/class-emails.php` (`after_order_table`, r. 99-118).
- Probleem: blacklist van 4 mail-id's; daardoor krijgt de klant in "Je bestelling is voltooid" (na levering) nog "Je krijgt eerst je digitale proef … Pas na jouw akkoord starten we de productie" (gemeten). Ook in `customer_invoice` en `customer_note`. Dat oogt amateuristisch en verwarrend.
- Voorstel: whitelist `customer_processing_order`, `customer_on_hold_order` (filterbaar via `bps_next_steps_email_ids`). Voor `customer_completed_order` eventueel een eigen korte tekst ("Je patches zijn onderweg. Opnieuw bestellen kan met één klik in je account." / FR).
- Acceptatie: renderen van de voltooid-, factuur- en notitiemail voor een order met patchlijn bevat de zin "Wat gebeurt er nu" niet; verwerking- en in-de-wachtmail bevatten hem wel (unit-test op `get_content_html()`).

### woo-compat-8 · MIDDEL · Kortingen: subtotaalcondities en gewone coupons tellen patches mee
- Bestanden: `includes/woo/class-cart.php` (`wdr_exclude`), nieuw: coupon-filter.
- Probleem: WDR sluit onze lijnen uit van productkortingen, maar de conditie "Winkelwagen-subtotaal" (`calculate_from: from_cart`, standaard) gebruikt `WC()->cart->get_subtotal()` (WDR `Woocommerce::getCartSubtotal`) en telt de patchwaarde mee: een regel "10 % op textiel vanaf € 150" wordt ontgrendeld door patches. Gewone Woo-coupons (bv. "WELKOM10") geven bovenop de patchstaffel nog eens korting: de staffel is al een volumekorting.
- Voorstel: instelling `woo.coupons` = `none` | `fixed_only` | `all` (standaard `fixed_only`) via `woocommerce_coupon_is_valid_for_product` (+ `woocommerce_coupon_get_discount_amount` voor vaste mand-coupons); WDR-instructie in de admin ("gebruik ‘Bereken vanaf: filter’ in subtotaalregels") en een compatibiliteitscheck die WDR-regels met `from_cart` meldt. Optioneel filter `bps_wdr_count_in_subtotal`.
- Acceptatie: met coupon "WELKOM10" (10 %) en `woo.coupons=fixed_only` blijft de patchlijn ongewijzigd en krijgen andere producten wel korting; met WDR-regel "10 % bij subtotaal ≥ 150 (from_filter)" en enkel patches in de mand wordt de regel niet toegepast (e2e met WDR geïnstalleerd).

### woo-compat-9 · MIDDEL · Product Add-ons (Woo / YITH): globale groepen raken het patchproduct
- Bestanden: `includes/woo/class-product.php` (`ensure`, `repair`).
- Probleem: globale add-on-groepen ("op alle producten") hangen ook aan "Patch op maat". Een **verplicht** globaal veld laat de interne `add_to_cart` falen ("Toevoegen aan je winkelmand is niet gelukt"); prijsverhogende add-ons tellen mee vóór onze prijsberekening of worden stil weggegooid. Niet getest (betaalde plugin), wel bekend gedrag van PAO.
- Voorstel: in `ensure()`/`repair()` op ons product `_product_addons_exclude_global = 1` zetten (Woo Product Add-ons) en de YITH-tegenhanger via hun filter uitsluiten; in `add_design()` bij falen de werkelijke validatiemelding loggen (debug) zodat de eigenaar de oorzaak ziet.
- Acceptatie: met Woo Product Add-ons + globale verplichte groep slaagt `POST /cart` en is de lijnprijs identiek aan `/quote`; product-meta `_product_addons_exclude_global` = 1 na activatie.

### woo-compat-10 · MIDDEL · Meertalig en multi-currency (WPML/WCML, Polylang, CURCY) niet voorbereid
- Bestanden: `includes/woo/class-config.php` (`product_id`), `includes/woo/class-cart.php` (`fee`), plugin-root (ontbrekend `wpml-config.xml`, staat in de bestandsboom van `docs/SPEC.md` r. 180 bij [core]).
- Probleem: product-ID niet vertaald (`wpml_object_id` / `pll_get_post`), geen `wpml-config.xml`, Woo-ordermails voor een Franstalige studio-klant gaan in de sitetaal (enkel onze eigen mails wisselen van taal). Bij multi-currency krijgt de lijn een EUR-bedrag dat de valutaplugin al dan niet omzet, terwijl fees altijd EUR blijven, en de studio EUR toont: gemengde valuta.
- Voorstel: `wpml-config.xml` (CPT's niet vertalen, admin-texts), `Config::product_id()` door `wpml_object_id`/`pll_get_post`; taal van de lijn als ordermeta `_bps_locale` + `woocommerce_email_setup_locale`/`switch_to_locale` voor klantmails van die order; als `get_woocommerce_currency() !== config.currency`: bedragen omzetten via filter `bps_convert_amount` met adapters voor WCML (`wcml_raw_price_amount`) en CURCY, anders de kassaknop blokkeren met melding + admin-waarschuwing.
- Acceptatie: met WCML en tweede valuta USD zijn lijn en fee in dezelfde valuta en klopt `/quote` met de mand; met Polylang FR komt het vertaalde product in de mand; verwerkingsmail van een FR-studio-order is in het Frans.

### woo-compat-11 · MIDDEL · Admin-orders: handmatig toegevoegde patch = € 0, offerte → bestelling ontbreekt
- Bestanden: `includes/woo/class-quotes.php`, `includes/woo/class-product.php`.
- Probleem: voegt de winkelier in de admin "Patch op maat" toe aan een (telefoon/B2B-)order, dan is de prijs 0 en ontbreken ontwerp, fiche en proefflow. Een goedgekeurde offerte (`bps_quote`) kan niet met één klik een order/betaallink worden; premium-concurrenten doen dat wel.
- Voorstel: knop "Maak bestelling + betaallink" op de offertedetail die een order aanmaakt met dezelfde lijnmeta als `Order::line_item_meta` (prijs uit `Pricing::quote` of handmatige offerteprijs) en `$order->get_checkout_payment_url()` naar de klant mailt; `woocommerce_ajax_add_order_item_validation` weigert ons product zonder ontwerp met een duidelijke melding.
- Acceptatie: vanuit een offerte ontstaat een order "In afwachting van betaling" met fiche + proefknop; betalen via de link zet het ontwerp op `ordered`; handmatig toevoegen zonder ontwerp geeft een adminmelding.

### woo-compat-12 · LAAG · Plugin-header: "WC tested up to 9.9" en geen "Requires Plugins"
- Bestanden: `borduur-patch-studio.php` (r. 9-10).
- Probleem: getest met WooCommerce 11.3, header zegt 9.9 → Woo toont "niet getest met je versie" op de pluginpagina (oogt niet-premium). Geen `Requires Plugins: woocommerce` (WP 6.5+ afhankelijkheden) en geen melding als WC < 8.8 actief is (`Integration::woo_ready` controleert enkel het bestaan).
- Voorstel: `WC tested up to: 11.3`, `Requires Plugins: woocommerce`, versiecontrole `WC()->version` met admin-melding; release-checklist die deze waarde bijwerkt.
- Acceptatie: geen Woo-compatibiliteitswaarschuwing op wp-admin/plugins.php met WC 11.3; met WC 8.7 verschijnt een duidelijke melding en registreert de integratie niets.

### woo-compat-13 · LAAG · Mini-cart en zijmandjes zijn overvol (8 regels per patch)
- Bestanden: `includes/woo/class-cart.php` (`item_data`).
- Probleem: in mini-cart, Elementor-zijmand en blok-mini-cart verschijnen Ontwerp, Techniek, Maat, Vorm, Afwerking (4 waarden), Tekst én "Maatwerk: Geen herroepingsrecht…" (gemeten in het fragment). Op gsm duwt dit de knoppen weg.
- Voorstel: in de klassieke mini-cart (`did_action( "woocommerce_before_mini_cart" ) > did_action( "woocommerce_after_mini_cart" )`) maximaal 2 regels tonen: ref + "Geborduurd · 8 cm · Rond". De blok-mini-cart gebruikt dezelfde Store API als de mand; daar is een compacte weergave alleen via de klant-UI/CSS mogelijk (gemeld in premium-ui-needs). Volledige regels blijven in mand, kassa en orders.
- Acceptatie: fragment `div.widget_shopping_cart_content` bevat per patch ≤ 2 metaregels; `/winkelmand/` toont nog alle regels.

### woo-compat-14 · LAAG · Rechtenverklaring en technische regels op klantfactuur/-mails
- Bestanden: `includes/woo/class-order.php` (`VISIBLE`, `bps_rights`).
- Probleem: `bps_rights` ("Rechtenverklaring: Bevestigd op … (tekstversie …)") is zichtbare itemmeta en komt in elke klantmail en in factuur-PDF-plugins (WooCommerce PDF Invoices & Packing Slips) terecht. Juridisch bewijs hoort in de admin, niet op de factuur.
- Voorstel: `woocommerce_order_item_get_formatted_meta_data` filteren zodat `bps_rights` enkel in de admin (`is_admin() && ! wp_doing_ajax()`) en de admin-mail zichtbaar is.
- Acceptatie: klantverwerkingsmail en "Mijn account › bestelling" tonen geen "Rechtenverklaring"; de orderpagina in de admin wel.

## Niet getest (betaalde plugins / buiten bereik)
- Elementor Pro Cart/Checkout/Menu-Cart-widgets: Menu Cart gebruikt het mini-cart-sjabloon, dus woo-compat-2 en -13 gelden daar ook; de kassa-melding hangt aan `woocommerce_review_order_before_submit` en moet in de Elementor Pro Checkout-widget nog visueel nagekeken worden.
- Woo Product Add-ons, WCML, CURCY: gedrag afgeleid van code en documentatie, zie acceptatiecriteria.
