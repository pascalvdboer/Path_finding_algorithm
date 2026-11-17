# Security-audit (premiumniveau): Patch Studio

Auditor "security", 17-11-2026. Ik heb geen code gewijzigd. Dit rapport volgt op `docs/review-security.md`: de punten S1-S6 daaruit zijn gerepareerd en hertest. Ik heb ze hier niet herhaald.

Ik heb de audit gedaan zoals Patchstack of Wordfence een betaalde plugin keurt. Bekeken: REST en AJAX, nonces, capabilities, escaping, uploads (SVG, polyglot-bestanden, grootte), privéopslag, proeftokens, IDOR, CSRF, rate limiting, SQL en opties.

## Wat ik echt getest heb
- **Lokale WP + WooCommerce** (`tests/e2e/start-wp.sh`, WP-CLI, curl).
  - `GET /token` geeft voor elke gast hetzelfde token.
  - CORS-preflight: elke `Origin` wordt teruggekaatst met `Allow-Credentials: true`, maar zonder `X-BPS-Token` in `Allow-Headers`.
  - Een polyglot-PNG (PNG + `<script>` + `<?php … ?>`) wordt aanvaard en byte-voor-byte bewaard.
  - Een SVG met CSS-escapes passeert de sanitizer (ook gecontroleerd met een CLI-harness).
  - 30 parallelle verzoeken op een bucket met limiet 5: er komen er **7** door (race condition).
  - `POST /quotes` zonder ontwerp stuurt een mail met door de aanvaller gekozen naam en bericht (incl. phishinglinks) naar een willekeurig adres: `victim@example.test` ontving "Dag Uw pakket wacht: https://phish.example/claim".
- **PHPCS 3 + WPCS 3**, alleen de security-sniffs: 7 errors. Dat zijn false positives, maar marktplaats-reviewers en Plugin Check zien ze wel.
- **Code-review van alle PHP** in `includes/`, `templates/`, `uninstall.php`, plus `assets/src/admin`. Nergens SQL-injectie, `unserialize`, `eval` of shell-calls. Escaping in templates en admin is consequent.

## Samenvatting
| id | ernst | titel |
|---|---|---|
| security-1 | hoog | Offerte- en bewaarmails zijn bruikbaar als spam/phishing-relais vanaf het shopdomein |
| security-2 | middel | Rate limiter is te omzeilen (IPv6 /128, race condition, proxy, transient-bloat) |
| security-3 | middel | Geen opslagquotum: disk-vulling via `POST /designs` |
| security-4 | middel | Eén ontwerp in meerdere bestellingen: proefstatus en orderkoppeling worden overschreven |
| security-5 | middel | Capability-tokens in URL's (proef, herstel, `?token=`) lekken naar analytics en logs, zonder verloop of intrekking |
| security-6 | laag-middel | SVG-sanitizer: CSS-escapes omzeilen de `url()`/`@import`-filter |
| security-7 | laag-middel | Raster/PDF-originelen worden onbewerkt bewaard (polyglot-payloads blijven staan) |
| security-8 | laag-middel | CSRF-bescherming steunt alleen op de CORS-headerlijst; gasttoken is globaal; `/cart` wordt de ingelogde gebruiker |
| security-9 | laag-middel | Data-levenscyclus: deïnstallatie/deactivatie laten klantbestanden onbeperkt staan, eraser onvolledig |
| security-10 | laag | Elke gepubliceerde pagina met de shortcode herschrijft de studio-URL van de herstelmails (optie-write op GET) |
| security-11 | laag | WPCS/Plugin Check niet schoon, geen phpcs-config of CI-poort |
| security-12 | laag | Vereisten voor de geplande licentie/update-client (nog niet gebouwd) |
| security-13 | laag | Geen automatische security-regressietests |

---

## security-1 · hoog · Mail-relais voor spam en phishing
**Bestanden:** `includes/rest/class-routes.php` (`request_quote`, `email_design`), `includes/woo/class-quotes.php` (`create` → `BPS_Email_Quote_Customer`), `templates/emails/quote-customer.php` + `plain/`, `templates/emails/save-design.php`

**Probleem**
- `POST /quotes` werkt **zonder ontwerp**. De klantbevestiging gaat naar elk opgegeven adres en bevat letterlijk de `name` (100 tekens) en `message` (2000 tekens) van de aanvaller. Mailclients maken van URL's automatisch klikbare links.
- `POST /designs/{id}/email` stuurt een mail met een door de aanvaller gekozen preview-PNG (willekeurige afbeelding) en tekstlagen. Per ontwerp kan dat naar 3 adressen, en een aanvaller maakt 30 ontwerpen per uur per IP.
- Die mails komen van het echte shopdomein, met SPF/DKIM in orde. Dat is ideaal voor phishing, en het domein kan op blacklists belanden. Dat raakt dan ook de ordermails.
- Getest: "Dag Uw pakket wacht: https://phish.example/claim … Bevestig je betaling via https://phish.example/pay" kwam aan bij `victim@example.test`.
- Samen met security-2 (IPv6-rotatie) is er praktisch geen limiet.

**Voorstel**
1. Zet in de **klantkopie** van de offertebevestiging geen vrije tekst van de bezoeker:
   - geen bericht;
   - de naam alleen als die geen URL of `@` bevat (regex `/(https?:|www\.|\.[a-z]{2,}\/|@)/i`), anders "Dag,".
2. Stuur de klantkopie alleen als er een geldig ontwerp + token is, of als de bezoeker ingelogd is. Een offerte zonder ontwerp krijgt dan alleen de adminmail.
3. Gebruik in de bewaarmail de preview alleen als het ontwerp een origineel of tekst heeft, en zet er geen vrije `notes` in.
4. Leg een **globale limiet** op uitgaande klantmails, onafhankelijk van IP: standaard 30 per uur, filter `bps_customer_mail_global_per_hour`. Daarboven gaat alleen de adminmail weg.
5. Maak een optionele, standaard uitgeschakelde Cloudflare Turnstile- of hCaptcha-integratie voor `/quotes` en `/email`. De UI-behoefte staat in `docs/handoff/premium-ui-needs.md`.

**Acceptatie**
- Een `POST /quotes` zonder `designId`, met `name` = "x https://a.example" en `message` = "https://b.example", geeft 200. In `bps-mails/` staat dan **geen** mail aan het klantadres met "a.example" of "b.example", wel de adminmail.
- 31 klantmails binnen een uur, vanaf 31 verschillende (gesimuleerde) IP's: de 31ste klantmail wordt niet verstuurd. Daarvoor bestaat een PHP-test.
- De bestaande e2e-scenario's 06 (offerte) en "bewaar & mail" blijven groen.

## security-2 · middel · Rate limiter is te omzeilen
**Bestanden:** `includes/rest/class-guard.php` (`rate_limit`, `client_ip`)

**Probleem**
- **IPv6:** de sleutel bevat het volledige /128-adres. Elke IPv6-klant heeft minstens een /64, dus 2^64 adressen. Daarmee zijn alle limieten waardeloos (offertes, mails, uploads).
- **Race condition:** `get_transient` en `set_transient` zijn niet atomair. Getest: 30 parallelle `POST /designs/125/email` bij een limiet van 5 → **7** keer 403 (doorgelaten) en 23 keer 429.
- **Transient-bloat:** zonder persistente object-cache maakt elk nieuw IP en elke bucket twee rijen aan in `wp_options`. IP-rotatie laat de tabel dus groeien.
- **Proxy:** achter een andere proxy dan Cloudflare delen alle bezoekers één IP. Eén aanvaller kan dan iedereen blokkeren: 5 offertes per uur voor de hele shop.
- **Spoofing:** met `trustCloudflareIp = true/'yes'` en zonder Cloudflare is `CF-Connecting-IP` vrij te vervalsen.

**Voorstel**
1. Normaliseer IPv6 naar het /64-prefix voordat je hasht (`inet_pton` en de eerste 8 bytes behouden). Maak dat filterbaar: `bps_rate_limit_ipv6_prefix`, standaard 64.
2. Maak de teller atomair:
   - met object-cache: `wp_cache_add` + `wp_cache_incr`;
   - anders: een eigen tabel `{prefix}bps_rate` met `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1`, plus `SELECT` van het resultaat. Op SQLite: `INSERT … ON CONFLICT DO UPDATE`.
   - De tabel wordt dagelijks opgeruimd door `bps_cleanup`.
3. Bouw een configureerbare lijst vertrouwde proxies (`woo.trustedProxies`, CIDR) met een header naar keuze (`X-Forwarded-For`, rechtse niet-vertrouwde hop). Toon in tab Algemeen een waarschuwing als `trustCloudflareIp` op "altijd" staat terwijl `REMOTE_ADDR` niet in de Cloudflare-ranges ligt.
4. Zet daarnaast een globale limiet per bucket, onafhankelijk van IP (bijv. 200 ontwerpen per uur). Dat is een noodrem.

**Acceptatie**
- Een PHP-test met `REMOTE_ADDR` = `2001:db8::1` en daarna `2001:db8::2` deelt dezelfde teller. `2001:db8:0:1::1` heeft een eigen teller.
- 30 parallelle requests (6 workers, `php -S`) op een bucket met limiet 5 laten er **precies 5** door.
- 1000 requests met elk een uniek IP maken **0** nieuwe rijen in `wp_options`.
- Met `trustCloudflareIp=true` en een niet-Cloudflare `REMOTE_ADDR` toont het statusblok een waarschuwing.

## security-3 · middel · Geen opslagquotum (disk-vulling)
**Bestanden:** `includes/rest/class-routes.php` (`create_design`), `includes/class-uploads.php` (`store_original`, `store_preview`, `max_bytes`), `includes/woo/class-cleanup.php`

**Probleem**
- Per IP kunnen er 30 ontwerpen per uur bij, elk met een origineel tot 25 MB en een preview tot 20 MB. Dat is ruim 750 MB per uur per IP, en met IPv6 (security-2) is het onbeperkt.
- Wezen (draft) verdwijnen pas na 30 dagen, in batches van 50.
- Er is geen totaalquotum en geen controle op vrije schijfruimte. Een volle schijf legt de hele webshop plat: sessies, orders en logs.

**Voorstel**
1. Leg een totaalquotum op `uploads/patch-studio/private` voor ontwerpen met status draft of carted: `woo.maxDraftStorageMb`, standaard 2048. Hou het bij in een teller (option) bij elke store en delete.
2. Boven het quotum, of als `disk_free_space()` kleiner is dan 1 GB: weiger nieuwe originelen met 507 `bps_storage` en een vriendelijke tekst ("Stuur je bestand gerust per e-mail…"). Mail de admin één keer per dag.
3. Ruim wezen zonder origineel en zonder status-upgrade al na 48 uur op, niet pas na 30 dagen. Maak dat instelbaar.
4. Beperk de preview tot 3 MB: een PNG van 1200 px is nooit groter.

**Acceptatie**
- Een PHP-test met een quotum van 1 MB: het tweede origineel van 0,8 MB geeft 507 `bps_storage`, en er komt geen bestand bij in de privémap.
- Een preview van meer dan 3 MB geeft 413.
- Cleanup verwijdert een draft van 49 uur oud zonder origineel.

## security-4 · middel · Eén ontwerp in meerdere bestellingen
**Bestanden:** `includes/rest/class-routes.php` (`cart`), `includes/woo/class-cart.php` (`add_design`), `includes/woo/class-order.php` (`mark_ordered`), `includes/admin/class-order-production.php` (`proof_of`, `design_for_item`, `send_proof`), `includes/woo/class-privacy.php`

**Probleem**
- `/cart` controleert niet of het ontwerp al besteld is. Een klant die opnieuw bestelt via de bewaar- of herstellink, of iemand met wie de link gedeeld werd, legt **hetzelfde** `bps_design` opnieuw in de mand.
- Bij de tweede bestelling overschrijft `mark_ordered` de velden `_bps_order_id` en `_bps_order_item_id`. De proefstatus (`_bps_proof`) hoort bij het ontwerp, niet bij de orderlijn. Gevolgen:
  - order B toont in de productiefiche "Proef goedgekeurd" van order A;
  - een nieuwe proefronde voor B maakt de links van A ongeldig;
  - cleanup en de privacy-eraser kijken alleen naar de laatste order;
  - een erasure-verzoek van klant A kan de bestanden van order B wissen.

**Voorstel**
1. Als het ontwerp al een `_bps_order_id` heeft of status `ordered` heeft, **kloon** het in `/cart`:
   - nieuwe post, nieuwe referentie;
   - origineel en preview kopiëren naar nieuwe willekeurige namen;
   - proef resetten naar `none`.
2. Geef `designId`, `token` en `ref` van de kloon terug in het antwoord van `/cart`. De UI moet die overnemen (zie handoff).
3. Of als alternatief: sla de proefstatus op per orderlijn (`_bps_proof` op het order-item) en laat `proof_token` dat item-id gebruiken.

**Acceptatie**
- Een e2e-scenario: bestel ontwerp X (order A), stuur een proef, de klant keurt goed. Daarna herstellink → opnieuw in de mand → order B.
  - Order B heeft een ander `_bps_design_id` en een andere `bps_ref`.
  - De fiche van B toont proefstatus "Nog niet verstuurd".
  - De proeflink van A geeft nog altijd "answered", niet "invalid".
  - De privacy-eraser voor de klant van A laat de bestanden van B staan.

## security-5 · middel · Tokens in URL's lekken
**Bestanden:** `includes/admin/class-order-production.php` (`proof_url`, `handle_proof_response`), `templates/admin/proof-response.php`, `includes/woo/class-designs.php` (`restore_url`, `token_for`), `includes/rest/class-routes.php` (`get_design`: `?token=`)

**Probleem**
- De proeflink `?bps_proof=&o=&t=` is een **goedkeuringsbevoegdheid**: met `t` kan iedereen via POST goedkeuren.
- Standaard rendert de pagina met het thema (`use_theme`), dus met GA, Meta-pixel, Hotjar en andere scripts die `document.location` volledig doorsturen. Zo krijgen derden het token.
- Hetzelfde geldt voor `?bps_design=&bps_token=`. De UI haalt die pas weg na het laden van het bundle; analytics in `<head>` vuurt eerder.
- `GET /designs/{id}?token=` zet het token in access logs en in de Cloudflare-logs.
- Er is geen `Referrer-Policy`.
- Ontwerptokens verlopen nooit en zijn niet intrekbaar. Intrekken kan alleen via `wp_salt`, en dat breekt alles.

**Voorstel**
1. **Proefpagina: Post/Redirect/Get zonder token in de URL.** Bij een geldige GET:
   - zet het token in een HttpOnly-cookie (`SameSite=Lax`, `Secure`, pad `/`, 30 min) of een eenmalige transient-sleutel;
   - geef een 303 naar `/?bps_proof=ID&o=ORDER` zonder `t`;
   - het formulier stuurt het token alleen mee in de POST-body.
   - Zet `Referrer-Policy: no-referrer` en `<meta name="referrer" content="no-referrer">`.
   - Zet `use_theme` standaard op `false`, of dequeue analytics via de filter `bps_proof_page_use_theme`. Documenteer dat.
2. **Herstellink:** de server leest `bps_design`/`bps_token` in `template_redirect`, zet een kortlevende HttpOnly-cookie (of een sessionStorage-overdracht via een fragment) en geeft een 302 naar de schone studio-URL. Alternatief: gebruik het **fragment** `#bps=ID.TOKEN`, dat nooit naar servers of Referer gaat (UI-behoefte).
3. `GET /designs/{id}` aanvaardt het token ook als header `X-BPS-Design-Token`. De UI stapt daarop over en de query-variant wordt deprecated.
4. Neem een **tokenversie** op in de HMAC: `id|created|_bps_token_ver`. Voeg een admin-actie "Herstellink intrekken" toe die de versie verhoogt. Laat ontwerptokens verlopen na `retention.savedDays` sinds de laatste opening (dat sluit aan bij de bewaartermijn).

**Acceptatie**
- Een geldige proeflink geeft 303 naar een URL zonder parameter `t`, met `Referrer-Policy: no-referrer`. De POST vanaf die pagina registreert de goedkeuring.
- Een herstellink geeft 302 naar een URL zonder `bps_token`, en het ontwerp laadt.
- De access log van `php -S` bevat na de e2e-run geen 64-hex-token meer.
- Na "Herstellink intrekken" geeft het oude token 403 `bps_forbidden`.

## security-6 · laag-middel · SVG-sanitizer: CSS-escapes
**Bestanden:** `includes/woo/class-svg-sanitizer.php` (`clean_css`, `clean_attributes`)

**Probleem**
- `clean_css` haalt alleen hexadecimale escapes weg (`\\[0-9a-f]{1,6}`). Niet-hex-escapes zijn in CSS geldig: `\o` = `o`, `\r` = `r`.
- Deze inputs blijven **onaangeroerd** staan (getest met een CLI-harness én via `POST /designs`, opgeslagen bestand gecontroleerd):
  - `<style>@imp\ort "https://evil.example/x.css";</style>`
  - `style="fill:u\rl(https://evil.example/a)"`
  - presentatie-attribuut `fill="u\rl(https://evil.example/a)"`
- `cross-fade("https://…")` en `image("https://…")` (stringvorm) blijven ook staan.
- Bij het streamen houdt de CSP `default-src 'none'` dit tegen. Maar als de digitaliseerder het gedownloade SVG lokaal in een browser of editor opent, laadt het externe bronnen. Dat is tracking/IP-lek, geen code-uitvoering.
- `enshrined/svg-sanitize` is niet meegebundeld, dus de eigen sanitizer is de enige verdediging.

**Voorstel**
1. Weiger elke `\` in CSS (style-element en -attribuut) en in presentatie-attributen. Verwijder het attribuut of de regel. Of decodeer CSS-escapes volledig (CSS Syntax §4.3.7) **voor** het filteren.
2. Laat elke string-URL in CSS vallen: alles met `"http`, `'http`, `//` binnen `@import`, `image(`, `cross-fade(` en `src(`.
3. Allowlist voor presentatie-attributen: alleen bekende SVG-attributen, waarden zonder `(` behalve `url(#id)`, `rgb()`, `hsl()`, `matrix()` en de andere transformfuncties.
4. Bundel `enshrined/svg-sanitize` via Strauss als extra pass (dat stond al in SPEC §6.1).

**Acceptatie**
- Nieuwe PHP-testvectoren (`tests/security/svg-vectors.php`), waarvan de uitvoer geen `evil.example` meer bevat:
  - `@imp\ort "https://evil.example/x.css"`
  - `u\rl(https://evil.example/a)` in `style` en in `fill`
  - `cross-fade("https://evil.example/m" 50%)`
  - `image("https://evil.example/m")`
  - `@import 'https://evil.example/x.css'`
- De bestaande e2e-upload met een geldig logo blijft gelijk renderen.

## security-7 · laag-middel · Polyglot-originelen worden onbewerkt bewaard
**Bestanden:** `includes/class-uploads.php` (`sniff`, `store_original`, `ensure_dirs`)

**Probleem**
- Het type wordt alleen bepaald op de eerste bytes. Een PNG gevolgd door `<html><script>…</script><?php system($_GET[1]); ?>` werd aanvaard en **byte-voor-byte** bewaard als `.png` (getest, design 125).
- Vandaag is dat niet uitbuitbaar: de privémap is afgeschermd, er is een willekeurige naam, en de download komt met `nosniff`, `attachment` en `CSP sandbox`.
- Maar het is een webshell-in-wachten bij:
  - nginx zonder de regel uit de zelftest;
  - een LFI in een andere plugin;
  - een host die `.png` via PHP laat lopen.
- Patchstack markeert dit standaard. GIF, WEBP, JPG, PDF, EPS en PSD worden ook niet geparsed.

**Voorstel**
1. Raster (png, jpg, gif, webp):
   - valideer met `getimagesize()` / `wp_getimagesize()` (moet slagen en het type moet overeenkomen met de sniff);
   - knip data af na `IEND` (PNG), `FFD9` (JPEG), de trailer `0x3B` (GIF) of de RIFF-lengte (WEBP);
   - weiger het bestand als de eerste 1 MB of de laatste 64 kB `<?php`, `<?=`, `<script` of `<html` bevat. Een echte afbeelding bevat dat praktisch nooit, en vals-positieven worden dan per e-mail opgevangen.
2. Zet in de `.htaccess` van de privémap extra: `php_flag engine off` (in `<IfModule mod_php.c>`), `RemoveHandler .php .phtml .phar` en `<FilesMatch "\.(php|phtml|phar)$">Require all denied</FilesMatch>`.
3. PDF/AI/EPS/PSD: bewaar zoals nu, maar weiger een bestand met `<?php` in de eerste 1 MB.

**Acceptatie**
- Het testbestand `poly.png` (PNG + HTML + PHP) geeft 415 `bps_type`, of wordt bewaard zonder de bytes na `IEND`: `sha1` van het bewaarde bestand = `sha1` van het PNG-deel.
- Een geldig PNG of JPG uit de fixtures wordt nog altijd identiek bewaard (`sha1` gelijk).
- De nieuwe `.htaccess`-regels staan erin na `Uploads::ensure_dirs()`.

## security-8 · laag-middel · CSRF-model is fragiel
**Bestanden:** `includes/rest/class-guard.php` (`create_token`, `token_ok`), `includes/rest/class-routes.php` (`restore_logged_in_user`, `cart`)

**Probleem**
- `wp_create_nonce('bps_public')` is voor alle gasten **identiek**. Getest: twee aanroepen van `/token` geven `628d4ad562`.
- WordPress kaatst elke `Origin` terug met `Access-Control-Allow-Credentials: true`. Getest met `Origin: https://evil.example`.
- De enige CSRF-rem is dat `X-BPS-Token` niet in `Access-Control-Allow-Headers` staat. Eén andere plugin die via `rest_allowed_cors_headers` headers toevoegt (bv. `*` of een lijst met deze header) haalt die rem weg.
- `/cart` zet dan de ingelogde gebruiker uit de cookie (`restore_logged_in_user`). Een externe site kan zo de mand van een ingelogde klant manipuleren.
- Een betaalde plugin mag niet afhangen van de configuratie van andere plugins.

**Voorstel**
1. Weiger in `Guard::public_write` met 403 `bps_bad_origin` als `Origin` (of, zonder `Origin`, `Referer`) gezet is en niet overeenkomt met `home_url()` / `site_url()` (scheme + host + poort). Maak extra hosts filterbaar met `bps_allowed_origins`.
2. Bind het token aan de bezoeker: zet bij `GET /token` een HttpOnly-cookie `bps_sid` (random, `SameSite=Lax`) en maak het token `HMAC(sid|tick)`. Dat is een double submit en blijft werken met page caching, want `/token` is `no-store`.
3. Voer `restore_logged_in_user()` alleen uit als de Origin-check geslaagd is.

**Acceptatie**
- `POST /cart` met een geldig token en `Origin: https://evil.example` geeft 403 `bps_bad_origin`. Hetzelfde met de eigen origin werkt.
- Twee curl-sessies met verschillende cookie-jars krijgen een verschillend token, en het token van sessie A faalt in sessie B.
- e2e 03, 04 en 08 blijven groen, ook met WP Rocket-achtige page cache, want `/token` blijft ongecachet.

## security-9 · laag-middel · Data-levenscyclus en privacy
**Bestanden:** `uninstall.php`, `includes/woo/class-cleanup.php` (`deactivate`), `includes/woo/class-privacy.php` (`erase`)

**Probleem**
- Bij deïnstallatie zonder `deleteDataOnUninstall` blijven alle klantbestanden (logo's, foto's) en ontwerpposts **voor altijd** staan. Er is dan geen cron meer en geen plugin die de bewaartermijn uit het privacybeleid nakomt.
- Deactivatie haalt de cron weg, dus zolang de plugin uit staat verloopt er niets.
- `bps_settings` wordt ook gewist als de eigenaar "gegevens behouden" koos. Dat is inconsequent.
- De eraser haalt de tekst op de patch (vaak namen) niet weg uit `_bps_payload` van het ontwerp en de order-items. Voor lopende bestellingen is behoud verdedigbaar, maar voor afgeronde bestellingen niet gedocumenteerd.
- De previews van offertes en bewaarde ontwerpen blijven publiek bereikbaar tot de cleanup.

**Voorstel**
1. Voer bij deïnstallatie zonder volledige wis **wel** de bewaartermijn uit: wis de originelen van designs met status draft, carted of saved. Toon vooraf een melding op het pluginscherm ("Klantbestanden ouder dan … worden gewist").
2. Bij deactivatie: een admin-notice dat de bewaartermijn niet loopt zolang de plugin uit staat. Of laat de cron bewust staan, met een guard die checkt of de plugin geladen is.
3. Wis `bps_settings` alleen bij `deleteDataOnUninstall`.
4. Eraser: vervang bij afgeronde orders `texts[].text` in `_bps_payload` (ontwerp en order-item) en de meta `bps_texts` door "[gewist]". Leg in het privacybeleid uit wat bewaard blijft voor de boekhouding en waarom.

**Acceptatie**
- Een PHP-test simuleert `uninstall.php` zonder volledige wis: designs met status draft of saved verliezen hun `_bps_original`-bestand, designs met status ordered houden het, en `bps_settings` blijft bestaan.
- Een eraser-test op een afgeronde order: `_bps_payload` van het item bevat geen originele tekst meer.

## security-10 · laag · Studio-URL wordt overschreven door elke pagina met de shortcode
**Bestanden:** `includes/frontend/class-shortcode.php` (`remember_studio_page`)

**Probleem**
- Bij elke frontend-weergave van een gepubliceerde pagina of post met `[patch_studio]` voert de plugin `update_option( Product::STUDIO_OPTION, … )` uit. Die URL wordt gebruikt in herstel- en bewaarlinks.
- Een gebruiker met de rol Auteur kan een eigen post met de shortcode publiceren. Na één bezoek (of na zijn eigen bezoek) gaan herstellinks met token naar zijn post. Daar kan hij eigen tekst of eigen analytics plaatsen, en zo tokens oogsten via security-5.
- Twee pagina's met de shortcode laten de optie bij elk bezoek omklappen: een DB-write op elke GET, met cache-invalidatie van `alloptions`.

**Voorstel**
1. Leg de studiopagina vast in de instellingen (tab Algemeen: paginakeuze, gezet door "Pagina aanmaken" of door de eigenaar).
2. Zet de automatische detectie alleen nog als de optie leeg is, en alleen voor posts van een auteur met `manage_woocommerce`.

**Acceptatie**
- Een post met de shortcode van een gebruiker met rol Auteur bezoeken verandert `bps_studio_page_id` niet.
- 100 GET's afwisselend op twee shortcode-pagina's veroorzaken 0 `update_option`-calls (meten met de hook `updated_option`).

## security-11 · laag · WPCS/Plugin Check niet schoon
**Bestanden:**
- `includes/admin/class-order-production.php` r. 1501, 1625, 1705 (`$_POST['message']`/`['note']` worden pas in `clean_text()` gesanitized; de `phpcs:ignore` dekt de regel niet);
- `includes/frontend/class-elementor-widget.php` r. 169-172 (echo van `Shortcode::render()` met een meerregelige ignore);
- geen `phpcs.xml.dist` in het project.

**Probleem**
- PHPCS (WPCS 3, security-sniffs) geeft **7 errors**: allemaal false positives, maar reviewers van Patchstack, WordPress.org en Envato rapporteren ze letterlijk.
- WordPress-Extra geeft 2336 errors en 239 warnings. Daarvan zijn 2521 automatisch te fixen (vooral spatiëring). Niet-automatisch: onder meer 15 keer short ternary, 6 keer "Capabilities unknown", 1 keer "global variable override".
- Er is geen CI-poort.

**Voorstel**
1. Sanitize direct bij het lezen: `$message = isset( $_POST['message'] ) ? self::clean_text( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';`
2. Gebruik in de Elementor-widget een `// phpcs:ignore` op dezelfde regel als `echo`, of zet de output in een variabele en echo die met een ignore op die regel.
3. Voeg `phpcs.xml.dist` toe (WordPress-Extra + WordPress.Security, text domain `borduur-patch-studio`, prefixes `bps`/`BorduurPatchStudio`, excludes `languages/*.l10n.php`). Voeg een stap toe aan `tests/run-all.sh` (`vendor/bin/phpcs`) en draai daarna eenmalig `phpcbf`.
4. Draai de WordPress **Plugin Check** (`wp plugin check borduur-patch-studio`) op de testsite.

**Acceptatie**
- `phpcs --standard=phpcs.xml.dist` geeft 0 errors in de categorie `WordPress.Security.*`, en `run-all.sh` faalt bij een nieuwe security-error.
- `wp plugin check borduur-patch-studio` geeft geen errors in de categorie security.

## security-12 · laag · Vereisten voor de licentie/update-client (BRIEF)
**Bestanden:** nog niet gebouwd (BRIEF: "optionele, standaard uitgeschakelde licentie/update-client")

**Probleem**
- Licentie- en update-code is het populairste aanvalsoppervlak van betaalde plugins: niet-ondertekende zips, remote config die code uitvoert, licentiesleutels die in HTML lekken, en SSRF via een instelbare update-URL.
- Het is goedkoper om dit vooraf vast te leggen dan achteraf te repareren.

**Voorstel: eisen voor wie het bouwt**
1. Standaard **uit**: geen enkele uitgaande request zolang er geen sleutel ingevuld is (ook geen "ping").
2. De update-server-URL is hardgecodeerd of een constante (`BPS_UPDATE_URL`) en loopt alleen via HTTPS (`sslverify` aan). Geen instelbaar veld in de admin.
3. Elk pakket wordt **ondertekend** (Ed25519 via `sodium_crypto_sign_verify_detached`, publieke sleutel in de plugin). WordPress krijgt een zip pas na verificatie, via de filter `upgrader_pre_download`.
4. De licentiesleutel wordt bewaard met `autoload = no`, nooit naar de frontend of de REST-config gestuurd, en gemaskeerd getoond (`••••1234`). Wijzigen kan alleen met `manage_options`, nonce en `check_admin_referer`.
5. De serverrespons is data, nooit code: geen `eval`, geen HTML zonder `wp_kses_post`, en het changelog wordt ge-escaped.
6. Responses worden gecachet (transient van 12 uur), met een timeout van 10 seconden en backoff na fouten.
7. Er gaat alleen `home_url`-host, pluginversie en sleutel mee. Dat staat in het privacybeleid.

**Acceptatie**
- Een checklist in `docs/premium/` is afgevinkt.
- Een PHP-test: een update-zip met een ongeldige handtekening wordt geweigerd (`WP_Error`).
- Met een lege sleutel registreert `pre_http_request` 0 uitgaande requests naar de update-host over 10 admin-pageloads.

## security-13 · laag · Geen security-regressietests
**Bestanden:** `tests/` (er bestaat geen `tests/security/`)

**Probleem**
- Geen enkele geautomatiseerde test bewaakt het volgende:
  - de SVG-sanitizer;
  - het weigeren van polyglot-bestanden;
  - tokenverificatie (verkeerd, leeg of ander ID);
  - rate limits;
  - capability- en nonce-checks op `admin-post` en `wp_ajax`;
  - proeflinks.
- De fixes uit `review-security.md` en uit dit rapport kunnen dus ongemerkt terugkomen.

**Voorstel**
1. Maak `tests/security/run.php` (CLI, zelfde bootstrap-stijl als `tests/pricing/run-php.php`) met vectoren voor de sanitizer (minstens 40, waaronder die uit security-6 en de OWASP SVG-cheatsheet), sniff/polyglot en `Designs::verify_token`.
2. Maak een e2e-script `09-security.e2e.cjs` dat het volgende controleert:
   - `admin-post.php?action=bps_download` zonder nonce → 403;
   - als klant (rol customer) met geldige nonce → 403;
   - `wp_ajax_bps_send_proof` als klant → 403;
   - proeflink met fout token → 403 zonder referentie in de HTML;
   - `/designs/{id}` met token van een ander ID → 403;
   - 6× `/quotes` → 429;
   - de privémap is niet bereikbaar.
3. Neem beide op in `tests/run-all.sh`.

**Acceptatie**
- `bash tests/run-all.sh` draait `tests/security` en `09-security`, en alles is groen.
- Het terugdraaien van één fix (bv. de namespace-check uit S4 of de `hash_equals` in `verify_token`) maakt minstens één test rood.

---

## Gecontroleerd en in orde (aanvulling op review-security.md §2)
- **SQL:** alle queries gebruiken `$wpdb->prepare`, ook de CAS-teller in `Designs::next_ref` en de opzoeking van shortcode-pagina's in de instellingen.
- **Instellingen:**
  - `handle_save` gebruikt `current_user_can( manage_woocommerce|manage_options )` + `check_admin_referer`;
  - elke waarde loopt door `Settings::sanitize_value` (URL's via `esc_url_raw`, kleuren via `sanitize_hex_color`, e-mail, key, text en textarea);
  - de publieke config bevat geen `woo`, `retention`, `admin` of `security` (gecontroleerd op de live testpagina).
- **AJAX productie** (`bps_send_proof`, `bps_set_proof_status`): nonce + `edit_shop_orders`, en het item moet bij de order horen. Een eigen proefafbeelding moet een image-attachment zijn.
- **Proeflinks:** HMAC per ronde, `hash_equals`, GET toont alleen een bevestigingspagina, en een fout token toont niets (S1).
- **Downloads:**
  - de admin krijgt `application/octet-stream`;
  - de klant krijgt een gesigneerde URL (HMAC + `exp`, 1 uur, alleen types die de browser kan tonen) met `nosniff`, `attachment` en `CSP: default-src 'none'; sandbox` (live gecontroleerd);
  - padcontrole met `realpath` (`is_ours`).
- **Previews:** worden met GD opnieuw geëncodeerd en de afmetingen worden gecontroleerd vóór het decoderen. In de previewmap is alleen `.png` toegelaten.
- **Uitvoer:** templates en mails escapen consequent. JSON in `<script>` gebruikt `JSON_HEX_TAG|JSON_HEX_AMP`. De `Reply-To` wordt ontdaan van CR/LF.
- **Directe toegang:** elk PHP-bestand heeft een `ABSPATH`- of `WP_UNINSTALL_PLUGIN`-guard (behalve `index.php`-placeholders en het l10n-array).
