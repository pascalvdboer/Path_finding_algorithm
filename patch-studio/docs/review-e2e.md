# Review e2e: Patch Studio in een echte WordPress + WooCommerce

Reviewer "wp-e2e", 17-11-2026. Ik heb de plugin in de lokale testsite gedraaid (`tests/e2e/start-wp.sh`: WordPress + WooCommerce, SQLite, `php -S`, thema Hello Elementor, HPOS aan). Alle scenario's liepen met Playwright/Chromium, één keer met de **klassieke** checkout (shortcodes) en één keer met de **blok**-checkout (WooCommerce Blocks).

## Resultaat

| Scenario | Klassiek | Blok |
|---|---|---|
| 01 studio laden (geen fouten, a11y, geen horizontale scroll) | PASS 22/22 | PASS |
| 02 upload + advies (vlak logo, ronde patch van de eigenaar) | PASS 34/34 | PASS |
| 03 winkelmand + overschrijving (UI = /cart = /quote = mand = order) | PASS 36/36 | PASS 36/36 |
| 04 cheque + gratis verzending | PASS 17/17 | PASS 17/17 |
| 05 admin-fiche, proef, print | PASS 32/32 | PASS |
| 06 offerte-aanvraag + mails | PASS 13/13 | PASS 13/13 |
| 07 gsm 390 px, tekst-only, sticky balk | PASS 31/31 | PASS 31/31 |
| **08 (nieuw)** randgevallen in de winkelmand + gsm-checkout | PASS 17/17 | PASS 17/17 |
| PHP debug.log (plugin-notices tijdens de run) | geen | geen |
| JS-console, pagefouten, mislukte requests (alle scenario's) | geen | geen |

Ook gecontroleerd: `npm run build` (patch-studio.js 95,0 kB gzip, binnen het budget van 100 kB), `npm test` 172/172, `npm run test:php` 47 vectoren / 652 checks, 0 fouten.

### debug.log volledig nagekeken (niet alleen de plugin-filter)
- `translations_api()`, `wp_version_check()`, `wp_update_*()`: warnings omdat de testsite bewust offline is (`WP_HTTP_BLOCK_EXTERNAL`). Dat is de omgeving, niet de plugin.
- 1× `_load_textdomain_just_in_time` voor het domein `woocommerce`, tijdens de eerste installatie via WP-CLI. Het komt niet terug bij gewone paginaladingen, dus het komt niet van Patch Studio.
- Geen enkele notice, warning of deprecated van `borduur-patch-studio`.

## Nieuw scenario 08: `tests/e2e/08-cart-edges.e2e.cjs`
Dit dekt wat 01–07 nog niet testten:
1. **GSM-foto van de eigenaar** (`reference/eigenaar-foto-patch.jpg`) in WordPress: "We hebben je patch uit de foto geknipt", ronde patch herkend, "Illustratie met tinten en schaduwen" (geen "foto"), knop *Bijstellen*. Het ontwerp is volledig zichtbaar (screenshot `e2e-classic-08-gsm-photo-step1.png`).
2. **Aantal wijzigen in de winkelmand** (10 → 25), klassiek via "Winkelwagen bijwerken" en blok via de Store API. De stukprijs volgt de staffel en het mandtotaal is gelijk aan `POST /quote` voor 25 stuks (€ 208,12).
3. **Twee ontwerpen in één mand** (logo + tekst-only patch). Elk ontwerp krijgt een eigen referentie en beide staan in de mand. De staffel wordt gedeeld en de eenmalige borduurkaart-kost maar één keer aangerekend: het totaal (€ 252,65) ligt lager dan de som van de losse offertes (€ 334,44).
4. **Afrekenen op een gsm (390 px)**: geen horizontale scroll, formulier volledig in beeld en de maatwerk-/digitale-proefmelding staat er. De bestelling bevat beide ontwerpregels en het ordertotaal is gelijk aan het mandtotaal.

## Wat ik repareerde
1. **Blok-winkelmand en blok-checkout vielen 80 px buiten beeld** (desktop én gsm; afgesneden, niet scrollbaar, dus de test "geen horizontale scroll" zag het niet). De oorzaak zat niet in de plugin. De testsite zette `alignwide` op de cart/checkout-blokken, en Hello Elementor geeft `.alignwide` een `margin-inline: -80px` zonder mobiele breakpoint. Fix in `tests/e2e/lib/wp-setup.php`: geen `alignwide` meer. Scenario 08 controleert nu ook of de formuliervelden volledig in beeld staan.
   *Advies voor de live site:* stapt de eigenaar ooit over op de blok-checkout met Hello Elementor, zet dan de uitlijning van het blok op "geen" (niet "breed"). Vandaag gebruikt de live site de Elementor-widget "Winkelwagen" (gecontroleerd op borduurservicebelgie.be/winkelwagen/), dus vandaag speelt dit niet.
2. Geen plugin-code gewijzigd: alle bestaande scenario's waren groen en ik vond geen PHP- of JS-fouten.

## Extra handmatige check: Frans
Met de taal van de site tijdelijk op `fr_BE` (daarna teruggezet op `nl_BE`) staat de studio volledig in het Frans, met `lang="fr-BE"`, geen achtergebleven Nederlandse woorden en geen consolefouten (`e2e-fr-step2.png`).

## Open punten
- **Elementor Pro-winkelwagenwidget** (live site): niet testbaar zonder Elementor Pro-licentie. De plugin gebruikt de standaard WooCommerce-filters (`woocommerce_get_item_data`, `woocommerce_cart_item_thumbnail`), die deze widget ook gebruikt. Toch even nakijken op staging: ontwerpref, miniatuur en melding in de mand.
- Andere live plugins (woo-product-addons, woo-discount-rules, conditional-extra-fees, WP Rocket) zitten niet in de testsite. Test op staging vooral **woo-discount-rules** (kan de prijs na `set_prices` nog aanpassen) en de **WP Rocket**-uitsluitingen (REST/nonce, delay-JS voor `patch-studio.js`).
- De PVC-minimumhoeveelheid in de blok-mand (Store API-filters) heb ik niet end-to-end getest, alleen in de code nagekeken.
- In de harness leest `cart.read()` de verzendregel van de blok-mand niet uit (`shipping undefined`). Dat is cosmetisch: de totalen kloppen wel.
- De screenshots van de blok-checkout tonen skeletons bij betaalmethoden en overzicht. Dat komt door het moment van de screenshot (React laadt nog). De bestelling zelf lukt.

## Screenshots (`docs/screenshots/`)
- Klassiek: `e2e-classic-01-studio-empty.png`, `-02-owner-step2.png`, `-03-step5.png`, `-03-cart.png`, `-03-order-received.png`, `-05-order-fiche.png`, `-06-quote-done.png`, `-07-m-step5.png`, `-07-m-cart.png`, `-08-gsm-photo-step1.png`, `-08-cart-qty25-classic.png`, `-08-cart-two-designs-classic.png`, `-08-m-checkout-classic.png`, `-08-m-order-received-classic.png`
- Blok: `e2e-block-03-cart.png`, `-03-order-received.png`, `-04-cart.png`, `-07-m-cart.png`, `-08-cart-qty25-block.png`, `-08-cart-two-designs-block.png`, `-08-m-checkout-block.png`, `-08-m-order-received-block.png`
- Frans: `e2e-fr-step2.png`

## Opnieuw draaien
```
bash tests/e2e/run-e2e.sh                              # klassiek (standaard)
BPS_WP_CHECKOUT=block bash tests/e2e/run-e2e.sh        # blok-checkout
bash tests/e2e/run-e2e.sh 08                            # alleen scenario 08
```
Let op: `run-e2e.sh` start `php -S` op de achtergrond. Pijp de uitvoer dus niet door `| tail`, anders blijft de pijp open. Schrijf de uitvoer naar een bestand.
