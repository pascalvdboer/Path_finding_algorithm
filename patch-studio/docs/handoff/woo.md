# Handoff — woo (WooCommerce-integratie, REST, uploads, shortcode/widget, e-mails)

## Gebouwd
| Bestand | Inhoud |
|---|---|
| `includes/woo/class-integration.php` | Component `Woo\Integration` (register/activate/deactivate) → registreert alle delen hieronder. HPOS-verklaring in docblock. |
| `includes/woo/class-config.php` | `Woo\Config`: leest `Settings::get()` (fallback: defaults.json), labels NL/FR, `product_id()`, `money()`, `size_text()` ("8 cm (80 × 80 mm)"). |
| `includes/woo/class-designs.php` | CPT `bps_design` + **exact de SPEC §6.1-API** (`get`, `token_for`, `verify_token`, `set_status`, `update_proof`, `original_download_url`) + `create`, `next_ref` (CAS-teller per jaar), `restore_url`, `signed_original_url`, `delete_files`, admin-download `admin-post.php?action=bps_download`. |
| `includes/woo/class-payload.php` | Whitelist-validatie DesignPayload (enums uit config, getallen begrensd, max 10 teksten × 60 tekens, notes ≤ 2000, onbekende sleutels weg). |
| `includes/woo/class-product.php` | Product "Patch op maat" (aanmaken/herstellen, lock tegen dubbel aanmaken, zichtbaarheid/naam uit settings), weigert gewone add-to-cart (klassiek + Store API) met link naar de studio, productpagina → 302 naar studio + noindex. |
| `includes/woo/class-cart.php` | Server-side prijs (`before_calculate_totals` prio 20, absolute prijs via `Pricing::cart_unit_excl`, staffelpool), fees per groep (scope order/design, legacy-skip) + per_line-extra's, WDR-uitsluiting (2 filters), revalidatie in `woocommerce_check_cart_items`, weergave (item data, thumbnail, Store API-afbeelding, permalink = ontwerp heropenen), aantalregels (klassiek + Store API min/multiple_of), kassamelding D (klassiek + boven het checkout-blok), knop "Bestellen en betalen" (klassiek filter + `registerCheckoutFilters` voor de blok-kassa). |
| `includes/woo/class-order.php` | Order item meta (SPEC §6.4-tabel, zichtbare + verborgen sleutels, `_bps_rights_consent` met text_sha1), vertaalde labels, `ordered`-status + `bps_design_ordered` (klassiek, Store API, payment_complete). |
| `includes/woo/class-emails.php` + `emails/class-base.php`, `class-quote-admin.php`, `class-quote-customer.php`, `class-saved-design.php` | `BPS_Email_Quote_Admin` (Reply-To klant), `BPS_Email_Quote_Customer` + `BPS_Email_Saved_Design` (taal van de klant via `switch_to_locale`); preview in ordermails (`show_image` + `woocommerce_order_item_thumbnail`), blok F "Wat gebeurt er nu?" (proeftermijn en annuleerzin enkel als ingesteld). |
| `includes/woo/class-quotes.php` | CPT `bps_quote` (WooCommerce › Patch-offertes, lijst met voorbeeld/klant/techniek/aantal, detailbox met download), `create()`, `design_rows()` (mensentaal: maat, garens, tekst, analyse). |
| `includes/woo/class-privacy.php` | Privacytekst (08 §2.5, termijnen uit config), exporter + eraser op e-mail (ontwerpen, offertes, orders via `wc_get_orders`; lopende orders → `items_retained`). |
| `includes/woo/class-cleanup.php` | Cron `bps_cleanup` dagelijks 03:17 (draft/carted, saved, quoted, ordered → ordermeta "Ontwerpbestand verwijderd op …"), batches van 50 met vervolgrun. |
| `includes/woo/class-svg-sanitizer.php` | Eigen allowlist-SVG-sanitizer (DOM, geen DOCTYPE/entities, geen script/foreignObject/animate/set, geen on*, enkel `#id`- of raster-data-href, CSS @import/url()/escapes weg). Gebruikt enshrined/svg-sanitize als extra pas als die klasse bestaat. |
| `includes/class-uploads.php` | Privé-opslag + previews (SPEC §6.1), magic-bytes (png/jpg/webp/gif/svg/pdf/ai/eps/psd), `.ai` blijft `.ai`, preview her-gecodeerd met GD (max 1200 px, min 600 px, ≤ 40 MP), `.htaccess`/`web.config`/`index.php`, `self_test()` (cache 12 u), `is_ours()` tegen path traversal. |
| `includes/rest/class-guard.php` | Token (`/token`), `public_write` (X-BPS-Token + rate limit), `public_read` (enkel rate limit), IP-hash (CF-Connecting-IP enkel als REMOTE_ADDR in Cloudflare-ranges bij `trustCloudflareIp:'auto'`). |
| `includes/rest/class-routes.php` | Alle routes uit SPEC §6.2 + `GET /designs/{id}/original` (gesigneerd, 1 u). Antwoorden `no-store`; taal FR als `locale`/`X-BPS-Locale`/design-JSON FR is. |
| `includes/frontend/class-shortcode.php` | `[patch_studio …]` → `Shortcode::render()` (root + config-JSON + skelet + noscript), CSS in `<head>` als de pagina de shortcode bevat, onthoudt de studiopagina. |
| `includes/frontend/class-elementor-widget.php` | Widget `bps-patch-studio` (techniek, startmaat, aantal, vorm, compact) → dezelfde `render()`. |
| `templates/emails/{save-design,quote-admin,quote-customer}.php` + `plain/` | WooCommerce-stijl, overschrijfbaar via `thema/woocommerce/emails/…`. |

## Afwijkingen van / aanvullingen op SPEC (contract naar buiten intact)
- **Statusvolgorde**: `set_status()` verlaagt nooit. Rang: draft < carted < saved < quoted < ordered (een bewaard ontwerp dat later in de mand komt, houdt de 90-dagen-termijn i.p.v. 30).
- **Cart item data `bps`**: extra sleutels `width_mm`, `height_mm`, `locale` (nodig voor "8 cm (80 × 80 mm)").
- **Extra meta op `bps_design`**: `_bps_technique`, `_bps_page_url`, `_bps_ordered_gmt`, `_bps_status_changed_gmt`, `_bps_files_removed_gmt`, `_bps_quote_id`. Ordermeta extra: `bps_file_removed` (zichtbaar, "Ontwerpbestand").
- **Extra REST-route** `GET /designs/{id}/original?exp=&sig=` (doel van `originalUrl`). `POST /designs` aanvaardt optioneel `pageUrl` (zelfde host; anders Referer) voor de herstel-link in de bewaarmail. `POST /quotes`: `designId` optioneel; fouten per veld in `data.fields`. `POST /cart` 400 `bps_min_qty` heeft `data.min`/`data.step`.
- **`/quote` (prijs)** gebruikt `Guard::public_read` (geen token nodig, wel 120/min) – het is geen schrijfroute.
- **Taal ordermeta** = taal van het afrekenverzoek (zoals mand en fees), niet de ontwerptaal. Klantmails van offerte/bewaren zijn wél in de ontwerptaal.
- **SVG**: geen Strauss/vendor-prefixed (valt buiten mijn schrijfrechten); eigen sanitizer, getest tegen XXE, `jav&#x09;ascript:`, XHTML-namespace, `<set>`, data:svg, CSS-escapes, CDATA-script.
- **Nieuwe opties**: `bps_studio_page_id` (studiopagina), `bps_product_lock` (kortstondig). Transient `bps_uploads_selftest`. Core `uninstall.php` mag die mee opruimen.
- Product krijgt geen korte beschrijving (de blok-mand toonde die onder elke patch).

## Voor de andere bouwers / integrator
- **ui**: skelet-markup in de root: `.bps-skeleton[data-bps-skeleton]`, bestandsinput `[data-bps-skeleton-input]` (een bestand dat vóór het laden gekozen werd zit in `input.files`), knop `[data-bps-action="text-only"]`, klassen `.bps-skeleton__title|subtitle|dropzone|drop-main|drop-hint|input|reassure|alt|text-only|privacy|noscript`. Compact: `.bps-studio--compact`. Bij `403 bps_bad_token` één keer `/token` opnieuw.
  Flow: `POST /designs` (multipart: `design`, `preview` ≥ 600 px PNG, optioneel `original`, `website:''`, optioneel `pageUrl`) → `POST /cart {designId, token, rightsConsent:{v, accepted:true}}` → `cartUrl`. Gebruik `quote` uit het antwoord als serverbedrag.
- **pricing**: verwacht `Pricing::quote($input)` met sleutels uit PriceInput (+ `locale`) en `Pricing::cart_unit_excl($bps, $qty, $pool_qty)` waarbij `$bps` de cart-data is (`technique`, `size_cm`, `options`). Tot de echte engine er was, testte ik met een stub in mijn scratch (niet in de plugin).
- **production**: gebruik `Woo\Designs` (SPEC-API). `Woo\Quotes::design_rows($design, $locale)` en `Woo\Cart::summary_rows()` geven kant-en-klare regels. Een eigen `BPS_Email_Proof` kan `Woo\Emails\Base` uitbreiden (taalwissel + templates in `templates/`) en registreert zich zelf via `woocommerce_email_classes`.
- **core**: Settings_Page zet ook al de productzichtbaarheid; `Product::sync_settings()` doet hetzelfde idempotent. Shortcode valt terug op `Plugin::register_assets()` als `bps-app` nog niet geregistreerd is.

## Testen (allemaal groen)
Eigen WP 6.x + WooCommerce 11.3 + SQLite in scratch `woo-wp` (`php -S 127.0.0.1:8988 -t site router.php`), met HPOS **aan én uit** getest:
- `node e2e.mjs` (44 checks): studiopagina/config/skelet, token + no-store, `/quote` 69,58, uploads (te kleine preview, fout type 415, honeypot, ongeldige techniek), SVG-sanering + gesigneerde originalUrl, rechten verplicht, mand = server-quote (182,41 + borduurkaart 54,45), zelfde ontwerp vervangt regel, gepoolde staffel (printed 10 + embroidered 25 → 10 %), PVC min 50/stap 10 (REST + Store API-limieten + update geweigerd), geweven → 409, offerte + te-snel-bot, bewaar & mail, Store API add-item van ons product geweigerd, klassieke mand/kassa (melding + knoptekst), blok-kassa (melding + knop "Bestellen en betalen" in de browser), Store API-checkout.
- `node classic.mjs` + `check-classic.php`: klassieke kassa (wc-ajax=checkout) met gesublimeerd 7 cm ×3 + flock + schaar = € 75,87, ordermeta, status `ordered`.
- `e2e-admin.mjs`: offertelijst/detail in admin, download origineel als attachment (met nonce, zonder → geweigerd), ingelogde klant: mand komt in de gebruikerssessie.
- PHP: ordermeta + e-mails (preview, blok F alleen naar klant), cleanup (draft weg, ordered → bestanden weg + ordernotitie), privacy export/erase, ref-teller, zelftest (detecteert terecht dat `php -S` .htaccess negeert), Elementor-widget registreert en rendert.
- `php -l` op alle eigen PHP-bestanden: OK; geen notices van de plugin in debug.log.

## Open punten
1. FR-vertaling van de PHP-teksten (REST-fouten, e-mails, mandregels) komt uit `languages/fr_BE.po` (core). Config-labels (techniek/vorm/opties) zijn al NL/FR.
2. Server-cron aanraden (WP-Cron draait pas bij bezoek). Nginx-regel voor `private/` staat in de zelftestmelding.
3. Verzending: WooCommerce selecteert standaard de eerste methode (betaald) ook als gratis mag; zet "Gratis" bovenaan of verberg flat rate boven € 150 (eigenaar).
4. `woo.quoteRecipient` leeg = beheerdersmail; ontvanger is ook instelbaar onder WooCommerce › Instellingen › E-mails.
5. Rate limits (`woo.rateLimits`) zijn per IP-hash; achter een andere proxy dan Cloudflare `trustCloudflareIp` op `false` laten.
