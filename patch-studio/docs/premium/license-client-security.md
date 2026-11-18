# Licentie- en updateclient: security-eisen (security-12, productisering-1)

Status 18-11-2026: de client is gebouwd in `plugin/borduur-patch-studio/includes/licensing/` (P02).
Deze checklist geldt voor elke wijziging aan die map en voor de serverkant. Een vinkje betekent: zo gebouwd en getest.

## Checklist (7 eisen)

- [x] **1. Standaard uit, 0 externe requests zonder sleutel.** Zonder opgeslagen sleutel hangt `Licensing\Client::register()`
  geen enkele filter in (`update_plugins_{host}`, `plugins_api`, `upgrader_pre_download`) en plant geen cron.
  De beheerpagina verschijnt alleen met `define( 'BPS_LICENSING', true );` in wp-config.php of als er al een sleutel is.
  Test: `tests/core/run-core-php.php` "licentie zonder sleutel" (10 admin-pageloads + een update-run, `pre_http_request`-spion = 0).
- [x] **2. Vaste update-host, alleen HTTPS met `sslverify => true`, nooit via de UI instelbaar.** Host = constante
  `BPS_UPDATE_URL` (standaard `https://borduurservicebelgie.be/`, enkel te overschrijven in wp-config.php).
  Een niet-HTTPS-waarde schakelt alle verkeer uit (`Client::update_url()` geeft dan `''`). Requests via
  `wp_safe_remote_post` met `sslverify => true`. De plugin-header `Update URI: https://borduurservicebelgie.be/` voorkomt
  dat wordpress.org een plugin met dezelfde slug als update aanbiedt.
- [x] **3. Ondertekende zips (Ed25519), geverifieerd in `upgrader_pre_download`.** `Client::pre_download()` downloadt het
  pakket zelf, controleert de losse handtekening met `sodium_crypto_sign_verify_detached()` (sodium_compat zit in core)
  tegen de publieke sleutel `Client::PUBLIC_KEY` (of `BPS_UPDATE_PUBLIC_KEY` in wp-config.php) en geeft WordPress pas
  dan het bestand. Ongeldige of ontbrekende handtekening, onbekend pakket-URL of geen publieke sleutel ⇒ `WP_Error`
  en het gedownloade bestand wordt gewist (fail closed). Test: geldige zip ⇒ pad, gewijzigde zip ⇒ `WP_Error`.
- [x] **4. Licentiesleutel: autoload=no, nooit in frontend of REST, gemaskeerd, alleen `manage_options` + nonce.**
  Optie `bps_license` (autoload off). Getoond als `••••••••ABCD`. Het invoerveld is altijd leeg (`type=password`).
  Opslaan/verwijderen/controleren via `admin-post.php?action=bps_license` met `check_admin_referer` en `manage_options`.
  De sleutel staat in `Plugin::owned_data()['always_options']`: elke deïnstallatie wist hem. Het statusrapport toont
  alleen "Licentiesleutel: ja/nee".
- [x] **5. Serverrespons = alleen data.** `Client::sanitize_release()` aanvaardt enkel: versie (regex), pakket-URL (HTTPS
  en dezelfde host als de update-host), handtekening (64 bytes base64), tested/requires/requires_php (versie-regex),
  changelog door `wp_kses_post`. Statussen alleen uit een vaste lijst. Er wordt nooit code van de server uitgevoerd.
- [x] **6. Caching 12 uur, timeout 10 s, backoff bij fouten.** Release-info in transient `bps_license_update` (12 u).
  Timeout 10 s. Na een fout: transient `bps_license_backoff`, 1 u, verdubbelend tot max 24 u. Geen eigen cron: de
  controle loopt alleen mee met de update-check van WordPress zelf.
- [x] **7. Zo weinig mogelijk data, gedocumenteerd in de privacytekst.** Verstuurd: sleutel, site-URL (`home_url`),
  slug, geïnstalleerde versie. Geen klant- of bestelgegevens. Met een sleutel voegt `Licensing\Admin::privacy_text()`
  een alinea toe aan de privacyverklaring-hulp van WordPress.

## Bedrijfsregel
Een verlopen of ongeldige licentie stopt **alleen updates**. Geen enkele winkelfunctie kijkt naar de licentie.

## Contract met de server (eigen WooCommerce-winkel, nog te kiezen backend)
`POST {BPS_UPDATE_URL}wp-json/bps-license/v1/{activate|deactivate|check|update}` met body `key`, `site`, `slug`
(+ `version` bij `update`). Antwoorden (JSON):
- activate/check: `{ "status": "valid|expired|invalid|inactive|disabled|site_limit", "expires": "YYYY-MM-DD", "message": "…" }`
- update: `{ "version": "1.1.0", "package": "https://borduurservicebelgie.be/…/borduur-patch-studio-1.1.0.zip",
  "signature": "<base64 Ed25519 detached signature van de zip-bytes>", "tested": "6.8", "requires": "6.4",
  "requires_php": "8.1", "changelog": "<p>…</p>" }` of `{}` als er geen update is voor deze sleutel.
Een tweede driver komt er pas als er echt een andere backend gekozen wordt (interface `Licensing\Driver`).
Nooit WooCommerce-REST-sleutels in de plugin meeleveren.

## Releasesleutel (wanneer verkoop beslist is)
1. Eenmalig offline: `php -r '$k=sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k)),"\n",base64_encode(sodium_crypto_sign_publickey($k)),"\n";'`
2. Publieke sleutel in `Client::PUBLIC_KEY` zetten (in de release die de client voor het eerst gebruikt).
3. Per release: `php -r 'echo base64_encode(sodium_crypto_sign_detached(file_get_contents($argv[1]), base64_decode($argv[2])));' borduur-patch-studio.zip <geheime sleutel>`
   en die handtekening in het `update`-antwoord zetten. De geheime sleutel staat nooit op de webserver.

## Release-checklist (header)
`Version`, `BPS_VERSION`, `package.json` en `config/defaults.json` gelijk; `WC tested up to` = laatst geteste WooCommerce
(nu 11.3); `Requires Plugins: woocommerce`; `Update URI` precies één keer.
