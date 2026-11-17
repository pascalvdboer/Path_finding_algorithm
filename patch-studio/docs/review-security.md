# Security review: Patch Studio (borduur-patch-studio)

Reviewer "security", 17-11-2026. Scope: alle PHP (REST, admin-post, AJAX, templates, uploads, SVG, cart/order, proeflinks, privacy, uninstall) plus de JS-plekken die tokens of HTML aanraken.

## Samenvatting
De basis is degelijk. Prijzen worden altijd op de server berekend. Ontwerptokens en proeftokens zijn HMAC-SHA256 en worden met `hash_equals` gecontroleerd. Uploads worden op inhoud gesniffed, nooit op extensie, en krijgen willekeurige namen in een afgeschermde privémap. Voorbeeld-PNG's worden opnieuw geëncodeerd. Admin-acties controleren nonce én capability, en de output wordt consequent ge-escaped.
Ik vond geen kritieke of hoge problemen. Vijf kleine of middelgrote punten heb ik meteen gerepareerd (zie §1).

## 1. Gerepareerd
| # | Ernst | Probleem | Fix | Bestand |
|---|---|---|---|---|
| S1 | Laag-middel | **Info-lek op de proefpagina**: `?bps_proof={id}&o={orderId}&t=<fout>` toonde ook zonder geldig token de ontwerpreferentie (PS-…) en het bestelnummer van een willekeurig order-ID. Daarmee kon je ID's en bestelnummers opsommen. | Bij een ongeldig token krijgt de template geen design, proef of order meer mee: je ziet enkel "Deze link werkt niet (meer)" met HTTP 403. Getest op de lokale site: een ongeldig token toont geen referentie meer, een geldige link werkt nog. | `includes/admin/class-order-production.php` (`handle_proof_response`) |
| S2 | Laag-middel | **Geheugen-DoS met de preview**: een PNG tot 40 MP werd met GD gedecodeerd (± 200 MB RAM per request). Dat geeft fatal errors en een makkelijke DoS. | De limiet is nu 4096×4096 (16,7 MP); de UI stuurt 1200 px. Vóór het decoderen roepen we `wp_raise_memory_limit( 'image' )` aan, net als WordPress core. | `includes/class-uploads.php` |
| S3 | Laag | **SVG-CSS**: een niet-gesloten `url(https://…` op het einde van een `<style>` (CSS sluit die automatisch) en `image-set("https://…")` bleven staan. Dat zijn externe verzoeken (tracking) als iemand het bestand rechtstreeks opent. | Alles wat geen lokale `url(#id)` is, wordt nu `none(`, en `image-set(` wordt geneutraliseerd. | `includes/woo/class-svg-sanitizer.php` (`clean_css`) |
| S4 | Laag | **SVG-root in een vreemde namespace** (bv. een XHTML-element `<svg xmlns="http://www.w3.org/1999/xhtml">`) werd aanvaard. | Het document wordt geweigerd als de root niet in de SVG-namespace zit (of geen namespace heeft). | idem |
| S5 | Laag-middel | **Mailmisbruik**: `POST /designs/{id}/email` stuurt een mail naar elk willekeurig adres. Per IP is er een limiet van 5 per uur, maar met één ontwerp kon je toch veel adressen bestoken. | Een ontwerp mag naar hoogstens 3 verschillende adressen gemaild worden (filter `bps_max_emails_per_design`). Opnieuw naar hetzelfde adres sturen mag altijd. We bewaren alleen een HMAC-hash van het adres (`_bps_email_sent_to`), en de privacy-eraser wist die meta mee. Getest: het 4de adres krijgt 429 `bps_rate`. | `includes/rest/class-routes.php`, `includes/woo/class-privacy.php` |
| S6 | Laag (privacy) | **Hersteltoken bleef in de adresbalk**: `?bps_design=&bps_token=` kon zo in bladwijzers, gedeelde links, screenshots en de URL van latere analytics-hits terechtkomen. | De UI leest de parameters en haalt ze meteen uit de URL met `history.replaceState`. Een reload werkt nog via de sessie-opslag. | `assets/src/ui/app.js` (mount) |

Na de fixes: `npm run build` groen (94,4 kB gzip), `npm test` 172/172 groen, `npm run test:php` 47 vectoren / 652 checks groen, `php -l` op alle gewijzigde bestanden OK.

## 2. Gecontroleerd en in orde
**Prijsmanipulatie**
- Het cart-item krijgt de `bps`-data uit het server-side gevalideerde ontwerp (`Payload::validate`: techniek, vorm, opties en maat tegen de config, aantal begrensd). De prijs komt telkens uit `Pricing::cart_unit_excl` in `woocommerce_before_calculate_totals`. `priceClient` dient alleen voor de mismatch-log.
- Ons product rechtstreeks toevoegen kan niet: `?add-to-cart` en de Store API worden geblokkeerd met `block_direct_add` / `block_store_api_add`. Wezen zonder `bps` verdwijnen bij het laden van de sessie (`drop_orphans`).
- Bij de checkout valideert `check_cart_items` alles opnieuw. Woo Discount Rules telt onze regels niet mee.

**IDOR**
- Elke route met een ontwerp-ID vereist het HMAC-token: GET/cart/quotes/email. Het token is niet raadbaar (256 bit).
- `/designs/{id}/original` gebruikt een aparte gesigneerde URL die 1 u geldig is. Het bestand moet in de privémap liggen (`is_ours`) en een browsertype hebben.
- Admin-AJAX controleert of het item bij het order hoort (`design_items`).

**Proeflinks**
- Het token is `hmac(proof|design|order|ronde)`, met `hash_equals`. Het order-ID moet overeenkomen.
- GET toont alleen een bevestigingspagina. Pas een POST registreert iets, zodat linkscanners in mail niets goedkeuren.
- Oude rondes krijgen "superseded". De pagina stuurt `nocache` en `noindex`.

**Nonces en capabilities**
- `admin_post_bps_download`, `bps_print_fiche`, settings en create-page, en `wp_ajax_bps_*` gebruiken allemaal `check_admin_referer` / `check_ajax_referer` plus `current_user_can`: `edit_shop_orders`, `manage_woocommerce` of `publish_pages`.
- De CPT's zijn niet publiek en niet zichtbaar in REST. Hun caps lopen via `manage_woocommerce`, en offertes kun je niet aanmaken in de admin.

**Publieke REST**
- Schrijfroutes vereisen de custom header `X-BPS-Token`. WordPress staat die header niet toe in de CORS-preflight, dus een cross-site formulier of fetch kan hem niet meesturen. Dat maakt ook `restore_logged_in_user()` voor `/cart` CSRF-veilig.
- Rate limits werken per IP-hash. `CF-Connecting-IP` vertrouwen we alleen vanuit de Cloudflare-ranges. Er zijn honeypots, en antwoorden krijgen `no-store`.

**Uploads**
- Het type wordt uit de magic bytes afgeleid. Er bestaan geen uitvoerbare extensies, de namen zijn willekeurig (62^32) en `is_uploaded_file` / `move_uploaded_file` worden gebruikt.
- De privémap heeft `.htaccess`, `web.config` en `index.php`, plus een zelftest die nginx detecteert.
- Previews worden met GD opnieuw geëncodeerd naar PNG. In die map wordt alles behalve `.png` geweigerd.
- Pad-traversal wordt afgevangen met `realpath`-prefix (`is_ours`), en verwijderen kan alleen binnen de eigen map.
- Streamen gebeurt als attachment met `nosniff` en `CSP: sandbox`. Admin-downloads zijn `application/octet-stream`. Bestandsnamen gaan door `sanitize_file_name` (geen CRLF).

**SVG**
- Allowlist van elementen. DOCTYPE en entities worden geweigerd (`LIBXML_NONET`). Event-attributen worden geschrapt, `href` mag alleen `#id` of `data:image/(png|jpg|gif|webp)` zijn.
- Geen `script`, `foreignObject`, `animate` of `set`. Vreemde namespaces gaan eruit. In de browser rendert de UI het bestand alleen via `<img>` / blob, nooit via innerHTML.

**XSS en escaping**
- Templates en admin gebruiken `esc_html` / `esc_attr` / `esc_url` / `esc_textarea`.
- JSON in `<script>` gebruikt `JSON_HEX_TAG|JSON_HEX_AMP`.
- In de JS komt `innerHTML` alleen voor bij statische iconen, QR-SVG en het server-ge-escapede proefblok.
- Klantvelden gaan door `sanitize_text_field` / `sanitize_textarea_field`. In de Reply-To-header worden CR/LF en `<>",;` verwijderd.

**SQL en overige**
- Alle queries gebruiken `$wpdb->prepare`.
- `uninstall.php` heeft de guard `WP_UNINSTALL_PLUGIN`.
- Geen `unserialize`, `eval` of shell-calls.

## 3. Open punten (geen code-fout, wel om te beslissen)
1. **Mail naar willekeurige adressen** blijft mogelijk via `/quotes` (bevestigingsmail met de ingevulde naam, max. 100 tekens) en `/email`, telkens 5 per uur per IP. Dat is gangbaar voor een contactformulier. Bij misbruik kun je de limiet verlagen in `woo.rateLimits`, of later een gratis Cloudflare Turnstile toevoegen.
2. **Rate limit achter een andere proxy dan Cloudflare**: dan delen alle bezoekers het IP van die proxy, en dus ook de limiet. In dat geval moet de proxy-IP-logica uitgebreid worden (filter of instelling).
3. **nginx**: de privémap is alleen afgeschermd via `.htaccess`. Op nginx is de serverregel nodig die de zelftest in tab Algemeen toont. De namen zijn wel onraadbaar.
4. **PDF/AI/EPS/PSD/GIF** worden onbewerkt bewaard; dat is nodig voor productie. Open ze in up-to-date software. De admin-download is altijd een attachment.
5. **Ontwerptokens verlopen niet**, alleen via de bewaartermijn (cleanup). Dat is een bewuste keuze: de bewaarmail moet blijven werken.
6. Het **`elapsed`-veld** (anti-bot bij offertes) is optioneel: een bot laat het gewoon weg. De honeypot en de rate limit blijven de echte rem.
7. **WooCommerce-kortingsbonnen** werken op het Patch Studio-product. Dat is een zakelijke keuze, geen lek.
