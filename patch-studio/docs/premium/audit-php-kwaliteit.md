# Audit PHP-codekwaliteit — Patch Studio (php-kwaliteit)

Datum: 2026-11-17 · Scope: alle PHP in `plugin/borduur-patch-studio` (32 klassebestanden in `includes/`, templates, views, `uninstall.php`).
Er is geen code gewijzigd. Let op: een ander team werkte tijdens de audit in dezelfde boom (bv. nieuw `includes/ai/`), dus de telling kan een paar procent verschuiven.

## Wat er gedraaid is

| Tool | Versie | Resultaat |
|---|---|---|
| `php -l` (alle bestanden) | PHP 8.3.6 | 0 syntaxfouten |
| PHPCompatibilityWP, testVersion 8.1- | 2.1.8 | 0 bevindingen |
| PHPCS + WPCS 3.4.1, standaard `WordPress`, zonder `languages/` | phpcs 3.13.6 | **352 errors + 314 warnings in 43 bestanden** (met prefixes `BorduurPatchStudio,bps_,BPS_`); phpcbf lost er 477 automatisch op. Met de te korte prefix `bps`: 572 errors. Het gegenereerde `languages/*.l10n.php` geeft nog eens 2215 errors (uitsluiten). |
| PHPStan 2.1.33 + szepeviktor/phpstan-wordpress 2.0.4 + woocommerce-stubs 11.3 | | level 0: 81 · level 5: **120** · level 6: **427** · level 8: **472** · level 9: 719 |
| `npm run test:php` | | 47 vectoren, 652 checks, 0 failed (enkel Pricing) |

Opmerking over de omgeving: composer kan vanuit deze sandbox geen GitHub-zips ophalen (`Could not authenticate against github.com`). Installeren lukte met `--prefer-source --no-plugins` (git-clone), en PHPStan als phar van de GitHub-release (`replace: phpstan/phpstan`). De config die ik gebruikte stond in de scratchpad; het voorstel hieronder (bevinding 1) zet hem in de repo.

## Samenvatting

De code is netjes voor een eerste versie. Escaping, nonces en `$wpdb->prepare` zijn bijna overal correct. Er zijn geen syntax- of compatibiliteitsproblemen en de prijsmotor heeft een goede pariteitstest. Wat ontbreekt voor betaalde-plugin-kwaliteit:
(1) de kwaliteitstools zitten niet in de repo of in `run-all.sh`, (2) er staan honderden WPCS- en PHPStan-meldingen open, met daartussen echte null- en typefouten, (3) `Order_Production` is een godklasse met klassen die voorwaardelijk in een functie gedeclareerd worden, (4) fouten worden stil ingeslikt zonder logging, met in de winkelmand een fail-open naar prijs 0, (5) uninstall en de AVG-exporter/-eraser zijn onvolledig, en (6) buiten Pricing zijn er geen PHP-unit-tests.

---

## Bevindingen

### php-kwaliteit-1 — Geen PHP-kwaliteitstooling in de repo of in de testketen (hoog)
**Bestanden:** (ontbreekt) `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`; `tests/run-all.sh`, `package.json`
**Probleem:** De BRIEF eist WordPress Coding Standards, maar er is geen composer-manifest, geen PHPCS-ruleset en geen PHPStan-config, en `run-all.sh` draait enkel `php -l`. Zonder ruleset geeft WPCS ook vals alarm: de prefix `bps` is te kort (ShortPrefixPassed, ~220 extra errors), de capabilities `edit_shop_orders`/`manage_woocommerce` zijn "Unknown" (6×), en het eigen `clean_text()` (een wrapper rond `sanitize_textarea_field`) wordt niet als sanitizer herkend (3× InputNotSanitized).
**Voorstel:** Zet in de projectroot (dus niet in de plugin-zip) een dev-`composer.json` met `wp-coding-standards/wpcs:^3`, `phpcompatibility/phpcompatibility-wp`, `phpstan/phpstan:^2`, `szepeviktor/phpstan-wordpress`, `php-stubs/woocommerce-stubs` (+ `php-stubs/elementor-stubs` of een eigen stub). Voeg een `phpcs.xml.dist` toe met: `<rule ref="WordPress"/>`, `prefixes = BorduurPatchStudio, bps_, BPS_`, `text_domain = borduur-patch-studio`, `minimum_wp_version 6.4`, `testVersion 8.1-`, `custom_capabilities` (edit_shop_orders, manage_woocommerce), `customSanitizingFunctions` (clean_text) en `<exclude-pattern>languages/*.l10n.php</exclude-pattern>`. Voeg ook een `phpstan.neon.dist` toe (level 5 om te starten, met bootstrap voor de BPS_*-constanten), composer-scripts `lint` / `lint:fix` / `analyse`, en een stap "PHPCS" + "PHPStan" in `tests/run-all.sh` (overslaan als `vendor/` ontbreekt).
**Acceptatie:** `composer lint` en `composer analyse` bestaan en draaien. `bash tests/run-all.sh --no-e2e` toont de stappen "PHPCS" en "PHPStan". `phpcs -s` meldt 0× ShortPrefixPassed, 0× Capabilities.Unknown en 0× InputNotSanitized op `clean_text()`-regels.

### php-kwaliteit-2 — PHPStan vindt echte null- en typefouten (hoog)
**Bestanden:** `includes/admin/class-order-production.php` (337, 1492, 1610, 1703, 1717, 1741, 1749, 1751, 1788), `includes/woo/class-svg-sanitizer.php` (73, 85, 107, 123, 128, 138, 174, 181), `includes/woo/class-order.php` (73, 74, 97, 104), `templates/emails/quote-customer.php:68`, `templates/emails/quote-admin.php:31`, `includes/woo/class-cleanup.php:157`, `includes/class-settings.php` (607, 860, 951), `includes/admin/class-settings-page.php` (157, 1108, 1127), `includes/woo/class-privacy.php:101`, `includes/woo/class-designs.php:456`, `includes/class-uploads.php:319`, `includes/rest/class-routes.php` (493-494)
**Probleem:** Op level 8 staan 45 nieuwe meldingen ten opzichte van level 6. Voorbeelden:
- `ajax_context()` belooft `array{WC_Order, WC_Order_Item_Product}`, maar geeft `WC_Order|WC_Order_Refund|false` terug. Daarna volgt `$order->get_id()` (1610).
- In `handle_proof_response()` wordt het resultaat van `Designs::get()` (kan `null` zijn) na de re-fetch zonder controle doorgegeven aan `proof_of(array)` en `record_response(array)`. Dat geeft een TypeError als het ontwerp tussendoor opgeruimd is.
- `strtolower( preg_replace(...) )` met een mogelijke `null`.
- `wp_date( strtotime(...) )` met `false`.
- `esc_url( get_permalink() )` met `false`.
- In de SVG-sanitizer: `iterator_to_array( $xpath->query() )` met `false`, en `->createTextNode()` op een mogelijke `null`.
- `WC()->session->has_session()` op het abstracte `WC_Session`.
- `imagefill()` met een kleur `false`.

In productie leidt elk van deze gevallen tot een fatal (PHP 8 TypeError) of tot stil fout gedrag.
**Voorstel:** Los alle meldingen van level 5 en 8 op met expliciete guards (`instanceof \WC_Order`, `is_array( $rec )`, `?? ''`, `false !== $ts`). Gebruik `instanceof \WC_Session_Handler` voor sessiemethodes. Leg geen baseline aan voor deze categorie.
**Acceptatie:** `phpstan analyse -l 5` geeft 0 fouten. `phpstan analyse -l 8` geeft 0 fouten van de types "might not exist on array|null", "expects string, string|null given", "expects int|null, int|false given" en "Cannot call method … on …|false|null". Een nieuwe test voor `handle_proof_response()` met een verwijderd ontwerp geeft de pagina "invalid" en geen fatal.

### php-kwaliteit-3 — Godklasse `Order_Production` met klassen voorwaardelijk gedeclareerd in een functie (hoog)
**Bestanden:** `includes/admin/class-order-production.php` (2283 regels, 76 methodes, 13 hooks), regels 2097-2283 (`declare_emails()`, `Proof_Email`, `Proof_Response_Email`)
**Probleem:** Eén bestand bevat de order-metabox, de productiefiche en de print, het versturen van proeven, de HMAC-tokens, de **publieke** klantenpagina voor de proefrespons, AJAX, lijstkolommen en twee `WC_Email`-klassen. Die laatste twee worden binnen de functie `declare_emails()` gedeclareerd. Dat is niet nodig: `Woo\Emails::email_classes()` laadt `Quote_Admin`/`Quote_Customer`/`Saved_Design` gewoon via de autoloader op `woocommerce_email_classes`. Gevolgen: PHPStan ziet de klassen niet (60+ meldingen "unknown class Proof_Email"), WPCS meldt OneObjectStructurePerFile (2×) en SeparateFunctionsFromOO, er zijn twee verschillende patronen voor dezelfde soort e-mail, en de publieke, beveiligingsgevoelige code is moeilijk apart te reviewen of te testen. Andere grote bestanden: `class-settings-page.php` (1156), `class-settings.php` (1046), `class-pricing.php` (954), `class-cart.php` (899).
**Voorstel:** Splits op in `Admin\Order_Metabox`, `Admin\Production_Fiche`, `Proof\Proof_Service` (token, versturen, respons registreren), `Proof\Response_Page` (frontend) en `Woo\Emails\Proof` + `Woo\Emails\Proof_Response` in `includes/woo/emails/class-proof.php` / `class-proof-response.php`, met hetzelfde patroon als `Quote_Admin`. Verwijder `declare_emails()`.
**Acceptatie:** Geen enkel bestand in `includes/` is groter dan 800 regels. `phpcs` meldt 0× OneObjectStructurePerFile/SeparateFunctionsFromOO. PHPStan meldt 0× "unknown class BorduurPatchStudio\Admin\Proof_". e2e `05-admin-fiche` blijft groen. De e-mail-ID's `bps_proof` en `bps_proof_response` blijven ongewijzigd, zodat opgeslagen WC-instellingen blijven werken.

### php-kwaliteit-4 — Fouten stil ingeslikt, geen logging, en een fail-open naar €0 in de winkelmand (hoog)
**Bestanden:** `includes/woo/class-cart.php` (131-134, 207-214, 477-485), `includes/rest/class-routes.php:285-289`, `includes/admin/class-settings-page.php` (531-547, 679-682, 1147-1153), `includes/class-settings.php:591`
**Probleem:** 9 `catch ( \Throwable )`-blokken loggen niets. `wc_get_logger` komt in de hele plugin maar 2× voor, en `Settings::read_json` gebruikt `error_log`. In `Cart::set_prices()` wordt de eenheidsprijs bij een exception van de prijsmotor `0.0` en via `set_price(0)` gezet. Het mini-cart-totaal en de Store API-totalen tonen dan €0. Pas `check_cart_items()` blokkeert later de checkout, met een generieke melding. De eigenaar ziet nergens wat er misliep.
**Voorstel:** Maak een kleine `Logger`-wrapper rond `wc_get_logger()` (source `patch-studio`, met terugval op `error_log` enkel als `WP_DEBUG_LOG` aan staat). Log in elk catch-blok met context (design_id, techniek, bericht). Zet in `set_prices()` bij een fout geen €0, maar houd de lijn ongeprijsd en markeer haar (bv. `$item['bps_price_error']`), zodat `check_cart_items()` een specifieke melding toont ("We konden de prijs van patch %s niet berekenen. Bel ons gerust." / FR). Toon op de tab Algemeen een statusregel met het aantal fouten in de laatste 24 uur.
**Acceptatie:** Elk `catch`-blok in `includes/` roept `Logger::` aan (controleerbaar met grep). Een PHP-test waarin `Pricing::cart_unit_excl` een exception gooit, levert geen lijn met prijs 0, wel een `wc_add_notice` van het type error. Onder WooCommerce > Status > Logs verschijnt de source `patch-studio`.

### php-kwaliteit-5 — 352 WPCS-errors en 314 warnings (middel)
**Bestanden:** alle PHP, vooral `includes/admin/views/*`, `templates/**`, `includes/frontend/class-elementor-widget.php` (169-172), `templates/admin/emails/proof-response.php:24`
**Probleem:** Na de juiste prefix-config (bevinding 1) blijven er 352 errors en 314 warnings in 43 bestanden. 477 daarvan zijn opmaak die phpcbf oplost (array-uitlijning, BlockComment). De handmatige rest:
- 37× VariableNotSnakeCase
- 33× doc comment zonder korte beschrijving
- 30× niet-geprefixte globale templatevariabele
- 18× niet-geprefixte hooknaam: dit zijn aanroepen van core- en WC-hooks (`woocommerce_email_header`, `nonce_life`, `litespeed_purge_all`, `https_local_ssl_verify`) die een gerichte `phpcs:ignore` met reden nodig hebben
- 15× short ternary `?:`
- 6× ongebruikte parameter
- 6× gereserveerd woord als parameternaam (`$default`, `$list`, `$class`)
- 4× EscapeOutput: de `phpcs:ignore` staat op de eerste regel van een meerregelige `echo` en dekt de rest niet
- 1× overschrijven van de WP-global `$action` in een e-mailtemplate
- 2× niet-Yoda

**Voorstel:** Eén phpcbf-run, daarna de handmatige fixes. Zet ignores met een reden op de juiste regel (`// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook`). Hernoem `$action` naar `$bps_action`.
**Acceptatie:** `vendor/bin/phpcs` (met de ruleset uit bevinding 1) geeft **0 errors en 0 warnings**, en draait als stap in `run-all.sh`.

### php-kwaliteit-6 — `WC_Email::$object` krijgt een array (middel)
**Bestanden:** `includes/woo/emails/class-quote-admin.php:70`, `class-quote-customer.php:64`, `class-saved-design.php:69`, `includes/admin/class-order-production.php` (2162, 2262)
**Probleem:** WooCommerce typeert `$object` als `bool|object` en geeft het door aan `woocommerce_email_recipient_{id}`, `woocommerce_email_subject_{id}`, `woocommerce_email_heading_{id}`, enzovoort. E-mailcustomizers (YayMail, Kadence, Decorator) en eigen snippets roepen daar vaak methodes op aan (bv. `$object->get_id()`). Met een array krijg je dan een fatal bij het versturen van een offerte. PHPStan: "Property WC_Email::$object (bool|object) does not accept array" (3×).
**Voorstel:** Houd de data in `$this->bps_args`/`$this->quote`. Zet `$this->object` op het `WC_Order` (proef) of het `WP_Post` van de offerte of het ontwerp, of laat het `false`.
**Acceptatie:** 0 PHPStan-meldingen voor `WC_Email::$object`. Een test-mu-plugin met `add_filter( 'woocommerce_email_recipient_bps_quote_admin', fn( $r, $o ) => is_array( $o ) ? die( 'array' ) : $r, 10, 2 )` laat e2e `06-quote-request` groen.

### php-kwaliteit-7 — Defensieve "builder nog niet klaar"-code die fouten verbergt, en drie loaders voor defaults.json (middel)
**Bestanden:** `includes/class-plugin.php:17-51` (COMPONENTS "skipped silently"), `includes/woo/class-config.php:26-40, 121`, `includes/class-pricing.php:657-666`, `includes/frontend/class-shortcode.php` (92, 141), `includes/admin/class-settings-page.php` (502, 678, 703), `includes/admin/class-order-production.php` (145, 455, 850, 1535, 1714, 2176, 2207), `includes/rest/class-routes.php` (250, 389), `includes/woo/class-cart.php` (194, 418, 457)
**Probleem:** Overal staan `class_exists( Settings::class )`, `class_exists( Pricing::class )` en `method_exists(...)` voor eigen klassen die de autoloader altijd kan laden. PHPStan meldt 9× "will always evaluate to true". Een hernoemde of kapotte klasse schakelt zo stilletjes een functie uit, bijvoorbeeld de hele REST-API of de prijsberekening, in plaats van luid te falen. `defaults.json` wordt op drie plaatsen geparsed (`Settings::read_json`, de fallback in `Woo\Config::get` en de fallback in `Pricing::config`), en alleen de `Config`-fallback past daarbij `bps_config` toe. Daardoor kunnen ze uit elkaar lopen.
**Voorstel:** Verwijder de checks op eigen klassen en de fallbacks in Config en Pricing. Settings wordt de enige bron, en Pricing krijgt in tests de config als parameter (dat kan al). In `Plugin::boot()`: als een COMPONENT ontbreekt, log dat en toon een admin-notice in plaats van het over te slaan.
**Acceptatie:** PHPStan meldt 0× "will always evaluate to true". `grep -rn "class_exists( \(Settings\|Pricing\|Designs\)::class" includes` geeft 0 treffers. `grep -rn "defaults.json" includes` geeft precies 1 treffer (Settings). `npm run test:php` blijft groen.

### php-kwaliteit-8 — Uninstall laat data achter (middel)
**Bestanden:** `uninstall.php`, `includes/woo/class-product.php` (19, 59-66), `includes/admin/class-settings-page.php:1085`, `includes/class-uploads.php:27`
**Probleem:** De opties `bps_studio_page_id` (autoload = ja!) en `bps_product_lock` blijven altijd staan. De WC-e-mailinstellingen `woocommerce_bps_proof_settings`, `…_proof_response_settings`, `…_quote_admin_settings`, `…_quote_customer_settings` en `…_saved_design_settings` worden nooit verwijderd. Bij "Alles verwijderen" blijft ook de ontwerppagina staan die de plugin zelf aanmaakt (`wp_insert_post` in Settings_Page). De optienamen zijn in `uninstall.php` letterlijk overgetypt, in plaats van de constanten te hergebruiken, dus ze lopen na een hernoeming uit elkaar.
**Voorstel:** Maak `Plugin::owned_options()` en `Plugin::owned_option_patterns()` aan (via `require_once` van de klasse ook bruikbaar in uninstall). Verwijder `bps_studio_page_id`/`bps_product_lock` en `woocommerce_bps_%_settings` altijd. Verwijder de ontwerppagina bij wipe_all, enkel als ze door de plugin aangemaakt is (markeer haar met post meta `_bps_created_page`).
**Acceptatie:** Op de e2e-WP: activeren, studio gebruiken, de e-mailinstellingen één keer opslaan, "Alles verwijderen" aanvinken en `wp plugin uninstall` uitvoeren. Daarna geven `wp option list --search='bps_*'` en `wp option list --search='woocommerce_bps_*'` allebei 0 rijen, en `wp post list --post_type=page --meta_key=_bps_created_page` 0 rijen.

### php-kwaliteit-9 — AVG-exporter en -eraser kappen stil af en negeren paginering (middel)
**Bestanden:** `includes/woo/class-privacy.php` (93-110, 120-137, 222-265)
**Probleem:** `design_ids()` haalt maximaal 200 orders op (`'limit' => 200`) en `quote_ids()` maximaal 500 offertes. De eraser negeert `$page` (WPCS: ongebruikte parameter), verwerkt alles in één request en geeft altijd `done => true`. Een B2B-klant met meer dan 200 bestellingen krijgt zo een onvolledige export of verwijdering, zonder dat iemand het merkt. Het is ook een time-out-risico.
**Voorstel:** Pagineer zoals WP het verwacht: een vaste `PAGE`-grootte voor zowel de export als de eraser, `done = count( $slice ) < PAGE`, en in `wc_get_orders` `'paginate' => false, 'limit' => -1, 'return' => 'ids'` of een ID-query. Sorteer deterministisch.
**Acceptatie:** Een PHP- of e2e-test met 250 orders en 520 offertes voor één e-mailadres: de export bevat alle 250 orders en 520 offertes, verdeeld over meerdere pagina's, en de eraser rapporteert `done=false` tot de laatste pagina, waarna 0 ontwerpen overblijven.

### php-kwaliteit-10 — Ontbrekende types en vormen (middel)
**Bestanden:** `includes/rest/class-routes.php` (160-622: 9 callbacks zonder return type), `includes/woo/class-cart.php` (~14 hookcallbacks), `includes/woo/class-emails.php`, `includes/woo/emails/*`, `includes/class-settings.php:45`, alle `array`-parameters
**Probleem:** PHPStan level 6 geeft 427 meldingen: 255× "no value type specified in iterable" en ±40 methodes zonder return type. De centrale datastructuren (design record, PriceQuote, de `bps`-data van een cart-item, config) hebben geen vorm, waardoor fouten zoals die in bevinding 2 niet opvallen. Het `@var` van `Settings::LIMITS` klopt niet met de waarde (int- tegenover float-tuples).
**Voorstel:** Definieer `@phpstan-type DesignRecord array{id:int, ref:string, order_id:int, payload:array<string,mixed>, …}`, `PriceQuote`, `CartBps` en `Config` op één plaats en importeer ze met `@phpstan-import-type`. Geef return types: `WP_REST_Response|WP_Error` voor REST, `array<string,mixed>` voor filters. Geen `declare(strict_types=1)` globaal, want dat is riskant met WP-callbacks.
**Acceptatie:** `phpstan analyse -l 6` geeft 0 fouten zonder baseline. Daarna wordt level 8 de CI-norm, met een baseline die niet mag groeien (`phpstan-baseline.neon` in review gecontroleerd).

### php-kwaliteit-11 — Geen PHP-unit-tests buiten Pricing (middel)
**Bestanden:** `tests/pricing/*` (enige PHP-test), te testen: `includes/woo/class-svg-sanitizer.php`, `includes/class-settings.php` (`sanitize_value`, `enforce`, LIMITS), `includes/rest/class-guard.php` (`client_ip`, CIDR, rate limit), `includes/woo/class-payload.php`, proof-token in `class-order-production.php`, `includes/class-uploads.php`
**Probleem:** De beveiligings- en geldkritieke PHP (SVG-sanitizer, sanitizer van admininvoer, Cloudflare-IP-detectie, HMAC-proeftokens, payloadvalidatie) wordt enkel indirect via e2e geraakt. Een regressie in de SVG-sanitizer valt dus niet op.
**Voorstel:** PHPUnit 10 met Brain Monkey (voor pure klassen) plus `wp-phpunit` voor de integratie op de bestaande lokale WP. Testvectoren: SVG XSS-payloads (script, `on*`, `javascript:` in `href`/`xlink:href`, `<use>` extern, `<foreignObject>`, entity-expansie), Settings-limieten (negatief, NaN, buiten bereik), CIDR v4/v6, en proeftokens (huidige, vorige en vervalste ronde).
**Acceptatie:** `composer test` draait in `run-all.sh`, met minstens 60 testcases. De line coverage van SvgSanitizer, Settings, Guard en Payload is minstens 80% (`--coverage-text`). Alle tests zijn groen.

### php-kwaliteit-12 — Testschakelaar `BPS_TESTING` in productiecode van de uploadvalidatie (middel)
**Bestanden:** `includes/class-uploads.php` (470-494)
**Probleem:** Als de constante `BPS_TESTING` op `true` staat, slaat `validate()` `is_uploaded_file()` over en kopieert `move()` willekeurige paden met `@copy()`. Een vergeten `define( 'BPS_TESTING', true )` op een staging- of productiesite (bv. een gekopieerde `wp-config`) zet zo een beveiligingscontrole stil uit in het pad voor bestanden van onbekende bezoekers.
**Voorstel:** Vervang dit door een geïnjecteerde "file mover" (interface met een `move()`-implementatie in productie en een test-double in de tests), of door een filter die enkel de e2e-mu-plugin registreert. Zet het `@` niet meer voor `copy`/`move_uploaded_file`; controleer de return en log.
**Acceptatie:** `grep -n BPS_TESTING includes/class-uploads.php` geeft 0 treffers. De uploadtests gebruiken de test-double. e2e `02-upload-advice` blijft groen.

### php-kwaliteit-13 — Statische globale staat en dubbele caches (laag)
**Bestanden:** `includes/admin/class-order-production.php:43, 145` (`self::$config`), `includes/class-settings.php:32-38`, `includes/woo/class-config.php:21`, `includes/woo/class-cart.php:22-25`
**Probleem:** Alles is `public static`, zonder instanties of dependency injection. `Order_Production` cachet de config apart van `Settings` en maakt die cache niet leeg bij `bps_settings_updated`, waardoor een opslag plus een mail in dezelfde request oude data gebruikt. `Cart::$internal_add` is een ongetypeerde statische vlag. Dat maakt unit-testen (bevinding 11) moeilijk.
**Voorstel:** Laat `Order_Production::cfg()` gewoon `Settings::get_path()` aanroepen en verwijder de eigen cache. Geef statische eigenschappen een type (`private static bool $internal_add = false;`). Schakel op termijn over naar een lichte container (`Plugin::instance()->settings()`), zodat tests een andere config kunnen injecteren.
**Acceptatie:** `grep -n "self::\$config" includes/admin/class-order-production.php` geeft 0 treffers. PHPStan meldt 0× "Property … has no type specified". Een test die `Settings::update()` aanroept en daarna de fiche rendert, toont de nieuwe waarde.

### php-kwaliteit-14 — Views en templates leunen op impliciete variabelen in scope (laag)
**Bestanden:** `includes/admin/views/tab-prijzen.php`, `tab-technieken.php`, `tab-teksten.php`, `tab-kleuren.php`, `tab-algemeen.php`; `templates/admin/*.php`, `templates/admin/emails/*.php`, `templates/emails/*.php`
**Probleem:** `$config`, `$vat` en `$defaults` komen uit een `include` binnen `Settings_Page`. PHPStan meldt 8× "Variable $config might not be defined", en WPCS meldt 30× een niet-geprefixte globale variabele. Thema-overrides van e-mailtemplates weten zo niet welke variabelen bestaan.
**Voorstel:** Zet bovenaan elke view en elk template een `@var`-docblok voor elke beschikbare variabele. Render views via een kleine `View::render( $file, array $args )` die `extract` vermijdt (`$args['config']`). Prefix lokale templatevariabelen met `bps_`.
**Acceptatie:** PHPStan meldt 0× "might not be defined" en WPCS 0× NonPrefixedVariableFound en GlobalVariablesOverride.

---

## Configuratie gebruikt voor de meting (ter referentie)

```
phpcs --standard=WordPress --extensions=php --ignore=*/languages/* \
  --runtime-set text_domain borduur-patch-studio \
  --runtime-set prefixes BorduurPatchStudio,bps_,BPS_ plugin/borduur-patch-studio
phpstan analyse -l 5|6|8 (includes vendor/szepeviktor/phpstan-wordpress/extension.neon,
  scanFiles woocommerce-stubs.php, bootstrap met BPS_*-constanten + autoloader)
```
