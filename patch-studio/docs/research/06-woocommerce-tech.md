# 06 — WooCommerce-integratie: technisch onderzoek en bouwrecepten

Onderzoeker: "woocommerce-tech" · 16-11-2026 · voor de bouwers van `plugin/borduur-patch-studio`.

Bronnen gecontroleerd in de **broncode** (gedownload van downloads.wordpress.org op 16-11-2026):
WooCommerce **11.3.0**, Woo Discount Rules **2.6.18** (Flycart), Elementor **4.4.1**; WP Rocket via
`github.com/wp-media/wp-rocket` (branch `develop`); enshrined/svg-sanitize **1.1.1** lokaal getest.
De live site is bekeken met curl (headers, HTML, REST-index). WooCommerce Product Add-Ons (betaalde extensie) is **niet**
publiek beschikbaar als broncode: wat daarover staat komt uit de live REST-schema's en documentatie en is als
"verifiëren op staging" gemarkeerd. Geen van de PHP-fragmenten hieronder is op een echte WP-installatie uitgevoerd
(behalve de SVG-sanering), dus test ze op staging.

Voorgestelde naamgeving (pas aan als de architect iets anders beslist): PHP-namespace `BorduurPatchStudio`,
prefix functies/opties/meta `bps_` / `_bps_`, REST-namespace `patch-studio/v1`, text domain `borduur-patch-studio`.

---

## 0. TL;DR — de 12 beslissingen

1. **Bestel op de bestaande producten + variaties** (geborduurd 29902, geprint 30484, gesublimeerd 36158, PVC 30541;
   zie 02-huidig-aanbod.md §10a) met de **bestaande Product Add-Ons-velden**. Dan blijven Woo Discount Rules (staffel),
   Conditional Extra Fees (borduurkaart/instelkosten/mal), verzending en Mollie ongewijzigd werken. Geen eigen prijsmotor in de kassa.
2. Ons ontwerp gaat mee als **cart item data** met alleen een **ID + HMAC-token** (`bps_design_id`), nooit afbeeldingen/base64 in de sessie.
3. **Zet zelf géén staffel** in `woocommerce_before_calculate_totals`. WDR doet dat op prioriteit **1000**. Moet je toch iets
   aan de prijs veranderen (bv. een toeslag die geen add-on is): prioriteit **< 1000** (bv. 500), met een "al toegepast"-vlag.
4. Weergave in winkelmand: `woocommerce_get_item_data` (werkt **ook** in blok-winkelmand via Store API) +
   `woocommerce_cart_item_thumbnail` (klassiek) + `woocommerce_store_api_cart_item_images` (blokken).
5. Order: `woocommerce_checkout_create_order_line_item` (wordt **ook** door de Store API-checkout aangeroepen) → `$item->add_meta_data()`.
   Alles via CRUD (`$order->get_meta()`), nooit `get_post_meta($order_id)` → **HPOS-compatibel**. Declareer `custom_order_tables` én `cart_checkout_blocks`.
6. De site gebruikt de **klassieke** winkelmand (Elementor Pro "WooCommerce Cart"-widget op `/winkelwagen/`), niet de blok-winkelmand.
   Klassieke hooks zijn dus primair; blok-ondersteuning is extra zekerheid.
7. Uploads: eigen REST-route, magic-bytes-check, willekeurige bestandsnaam, **privémap** buiten bereik
   (`.htaccess` werkt: Hostinger = LiteSpeed) + publieke **preview-PNG** (her-gecodeerd) met onraadbare naam voor winkelmand/e-mail.
   SVG enkel na `enshrined/svg-sanitize` (gescoped met Strauss).
8. REST: altijd `permission_callback`; voor gasten beschermt `X-WP-Nonce` **niets** → eigen rate-limit + honeypot + token
   dat runtime opgehaald wordt (pagina's zijn gecachet door WP Rocket/Cloudflare, dus **geen nonce in de HTML**).
9. WP Rocket **Delay JS staat aan** op de site (49× `rocketlazyloadscript` op /patches/). Sluit ons script uit van Delay JS
   (`rocket_delay_js_exclusions`), defer mag. Config via `data-`-attributen, niet via inline script.
10. Elementor-widget **en** shortcode `[patch_studio]` renderen exact dezelfde HTML via één render-functie.
11. Toevoegen aan winkelmand: **klassieke form-POST** naar de productpagina met de PA-velden + onze verborgen velden
    (Store API `add-item` weigert producten met verplichte add-ons, zie 02 §10a). Alternatief: eigen REST-route die `$_POST` vult.
12. Offerte (geweven/chenille/leder/> 12 cm/groot volume): eigen REST-route → private CPT `bps_quote` + WooCommerce-gestileerde
    e-mails (eigen `WC_Email`-klassen, beheerbaar onder WooCommerce › Instellingen › E-mails) met preview-afbeelding.

---

## 1. Wat er live draait (relevant voor de techniek)

Gemeten op 16-11-2026 (curl van de publieke site):

| Item | Waarde | Gevolg |
|---|---|---|
| Hosting | `platform: hostinger`, `x-powered-by: PHP/8.3.36`, achter **Cloudflare** (`server: cloudflare`, `cf-cache-status: DYNAMIC` voor HTML) | LiteSpeed → `.htaccess` wordt gelezen. Statische assets worden door Cloudflare gecachet → **versie in asset-URL** (`?ver=` = pluginversie of `filemtime`). Cloudflare-limiet uploadgrootte (gratis plan) = 100 MB per request. |
| WP Rocket | actief: Delay JS (`type="text/rocketlazyloadscript"`, `data-rocket-src`), Defer (`data-rocket-defer`), Minify JS (`/cache/min/1/...`, `data-minify="1"`), LazyLoad (`rocket-lazyload`), pagina-cache (`Debug: cached@...`). **Geen** Remove Unused CSS gevonden (geen `wpr-usedcss`). jQuery wordt enkel gedeferred, niet vertraagd. | Zie §9. |
| Winkelmand | `/winkelwagen/` (ook `/winkelmand/` en `/afrekenen/` → redirect bij lege mand). Elementor-widget `elementor-widget-woocommerce-cart` = **klassieke** winkelmand (shortcode-gebaseerd). Geen `wp-block-woocommerce-cart`. | Klassieke filters zijn primair. Kassa niet bekeken (vereist gevulde mand); vermoedelijk ook Elementor-widget → klassiek. **Verifiëren.** |
| REST-namespaces | o.a. `wc/store/v1`, `wc-product-add-ons/v1` + `/v2`, `mollie/v1`, `woocommerce-myparcel/v1`, `wp-mail-smtp`, `rankmath`, `elementskit`, `jetpack-boost/v1`, `code-snippets/v1` | Mollie = betaling (orders starten `pending`). WP Mail SMTP = mails gaan via SMTP (goed voor afleverbaarheid van onze mails). Jetpack Boost aanwezig: controleer of "Defer non-essential JS" uit staat (dubbel met WP Rocket). |
| Offerteformulier | `/offerte-aanvragen/` = **Elementor Pro Form** (`elementor-form`) | Zie §12 voor koppeling. |
| Product Add-Ons | live REST-schema `wc-product-add-ons/v2/global-add-ons`: veldtypes `multiple_choice, checkbox, custom_text, custom_textarea, file_upload, custom_price, input_multiplier, heading, datepicker`; `price_type` ∈ `flat_fee, quantity_based, percentage_based` | `flat_fee` = eenmalig per regel, `quantity_based` = per stuk. |

Bron: `curl -I https://borduurservicebelgie.be/offerte-aanvragen/`, `https://borduurservicebelgie.be/wp-json/`,
`https://borduurservicebelgie.be/wp-json/wc-product-add-ons/v2`, HTML van `/patches/`, `/winkelwagen/`.

---

## 2. Architectuur van de bestelstroom

```
[Browser: Patch Studio]
  analyse + voorbeeld (alles client-side)
   │ 1. POST /wp-json/patch-studio/v1/designs   (multipart: origineel bestand + preview.png + JSON-ontwerp)
   │    ← { design_id, token, preview_url }
   │
   ├─ 2a. Bestellen  → klassieke form-POST naar product-URL:
   │       add-to-cart=<product_id>, variation_id, attribute_pa_maat, quantity,
   │       addon-<pid>-<n>… (Product Add-Ons), bps_design_id, bps_design_token
   │       → WooCommerce + PA + WDR doen hun werk → redirect naar /winkelwagen/
   │
   └─ 2b. Offerte    → POST /wp-json/patch-studio/v1/quotes  { design_id, token, contact, aantal, … }
                       → CPT bps_quote + mail naar winkel + bevestiging naar klant
[Server]
  woocommerce_add_to_cart_validation → token/ontwerp controleren
  woocommerce_add_cart_item_data     → ['bps' => [design_id, …]]
  woocommerce_get_item_data          → "Ontwerp: PS-2026-0042 · Advies: geborduurd" (klassiek + blokken)
  woocommerce_cart_item_thumbnail / woocommerce_store_api_cart_item_images → preview
  woocommerce_checkout_create_order_line_item → order item meta
  woocommerce_checkout_order_processed / woocommerce_store_api_checkout_order_processed → ontwerp "besteld"
  woocommerce_order_item_thumbnail + woocommerce_email_order_items_args → preview in e-mails
  woocommerce_after_order_itemmeta (admin) → preview + download origineel + analyse
```

Waarom ontwerp **eerst** uploaden en daarna pas toevoegen: de winkelmandsessie (`wp_woocommerce_sessions`) blijft klein,
het ontwerp overleeft een sessie-reset, en dezelfde opslag dient zowel de bestelling als de offerte.

---

## 3. Toevoegen aan de winkelmand

### 3a. Aanbevolen: klassieke form-POST (maximale compatibiliteit)

WooCommerce verwerkt `add-to-cart` op `wp_loaded` (`WC_Form_Handler::add_to_cart_action`), Product Add-Ons leest zijn velden
uit `$_POST` in zijn eigen `woocommerce_add_to_cart_validation` en `woocommerce_add_cart_item_data`. Een gewone POST naar de
productpagina is dus exact wat een klant vandaag doet: alle prijzen/fees/staffels zijn gegarandeerd gelijk aan de kassa.

```js
// assets/js/cart.js — bouwt een onzichtbaar formulier en verstuurt het
function bpsSubmitToCart({ productUrl, productId, variationId, sizeAttr, sizeValue, qty, addons, designId, token }) {
  const f = document.createElement('form');
  f.method = 'post';
  f.action = productUrl;                       // bv. https://borduurservicebelgie.be/product/geborduurde-patch/
  f.enctype = 'multipart/form-data';           // PA verwacht dit bij een file_upload-veld; kan geen kwaad
  const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
  add('add-to-cart', productId);
  add('product_id', productId);
  add('variation_id', variationId);
  add(sizeAttr, sizeValue);                    // bv. 'attribute_pa_maat' → '8-cm' (uit config, niet hardcoden)
  add('quantity', qty);
  for (const [name, value] of addons) add(name, value);   // bv. ['addon-29902-2', 'uit-doek-geknipt'], ['addon-29902-1[]', 'opstrijkbare-lijm-laag']
  add('bps_design_id', designId);
  add('bps_design_token', token);
  document.body.appendChild(f);
  f.submit();                                  // WooCommerce redirect: instelling "Doorsturen naar winkelmand" of terug naar product
}
```

Na de POST stuurt WooCommerce terug naar de productpagina (of naar de winkelmand als
*WooCommerce › Instellingen › Producten › "Doorsturen naar de winkelmand na succesvol toevoegen"* aan staat). Wil je altijd
naar de winkelmand, filter enkel voor onze items:

```php
add_filter( 'woocommerce_add_to_cart_redirect', function ( $url ) {
	if ( ! empty( $_POST['bps_design_id'] ) && ! wc_notice_count( 'error' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return wc_get_cart_url();
	}
	return $url;
} );
```

**Product Add-Ons-veldnamen**: live gemeten `addon-29902-1[]`, `addon-29902-2`, … (zie 02 §10a); de index kan verschuiven
als de beheerder add-ons herschikt. Bouw de mapping **server-side** op uit de add-on-definities en match op **naam van de add-on**
en **label van de optie** (stabiel voor de beheerder), niet op index:

```php
// VERIFIËREN OP STAGING: PA is closed source; signatuur volgens bekende versies 6.x/7.x.
function bps_get_pa_field_map( int $product_id ): array {
	if ( ! class_exists( 'WC_Product_Addons_Helper' ) ) {
		return array();
	}
	$addons = WC_Product_Addons_Helper::get_product_addons( $product_id );
	$map    = array();
	foreach ( (array) $addons as $i => $addon ) {
		// PA bouwt de input-naam als 'addon-' . $product_id . '-' . $addon['field_name'] (of de index $i in oudere versies).
		$field = isset( $addon['field_name'] ) ? $addon['field_name'] : (string) $i;
		$opts  = array();
		foreach ( (array) ( $addon['options'] ?? array() ) as $opt ) {
			$opts[ $opt['label'] ] = array(
				'value'      => sanitize_title( $opt['label'] ),   // live waarden zijn slugs: 'opstrijkbare-lijm-laag'
				'price'      => (float) $opt['price'],
				'price_type' => $opt['price_type'] ?? 'flat_fee',   // flat_fee | quantity_based | percentage_based
			);
		}
		$map[ $addon['name'] ] = array(
			'input'    => 'addon-' . $product_id . '-' . $field,
			'multiple' => in_array( $addon['type'], array( 'checkbox' ), true ), // checkbox → naam met []
			'required' => ! empty( $addon['required'] ),
			'type'     => $addon['type'],
			'options'  => $opts,
		);
	}
	return $map;
}
```

Controleer op staging met de echte productpagina (DevTools → `form.cart` → inputnamen/waarden) dat de opgebouwde map
exact dezelfde `name`/`value` geeft. Toon in de admin-instellingen een **"Kassa-check"** (zie §15) die per techniek de map en de
prijzen laat zien en afwijkingen in rood markeert.

### 3b. Alternatief: eigen REST-route die de mand vult (geen paginawissel nodig)

Nodig als je in de tool zelf "Toegevoegd ✓ — verder winkelen / naar winkelmand" wil tonen. Let op: de mand is in REST-context
**niet** geladen; `wc_load_cart()` (bestaat sinds WC 3.6, `includes/wc-core-functions.php`) laadt sessie + mand.

```php
register_rest_route( 'patch-studio/v1', '/cart', array(
	'methods'             => 'POST',
	'permission_callback' => '__return_true', // publiek; zie §8 voor rate-limit/token
	'callback'            => function ( WP_REST_Request $req ) {
		wc_load_cart();
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true ); // gast krijgt sessiecookie
		}
		$p = $req->get_json_params();
		// PA en onze eigen hooks lezen $_POST: vul het gecontroleerd (alleen whitelisted keys uit bps_get_pa_field_map()).
		$_POST = $_REQUEST = bps_build_whitelisted_post( $p ); // phpcs:ignore
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $p['product_id'], (int) $p['quantity'], (int) $p['variation_id'], $p['variation'] ?? array() );
		$key    = $passed ? WC()->cart->add_to_cart( (int) $p['product_id'], (int) $p['quantity'], (int) $p['variation_id'], (array) ( $p['variation'] ?? array() ) ) : false;
		$errors = wc_get_notices( 'error' );
		wc_clear_notices();
		if ( ! $key ) {
			return new WP_Error( 'bps_cart', wp_strip_all_tags( $errors[0]['notice'] ?? __( 'Toevoegen mislukt.', 'borduur-patch-studio' ) ), array( 'status' => 400 ) );
		}
		WC()->cart->calculate_totals();
		return array( 'cart_url' => wc_get_cart_url(), 'count' => WC()->cart->get_cart_contents_count() );
	},
) );
```

Daarna in JS `jQuery(document.body).trigger('wc_fragment_refresh')` zodat de Elementor menu-cart (3× `elementor-widget-woocommerce-menu-cart`
op de site) het aantal bijwerkt. Valkuil: `WC_Cart::add_to_cart()` vuurt **geen** `woocommerce_add_to_cart_validation`; dat
moet je zelf doen (zoals hierboven), anders slaat PA zijn verplicht-veld-check over.

### 3c. Niet doen: Store API `POST /wc/store/v1/cart/add-item`

Werkt niet voor deze producten: de verplichte PA-velden (rand, drager) worden niet uit de Store API-request gelezen, en de mand
weigert (gemeten in 02 §10a). `woocommerce_store_api_add_to_cart_data` (WC ≥ 8.8, `src/StoreApi/Routes/V1/CartAddItem.php`)
kan wél extra `cart_item_data` toevoegen, maar lost de PA-validatie niet op.

---

## 4. Cart item data (ons ontwerp aan de mandregel hangen)

```php
namespace BorduurPatchStudio;

// 1) Valideren — draait bij form-POST (WC_Form_Handler) en bij onze REST-route (3b)
add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id, $qty, $variation_id = 0 ) {
	if ( empty( $_POST['bps_design_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- token hieronder
		return $passed;                      // gewone bestelling zonder Patch Studio: niets doen
	}
	$id    = absint( $_POST['bps_design_id'] );
	$token = sanitize_text_field( wp_unslash( $_POST['bps_design_token'] ?? '' ) );
	if ( ! Designs::verify_token( $id, $token ) ) {
		wc_add_notice( __( 'We konden je ontwerp niet terugvinden. Laad de ontwerptool opnieuw en probeer nog eens.', 'borduur-patch-studio' ), 'error' );
		return false;
	}
	$design = Designs::get( $id );
	if ( (int) $design['product_id'] !== (int) $product_id ) {          // ontwerp hoort bij deze techniek
		wc_add_notice( __( 'Dit ontwerp hoort bij een andere techniek.', 'borduur-patch-studio' ), 'error' );
		return false;
	}
	$min = Config::min_qty( $product_id );                                // PVC: 50, stappen van 10
	if ( $qty < $min ) {
		wc_add_notice( sprintf( __( 'Voor deze techniek bestel je minimaal %d stuks.', 'borduur-patch-studio' ), $min ), 'error' );
		return false;
	}
	return $passed;
}, 20, 4 );

// 2) Data aan de mandregel hangen. Uniek ontwerp-ID ⇒ uniek cart-item-key (WC hasht cart_item_data in generate_cart_id),
//    dus twee ontwerpen op hetzelfde product worden nooit samengevoegd.
add_filter( 'woocommerce_add_cart_item_data', function ( $data, $product_id, $variation_id, $qty ) {
	if ( empty( $_POST['bps_design_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $data;
	}
	$design      = Designs::get( absint( $_POST['bps_design_id'] ) ); // al gevalideerd
	$data['bps'] = array(
		'design_id'   => (int) $design['id'],
		'ref'         => $design['ref'],          // 'PS-2026-0042' — leesbaar voor klant en atelier
		'technique'   => $design['technique'],    // 'embroidered'
		'advice'      => $design['advice_label'], // 'Geborduurd — max. 9 garenkleuren na vereenvoudiging'
		'shape'       => $design['shape'],        // 'round'
		'size_mm'     => $design['size_mm'],      // [80, 80]
		'preview_url' => $design['preview_url'],  // publieke PNG, zie §7
		'v'           => 1,                       // schema-versie
	);
	return $data;
}, 10, 4 );
```

Feiten uit de WooCommerce-broncode (`includes/class-wc-cart.php` r. 1151 `generate_cart_id`, r. 1338 filter):
- Alles in `cart_item_data` wordt meegenomen in de hash van de cart-key en **automatisch in de sessie bewaard** (alles behalve
  `data`). `woocommerce_get_cart_item_from_session` is dus niet nodig, tenzij je iets wil herberekenen.
- Houd het **klein**: alleen scalars. Geen base64, geen hele analyse-JSON (sessietabel `wp_woocommerce_sessions` wordt bij
  elke pagina gelezen/geschreven).
- `woocommerce_add_cart_item_data` draait **ook** bij Store API `add-item` (via `CartController::add_to_cart` → `WC_Cart::add_to_cart`).

### 4a. Tonen in de winkelmand (klassiek én blokken met één filter)

```php
add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
	if ( empty( $cart_item['bps'] ) ) {
		return $item_data;
	}
	$b           = $cart_item['bps'];
	$item_data[] = array( 'key' => __( 'Ontwerp', 'borduur-patch-studio' ), 'value' => $b['ref'] );
	$item_data[] = array( 'key' => __( 'Advies', 'borduur-patch-studio' ), 'value' => $b['advice'] );
	$item_data[] = array( 'key' => __( 'Digitale proef', 'borduur-patch-studio' ), 'value' => __( 'Je krijgt eerst een proef ter goedkeuring', 'borduur-patch-studio' ) );
	return $item_data;
}, 10, 2 );
```

Store API (`src/StoreApi/Schemas/V1/CartItemSchema.php` r. 216–258) roept **dezelfde** filter aan en haalt elk element door
`wp_kses_post`; de sleutel `hidden => true` verbergt een regel in de blok-winkelmand. Geen HTML met `<script>`/inline-events
gebruiken; eenvoudige tekst is het veiligst (klassiek toont `display` als die bestaat, anders `value`).

### 4b. Preview als productafbeelding in de winkelmand

```php
// Klassieke winkelmand + mini-cart (Elementor Pro menu-cart gebruikt dezelfde filter)
add_filter( 'woocommerce_cart_item_thumbnail', function ( $html, $cart_item ) {
	if ( empty( $cart_item['bps']['preview_url'] ) ) {
		return $html;
	}
	return sprintf(
		'<img src="%s" alt="%s" width="300" height="300" class="bps-cart-preview skip-lazy" data-no-lazy="1" loading="eager" />',
		esc_url( $cart_item['bps']['preview_url'] ),
		esc_attr__( 'Voorbeeld van jouw patch', 'borduur-patch-studio' )
	);
}, 10, 2 );

// Blok-winkelmand / Store API (WC ≥ 9.6-ish; aanwezig in 11.3: CartItemSchema::get_cart_images)
add_filter( 'woocommerce_store_api_cart_item_images', function ( $images, $cart_item, $key ) {
	if ( empty( $cart_item['bps']['preview_url'] ) ) {
		return $images;
	}
	$url = esc_url_raw( $cart_item['bps']['preview_url'] );
	return array( (object) array(
		'id'        => 0,          // MOET gezet zijn (isset), anders valt WC terug op productfoto
		'src'       => $url,       // MOET absolute URL met host zijn
		'thumbnail' => $url,       // idem
		'srcset'    => '',
		'sizes'     => '',
		'name'      => $cart_item['bps']['ref'],
		'alt'       => __( 'Voorbeeld van jouw patch', 'borduur-patch-studio' ),
	) );
}, 10, 3 );
```

`skip-lazy` / `data-no-lazy="1"`: anders vervangt WP Rocket LazyLoad de `src` door `data-lazy-src` in de mini-cart-fragmenten.

---

## 5. Prijs: server-side herberekening en staffels (Woo Discount Rules)

### 5a. Hoe WDR werkt (broncode 2.6.18)

- `v2/App/Router.php` r. 180: `add_action('woocommerce_before_calculate_totals', [ManageDiscount, 'applyCartProductDiscount'], 1000)`.
- `ManageDiscount::applyCartProductDiscount()` (r. 1395) rekent per regel en doet `$cart_item['data']->set_price($discounted)`
  en bewaart de oorspronkelijke prijs in `$product->awdr_product_original_price`.
- De **startprijs** komt uit `$product->get_price()` op dat moment (`DiscountCalculator::mayApplyPriceDiscount`, r. 541–559,
  instelling `calculate_discount_from` = `sale_price` standaard). Dus: alles wat **vóór prioriteit 1000** de prijs zet (bv. Product
  Add-Ons, of wij) krijgt de staffelkorting erbovenop. Alles wat **na 1000** de prijs zet, **overschrijft** de korting.
- Filters om items uit te sluiten of anders te tellen:
  - `advanced_woo_discount_rules_calculate_discount_for_cart_item` (`$bool, $cart_item`) — geen korting op dit item.
  - `advanced_woo_discount_rules_include_cart_item_to_count_quantity` (`$bool, $cart_item, $type`) — telt dit item mee voor de staffel-hoeveelheid.
  - `advanced_woo_discount_rules_product_price_on_before_calculate_discount` (`$price, $product, $qty, $cart_item, $from`) — basisprijs aanpassen.
  - `advanced_woo_discount_rules_discounted_price_of_cart_item` / `advanced_woo_discount_rules_do_apply_price_discount`.
- WDR heeft een ingebouwde compatibiliteitsoptie voor Product Add-Ons (`v2/App/Compatibility/ProductAddOnsByWooCommerce.php`),
  maar die regelt enkel de **doorgestreepte prijs op de productpagina** (JS), niets in de mand.

### 5b. Regels voor Patch Studio

1. **Standaardmodus (aanbevolen): wij rekenen niets in de mand.** Variatieprijs + PA-opties + WDR-staffel + Conditional Extra Fees
   doen alles. Wat de tool toont, is een **voorspelling** uit de config; de mand is de waarheid.
2. **Nooit zelf een staffel toepassen** op items die WDR ook ziet → dubbele korting. Toon in de tool de staffel enkel ter info.
3. Moet er toch een eigen toeslag op (iets wat geen PA-optie is, bv. "eigen vorm"): **liever een PA-optie aanmaken** in de backend
   (beheerder kan die zelf beheren, WDR en e-mails tonen ze automatisch). Lukt dat niet, dan zo:

```php
// Toeslag VÓÓR WDR (1000) zodat de staffel er netjes over gaat. Idempotent dankzij vlag op het product-object
// (cart_item['data'] wordt per request opnieuw opgebouwd uit de sessie, maar calculate_totals kan meerdere keren per request draaien).
add_action( 'woocommerce_before_calculate_totals', function ( WC_Cart $cart ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['bps']['surcharge'] ) || ! empty( $item['data']->bps_surcharge_applied ) ) {
			continue;
		}
		$surcharge = Config::surcharge_excl_vat( $item['bps']['surcharge'] ); // herbereken uit server-config, NOOIT uit de request
		$item['data']->set_price( (float) $item['data']->get_price() + $surcharge );
		$item['data']->bps_surcharge_applied = true;
	}
}, 500 );
```

4. **Vertrouw nooit een prijs uit de browser.** De browser stuurt keuzes (techniek, maat, opties, aantal); de server leidt prijzen af
   uit WooCommerce/Config. `cart_item_data` bevat geen bedragen.
5. Toekomstige modus "eigen product Patch op maat" (één product, prijs volledig door onze plugin): dan **wel** zelf rekenen in
   `before_calculate_totals` (prioriteit 20) én het item uitsluiten uit WDR met **beide** filters, of de staffel aan WDR laten en
   zelf geen staffel rekenen. Kies één bron van waarheid:

```php
add_filter( 'advanced_woo_discount_rules_calculate_discount_for_cart_item', function ( $calc, $cart_item ) {
	return ! empty( $cart_item['bps']['own_pricing'] ) ? false : $calc;
}, 10, 2 );
add_filter( 'advanced_woo_discount_rules_include_cart_item_to_count_quantity', function ( $count, $cart_item, $type ) {
	return ! empty( $cart_item['bps']['own_pricing'] ) ? false : $count; // of true als ze wél moeten meetellen voor andermans staffel
}, 10, 3 );
```

6. Bekende eigenaardigheid (gemeten in 02 §3): gesublimeerd krijgt in de kassa **geen** staffel en telt niet mee. De tool moet
   dat gedrag **overnemen** uit de config (`tiers: false`, `tierPoolExcludes`), niet "rechtzetten". Eigenaar beslist (open punt 02 §8).
7. Staffel-pool: WDR telt (afhankelijk van de regelinstelling) de hoeveelheid over **alle** patchregels in de mand. De tool kan de
   huidige mandinhoud ophalen (Store API `GET /wc/store/v1/cart`, publiek, geen nonce nodig voor GET) om "je hebt al 15 patches in
   je mand → je zit in de 10 %-trede" eerlijk te tonen. Optioneel, maar overtuigend en correct.

### 5c. Prijzen incl. btw tonen zoals de kassa

WooCommerce bewaart excl. btw; de site toont incl. 21 %. Bereken in PHP met WooCommerce zelf, zodat btw-instellingen/afronding
gelijk lopen: `wc_get_price_including_tax( $product, array( 'price' => $excl, 'qty' => 1 ) )`. Lever de tool een **config-JSON**
die server-side uit de echte variatieprijzen (`wc_get_product( $variation_id )->get_price()`) en PA-optieprijzen wordt opgebouwd,
zodat de beheerder prijzen maar op één plek (WooCommerce) wijzigt. Staffel (WDR-regels) en fees (Conditional Extra Fees) staan
in onze instellingen; de kassa-check (§15) vergelijkt ze.

---

## 6. Order item meta, HPOS en blokken

### 6a. Meta op de orderregel

```php
add_action( 'woocommerce_checkout_create_order_line_item', function ( WC_Order_Item_Product $item, $cart_item_key, $values, WC_Order $order ) {
	if ( empty( $values['bps'] ) ) {
		return;
	}
	$b = $values['bps'];
	// Zichtbaar voor klant (bedankpagina, Mijn account, e-mails) en admin:
	$item->add_meta_data( __( 'Ontwerp', 'borduur-patch-studio' ), $b['ref'], true );
	$item->add_meta_data( __( 'Advies', 'borduur-patch-studio' ), $b['advice'], true );
	// Verborgen (underscore-prefix ⇒ niet in wc_display_item_meta / niet in e-mails):
	$item->add_meta_data( '_bps_design_id', (int) $b['design_id'], true );
	$item->add_meta_data( '_bps_preview_url', esc_url_raw( $b['preview_url'] ), true );
	$item->add_meta_data( '_bps_schema', (int) $b['v'], true );
}, 10, 4 );
```

- Deze action vuurt in `WC_Checkout::create_order_line_items()` (`includes/class-wc-checkout.php` r. 612) en die methode
  wordt **ook** door de Store API-checkout gebruikt (`src/StoreApi/Utilities/OrderController.php` r. 1011). Eén hook dekt dus
  klassieke én blok-checkout.
- Leesbare sleutels (`Ontwerp`) worden letterlijk getoond. Wil je technische sleutels met vertaalde labels, gebruik dan
  `bps_ref` + `woocommerce_order_item_display_meta_key` (in `includes/class-wc-order-item.php` r. 372).
- Ontwerp-status bijwerken zodra de order bestaat (twee paden):

```php
add_action( 'woocommerce_checkout_order_processed', fn( $order_id, $posted, $order ) => Designs::mark_ordered_from_order( $order ), 10, 3 ); // klassiek
add_action( 'woocommerce_store_api_checkout_order_processed', fn( $order ) => Designs::mark_ordered_from_order( $order ) );          // blokken
// mark_ordered_from_order(): loop $order->get_items(), lees $item->get_meta('_bps_design_id'), zet ontwerp-status 'ordered' + order_id.
```

### 6b. Admin: preview + download in het orderscherm

```php
add_action( 'woocommerce_after_order_itemmeta', function ( $item_id, $item, $product ) {
	if ( ! $item instanceof WC_Order_Item_Product || ! $item->get_meta( '_bps_design_id' ) ) {
		return;
	}
	$id  = (int) $item->get_meta( '_bps_design_id' );
	$dl  = wp_nonce_url( admin_url( 'admin-post.php?action=bps_download&design=' . $id ), 'bps_download_' . $id );
	printf(
		'<div class="bps-admin-item"><img src="%1$s" width="120" alt=""> <a class="button" href="%2$s">%3$s</a> <a href="%4$s">%5$s</a></div>',
		esc_url( $item->get_meta( '_bps_preview_url' ) ),
		esc_url( $dl ),
		esc_html__( 'Originele bestand downloaden', 'borduur-patch-studio' ),
		esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) ),
		esc_html__( 'Analyse en ontwerpdetails', 'borduur-patch-studio' )
	);
}, 10, 3 );
```

Deze hook werkt op het oude én het HPOS-orderscherm (zelfde template `html-order-item-meta.php`).

### 6c. HPOS-regels

```php
// in het hoofdbestand, VÓÓR WooCommerce init
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );
```

- Orders/orderregels: alleen `wc_get_order()`, `$order->get_meta()/update_meta_data()/save()`, `$item->get_meta()`,
  `wc_get_orders()`. Nooit `get_post_meta( $order_id )`, `WP_Query` op `shop_order`, of `$wpdb` op `wp_posts` voor orders.
- Metabox op het orderscherm (indien nodig): scherm-id ophalen met
  `wc_get_page_screen_id( 'shop-order' )` (geeft `woocommerce_page_wc-orders` bij HPOS, `shop_order` zonder).
- **Onze eigen** gegevens (ontwerpen, offertes) zijn géén orders: een private CPT (`bps_design`, `bps_quote`) of eigen tabel mag gewoon.
  Advies: CPT met `public => false, show_ui => true, show_in_menu => 'woocommerce'`, `capability_type` gekoppeld aan
  `manage_woocommerce`, `supports => ['title']`. Meta via `update_post_meta` (dat zijn geen orders).
- Bron: <https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/>,
  `FeaturesUtil::declare_compatibility` in `src/Utilities/FeaturesUtil.php` r. 87.

---

## 7. Veilige uploads

### 7a. Opslagindeling

```
wp-content/uploads/patch-studio/
  ├─ private/            ← originele klantbestanden (logo's, foto's, PDF/AI/EPS)
  │    ├─ .htaccess      ← alles weigeren
  │    ├─ index.php      ← "<?php // Silence"
  │    └─ 2026/11/<32 willekeurige tekens>.<ext>
  └─ previews/           ← publieke, her-gecodeerde PNG's (voorbeeld van de patch), max 1200 px
       ├─ .htaccess      ← noindex, geen scripts
       └─ <32 willekeurige tekens>.png
```

`.htaccess` voor `private/` (Apache 2.4 + LiteSpeed; Hostinger draait LiteSpeed):

```apache
# Patch Studio – privébestanden, enkel via admin-download
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order deny,allow
  Deny from all
</IfModule>
```

`.htaccess` voor `previews/`:

```apache
Options -Indexes -ExecCGI
<FilesMatch "\.(?!png$)[^.]+$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header set X-Robots-Tag "noindex, nofollow"
  Header set X-Content-Type-Options "nosniff"
</IfModule>
```

Nginx (als de site ooit verhuist; `.htaccess` wordt dan genegeerd!) — tonen in de admin-statuspagina als tekst om te kopiëren:

```nginx
location ^~ /wp-content/uploads/patch-studio/private/ { deny all; return 404; }
location ^~ /wp-content/uploads/patch-studio/previews/ {
    add_header X-Robots-Tag "noindex, nofollow" always;
    add_header X-Content-Type-Options "nosniff" always;
    location ~ \.(?!png$)[^.]+$ { deny all; }
}
```

**Zelftest bij activatie en in de admin-status**: plaats `private/bps-test.txt` en doe
`wp_remote_get( $url, ['sslverify' => true] )`; verwacht 403/404. Krijg je 200 → rode waarschuwing "Je privéuploads zijn publiek
bereikbaar, voeg de nginx-regel toe" (zo doen o.a. WooCommerce voor `woocommerce_uploads`). Cloudflare kan een 200 cachen bij een
foute configuratie: de bestandsnamen zijn daarom ook onraadbaar (defense in depth).

### 7b. Upload-route

```php
register_rest_route( 'patch-studio/v1', '/designs', array(
	'methods'             => WP_REST_Server::CREATABLE,
	'permission_callback' => array( Guard::class, 'public_write' ), // rate-limit + token, zie §8
	'callback'            => array( Designs::class, 'create_from_request' ),
) );

final class Uploads {
	const MAX_BYTES = 25 * MB_IN_BYTES;           // instelbaar; Cloudflare-limiet 100 MB; check ook wp_max_upload_size()
	const TYPES = array(                           // ext => [mime, magic-check]
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'webp' => 'image/webp',
		'svg'  => 'image/svg+xml',
		'pdf'  => 'application/pdf',
		'ai'   => 'application/pdf',               // moderne .ai = PDF-compatibel ("%PDF-")
		'eps'  => 'application/postscript',
	);

	/** Detecteer type op inhoud, niet op extensie of browser-MIME. */
	public static function sniff( string $path ): ?string {
		$h = (string) file_get_contents( $path, false, null, 0, 64 );
		if ( str_starts_with( $h, "\x89PNG\r\n\x1a\n" ) ) return 'png';
		if ( str_starts_with( $h, "\xFF\xD8\xFF" ) ) return 'jpg';
		if ( str_starts_with( $h, 'RIFF' ) && substr( $h, 8, 4 ) === 'WEBP' ) return 'webp';
		if ( str_starts_with( $h, '%PDF-' ) ) return 'pdf';
		if ( str_starts_with( $h, '%!PS' ) || str_starts_with( $h, "\xC5\xD0\xD3\xC6" ) ) return 'eps';
		$head = strtolower( ltrim( (string) file_get_contents( $path, false, null, 0, 2048 ), "\xEF\xBB\xBF \t\r\n" ) );
		if ( ( str_starts_with( $head, '<?xml' ) || str_starts_with( $head, '<svg' ) || str_starts_with( $head, '<!--' ) ) && str_contains( $head, '<svg' ) ) return 'svg';
		return null;
	}

	public static function store_original( array $file ): array|WP_Error {
		if ( ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'bps_upload', __( 'Het uploaden is niet gelukt. Probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}
		if ( $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'bps_too_big', sprintf( __( 'Je bestand is groter dan %d MB. Stuur het gerust via WeTransfer of mail, dan bekijken we het samen.', 'borduur-patch-studio' ), self::MAX_BYTES / MB_IN_BYTES ), array( 'status' => 413 ) );
		}
		$ext = self::sniff( $file['tmp_name'] );
		if ( ! $ext ) {
			return new WP_Error( 'bps_type', __( 'Dit bestandstype kunnen we niet lezen. Gebruik PNG, JPG, WEBP, SVG, PDF, AI of EPS.', 'borduur-patch-studio' ), array( 'status' => 415 ) );
		}
		if ( 'svg' === $ext ) {
			$clean = Svg::sanitize( (string) file_get_contents( $file['tmp_name'] ) );
			if ( false === $clean ) {
				return new WP_Error( 'bps_svg', __( 'Dit SVG-bestand kunnen we niet veilig openen.', 'borduur-patch-studio' ), array( 'status' => 415 ) );
			}
			file_put_contents( $file['tmp_name'], $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$name = wp_generate_password( 32, false, false ) . '.' . $ext;
		$dir  = self::private_dir() . gmdate( 'Y/m/' );
		wp_mkdir_p( $dir );
		// wp_handle_upload met eigen upload_dir zou ook kunnen, maar wp_check_filetype_and_ext() weigert
		// legitieme bestanden (SVG zonder XML-header = text/plain, .ai = application/pdf ≠ application/postscript).
		// Daarom: eigen magic-check hierboven + move_uploaded_file.
		if ( ! @move_uploaded_file( $file['tmp_name'], $dir . $name ) ) { // phpcs:ignore
			return new WP_Error( 'bps_move', __( 'Opslaan mislukt.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
		@chmod( $dir . $name, 0640 ); // phpcs:ignore
		return array( 'path' => $dir . $name, 'ext' => $ext, 'orig_name' => sanitize_file_name( $file['name'] ), 'bytes' => (int) $file['size'] );
	}

	/** Preview-PNG uit de browser: altijd her-coderen (strip metadata/polyglots), maximaal 1200 px. */
	public static function store_preview( string $tmp ): string|WP_Error {
		if ( 'png' !== self::sniff( $tmp ) ) {
			return new WP_Error( 'bps_preview', 'Invalid preview', array( 'status' => 400 ) );
		}
		$ed = wp_get_image_editor( $tmp );           // Imagick of GD
		if ( is_wp_error( $ed ) ) return $ed;
		$ed->resize( 1200, 1200, false );
		$file = self::preview_dir() . wp_generate_password( 32, false, false ) . '.png';
		$res  = $ed->save( $file, 'image/png' );
		return is_wp_error( $res ) ? $res : self::preview_url_for( $res['path'] );
	}
}
```

Waarom zelf her-coderen: de preview komt uit de browser (canvas `toBlob('image/png')`) en is dus ongecontroleerde invoer.
Her-coderen via `wp_get_image_editor` verwijdert eventuele aangehangen data.

Als je tóch `wp_handle_upload()` gebruikt (bv. voor PNG/JPG): geef `array( 'test_form' => false, 'mimes' => [...] )` mee en zet de map
tijdelijk om met `add_filter( 'upload_dir', $cb )` / `remove_filter` er direct na; anders belanden klantbestanden in de publieke
`uploads/2026/11/`. Bron: <https://developer.wordpress.org/reference/functions/wp_handle_upload/>,
<https://developer.wordpress.org/reference/functions/wp_check_filetype_and_ext/>.

### 7c. SVG-sanering

```bash
composer require enshrined/svg-sanitize:^1.1   # 1.1.1 = 20-10-2026, vereist ext-dom + ext-libxml
composer require --dev brianhenryie/strauss     # 0.31.x: prefixt vendor-namespaces → geen botsing met "Safe SVG" e.a.
```

```php
final class Svg {
	public static function sanitize( string $svg ): string|false {
		if ( strlen( $svg ) > 5 * MB_IN_BYTES ) return false;
		$s = new \BorduurPatchStudio\Vendor\enshrined\svgSanitize\Sanitizer(); // na Strauss-prefix
		$s->removeRemoteReferences( true );   // geen externe <image href="https://...">
		$s->minify( true );
		return $s->sanitize( $svg );          // false bij ongeldige XML
	}
}
```

Lokaal getest (16-11-2026) met `onload`, `<script>`, `javascript:`-link, externe `<image>` en `<foreignObject>`: alles
verwijderd; ongeldige XML → `false`. Extra regels:
- In de browser een SVG **altijd via `<img>`/`Image()` naar canvas** tekenen (scripts lopen dan niet), nooit via `innerHTML`.
- Bij admin-download: `Content-Type: application/octet-stream` + `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`,
  zodat een SVG nooit in de browser van de beheerder rendert.
- PDF/AI/EPS: server bewaart enkel; de browser kan PDF/AI tonen met pdf.js (AI is meestal PDF-compatibel). EPS kan de browser
  niet lezen → eerlijke melding: "We bekijken je EPS-bestand zelf; ondertussen kun je een PNG/JPG gebruiken voor het voorbeeld."
- iPhone-HEIC: met `<input type="file" accept="image/png,image/jpeg,image/webp,.svg,.pdf,.ai,.eps">` converteert iOS foto's
  automatisch naar JPEG. Zet **geen** `image/heic` in `accept`.

### 7d. Download voor beheerders

```php
add_action( 'admin_post_bps_download', function () {
	$id = absint( $_GET['design'] ?? 0 );
	check_admin_referer( 'bps_download_' . $id );
	if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die( '', 403 );   // HPOS gebruikt dezelfde capability
	$d = Designs::get( $id );
	$path = realpath( $d['original_path'] );
	if ( ! $path || ! str_starts_with( $path, realpath( Uploads::private_dir() ) ) ) wp_die( '', 404 ); // path traversal
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="' . rawurlencode( $d['ref'] . '-' . $d['orig_name'] ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore
	exit;
} );
```

### 7e. Opruimen en privacy (GDPR)

- WP-Cron dagelijks: ontwerpen met status `draft` (nooit besteld/offerte) ouder dan **30 dagen** (instelbaar) → bestanden + post wissen.
  Bestelde ontwerpen: bewaren zolang de order bestaat; bij orderverwijdering (`woocommerce_delete_order` / `before_delete_post`) mee opruimen.
- `wp_add_privacy_policy_content()` met tekst over wat we bewaren, en registreer exporter/eraser
  (`wp_privacy_personal_data_exporters` / `_erasers`) op e-mailadres voor offertes.
- Geen IP-adressen opslaan in ontwerpen; voor rate-limiting enkel een **hash** van het IP in een transient van 1 uur.

---

## 8. REST-routes, nonces en caching

### 8a. Feiten

- Sinds WP 5.5 is `permission_callback` **verplicht** (anders `_doing_it_wrong`); voor publieke routes expliciet `__return_true`
  of een eigen guard. Bron: <https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/#permissions-callback>.
- `X-WP-Nonce` (`wp_create_nonce('wp_rest')`) beschermt **alleen ingelogde** cookie-sessies (`rest_cookie_check_errors`). Voor gasten
  wordt er niets gecontroleerd. Een nonce voor uitgelogde bezoekers is bovendien **voor iedereen gelijk** (user 0).
  Bron: <https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/>.
- De pagina met de tool is **gecachet** (WP Rocket, max. levensduur standaard 10 u; Cloudflare voor assets). Een nonce in de HTML
  kan dus verlopen zijn (nonce = 12–24 u geldig). WooCommerce Store API-nonces zijn aan de klantsessie gekoppeld → **nooit**
  een Store API-nonce in gecachte HTML zetten.

### 8b. Aanpak

1. **Geen nonce in de HTML.** De tool haalt bij de eerste schrijfactie een token op via `GET /patch-studio/v1/token`
   (antwoord met `nocache_headers()`, `Cache-Control: no-store`). Token = `wp_create_nonce( 'bps_public' )` voor gasten of
   `wp_create_nonce( 'wp_rest' )` + header `X-WP-Nonce` voor ingelogde gebruikers.
2. Bij `403 rest_cookie_invalid_nonce` of onze eigen `bps_bad_token`: token één keer opnieuw ophalen en request herhalen.
3. Guard voor publieke schrijfroutes:

```php
final class Guard {
	public static function public_write( WP_REST_Request $r ): bool|WP_Error {
		// 1. token (CSRF-light; voor gasten vooral anti-bot, geen echte authenticatie)
		if ( ! wp_verify_nonce( (string) $r->get_header( 'x-bps-token' ), 'bps_public' ) ) {
			return new WP_Error( 'bps_bad_token', 'Token expired', array( 'status' => 403 ) );
		}
		// 2. rate limit per IP-hash: bv. 20 uploads/uur, 5 offertes/uur
		$key  = 'bps_rl_' . md5( ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) . $r->get_route() ); // CF-header: site zit achter Cloudflare
		$hits = (int) get_transient( $key );
		if ( $hits >= ( str_ends_with( $r->get_route(), '/quotes' ) ? 5 : 20 ) ) {
			return new WP_Error( 'bps_rate', __( 'Even geduld: je hebt veel verzoeken na elkaar gestuurd. Probeer binnen een uur opnieuw of bel ons.', 'borduur-patch-studio' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return true;
	}
}
```

   Let op: `HTTP_CF_CONNECTING_IP` alleen vertrouwen omdat de site achter Cloudflare staat; zonder Cloudflare kan iedereen die
   header zetten. Maak het een instelling (standaard aan, met detectie via `HTTP_CF_RAY`).
4. **Ontwerp-token** (bewijs dat de browser het ontwerp zelf maakte): `hash_hmac( 'sha256', $design_id . '|' . $created, wp_salt( 'auth' ) )`.
   Bewaar in de browser (localStorage) samen met het ID, stuur mee bij winkelmand/offerte. Zo kan niemand andermans ontwerp-ID raden
   en aan zijn mand hangen.
5. Args altijd met `sanitize_callback`/`validate_callback` of `'type'`/`'enum'`/`'minimum'` in het schema; nooit vrije HTML terug.
6. Antwoorden van schrijfroutes en `/token`: `nocache_headers()`. WP Rocket cachet `/wp-json/` niet, maar Cloudflare-regels
   ("Cache Everything") zouden dat wel kunnen → expliciet `Cache-Control: no-store`.
7. JSON-ontwerp (tekstlagen, posities, analyse) in de upload-request valideren met een **whitelist-schema** (max. 10 tekstlagen,
   max. 120 tekens per tekst, enum voor lettertypes/vormen, getallen begrensd) en `wp_json_encode` opslaan. Tekst in admin/e-mails altijd `esc_html`.

---

## 9. WP Rocket (en Jetpack Boost) compatibiliteit

Gemeten op `/patches/`: Delay JS **aan** (49× `rocketlazyloadscript`), Defer JS aan (27× `data-rocket-defer`), Minify JS aan,
LazyLoad aan, geen RUCSS. Delay JS laadt scripts pas na de eerste interactie (scroll/touch/klik). Gevolg zonder uitsluiting:
de tool blijft "dood" tot de klant iets aanraakt, en de eerste tik op "Upload je ontwerp" doet niets → dat kost bestellingen.

```php
// WP Rocket ≥ 3.9 — broncode: inc/Engine/Optimization/DelayJS/HTML.php r. 119 (patronen = substring/regex op de <script>-tag)
add_filter( 'rocket_delay_js_exclusions', function ( array $ex ): array {
	$ex[] = '/borduur-patch-studio/assets/';   // onze bestanden (src-pad)
	$ex[] = 'bps-app-js';                      // id-attribuut van wp_enqueue_script('bps-app') en inline -before/-after
	return $ex;
} );
// Defer mag blijven (ons script is defer-veilig). Alleen als je toch inline afhankelijk bent van niet-gedeferde code:
add_filter( 'rocket_exclude_defer_js', fn( $ex ) => array_merge( $ex, array( '/wp-content/plugins/borduur-patch-studio/assets/(.*).js' ) ) ); // inc/Engine/Optimization/DeferJS/DeferJS.php r. 209
// Minify: in principe ok. Bij problemen: rocket_exclude_js (inc/Engine/Optimization/Minify/JS/AbstractJSOptimization.php r. 69).
```

Regels voor de bouwers:
- **Geen jQuery-afhankelijkheid** voor de tool (jQuery is hier wel uitgesloten van delay, maar is gedeferred). Vanilla JS,
  één gebundeld klassiek script (IIFE), `wp_enqueue_script( 'bps-app', $url, array(), BPS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) )`.
  Geen ES-modules via `wp_register_script_module` (WP Rocket-gedrag met `type="module"` is minder voorspelbaar).
- **Config via `data-`-attributen** op de root (`<div class="bps-root" data-config='{"…"}'>`) i.p.v. `wp_localize_script`/inline
  script → niets inline om uit te sluiten, en de config wordt mee gecachet (prima: bevat geen nonce/gebruikersdata).
- Scripts **alleen laden waar de tool staat**: registreren op `wp_enqueue_scripts`, enqueuen vanuit shortcode-render of Elementor
  `get_script_depends()` (§10). Zo blijft de rest van de site even snel.
- Zware onderdelen (pdf.js ~1 MB, lettertypes voor tekstlagen) **lazy laden** met `import()`/dynamisch `<script>` pas als nodig;
  dynamisch toegevoegde scripts worden niet door Delay JS onderschept.
- Afbeeldingen in de tool: `class="skip-lazy"` of `data-no-lazy="1"`, anders zet LazyLoad ze op `data-lazy-src`.
- Lettertypes (Sora/Oswald/… voor tekstlagen): lokaal meeleveren (woff2, subset latin) i.p.v. Google Fonts → geen extra
  cookies/consent nodig en geen layout-shift; laden via `FontFace` API pas wanneer de klant een tekstlaag toevoegt.
- **Cache wissen** na wijziging van onze instellingen of productprijzen, want de config zit in gecachte HTML:

```php
function bps_purge_page_cache(): void {
	if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();      // WP Rocket publieke helper
	// Cloudflare: WP Rocket-addon of Cloudflare-plugin purge't mee; anders melden in admin-notice.
}
add_action( 'update_option_bps_settings', 'bps_purge_page_cache' );
add_action( 'woocommerce_update_product', function ( $id ) { if ( in_array( (int) $id, Config::product_ids(), true ) ) bps_purge_page_cache(); } );
```

- Jetpack Boost (REST-namespace aanwezig): als "Defer Non-Essential JavaScript" daar ook aan staat → dubbel; laat het bij WP Rocket.
  Uitsluiten in Boost kan met attribuut `data-jetpack-boost="ignore"` op de script-tag (filter `script_loader_tag`).
- Documenteer in de README ook de **handmatige** WP Rocket-instelling (Instellingen › Bestandsoptimalisatie › Delay JS › Uitgesloten
  bestanden: `/borduur-patch-studio/assets/`) voor als onze filter door een andere plugin overschreven wordt.

Bronnen: <https://docs.wp-rocket.me/article/1560-delay-javascript-execution>,
<https://docs.wp-rocket.me/article/976-exclude-files-from-defer-js>,
<https://docs.wp-rocket.me/article/1560-delay-javascript-execution-compatibility-exclusions>,
<https://github.com/wp-media/wp-rocket/blob/develop/inc/Engine/Optimization/DelayJS/HTML.php>.

---

## 10. Elementor-widget + shortcode

De site is Hello Elementor + Elementor Pro; de huidige "samensteller" op `/patches/` is een HTML-widget. Beide ingangen
(widget en shortcode) roepen **dezelfde** render-functie aan.

```php
// Shortcode: [patch_studio techniek="embroidered" maat="8" aantal="10" vorm="auto"]
add_shortcode( 'patch_studio', function ( $atts ) {
	$atts = shortcode_atts( array( 'techniek' => '', 'maat' => '', 'aantal' => '', 'vorm' => 'auto', 'compact' => 'no' ), $atts, 'patch_studio' );
	return \BorduurPatchStudio\Frontend::render( $atts ); // enqueue't zelf 'bps-app' + 'bps-style' en geeft HTML terug (nooit echo)
} );

// Elementor-widget (Elementor ≥ 3.5 API; broncode 4.4.1: includes/managers/widgets.php r. 146/158/260)
add_action( 'elementor/widgets/register', function ( \Elementor\Widgets_Manager $wm ) {
	require_once __DIR__ . '/includes/elementor/class-widget.php';
	$wm->register( new \BorduurPatchStudio\Elementor\Widget() );
} );
```

```php
namespace BorduurPatchStudio\Elementor;

class Widget extends \Elementor\Widget_Base {
	public function get_name(): string { return 'bps-patch-studio'; }
	public function get_title(): string { return esc_html__( 'Patch Studio', 'borduur-patch-studio' ); }
	public function get_icon(): string { return 'eicon-image-box'; }
	public function get_categories(): array { return array( 'woocommerce-elements', 'general' ); }
	public function get_keywords(): array { return array( 'patch', 'borduur', 'badge', 'ontwerp', 'designer' ); }
	public function get_script_depends(): array { return array( 'bps-app' ); }   // laadt enkel op pagina's met de widget
	public function get_style_depends(): array { return array( 'bps-style' ); }
	public function has_widget_inner_wrapper(): bool { return false; }          // lichtere DOM (Elementor "optimized markup")

	protected function register_controls(): void {
		$this->start_controls_section( 'content', array( 'label' => esc_html__( 'Startinstellingen', 'borduur-patch-studio' ) ) );
		$this->add_control( 'techniek', array(
			'label'   => esc_html__( 'Standaardtechniek', 'borduur-patch-studio' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => '',
			'options' => array( '' => 'Automatisch (advies)', 'embroidered' => 'Geborduurd', 'printed' => 'Geprint', 'sublimated' => 'Gesublimeerd', 'pvc' => 'PVC' ),
		) );
		$this->add_control( 'compact', array( 'label' => esc_html__( 'Compacte weergave', 'borduur-patch-studio' ), 'type' => \Elementor\Controls_Manager::SWITCHER ) );
		$this->end_controls_section();
		// Geen stijl-controls voor kleuren: de tool gebruikt Elementor globale kleuren via CSS-variabelen
		// (var(--e-global-color-primary, #5926C5) enz.), zodat hij automatisch in het thema blijft.
	}

	protected function render(): void {
		$s = $this->get_settings_for_display();
		echo \BorduurPatchStudio\Frontend::render( array( 'techniek' => $s['techniek'] ?? '', 'compact' => ( $s['compact'] ?? '' ) === 'yes' ? 'yes' : 'no' ) ); // phpcs:ignore -- render() escapet zelf
	}
}
```

- Registreer alleen als Elementor geladen is: `if ( did_action( 'elementor/loaded' ) )` of de hook simpelweg gebruiken (vuurt niet zonder Elementor).
- In de editor-preview draait ons script ook; init dan op
  `window.elementorFrontend?.hooks.addAction('frontend/element_ready/bps-patch-studio.default', ($el) => BPS.mount($el[0]))`.
  Op de **live** pagina niet op `elementorFrontend` wachten: dat script valt onder Delay JS → onze tool zou mee vertraagd worden.
  Mount gewoon op `DOMContentLoaded` voor alle `.bps-root`.
- Element-cache (Elementor ≥ 3.22): `Element_Base::is_dynamic_content()` geeft standaard `true` (broncode r. 308) = niet gecachet.
  Laat zo, tenzij de output gegarandeerd statisch is.
- Elementor globale kleuren zijn als CSS-variabelen beschikbaar (`--e-global-color-primary`, `--e-global-color-secondary`, `--e-global-color-text`,
  `--e-global-color-accent`, en voor extra globale kleuren `--e-global-color-<id>`). Altijd een fallback (#5926C5, #FD5C48, …) meegeven
  voor pagina's buiten Elementor (shortcode in een klassieke pagina).

Bron: <https://developers.elementor.com/docs/widgets/>, <https://developers.elementor.com/docs/scripts-styles/widget-scripts/>.

---

## 11. E-mails met preview-afbeelding

WooCommerce-e-mailtemplate `templates/emails/email-order-items.php` (11.3): toont productafbeelding als `show_image` true is
en filtert die via `woocommerce_order_item_thumbnail` (r. 80 en 166, door `wp_kses_post` → `<img>` met https-URL blijft staan).
`show_image` zet je via `woocommerce_email_order_items_args` (`includes/wc-template-functions.php` r. 4020).

```php
add_filter( 'woocommerce_email_order_items_args', function ( $args ) {
	foreach ( $args['order']->get_items() as $item ) {
		if ( $item->get_meta( '_bps_design_id' ) ) {
			$args['show_image'] = true;
			$args['image_size'] = array( 96, 96 );
			break;
		}
	}
	return $args;
} );

add_filter( 'woocommerce_order_item_thumbnail', function ( $html, $item ) {
	$url = $item instanceof WC_Order_Item_Product ? $item->get_meta( '_bps_preview_url' ) : '';
	if ( ! $url ) return $html;
	return sprintf(
		'<img src="%s" alt="%s" width="96" height="96" style="display:block;border:0;border-radius:6px;max-width:96px;height:auto;" />',
		esc_url( $url ), esc_attr__( 'Voorbeeld van je patch', 'borduur-patch-studio' )
	);
}, 10, 2 );
```

Valkuilen e-mail:
- **Geen data-URI's en geen SVG** in mails (Gmail/Outlook tonen ze niet). Altijd een publieke **PNG** met absolute `https://`-URL.
  Daarom bestaat `uploads/patch-studio/previews/`.
- Preview **zonder** persoonsgegevens en met onraadbare naam; ze is publiek bereikbaar (nodig voor e-mailclients).
- Gmail haalt beelden via zijn proxy op: geen auth of cookies mogelijk → signed/expiring URL's werken pas na de vervaldatum niet meer
  in oude mails. Daarom: permanente, onraadbare URL zolang de order bestaat.
- Extra tekst onder de regel ("Je krijgt eerst een digitale proef ter goedkeuring vóór we starten.") kan met
  `woocommerce_order_item_meta_end` (`$item_id, $item, $order, $plain_text`); respecteer `$plain_text`.
- De beheerdersmail "Nieuwe bestelling" krijgt dezelfde preview: het atelier ziet meteen wat er besteld is.

---

## 12. Offerte-aanvraag

Wanneer: techniek zonder webshopproduct (geweven — min. 100 —, chenille, leder, rubber), maat > 12 cm, PVC < 50, of als de klant
zelf "Ik wil eerst een prijs op maat" kiest. Zie 02 §7.

### 12a. Route + opslag

```php
register_rest_route( 'patch-studio/v1', '/quotes', array(
	'methods'             => 'POST',
	'permission_callback' => array( Guard::class, 'public_write' ),
	'args'                => array(
		'design_id' => array( 'type' => 'integer', 'required' => true ),
		'token'     => array( 'type' => 'string', 'required' => true ),
		'name'      => array( 'type' => 'string', 'required' => true, 'maxLength' => 100, 'sanitize_callback' => 'sanitize_text_field' ),
		'email'     => array( 'type' => 'string', 'required' => true, 'format' => 'email', 'sanitize_callback' => 'sanitize_email' ),
		'phone'     => array( 'type' => 'string', 'maxLength' => 30, 'sanitize_callback' => 'sanitize_text_field' ),
		'company'   => array( 'type' => 'string', 'maxLength' => 100, 'sanitize_callback' => 'sanitize_text_field' ),
		'vat'       => array( 'type' => 'string', 'maxLength' => 20, 'sanitize_callback' => 'sanitize_text_field' ),
		'quantity'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'required' => true ),
		'technique' => array( 'type' => 'string', 'enum' => array( 'woven', 'chenille', 'leather', 'rubber', 'embroidered', 'printed', 'sublimated', 'pvc' ) ),
		'deadline'  => array( 'type' => 'string', 'format' => 'date' ),
		'message'   => array( 'type' => 'string', 'maxLength' => 2000, 'sanitize_callback' => 'sanitize_textarea_field' ),
		'consent'   => array( 'type' => 'boolean', 'required' => true ),
		'website'   => array( 'type' => 'string' ),            // honeypot: moet leeg zijn
		'elapsed'   => array( 'type' => 'integer' ),           // ms sinds formulier open; < 3000 = bot
	),
	'callback'            => array( Quotes::class, 'create' ),
) );
```

`Quotes::create`: honeypot/tijd controleren → ontwerp-token verifiëren → `wp_insert_post( post_type 'bps_quote', post_status 'private' )`
met meta (contact, aantal, techniek, ontwerp-ID, analyse-samenvatting, taal nl/fr) → ontwerpstatus `quoted` → twee e-mails → antwoord
`{ ok: true, ref: 'OF-2026-0017' }`. Toon de klant daarna een duidelijke bevestiging: wat er nu gebeurt, wanneer (instelbare tekst,
geen beloofde termijn als de eigenaar er geen opgeeft), en dat de digitale proef gratis vooraf komt.

### 12b. E-mails in WooCommerce-stijl (beheerbaar)

Eigen `WC_Email`-subklassen via `woocommerce_email_classes` → zichtbaar onder WooCommerce › Instellingen › E-mails (aan/uit,
ontvanger, onderwerp, kop), zelfde header/footer/kleuren als de ordermails, en WP Mail SMTP (actief op de site) verstuurt ze.

```php
add_filter( 'woocommerce_email_classes', function ( $emails ) {
	$emails['BPS_Email_Quote_Admin']    = new \BorduurPatchStudio\Emails\QuoteAdmin();    // naar winkel; Reply-To = klant
	$emails['BPS_Email_Quote_Customer'] = new \BorduurPatchStudio\Emails\QuoteCustomer(); // bevestiging naar klant (nl/fr)
	return $emails;
} );
// Versturen: WC()->mailer()->emails['BPS_Email_Quote_Admin']->trigger( $quote_id );
```

Admin-mail bevat: preview-PNG (`<img>` zoals §11), techniek + advies + analyse in mensentaal (kleuren, fijnste lijn in mm, vorm),
aantal/maat/deadline, contact, knop "Origineel downloaden" (admin-post-link met nonce, werkt alleen ingelogd) en "Bekijk offerte in WP".
Header `Reply-To: Naam <klant@…>` via `get_headers()` van de e-mailklasse, zodat de eigenaar direct kan antwoorden.

Snelle variant als de tijd op is: `WC()->mailer()->wrap_message( $heading, $html )` + `WC()->mailer()->send( $to, $subject, $msg, $headers )`.

### 12c. Koppeling met het bestaande Elementor-offerteformulier

`/offerte-aanvragen/` gebruikt een Elementor Pro Form. Twee opties, niet exclusief:
- Onze eigen route (aanbevolen: ontwerp, analyse en bestand gaan gegarandeerd mee).
- Een link "Liever het gewone offerteformulier?" naar `/offerte-aanvragen/?ontwerp=OF-2026-0017&techniek=geweven`; in Elementor Pro
  kan een veld die waarde vooraf invullen met de dynamische tag **Request Parameter**. Het formulier krijgt dan enkel de referentie;
  het atelier vindt het ontwerp terug in WP onder Patch Studio › Ontwerpen.

---

## 13. Compatibiliteit met de overige plugins op de site

| Plugin | Wat kan misgaan | Wat doen |
|---|---|---|
| woocommerce-product-addons | Veldindex verschuift; verplichte velden ontbreken → "Vul de verplichte velden in"; PA bewaart eigen ordermeta (labels) | Mapping op naam/label (§3a), kassa-check (§15), altijd verplichte velden vullen met de standaardoptie uit 02 §10b |
| woo-discount-rules | Dubbele staffel; prijs na prio 1000 zetten wist korting | §5 |
| conditional-extra-fees-for-woocommerce | Fees zijn gebonden aan **producten** in de mand | Door op bestaande producten te bestellen werken ze vanzelf. Toon in de tool dezelfde fees (config) met uitleg "eenmalig per bestelling" |
| super-product-swatches | Verandert het variatieformulier op de productpagina | Wij posten zelf `variation_id` + `attribute_*`; swatches zijn niet betrokken. Wel testen dat het attribuutnaam/-slug klopt (`attribute_pa_maat`?) |
| wp-rocket | Delay JS, LazyLoad, cache met oude config | §9 |
| cookie-law-info (CookieYes) | Blokkeert scripts met tracking-categorie | Onze tool zet geen tracking-cookies → niet categoriseren. Eventuele analytics-events (bv. "advies getoond") enkel via bestaande `gtag` als die al toestemming heeft (google-listings-and-ads laadt gtag) |
| jetpack / Jetpack Boost | Dubbele JS-optimalisatie | §9 |
| Mollie | Order `pending` tot betaling | Ontwerpstatus `ordered` pas zetten bij `woocommerce_checkout_order_processed`/Store API-variant; "betaald" eventueel op `woocommerce_order_status_processing` |
| MyParcel | Leest orderregels voor verzendlabel | Geen impact; houd zichtbare meta kort (lange teksten belanden soms op pakbonnen) |
| Rank Math | Sitemap kan CPT's opnemen | CPT's `public => false`, `publicly_queryable => false`, `exclude_from_search => true` |
| Elementor Pro Cart/Checkout-widgets | Klassieke templates | Klassieke filters (§4) werken; testen in de Elementor-cart-widget-weergave |

---

## 14. Meertaligheid (nl + fr)

- Alle PHP-teksten via `__()`/`esc_html__()` met text domain `borduur-patch-studio`; JS-teksten via `wp_set_script_translations( 'bps-app', 'borduur-patch-studio', plugin_dir_path( __FILE__ ) . 'languages' )`
  en `wp.i18n.__` (vereist dependency `wp-i18n`; ±5 kB) **of** een eigen JSON-woordenboek in de `data-config` (lichter en
  geen extra afhankelijkheid). Aanbevolen: eigen woordenboek met `nl_BE` als bron en `fr_BE`, taal via `determine_locale()`.
- De site heeft vandaag geen meertaligheidsplugin (geen WPML/Polylang-namespaces in de REST-index). Taal van offertemails = taal van de tool-sessie, opgeslagen bij de offerte.
- Klant-e-mails van WooCommerce volgen de site-taal; onze `WC_Email`-klassen kunnen per offerte `switch_to_locale()` doen.

---

## 15. Admin "Kassa-check" (aanbevolen, voorkomt de prijsverschillen uit 02 §8)

Een knop in Patch Studio › Instellingen die voor elke techniek in de config:
1. `wc_get_product( $variation_id )` ophaalt en de prijs vergelijkt met de config;
2. de PA-mapping (§3a) toont en controleert of elk optielabel uit de config bestaat;
3. optioneel een **echte proefmand** opbouwt in een tijdelijke sessie (zoals 02 deed via de Store API) en het totaal vergelijkt met de
   voorspelling van de tool (zelfde rekenfunctie als in JS, gedeeld via een JSON-testtabel) — mand daarna leegmaken;
4. resultaat: groen/rood per regel met de concrete afwijking ("Config zegt borduurkaart € 45,00 excl., fee-plugin rekent € 45,00 ✓").

Zo kan de eigenaar prijzen in WooCommerce wijzigen zonder dat de tool stilletjes andere bedragen toont dan de kassa — essentieel voor
eerlijk advies.

---

## 16. Valkuilen-checklist voor de bouwers

1. `permission_callback` vergeten → notice + publiek schrijfbaar.
2. Nonce of Store API-nonce in gecachte HTML → werkt een dag, daarna 403 voor iedereen.
3. Base64-afbeelding in `cart_item_data` → sessietabel explodeert, trage site.
4. `set_price()` in `before_calculate_totals` zonder idempotentie → prijs stapelt bij meerdere herberekeningen.
5. Eigen staffel + WDR-staffel → dubbele korting. Prijs zetten na prio 1000 → staffel weg.
6. Toevoegen via Store API → PA-verplichte velden falen.
7. `WC()->cart` in REST zonder `wc_load_cart()` → `null`/fatal.
8. `get_post_meta( $order_id, … )` → breekt met HPOS.
9. Klantbestanden in `uploads/YYYY/MM` via standaard `wp_handle_upload` → publiek en indexeerbaar.
10. SVG zonder sanering of inline in de DOM → XSS in de admin.
11. Vertrouwen op `$_FILES['type']` of extensie → gebruik magic bytes.
12. `.htaccess` als enige bescherming → zelftest + onraadbare namen.
13. Data-URI/SVG in e-mails → onzichtbaar in Gmail/Outlook.
14. Script vertraagd door WP Rocket Delay JS → tool reageert niet bij eerste tik.
15. Afbeeldingen in de tool/mini-cart door LazyLoad vervangen → `skip-lazy`.
16. Wachten op `elementorFrontend` op de live pagina → mee vertraagd.
17. Config met prijzen in gecachte HTML zonder cache-purge bij prijswijziging → oude prijzen in de tool.
18. PVC: minimum 50, stappen van 10 → zowel in de tool als in `woocommerce_add_to_cart_validation` afdwingen (en `woocommerce_quantity_input_args` voor de mand).
19. `HTTP_CF_CONNECTING_IP` vertrouwen zonder Cloudflare → spoofbaar.
20. Gesublimeerd zonder staffel is het **huidige** kassagedrag → tool moet dat tonen, niet anders beloven.

---

## 17. Bronnen

- WooCommerce 11.3.0 broncode (downloads.wordpress.org/plugin/woocommerce.latest-stable.zip): `includes/class-wc-cart.php`,
  `includes/class-wc-checkout.php`, `src/StoreApi/Schemas/V1/CartItemSchema.php`, `src/StoreApi/Routes/V1/CartAddItem.php`,
  `src/StoreApi/Utilities/OrderController.php`, `src/StoreApi/Routes/V1/Checkout.php`, `templates/emails/email-order-items.php`,
  `includes/class-wc-order-item.php`, `src/Utilities/FeaturesUtil.php`, `src/Internal/Utilities/OrderItemMetaUtil.php`.
- Woo Discount Rules 2.6.18 broncode (downloads.wordpress.org/plugin/woo-discount-rules.latest-stable.zip): `v2/App/Router.php`,
  `v2/App/Controllers/ManageDiscount.php`, `v2/App/Controllers/DiscountCalculator.php`, `v2/App/Helpers/Helper.php`,
  `v2/App/Compatibility/ProductAddOnsByWooCommerce.php`.
- Elementor 4.4.1 broncode: `includes/managers/widgets.php`, `includes/base/element-base.php`, `includes/base/widget-base.php`.
- WP Rocket: <https://github.com/wp-media/wp-rocket/blob/develop/inc/Engine/Optimization/DelayJS/HTML.php>,
  <https://github.com/wp-media/wp-rocket/blob/develop/inc/Engine/Optimization/DeferJS/DeferJS.php>,
  <https://github.com/wp-media/wp-rocket/issues/3233>, <https://docs.wp-rocket.me/article/976-exclude-files-from-defer-js>,
  <https://docs.wp-rocket.me/article/1560-delay-javascript-execution-compatibility-exclusions>,
  <https://docs.wp-rocket.me/article/1645-exclude-js-scripts-from-delay-js-only-at-some-urls>.
- HPOS: <https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/>.
- Cart/Checkout blocks extensibility: <https://developer.woocommerce.com/docs/block-development/cart-and-checkout-blocks/>,
  Store API: <https://developer.woocommerce.com/docs/apis/store-api/>.
- WordPress REST: <https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/>,
  <https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/>.
- Uploads: <https://developer.wordpress.org/reference/functions/wp_handle_upload/>,
  <https://developer.wordpress.org/reference/functions/wp_check_filetype_and_ext/>,
  <https://developer.wordpress.org/reference/functions/wp_get_image_editor/>.
- SVG: <https://packagist.org/packages/enshrined/svg-sanitize> (1.1.1), <https://github.com/darylldoyle/svg-sanitizer>;
  Strauss: <https://github.com/BrianHenryIE/strauss>.
- Elementor: <https://developers.elementor.com/docs/widgets/>, <https://developers.elementor.com/docs/scripts-styles/widget-scripts/>.
- Product Add-Ons: live schema <https://borduurservicebelgie.be/wp-json/wc-product-add-ons/v2>;
  docs <https://woocommerce.com/document/product-add-ons/>; algemene cart-item-data:
  <https://wp-kama.com/plugin/woocommerce/hook/woocommerce_add_cart_item_data>, <https://pluginrepublic.com/add-custom-cart-item-data-in-woocommerce/>.
- Live site (16-11-2026): `https://borduurservicebelgie.be/wp-json/`, `/patches/`, `/winkelwagen/`, `/offerte-aanvragen/` (headers + HTML).
- Prijzen/velden/kassagedrag: `docs/research/02-huidig-aanbod.md` (collega-onderzoeker).

## 18. Open punten (te verifiëren op staging)

1. Exacte PA-veldnaamopbouw (`field_name` vs index) en `WC_Product_Addons_Helper::get_product_addons()`-signatuur in de geïnstalleerde PA-versie.
2. Kassa (`/afrekenen/`): klassiek (Elementor Checkout-widget) of blok? Met een gevulde mand controleren.
3. Variatie-attribuutnaam en slugs (`attribute_pa_maat` = `8-cm`?) — uitlezen uit `$product->get_variation_attributes()`.
4. Doet PA zijn prijsaanpassing in `woocommerce_add_cart_item`/sessie-load of in `before_calculate_totals`? (bepaalt of onze toeslag-hook op 500 erna komt; met de "liever PA-optie"-regel is dit enkel relevant als er toch een eigen toeslag komt.)
5. WP Rocket-versie op de site (≥ 3.9 nodig voor `rocket_delay_js_exclusions`) en of Jetpack Boost JS-defer aan staat.
6. PHP `upload_max_filesize`/`post_max_size` bij Hostinger (WP `wp_max_upload_size()`), en Imagick beschikbaar voor her-coderen.
