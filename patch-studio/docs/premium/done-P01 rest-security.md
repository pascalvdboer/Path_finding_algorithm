# P01 rest-security: wat er gedaan is (18-11-2026)

Bestanden gewijzigd: `includes/rest/class-routes.php`, `includes/rest/class-guard.php`, `includes/woo/class-svg-sanitizer.php`, `includes/woo/class-payload.php`.
Nieuw: `includes/class-help-line.php`, `tests/security/**` (runner, bridge, stubs, 55 SVG-vectoren, 9 legitieme SVG's met gouden uitvoer, e2e-helpers), `tests/e2e/09-security.e2e.cjs`, `tests/e2e/10-api-errors.e2e.cjs`, `tests/e2e/mu/p01-test-hooks.php`.
Handoff: blok `## [P01] …` toegevoegd aan `docs/handoff/premium-ui-needs.md`.

## Testresultaten
- `php tests/security/run-security-php.php`: **427/427 ok** (draait mee in `npm test` via `tests/security/security-php.test.js`, die ook ≥ 40 asserties eist).
- Mutatietests, telkens hersteld na de run. Elke regressie maakt de suite rood:
  backslash-filter in `clean_css` weg (5 fails), backslash-filter in attributen weg (2), `on*`-strip weg (7), `javascript:`-check weg (1), `hash_equals` in `verify_token` vervangen door `==` (2), handtekeningcontrole van het Guard-token weg (1), Origin-check weg (7).
- e2e op een eigen testsite (`BPS_WP_DIR=…/p01-wp`, poort 8911): **01, 03, 04, 05, 06, 08, 09 (22/22) en 10 (28/28) PASS**, plus `php-debug-log` (geen plugin-notices).
  - 02 en 07 falen op UI-selectoren en layout: de advies-knop van stap 2, de prijsbalk op mobiel en axe `target-size` op `.bps-top__new`. Die klant-UI wordt tegelijk herbouwd door het andere team, en beide scenario's falen al vóór er een REST-call uit dit pakket gebeurt.
- `npm test`: 408 pass, 3 TODO (accuracy, bekend) en 1 fail buiten dit pakket (`tests/ai/frontend-bundle.test.js`: bundel 25 632 B > 25 kB). `npm run test:php` (pricing): 652 checks, 0 failed.
- `php -l` is schoon op elk gewijzigd of nieuw PHP-bestand. De WPCS-sniffs `WordPress.Security.*` en `DB.PreparedSQL` geven 0 meldingen op de bestanden van dit pakket. Wat overblijft is opmaak voor de phpcbf-fase (P12).

## Per bevinding

### security-6 · SVG-sanitizer: CSS-escapes en string-URL-functies
- `clean_css()` zet CSS-hex-escapes eerst om naar hun teken (zoals een browser dat leest). Daarna verdwijnt **elke backslash**, en pas dan lopen de filters (`@import`, `url()`, `expression` …).
- Presentatie-attributen: eerst alle backslashes weg, dan wordt de waarde opnieuw platgeslagen (entities gedecodeerd, witruimte weg). `url(` mag er alleen nog in als `url(#id)`, zonder quotes.
- `image(`, `image-set(`, `cross-fade(`, `src(` en `element(` worden geneutraliseerd, en een niet-lokale/onafgesloten `url(` ook. De hele aanroep **inclusief argument** wordt `none` (nieuw: `drop_calls()`, quote- en haakjes-bewust), zodat er ook geen `evil.example` in de uitvoer blijft staan. In attributen leidt zo'n functie tot het verwijderen van het attribuut.
- Getest: de 6 vectoren uit de bevinding plus `@import` met enkele quotes, en nog 48 andere (script, `on*`, `javascript:`/`data:` via entities en tabs, foreignObject, externe use/image/feImage, XXE, billion laughs, xml-stylesheet, XHTML-namespace …). De uitvoer moet well-formed XML zijn.
- Er zijn geen SVG-fixtures in `tests/e2e/fixtures` (alleen `logo-flat.png`). Daarom heb ik **vóór** de wijziging een gouden uitvoer vastgelegd van 9 legitieme SVG's: Illustrator, Inkscape, gradient/clipPath/textPath, `<style>` met CDATA, embedded PNG, de demo-SVG en 3 AI-mocks. Na de wijziging is die byte-identiek.

### security-2 · Rate limiter
- `Guard::rate_ip()`: IPv6 wordt teruggebracht tot /64 (filter `bps_rate_limit_ipv6_prefix`, begrensd op 16–128). IPv4 blijft /32, en een IPv4-mapped IPv6 telt als het IPv4-adres.
- Met `wp_using_ext_object_cache()` tel ik atomair met `wp_cache_add` + `wp_cache_incr` (groep `bps_rate`), anders met een transient.
- Statuswaarschuwing via `bps_status_checks` (C7), geregistreerd door `Guard::register()` (aangeroepen vanuit `Routes::register()`). Die verschijnt bij `trustCloudflareIp` true/'yes' terwijl `REMOTE_ADDR` niet in `CF_RANGES` ligt. Velden: id, label, status, message, plus ok/detail voor de huidige statuspagina.
- **Extra bug gevonden door e2e 09:** WordPress roept permission callbacks na de dispatch opnieuw aan (`rest_send_allow_header`). Elk verzoek telde daardoor **dubbel**, waardoor 5 offertes per uur er in werkelijkheid 3 waren. Opgelost met `Guard::once()`: één keer controleren per request-object.
- Getest (PHP): 2001:db8::1 en ::2 delen een teller, 2001:db8:0:1::1 heeft een eigen teller, IPv4 per adres, zowel met transient als met object-cache, en een herhaalde controle telt één keer. De waarschuwing verschijnt bij true/'yes' met 203.0.113.5 en niet bij 'auto' of bij een Cloudflare-IP. In e2e 10 gebeurt hetzelfde met echte verzoeken (/64 via de testheader).

### security-8 · Origin-controle
- `Guard::public_write` controleert eerst het token, dan de Origin, dan de rate limit. Een Origin met een vreemde host (ook `null`) geeft 403 `bps_bad_origin`. Zonder Origin moet een meegestuurde Referer kloppen; zonder beide gaat het verzoek door, want zonder browser zijn er geen cookies.
- Toegelaten: hosts van `home_url()`/`site_url()`, plus filter `bps_allowed_origins`. Het schema wordt niet vergeleken, de poort alleen als beide kanten er expliciet een hebben.
- `restore_logged_in_user()` verhuist naar `Guard` (publiek). Het wordt pas in de callback aangeroepen, dus altijd na token en Origin.
- Getest: in PHP 15 gevallen (suffix-/prefix-trucs, hoofdletters, poort, Referer, filter). Een geweigerd verzoek wordt nooit de ingelogde gebruiker. In e2e 09 geven `/cart`, `/designs` en `/designs/{id}/email` met `Origin: https://evil.example` 403, een vreemde Referer ook 403, en de eigen Origin gaat door. e2e 03, 04, 06 en 08 blijven groen.

### security-3 (routedeel) · opslag en previewlimiet
- `create_design` roept vóór het opslaan `check_storage($bytes)` aan, wat 507 `bps_storage` geeft met de tekst "We kunnen je bestand nu even niet bewaren. Stuur het gerust per e-mail of via WeTransfer, …". De oorzaak wordt gelogd.
- **Tijdelijke guard:** zolang `Uploads::check_storage()` (C3, P06) er niet is, controleert `Routes::check_storage()` alleen de vrije schijfruimte (filter `bps_min_free_disk_bytes`, standaard 1 GB). Zodra P06 de methode levert, wordt die automatisch gebruikt (`is_callable`) en zet Routes elke fout om naar 507 met dezelfde klanttekst. Het teller- en quotumdeel (maxDraftStorageMb, adminmail) is van P06.
- Previewlimiet: 8 MB (constante `PREVIEW_MAX_BYTES`, filter `bps_preview_max_bytes`), ook bij `UPLOAD_ERR_INI_SIZE`, met 413 `bps_too_big`.
- Getest: in PHP geven een lage schijfruimte → 507 zonder nieuw bestand en een preview > 8 MB → 413. In e2e 10 gebeurt hetzelfde via de testhooks, met de controle dat er geen restbestanden achterblijven.

### tests-ci-6 · REST-foutpaden en post_max_size
- `create_design` geeft meteen 413 `bps_too_big` ("Je bestand is groter dan X MB …") als `CONTENT_LENGTH` > `post_max_size`, nog vóór de honeypot en `Payload::validate`. Het **vermoeden is bevestigd**: zonder die check gaf zo'n verzoek een ontwerpfout.
- e2e 10 start daarvoor een tweede `php -S` met `post_max_size=1M` (poort = WP-poort + 13). De melding noemt de **werkelijke** limiet ("groter dan 1 MB" op die server; "25 MB" in de standaardconfig, getest in PHP).
- Ook gedekt in e2e 10: geen of vervalst token → 403, 429, origineel > limiet → 413, rommel-PNG als preview → 400 en als origineel → 415, en een SVG met script/onload/`@imp\ort`/`u\rl()` die gesaneerd bewaard wordt. Na elke weigering blijft er geen bestand achter.

### security-5 (headerdeel)
- `GET /designs/{id}` leest eerst de header `X-BPS-Design-Token` en valt anders terug op `?token=`. Het route-argument `token` is niet meer verplicht. Een (foute) header gaat voor een query-token.
- Getest: in PHP en in e2e 09/10 geeft de header 200, een fout token 403 `bps_forbidden` en het token van een ander ontwerp ook 403. Het proefpaginadeel is van P05.

### gdpr-juridisch-10
- `/quotes` eist `consent` niet meer en negeert het als het toch meekomt. Er staat een commentaar bij over de rechtsgrond (art. 6.1.b). Getest in e2e 10: zonder consent 200, met `consent=true` 200. De UI-tekst staat in de handoff.

### productisering-4 (guarddeel)
- Nieuw: `Help_Line::text( string $locale = '' )`, met daarnaast `::variant()` en `::all()`. Op basis van `contact.phone`, `whatsapp`, `email` en `quoteUrl` kiest het een van 7 varianten, in NL (je-vorm) en FR (tu-vorm). Een relatieve quoteUrl wordt absoluut gemaakt, een `javascript:`-URL nooit getoond. Filter `bps_help_line`.
- De 429-melding van de Guard gebruikt deze helper. Het nametape-deel is van P03, dat `Help_Line::all()` aan de JS kan doorgeven.
- Getest: zonder WhatsApp staat er geen "WhatsApp" in de tekst. Zonder telefoon en WhatsApp staan "bel" en "WhatsApp" er geen van beide in, en verwijst de tekst naar de offertepagina. Verder getest: alleen WhatsApp, niets ingevuld, FR en de filter.

### security-4 (routedeel) en contract C4
- `Routes::cart`: heeft het ontwerp status `ordered` of een `_bps_order_id`, dan kloont het via `Designs::clone( $id, ['parent_id' => $id] )`. De respons krijgt dan `cloned: true`, `designId`, `token` en `ref`. **Tijdelijke guard:** zolang `Designs::clone()` (P06) ontbreekt, blijft het oude gedrag en wordt `cart_ordered_design_without_clone` gelogd.
  e2e 10 test de kloon automatisch zodra de methode bestaat (nieuwe id, ref en token; het origineel blijft `ordered` met zijn order-id). Nu controleert de test dat /cart blijft werken.
- `do_action( 'bps_design_emailed', $id, $email, $request )` na een geslaagde mail in `email_design`. Getest in e2e 10 via een recorder in de mu-plugin.
- De ingelogde gebruiker wordt hersteld vóór `Designs::create` en vóór de kloon (klant-account-2). In de cart gebeurde dat al.
- `Payload::validate`: filter `bps_payload_validate( null|WP_Error, $payload, $raw )` (C4), plus een **extra** filter `bps_payload_clean( $out, $raw )` waarmee P04 gevalideerde namenvarianten aan de payload kan toevoegen (anders worden onbekende sleutels gewist). JSON-diepte begrensd op 16.
- `pre_dispatch` gebruikt `I18n::switch_lang()`/`I18n::restore()` (C9) zodra die bestaan (`is_callable`). Tot dan blijft de oude `switch_to_locale('fr_BE')`-fallback.
- php-kwaliteit-7: de `class_exists( Pricing::class )`-checks in Routes zijn weg. Logging gaat via een private `Routes::log()`: `Logger::<level>()` (C1) als die bestaat, anders `error_log` met WP_DEBUG_LOG. De catch in `quote()` logt nu, `wc_get_logger()` voor de prijsafwijking is vervangen door de Logger.

### php-kwaliteit-11, tests-ci-2, security-13 · securitytests
- `tests/security/run-security-php.php` (stubs in `tests/security/php/wp-stubs.php`) en de bridge `security-php.test.js`. Onderdelen: 55 SVG-vectoren plus 9 gouden fixtures en `clean_css`-units, Settings-LIMITS/ENUMS met kwade invoer (wildcards, clamping, enum, markup in contactvelden), Guard (CIDR v4/v6 met randen, `client_ip` per modus, /64-sleutel, rate limit N/N+1, Origin, statuscheck), `verify_token` (formaat, id, posttype, verwijderd ontwerp, statische controle op `hash_equals`), `Uploads::sniff` (16 inhoudstypes en de e2e-fixture), `check_upload` voor alle 7 `UPLOAD_ERR_*`-codes plus een childproces **zonder** BPS_TESTING dat een niet-geüpload bestand weigert, Payload-randgevallen (31), en REST-foutpaden zonder WordPress.
- Proeftokentests komen van P05. Het is niet nodig `run-all.sh` aan te passen: `npm test` pikt de bridge op, en de e2e-glob pikt 09 en 10 op.

## Voor andere pakketten
- **P13:** e2e 09 en 10 hebben `tests/e2e/mu/p01-test-hooks.php` nodig. Ze controleren zelf of die geladen is, en kopiëren hem anders naar `wp-content/mu-plugins/bps-p01-test-hooks.php`. Een guard-constante voorkomt dubbel laden. De hooks staan pas aan als de optie `bps_p01_test` gezet is, en het scenario verwijdert die weer. e2e 10 gebruikt poort WP-poort + 13 voor de tweede server.
- **P06:** `Uploads::check_storage()` hoort de filter `bps_min_free_disk_bytes` te respecteren (e2e 10 simuleert er een volle schijf mee). `Designs::clone()` wordt aangeroepen met `['parent_id' => $id]`.
- **P02:** zodra `Logger` er is, gebruikt Routes het automatisch.
- **P11:** nieuwe NL-strings (FR-voorstel):
  - "Dit verzoek kwam niet van onze website en werd daarom geweigerd. Herlaad de pagina en probeer het opnieuw." → « Cette demande ne provient pas de notre site et a donc été refusée. Recharge la page et réessaie. »
  - "Even geduld: je stuurde veel verzoeken na elkaar. Probeer het binnen %s opnieuw." → « Un instant : tu as envoyé beaucoup de demandes d'affilée. Réessaie dans %s. »
  - "We kunnen je bestand nu even niet bewaren. Stuur het gerust per e-mail of via WeTransfer, dan bekijken we het samen." → « Nous ne pouvons pas enregistrer ton fichier pour le moment. Envoie-le par e-mail ou via WeTransfer, et nous le regarderons ensemble. »
  - "Het voorbeeld van je patch is te groot om te bewaren. Laad de pagina opnieuw en probeer het nog eens." → « L'aperçu de ton écusson est trop lourd pour être enregistré. Recharge la page et réessaie. »
  - "We konden je ontwerp niet opnieuw klaarzetten. Probeer het nog eens of contacteer ons." → « Nous n'avons pas pu préparer à nouveau ton design. Réessaie ou contacte-nous. »
  - Admin: "Cloudflare-IP" / "Het IP-adres van Cloudflare wordt altijd vertrouwd, …" (statuscheck).
- **P08:** de statuscheck gebruikt het C7-formaat `[id, label, status, message]` plus `ok`/`detail` voor de huidige weergave.
