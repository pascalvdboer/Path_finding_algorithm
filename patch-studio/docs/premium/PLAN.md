# Patch Studio premium: werkplan (hoofdarchitect, 18-11-2026)

Bron: de bevestigde bevindingen uit `docs/premium/audit-*.md`. Ze zijn gegroepeerd in **13 werkpakketten die parallel lopen**.
Elk pakket heeft een exclusieve lijst bestanden ("owns"). Een bestand dat niet in je eigen lijst staat, wijzig je niet.
Heb je daar iets nodig, gebruik dan het contract (hook of API) uit §3, of schrijf het op in je eindrapport.

## 1. Harde regels voor alle pakketten
- **Verboden paden (ander team):** `assets/src/ui/**`, `assets/src/render/**`, `assets/src/main.js`, `assets/css/**`, `dev/**`, `demo/**`, `scripts/build-demo.mjs`.
  Wat de klant-UI nodig heeft, voeg je toe aan `docs/handoff/premium-ui-needs.md`. Dat bestand is **append-only en gedeeld**:
  voeg één blok toe met kop `## [Pxx] <onderwerp> (18-11)` in één schrijfactie (`cat >> …`). Nooit herschrijven of herordenen.
- Geen git-commits. Code-commentaar in het Engels. Klantteksten in Vlaams NL (je-vorm) en FR. Eerlijk: geen nep-reviews, geen valse schaarste.
- Nieuwe PHP-klassen volgen de autoloaderconventie (`includes/<map>/class-<naam>.php`, namespace `BorduurPatchStudio\…`).
- **Gedeelde bestanden hebben één eigenaar:** hoofdpluginbestand, `class-plugin.php` en `uninstall.php` = P02;
  `config/defaults.json` en `class-settings.php` = P08; `tests/run-all.sh` en `package.json` = P12; `tests/e2e/lib/**` = P13.
- **Tests worden automatisch opgepikt**, zodat niemand `run-all.sh` of `package.json` hoeft te wijzigen:
  - node: elk `tests/**/*.test.js` (de glob in `npm test`);
  - PHP: maak een runner `tests/<gebied>/run-<gebied>-php.php` en een bridge `tests/<gebied>/<gebied>-php.test.js` volgens het model van `tests/services/services-php.test.js`. P12 laat `test:php` alle `run-*-php.php` draaien;
  - e2e: elk `tests/e2e/NN-*.e2e.cjs` (de glob in run-e2e.sh). Testfilters voor WP zet je in een **nieuw** bestand `tests/e2e/mu/<pakket>-*.php`; P13 laadt die map in de testsite.
- **Gereserveerde e2e-nummers:** 09 P01 security, 10 P01 api-errors, 11 P06 account, 12 P10 atelier, 13 P08 admin-settings, 14 P13 perf, 15 P13 nametape, 16 P13 ai-mock, 17 P04 minicart/reorder, 18 P05 proof, 19 P03 privacy (optioneel), 20 P07 quote-order.
- Elk pakket logt in zijn eigen catch-blokken via `Logger` (contract C1) en laat zijn eigen bestanden schoon voor `WordPress.Security.*`.
- De e2e-scenario's 01-08 blijven groen. `npm test` en `npm run test:php` blijven groen voor je eigen gebied.

## 2. Volgorde en fasen
- **Fase 1 (parallel):** P01-P13. Ze staan geordend op prioriteit: security en data eerst. P02 schrijft als **eerste stap** `includes/class-logger.php` en de moduleregistry, P11 schrijft als eerste stap `includes/class-i18n.php` (API uit C9), zodat de anderen erop kunnen bouwen.
- **Fase 2 (sequentieel, na fase 1):** (a) P12 draait phpcbf en het opruimwerk van php-kwaliteit-5 over de hele plugin, behalve de verboden paden, en legt de baseline vast. (b) P11 regenereert POT/PO en vertaalt alle nieuwe strings. (c) Daarna volgt een volledige `bash tests/run-all.sh`.
  Mass-reformatting tijdens fase 1 is verboden: dat geeft conflicten met de andere pakketten.

## 3. Contracten (API's en hooks tussen pakketten)
| # | Eigenaar | Contract |
|---|---|---|
| C1 | P02 | `Logger::error/warning/info/debug( string $event, array $context = [] )`. Source `borduur-patch-studio`. Valt terug op error_log alleen met WP_DEBUG_LOG. Context gaat door redact, e-mails en IP's worden gehasht. Instelling `admin.logLevel` (standaard `error`). |
| C2 | P02 | `Plugin::modules()` (registry: id, class, label, requires, default) en `Plugin::owned_data()` (options, option-prefixen, tabellen, cron-hooks, AS-groepen), uitbreidbaar met filter `bps_owned_data`. Actie `bps_upgraded( $from, $to )`. Elke module heeft `register_always()` voor de privacyhooks en cron-opruiming, die ook draaien als de module uit staat. |
| C3 | P06 | `Designs::clone( int $id, array $args = [] ): int\|WP_Error`. Args: `parent_id`, `reorder_of` = {design_id, order_id}, `from_payload` (array, als de bestanden weg zijn), `status`. Geeft altijd een nieuwe ref, nieuwe created_gmt (dus een nieuw token), gekopieerde bestanden en proef none. `Designs::preview_url( int $id, string $size )` met size `full`, `cart` (300 webp), `cart2x` (600 webp) of `mail` (600 jpg). Valt terug op de PNG. `Uploads::check_storage( int $bytes ): true\|WP_Error` (507 bps_storage). |
| C4 | P01 | Routes: `do_action( 'bps_design_emailed', $design_id, $email, $request )` in email_design. `Routes::cart` kloont via C3 als het ontwerp besteld is. De gebruiker wordt hersteld vóór `Designs::create`. Filter `bps_payload_validate( null\|WP_Error $err, array $payload, array $raw )`. Helper `Help_Line::text( string $locale )` (`includes/class-help-line.php`). Een Origin-check in `Guard::public_write` geldt voor alle publieke schrijfroutes, ook die van andere pakketten via `Guard::public_write`. |
| C5 | P04 | `Woo\Config::woo_unit_price( float $excl, WC_Product $p ): float`. `Order::write_line_meta( WC_Order_Item_Product $item, array $bps )`. Cart-item-sleutels `bps.reorder_of` en `bps.locked_unit_excl` (prijs alleen server-side gezet). Herbestellingsfee: **één instelling, `promises.reorderKeepsSetup`** (bestaat al, standaard false). `fees.waiveOnReorder` uit klant-account-5 wordt dus NIET gebouwd. `Lead_Time::estimate( … ): ?array`. `Product::studio_url()` delegeert naar C8. |
| C6 | P05 | `Proof\Proof_Service::get( $item )`, `::url( $order, $item )`, `::export_for_order( $order )`. Acties `bps_proof_sent( $order_id, $item_id, $round )` en `bps_proof_response( $order_id, $item_id, $answer, $round )`. `Order_Production::fiche( $order, $item )` gaat door filter `bps_fiche_data( $fiche, $order, $item )`. Actie `bps_fiche_after_specs( $fiche, $context )`. `Order_Production::render_print( array $pairs /* [order_id,item_id] */ ): string`. De werkbonstappen lezen `$fiche['production']['status']`. |
| C7 | P08 | Filter `bps_status_checks` (lijst van [id, label, status ok\|warn\|error, message]). `Admin\Menu::SLUG = 'patch-studio'`: alle beheerpagina's hangen hieronder. Nieuwe defaults.json-sleutels (§4). `Settings::public_config()` laat `_`-sleutels, priceSource en privé-velden weg. |
| C8 | P09 | `Frontend\Studio_Pages::ids(): int[]`, `::url( ?string $locale = null ): string`, `::detected(): array`. Opties `bps_studio_pages` (autoload no) en `bps_studio_main_page`. |
| C9 | P11 | `I18n::switch_lang( string $lang ): bool`, `I18n::restore(): void`, `I18n::boot()` (fr_*-locale ⇒ fr_BE-plugincatalogus). P02 zet I18n in de componenten. |
| C10 | P13 | `tests/e2e/lib`: `seedOrder()`, strict-modus (SKIP = FAIL in CI), laden van `tests/e2e/mu/*.php`. |

## 4. Nieuwe instellingen (P08 zet ze in defaults.json + LIMITS + een veld; de andere pakketten lezen ze met een veilige fallback)
`modules.{ai,nametape,crosssell,services}.enabled`, `admin.logLevel`, `reminder.enabled` (true), `reminder.delayDays` (3), `account.enabled` (true),
`account.slug` (nl/fr), `retention.accountSavedDays` (730), `retention.keepPreviewOnExpiry` (true), `woo.maxDraftStorageMb` (2048),
`production.autoStart` (false), `proof.reminderDays` (3), `leadTime.*` (productionDays per techniek null, proofDays, shippingDays, closures[]),
`fees.perVariantName` (0), `fonts[].machineFont` (privé), `fonts[].enabled`, `techniqueOrder[]`, `brand.*` (shopName, accent, studioTitle nl/fr),
`upload.acceptStore` + dst/pes/exp/jef, nametape `retention.daysAfterComplete` (180, in de nametape-instellingen: P03).
features.reminder en features.account gaan naar public_config.

## 5. Bewuste architectuurbeslissingen
- **Proef per orderregel** (atelier-flow-1, P05) is de echte oplossing voor security-4. Het klonen bij /cart (P06/P01) komt er bovenop.
- **Eén kloon-API** (C3) voor security-4, klant-account-3/5 en woo-compat-3. **Eén uninstall-lijst** (C2) voor php-kwaliteit-8, productisering-2, gdpr-13 en security-9. **Eén Logger** (C1) voor php-kwaliteit-4 en productisering-9.
- **Modules:** registry met aan/uit per module (productisering-10). Dat vervangt de vaste COMPONENTS-regel van gdpr-juridisch-3. De privacyhooks en cron-opruiming van elke module worden altijd geregistreerd.
- concurrentie-features-3 (atelier-modus) bouwt zelf een minimaal offertemodel op bps_quote, omdat klant-account-6 niet bevestigd is. Dat gebeurt na woo-compat-11, in hetzelfde pakket.

## 6. Pakketten
Zie de tabel hieronder. De volledige instructies staan in de workflow-uitvoer.

| Pakket | Doel | Bevindingen |
|---|---|---|
| P01 rest-security | REST, Guard, SVG en payload verharden, plus security-tests | security-6, -2, -8, -3 (route), -5 (header), -13, php-kwaliteit-11, tests-ci-2, tests-ci-6, gdpr-juridisch-10, productisering-4 (guard), security-4 (cart-aanroep) |
| P02 core-lifecycle | Bootstrap, modules, uninstall, upgrade, logger, statusrapport, licentie | productisering-10, -2, -7, -8, -9, -1, gdpr-juridisch-3, -13, php-kwaliteit-8, -4 (Logger), security-9 (uninstall), security-12, woo-compat-12, prestatie-3, prestatie-4 |
| P03 privacy-retention | AVG-export/erase, bewaartermijnen, naamstrook-privacy, AI-privacy | php-kwaliteit-9, security-9 (eraser), klant-account-8, -12, gdpr-juridisch-1, -2, -5, -6, -7, -11, -12, -15, productisering-4 (nametape) |
| P04 woo-cart-pricing | Prijzen incl./excl. btw, mini-cart, herbestellen, add-ons, leverdatum, namenvarianten | woo-compat-1, -2, -3, -6, -9, -13, php-kwaliteit-4 (cart), php-kwaliteit-7, gdpr-juridisch-4, -8, concurrentie-features-7, -9, prestatie-1 (cart), i18n-a11y-6 (cart) |
| P05 proof-workflow | Order_Production opsplitsen, proef per regel, dossier, werkbon, proefpagina, herinnering | php-kwaliteit-3, atelier-flow-1, -2, -3, -7, -9, -10, -11, -13, -14, woo-compat-4, security-5 (pagina), i18n-a11y-5, -6, -14, -7 (proef), -2 (admin plain), security-11 (sanitize), concurrentie-features-5, prestatie-1 (proefmail) |
| P06 designs-account | Ontwerpen, opslag, previewvarianten, Mijn ontwerpen, herinnering, revise, reorder, DST | klant-account-1, -2, -3, -5, -13, security-4, security-3 (teller), prestatie-1, concurrentie-features-8 |
| P07 quotes-emails | Veilige klantmails, offerte naar order, mail-locale, atelier-modus | security-1, php-kwaliteit-6, i18n-a11y-2, -4, -7, -6 (mail), woo-compat-7, -11, concurrentie-features-3, gdpr-juridisch-2 (anonymise), klant-account-2 (offerte-auteur), prestatie-1 (mail) |
| P08 admin-settings | Instellingen-UX, validatie, back-up, Studio-tab, merk, garenkaart, menu | admin-ux-1, -3, -4, -5, -8, -9, -10, -11, -12, -13, -14, -15, productisering-6, concurrentie-features-11 |
| P09 frontend-studio | Studiopagina-detectie, shortcode/widget-presets, sjablonenbibliotheek, accent-CSS | prestatie-2, security-10, concurrentie-features-1, -10, admin-ux-8 (frontend-CSS) |
| P10 atelier-overview | Productiestatus, atelieroverzicht, digitaliseerpakket, ontwerpenlijst, overzichtscijfers | atelier-flow-4, -5, -6, concurrentie-features-4, -2 |
| P11 i18n | I18n-helper, POT/PO, FR-vertaling, glossarium, i18n-poort | i18n-a11y-10, -1, -13, tests-ci-15 (i18n) |
| P12 quality-tooling | composer/phpcs/phpstan/eslint, test:php-runners, playwright pin, CI, QR-test | php-kwaliteit-1, -5, security-11 (poort), tests-ci-1, -4, -5, -9, -10, -11 |
| P13 e2e-release | Reproduceerbare e2e, harness, perf-e2e, extra e2e, readme/changelog/versie/zip | tests-ci-8, -12, -13, -14, -15 (zip), productisering-3, -11, prestatie-14 |
