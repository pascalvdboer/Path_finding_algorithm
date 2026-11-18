# Audit i18n + toegankelijkheid (PHP- en e-mailkant): Patch Studio

Auditor "i18n-a11y", 17-11-2026. Ik heb geen plugincode gewijzigd. Scope: alles wat PHP of e-mail rendert (admin, WooCommerce-mails, proefpagina, cart/order-meta, config-teksten), plus de vertaalbestanden. De klant-UI (JS) heb ik alleen gelezen om terminologie te vergelijken. Wat de UI nodig heeft, staat in `docs/handoff/premium-ui-needs.md`.

## Wat ik echt getest heb
- **WP-CLI `i18n make-pot`** op de huidige code (2x, ook na de nieuwe bestanden in `includes/ai` en `includes/services`): 728 msgids, **identiek** aan `languages/borduur-patch-studio.pot`. Wel 7 waarschuwingen over tegenstrijdige `translators:`-commentaren.
- **fr_BE-catalogus**: 0 onvertaald, 0 fuzzy, 0 placeholder-mismatches (eigen check op `.l10n.php`: `%s/%d/%1$s` en HTML-tags per string vergeleken).
- **PHPCS + WPCS 3, sniff `WordPress.WP.I18n`** op de hele plugin: 0 fouten. Tekstdomein, letterlijke strings, geordende placeholders en translators-commentaren zijn in orde.
- **Runtime in de lokale WP** (`wp eval`):
  - `switch_to_locale()` naar fr_BE / fr_FR / en_US / de_DE, en dan `__( 'Techniek' )`: alleen **fr_BE** geeft "Technique". **fr_FR** en en_US geven "Techniek", de_DE kan niet eens wisselen.
  - Alle mails eerst in een fr_BE-request gerenderd: **de shopmail "nieuwe offerte" komt in het Frans**.
  - Woo's plain-text-pijplijn op `esc_html()`-uitvoer: "Chloé D'Hondt, voici l'épreuve" wordt **"Chloé DHondt, voici lépreuve"**.
- **axe-core 4 (Playwright)** op de 5 instellingentabs (`wcag2a/aa, 21aa, 22aa, best-practice`): alleen `landmark-banner-is-top-level` (moderate). Op de proefpagina (`/?bps_proof=…`): 0 fouten van de plugin zelf, wel 2 van het thema.
- **Contrast** van de kleuren in mails en proefpagina: #0B74A6 op wit 5,17:1, #6B6B6B op wit 5,33:1, foutmelding 6,05:1. Allemaal AA. Alleen #21A9E2 haalt 2,68:1, maar wordt enkel als randkleur gebruikt.
- **Drift-check** tussen de ingebouwde Franse tabel `Order_Production::FR` en de .po: **21 van de 59** vertalingen verschillen.

## Wat al premium is
De sterke basis eerst:
- Tekstdomein laadt op `init`.
- Alle PHP-strings zitten in `__()` met translators-commentaar.
- De .pot is actueel en fr_BE is volledig, inclusief `.l10n.php` (WP 6.5+).
- Klantmails switchen naar de taal van het ontwerp.
- Het admin-scherm is met axe zo goed als foutloos: zichtbare focus (#5926C5), `aria-current` op de tabs, `aria-describedby` voor hulpteksten, `aria-live` voor de btw-hint, `prefers-reduced-motion`.
- De proefpagina heeft labels, `role="alert"`/`role="status"`, knoppen van 48 px en een eigen `lang`.

## Samenvatting
| id | ernst | titel |
|---|---|---|
| i18n-a11y-1 | hoog | fr_FR/fr_CA/nl_NL-sites tonen Nederlands; mailtaal faalt stil als het fr_BE-taalpakket ontbreekt |
| i18n-a11y-2 | hoog | Plain-text-mails slikken apostroffen in ("DHondt", "lépreuve") |
| i18n-a11y-3 | hoog | Geen en_US en geen derde taal: alles wat niet "fr" is, wordt Nederlands |
| i18n-a11y-4 | middel | Shopmails (offerte) komen in de taal van de klant i.p.v. die van de winkel |
| i18n-a11y-5 | middel | Parallelle Franse tabel `Order_Production::FR` wijkt in 21/59 strings af van de .po |
| i18n-a11y-6 | middel | Meervouden ontbreken: "1 stuks", "1 pièces", "%d dagen", "%1$d kleuren" |
| i18n-a11y-7 | middel | ±400 klantteksten in JSON/instellingen + mailonderwerpen onbereikbaar voor WPML/Polylang |
| i18n-a11y-8 | middel | WPML/Polylang: vertaald "Patch op maat"-product wordt niet herkend (`is_ours` op exact ID) |
| i18n-a11y-9 | middel | Bedragen en datums hardgecodeerd (€, `,`/`.`, `d-m-Y`), los van WooCommerce en taal |
| i18n-a11y-10 | middel | Geen i18n-kwaliteitspoort in `tests/run-all.sh` terwijl meerdere teams strings toevoegen |
| i18n-a11y-11 | laag-middel | RTL: mails, proefpagina en admin-CSS gebruiken vaste left/right |
| i18n-a11y-12 | laag-middel | Order-meta bevriest de waarden in de taal van de checkout (Franse waarden voor NL-admin) |
| i18n-a11y-13 | laag | FR-terminologie: "dessin" (PHP/mails, 63x) tegenover "design" (studio, 91x) voor "ontwerp" |
| i18n-a11y-14 | laag | Proefpagina in thema: geen `lang`/`dir` op de inhoud, mogelijk dubbele `<main>` |
| i18n-a11y-15 | laag | Admin-WCAG-afwerking: banner-landmark, `target=_blank` zonder melding, kleurenschema overschreven |

---

## i18n-a11y-1 · hoog · Franse sites buiten fr_BE (en nl_NL) krijgen Nederlands; mailtaal faalt stil
**Bestanden:** `includes/class-plugin.php:188`, `includes/woo/class-config.php:141-164`, `includes/woo/emails/class-base.php:49-55`, `includes/rest/class-routes.php:176`, `includes/admin/class-order-production.php:1731-1733, 2155-2159`, `languages/`

**Probleem:** de plugin levert alleen `borduur-patch-studio-fr_BE.mo`. Daardoor loopt het op drie manieren fout:
- Een site of gebruiker in **fr_FR** (vaak bij WPML/Polylang), fr_CA, fr_LU of fr_CH krijgt alle PHP-teksten in het Nederlands: admin, cart-meta, foutmeldingen, mails. Runtime-test: `switch_to_locale('fr_FR'); __('Techniek')` geeft "Techniek".
- Mails en REST forceren `switch_to_locale('fr_BE')`/`'nl_BE'`. WordPress geeft dan stil `false` terug als dat core-taalpakket niet geïnstalleerd is (`WP_Locale_Switcher::switch_to_locale` controleert `get_available_languages()`). Een Franstalige klant krijgt dan een Nederlandse mail, met `lang="nl-BE"`.
- De Franse noodtabel (zie -5) dekt alleen de proefmail.

**Voorstel:**
1. Registreer een fallback voor het tekstdomein, bv. met de filter `load_translation_file` / `load_textdomain_mofile` of een eigen `load_textdomain()`: voor elke `fr_*`-locale wordt `borduur-patch-studio-fr_BE.(l10n.php|mo)` geladen als er geen exact bestand is, en voor `nl_*` gebeurt niets (dat is de brontaal).
2. Kies de mail/REST-locale niet hardgecodeerd. Kies de beste **geïnstalleerde** locale van die taal (`get_available_languages()`: fr_BE > fr_FR > eerste `fr_*`). Lukt de switch niet, laad dan toch het plugin-tekstdomein in die taal (`switch_to_locale` is niet nodig voor onze eigen strings).
3. Log één admin-notice als er geen enkele `fr_*`-core-locale is: "Installeer het Franse taalpakket zodat ook WooCommerce-teksten in mails vertaald zijn."

**Acceptatie:**
- `wp eval` met `switch_to_locale('fr_FR')` geeft "Technique" voor `__('Techniek','borduur-patch-studio')`. Idem voor fr_CA als die geïnstalleerd is.
- Op een site met alleen nl_BE + fr_FR (fr_BE-pakket verwijderd) bevat de proefmail voor een ontwerp met `locale: fr-BE` "Bonjour" en `<html lang="fr-FR">`.
- Een PHPUnit/WP-CLI-test dekt beide gevallen.

## i18n-a11y-2 · hoog · Plain-text-mails slikken apostroffen in
**Bestanden:** `templates/emails/plain/*.php`, `templates/admin/emails/plain/*.php` (bv. `plain/quote-customer.php:18`, `plain/quote-admin.php:31-39`, `admin/emails/plain/proof.php`)

**Probleem:** de plain-templates sturen alles door `esc_html()`. Daardoor wordt `'` → `&#039;`. WooCommerce maakt van de plain-tekst daarna `preg_replace($plain_search, …)`, en `&#039;` staat niet in die lijst. Het valt onder "Unknown entities" en wordt **verwijderd**.

Runtime-test: "Chloé D'Hondt, voici l'épreuve numérique" wordt "Chloé DHondt, voici lépreuve numérique". Dat raakt:
- Vlaamse familienamen (D'Hondt, 't Kint)
- vrije klantberichten in de shopmail
- alle Franse strings uit de noodtabel `FR` (rechte apostrof)
- `'s`-constructies in het Nederlands

Voor een screenreader of Braille-leesregel (de reden voor plain-text) wordt dat onzin.

**Voorstel:** escape in plain-templates niet als HTML. Gebruik `wp_strip_all_tags()` + `html_entity_decode( …, ENT_QUOTES, 'UTF-8' )`, of een helper `BPS\Emails\plain( $s )`. Onderdruk de phpcs-melding met een gerichte `// phpcs:ignore WordPress.Security.EscapeOutput -- plain text e-mail` zoals WooCommerce-extensies dat doen. Laat URL's door `esc_url_raw()` gaan.

**Acceptatie:**
- Een test die `get_content()` van elke BPS-mail rendert met `email_type=plain`, klantnaam `D'Hondt`, bericht `l'écusson "rond" & co`, in nl en fr. De uitvoer bevat die strings letterlijk.
- Geen `&#` of `&amp;` in de uitvoer.

## i18n-a11y-3 · hoog · Geen en_US en geen derde taal
**Bestanden:** `includes/woo/class-config.php:141-164`, `includes/class-settings.php:310-318`, `includes/admin/class-order-production.php:173-182`, `borduur-patch-studio.php` (header "Source strings: nl_BE"), `languages/`, `scripts/make-i18n.sh`

**Probleem:** de taal is overal binair: `0 === stripos($locale,'fr') ? 'fr' : 'nl'`. Een en_US-, de_DE- of nl_NL-gebruiker krijgt Nederlands, en er bestaat geen en_US-catalogus.

Voor een plugin die later commercieel verkocht kan worden (BRIEF, "premium-kwaliteit") is dat een blokkade:
- Engels is de verwachte basistaal.
- Een Engelstalige admin kan de instellingen niet lezen.
- Een tweetalige NL/EN-shop is onmogelijk.

**Voorstel:**
1. Lever `borduur-patch-studio-en_US.po/.mo/.l10n.php` (vertaling van de 728 NL-bronstrings). Op lange termijn: schakel over op Engelse bronstrings en lever nl_BE + fr_BE, het WordPress-standaardmodel.
2. Vervang de drie binaire helpers door één `Config::lang()` met een register `supported = ['nl','fr','en']` (filterbaar via `bps_supported_languages`). Mapping: `nl_*`→nl, `fr_*`→fr, `en_*`→en, de rest → instelbare standaardtaal (standaard: taal van de site, anders en).
3. Breid `Config::label()` uit met een fallbackketen: gevraagde taal → en → nl → eerste waarde.
4. Handoff: de JS-catalogi (`messages.js`) moeten een `en`-kolom krijgen (zie premium-ui-needs.md).

**Acceptatie:**
- Met gebruikerstaal en_US toont `admin.php?page=bps-settings` geen Nederlandse tekst. Te testen met Playwright: geen van 20 vooraf gekozen NL-woorden ("Techniek", "Bewaar", "stuks" …) staat op de pagina.
- `Config::lang('de_DE')` geeft de ingestelde standaardtaal.
- `make-pot`/`update-po` houdt en_US op 0 onvertaald.

## i18n-a11y-4 · middel · Shopmails komen in de taal van de klant
**Bestanden:** `includes/woo/emails/class-base.php:49-68`, `includes/woo/emails/class-quote-admin.php:65-84`, `includes/rest/class-routes.php:160-205`, `Proof_Response_Email` in `includes/admin/class-order-production.php`

**Probleem:** een Franse klant vraagt een offerte aan. `pre_dispatch` switcht dan het hele REST-request naar fr_BE. `Quote_Admin` heeft geen `bps_locale`, dus `parent::setup_locale()` gebruikt WooCommerce. Woo switcht **alleen bij klantmails** (`is_customer_email()`), dus de shopmail blijft Frans.

Runtime-test in een fr_BE-context: onderwerp "[{site_name}] Nouvelle demande de devis {ref} de {customer_name}". Ook `Quotes::design_rows( $d )` zonder locale geeft Franse labels. Hetzelfde risico geldt voor `Proof_Response_Email` als de klant op de Franse proefpagina antwoordt (die switcht ook naar fr_BE).

**Voorstel:** in `Base::setup_locale()`, voor `! $this->is_customer_email()`, switch expliciet naar de taal van de ontvanger:
- `switch_to_user_locale()` van de eerste ontvanger als die een WP-gebruiker is,
- anders `get_locale()` (de site), met `$this->bps_switched` bijgehouden.

Geef `design_rows()` die locale expliciet mee.

**Acceptatie:** een test zet `switch_to_locale('fr_BE')`, triggert `BPS_Email_Quote_Admin` en `BPS_Email_Proof_Response` op een nl_BE-site, en controleert dat onderwerp en body "Nieuwe offerte-aanvraag" / "Antwoord op een digitale proef" bevatten en geen "devis".

## i18n-a11y-5 · middel · Parallelle Franse tabel wijkt af van de .po
**Bestanden:** `includes/admin/class-order-production.php:45-109` (`const FR`), `:208-214` (`l()`), templates `admin/emails/proof*.php`, `admin/proof-response.php`

**Probleem:** de proefmail en de proefpagina hebben een tweede, handgeschreven Franse catalogus. **21 van de 59** strings verschillen van de .po. Voorbeelden:
- "design" vs "dessin"
- "Finalement, je souhaite modifier" vs "Modifier quand même quelque chose"
- rechte vs typografische apostrof

Welke versie de klant ziet, hangt dus af van het wel of niet geladen zijn van de .mo. `l()` zoekt bovendien op de **vertaalde** uitvoer: past de shop de NL-tekst aan met Loco Translate, dan valt het Frans weg. Strings die niet in de tabel staan (bv. `'%1$s om %2$s'`) blijven Nederlands, wat een gemengde zin oplevert.

**Voorstel:** schrap `FR` en `l()` zodra -1 de fr-catalogus altijd laadt. Wil je toch een vangnet, genereer het dan tijdens de build uit de .po (`.l10n.php` meeleveren volstaat) en laad het via `load_textdomain()`. Geen tweede bron.

**Acceptatie:**
- `grep -c "const FR" includes/admin/class-order-production.php` = 0, of een test faalt bij elke afwijking tussen de tabel en de .po.
- De Franse proefmail is identiek, met en zonder fr_BE-core-taalpakket.

## i18n-a11y-6 · middel · Meervouden ontbreken
**Bestanden:**
- `includes/admin/class-order-production.php:563, 677, 957, 960, 969, 1514, 1886`
- `templates/admin/fiche-card.php:42`
- `includes/woo/class-cart.php:520, 529, 778, 783`
- `templates/emails/save-design.php:81` + `plain/save-design.php:40`
- `includes/woo/class-privacy.php:44`

**Probleem:** de plugin heeft maar 5 `_n()`-calls. Strings als `'%s stuks'` (proefmail en fiche bij een bestelling van 1 stuk: "1 stuks" / "1 pièces"), `'%1$s maken we vanaf %2$d stuks'`, `'De link werkt %d dagen'` en `'%1$d kleuren; maximum is %2$d'` zijn grammaticaal fout bij 1. In het Frans geldt dat ook bij 0 (nplurals=2; plural=(n > 1)), en het blokkeert talen met 3 of meer vormen.

**Voorstel:**
- Gebruik `_n()` (of `_n_noop` + `translate_nooped_plural` in tabellen) voor elke string waarvan het getal een telwoord is.
- Geef de waarde als getal door en formatteer met `number_format_i18n()`.
- Splits strings met twee tellers in twee meervoudszinnen.

**Acceptatie:**
- `grep -c msgid_plural languages/*.pot` ≥ 20.
- De proefmail voor qty=1 bevat "1 stuk" / "1 pièce".
- Een test rendert `customer_rows()` voor qty 1 en 2 in nl en fr.

## i18n-a11y-7 · middel · ±400 klantteksten onbereikbaar voor WPML/Polylang
**Bestanden:**
- `config/*.json`: {nl,fr}-paren per bestand: defaults 117, thread-palette 106, nametape 50, ai-services 34, ai-prompts 30, crosssell 27, ai-legal 25, ai-mock 10, ai-pricing 3
- `includes/admin/views/tab-teksten.php` (`text_pair`)
- `includes/woo/class-config.php:166+` (`label()`)
- WooCommerce-mailinstellingen (onderwerp, kop, extra inhoud)
- geen `wpml-config.xml`

**Probleem:** klantteksten (beloftes, labels van technieken/vormen/garens, juridische AI-teksten) staan als `{nl,fr}`-objecten in JSON en in de optie `bps_settings`:
- WPML String Translation en Polylang zien ze niet: geen `wpml-config.xml` met `admin-texts`, geen `pll_register_string`.
- Een derde taal toevoegen kan alleen via code.
- Het onderwerp, de kop en de "extra inhoud" van de BPS-mails zijn per mail één optie. Past de shop het onderwerp aan in het NL, dan krijgen Franse klanten een NL-onderwerp. WCML regelt dat alleen voor core-mails.

**Voorstel:**
1. Lever `wpml-config.xml` met `<admin-texts>` voor `woocommerce_bps_email_*_settings` (subject, heading, additional_content) en de vrije-tekstvelden in `bps_settings`.
2. Registreer de `text_pair`-velden bij Polylang (`pll_register_string`, groep "Patch Studio") en lees ze terug met `pll__()`/`icl_t`-filters (`wpml_translate_single_string`).
3. Generaliseer `text_pair` naar "één veld per actieve taal": WPML `wpml_active_languages`, Polylang `pll_languages_list`, anders nl+fr.
4. Of geef de BPS-mails eigen velden `subject_fr`/`heading_fr` zolang er geen meertalige plugin actief is.

**Acceptatie:**
- Met WPML + String Translation (of de gratis Polylang) verschijnen "Patch Studio"-strings in de vertaalinterface, en een vertaald mailonderwerp wordt gebruikt bij een fr-klant.
- Zonder meertalige plugin: een aangepast FR-onderwerp in de instellingen komt in de Franse mail.
- Een geautomatiseerde test met Polylang (te installeren via wordpress.org) dekt één string.

## i18n-a11y-8 · middel · Vertaald product wordt niet herkend
**Bestanden:** `includes/woo/class-product.php:196-199`, `includes/woo/class-config.php:120-130`, `includes/woo/class-cart.php:262, 299, 409`

**Probleem:** `Product::is_ours()` vergelijkt exact met één product-ID. WPML/WCML en Polylang for WooCommerce maken per taal een **apart product-ID**. Op de Franse productpagina en in de Franse winkelmand faalt `is_ours()`:
- de bescherming tegen "toevoegen zonder ontwerp" werkt niet,
- de prijzen en cartregels van de studio vallen weg,
- de "Patch op maat" kan als gewoon product van €0 besteld worden.

**Voorstel:** normaliseer elk binnenkomend ID naar het origineel voor je vergelijkt:
- `apply_filters( 'wpml_object_id', $id, 'product', true, $default_lang )`,
- of `pll_get_post( $id, pll_default_language() )`,
- of vergelijk met `in_array( $id, self::all_translation_ids() )`.

Voeg bij het aanmaken van het product `_bps_product = 1` als meta toe en controleer die meta in plaats van het ID (WCML kopieert meta naar vertalingen).

**Acceptatie:** een e2e- of integratietest met een tweede product dat als vertaling geregistreerd is (mock-filter `wpml_object_id` volstaat):
- `?add-to-cart=<vertaald-id>` zonder ontwerp wordt geweigerd,
- een studio-cartregel met het vertaalde ID behoudt de berekende prijs.

## i18n-a11y-9 · middel · Bedragen en datums hardgecodeerd
**Bestanden:**
- `includes/woo/class-config.php:224, 237-240` (`money()`, `num()`)
- `includes/admin/class-settings-page.php:283, 300, 345`
- `includes/admin/class-order-production.php:239, 563, 677, 1111`
- `includes/woo/class-order.php:73`, `includes/woo/class-cleanup.php:197`
- `templates/emails/quote-*.php` (`wp_date('d-m-Y')`), `templates/admin/fiche-print.php:127`

**Probleem:** `money()` geeft altijd `€ 1.234,56` / `1.234,56 €`:
- De WooCommerce-instellingen worden genegeerd: munt, symboolpositie, scheidingstekens, decimalen. Een shop in CHF of met 0 decimalen krijgt mails die niet overeenkomen met de kassa.
- fr-BE gebruikt volgens CLDR een (smalle) spatie als duizendtal-scheiding.
- Datums staan overal vast als `d-m-Y` + "om/à HH:MM", los van `date_format` en de taal (en_US verwacht bv. "November 17, 2026").

**Voorstel:**
- Formatteer bedragen met `wc_price()` (HTML) of `html_entity_decode( wp_strip_all_tags( wc_price( $x ) ) )` (plain/attributen), na `switch_to_locale`.
- Gebruik `number_format_i18n()` voor aantallen en steken.
- Datums: `wp_date( get_option( 'date_format' ) )` of een vertaalbare formaatstring (`/* translators: date format */ __( 'j F Y', … )`).

**Acceptatie:**
- Met Woo-munt USD, symbool rechts en 0 decimalen toont de bewaarmail exact dezelfde string als `wc_price()` in de winkelmand.
- De fr-mail toont "17 novembre 2026" of het `date_format` van de site.
- Er staat geen `number_format(` meer in templates/ en includes/ buiten één centrale helper.

## i18n-a11y-10 · middel · Geen i18n-kwaliteitspoort
**Bestanden:** `tests/run-all.sh`, `scripts/make-i18n.sh`, `languages/`

**Probleem:** POT en fr_BE kloppen vandaag (zelf geverifieerd), maar niets bewaakt dat. Andere teams voegen nu tegelijk code toe (`includes/ai/*`, `includes/services/*`). `make-i18n.sh` hangt af van een `wp` op het PATH die in CI ontbreekt. `make-pot` geeft al 7 waarschuwingen over tegenstrijdige translators-commentaren (bv. "Hallo %s," met "first name" en met "customer first name"). Eén nieuwe string zonder vertaling, of een `%s` die in de FR-vertaling verdwijnt, komt zo ongemerkt bij de klant.

**Voorstel:** voeg `tests/i18n/check.sh` (of `.mjs`) toe aan `run-all.sh`. Die:
1. draait `wp i18n make-pot` naar een temp-bestand en faalt bij een verschil in msgids met de ingecheckte .pot;
2. faalt bij onvertaalde of fuzzy strings in elke .po;
3. controleert placeholder- en HTML-tag-pariteit per vertaling;
4. faalt bij `make-pot`-waarschuwingen;
5. controleert dat .mo/.l10n.php nieuwer zijn dan .po.

Gebruik het WP-CLI-phar dat `tests/e2e/start-wp.sh` al downloadt (met `--allow-root`). Voeg de WPCS-sniff `WordPress.WP.I18n` toe aan de PHPCS-run.

**Acceptatie:**
- `bash tests/run-all.sh` faalt als je één `__( 'Testzin', 'borduur-patch-studio' )` toevoegt zonder `make-i18n.sh` te draaien, of als je in de .po een `%s` weghaalt.
- Op de huidige code is hij groen nadat de 7 commentaarconflicten opgelost zijn.

## i18n-a11y-11 · laag-middel · RTL-onveilig
**Bestanden:**
- `templates/emails/*.php` en `templates/admin/emails/proof*.php` (`text-align:left`, `padding-left:20px`, `margin-right:6px`, `border-left:4px`)
- `templates/admin/proof-response.php:68-91` (inline-CSS)
- `includes/admin/assets/settings.css` (10 fysieke left/right-declaraties: r.25, 29, 54, 56, 58, 72, 73, 91, 92, 111; 0 logische)
- geen `wp_style_add_data( …, 'rtl', 'replace' )`

**Probleem:** WooCommerce zet in mails `dir="rtl"` en gebruikt `$text_align = is_rtl() ? 'right' : 'left'`. Onze tabellen en notities blijven links uitgelijnd. Een admin of klant in ar/he/fa (of een RTL-thema) ziet:
- gespiegelde lijsten met de inspringing aan de verkeerde kant,
- `€`-prefix-velden met de rand aan de verkeerde kant,
- de randaccent van de quickstart links.

Voor de huidige shop is dit laag, voor een verkoopbare plugin verwacht.

**Voorstel:**
- Mails: `$bps_align = is_rtl() ? 'right' : 'left'` en `$bps_start = is_rtl() ? 'right' : 'left'`, gebruikt in alle inline-styles.
- Proefpagina en admin-CSS: logische eigenschappen (`text-align:start`, `margin-inline-start`, `border-inline-start`, `inset-inline-start`).
- Of genereer `settings-rtl.css` met rtlcss en registreer die met `wp_style_add_data`.
- Zet `dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>"` op de standalone proefpagina.

**Acceptatie:**
- `grep -E "text-align: ?left|padding-left|margin-left|border-left|margin-right|left:" templates includes/admin/assets` geeft 0 hits buiten een expliciete `$bps_align`-variabele.
- Een screenshot van de instellingenpagina met gebruikerstaal `he_IL` laat geen overlappende of verkeerd uitgelijnde velden zien.

## i18n-a11y-12 · laag-middel · Order-meta bevriest de taal van de checkout
**Bestanden:** `includes/woo/class-order.php:42-90, 127-148`, `includes/woo/class-cart.php` (`finish_text`, `texts_text`)

**Probleem:** de sleutels worden bij weergave vertaald (`display_key`, goed). De **waarden** (`bps_technique`, `bps_shape`, `bps_finish`, `bps_texts`, `bps_rights` "Bevestigd op … om …") worden bij de checkout als tekst in de klanttaal opgeslagen. Gevolgen:
- Een NL-admin of atelier ziet in de orderdetails en in heruitgestuurde Woo-shopmails "Brodé · Rond · Bordure merrow".
- Een Franse klant die later in een NL-sessie inlogt, ziet zijn order in het Frans. Bij WPML-orders klopt de taal van "Mijn account" niet.

**Voorstel:**
- Render de waarden bij weergave opnieuw met `woocommerce_order_item_display_meta_value`, uit de stabiele ID's die al opgeslagen zijn (`_bps_technique_id`, `_bps_shape_id`, `_bps_options`, `_bps_rights_consent.at`), in de taal van de kijker of van de mailontvanger.
- Hou de opgeslagen tekst als fallback voor oude orders.

**Acceptatie:** een order met `locale: fr-BE` geplaatst. Op het orderscherm met admin-taal nl_BE staat "Techniek: Geborduurd". In de klant-processing-mail (fr) staat "Technique : Brodé".

## i18n-a11y-13 · laag · Franse terminologie inconsistent
**Bestanden:** `languages/borduur-patch-studio-fr_BE.po` (63x "dessin", 4x "design"), `includes/admin/class-order-production.php` (`FR`: "design"), ter vergelijking `assets/src/**/messages.js` (91x "design", 1x "dessin")

**Probleem:** de klant ontwerpt in de studio een "design" en krijgt in de winkelmand, de bewaarmail en de offertebevestiging "votre dessin". Ook binnen de PHP-kant staan beide vormen. Voor een premiumproduct is één woordenlijst een basisvereiste: Belgisch-Frans gebruikt in de borduurbranche vooral "design"/"motif".

**Voorstel:** leg in `docs/i18n-glossary.md` een woordenlijst NL→FR vast (ontwerp=design, patch=écusson, proef=épreuve (numérique), klittenband=velcro/scratch, borduurkaart=carte de broderie, garen=fil). Vervang in de .po alle "dessin(s)" waar "ontwerp" bedoeld is. Zie handoff voor de JS-kant.

**Acceptatie:**
- `grep -ci "dessin" languages/*fr_BE.po` = 0 (of alleen waar "tekening" bedoeld is, met een translators-commentaar).
- Een test vergelijkt de 10 woordenlijsttermen tussen de .po en `messages.js`.

## i18n-a11y-14 · laag · Proefpagina binnen het thema: taal en landmark
**Bestanden:** `templates/admin/proof-response.php:44-57, 93`, `includes/admin/class-order-production.php:1791`

**Probleem:** bij klassieke thema's (standaard `use_theme = true`) komt `<html lang>` van het thema, dus de taal van de site, terwijl de inhoud in de taal van de klant staat. `<main class="bps-proofpage">` heeft geen eigen `lang`/`dir`, dus een screenreader leest Frans met een Nederlandse stem (WCAG 3.1.2 Language of Parts). De meeste klassieke thema's (Astra, Hello, Storefront) openen zelf al `<main>`, wat een tweede `main`-landmark oplevert. De `alt` van de proefafbeelding in mail en pagina is generiek ("Digitale proef van je patch") en noemt de referentie niet.

**Voorstel:**
- `<main>` → `<div class="bps-proofpage" role="region" aria-labelledby="bps-proof-title" lang="fr-BE|nl-BE" dir="…">` wanneer `$use_theme`, en `<main lang dir>` alleen in de standalone-variant.
- Alt-tekst: "Digitale proef van patch %s" met de referentie.

**Acceptatie:**
- axe op de proefpagina met een klassiek thema (bv. Storefront in de testomgeving) geeft 0 `landmark-no-duplicate-main`.
- Het element met de inhoud heeft `lang="fr-BE"` voor een Frans ontwerp op een NL-site (Playwright-assert).

## i18n-a11y-15 · laag · Admin-WCAG-afwerking
**Bestanden:** `includes/admin/class-settings-page.php:153, 169-176`, `includes/admin/assets/settings.css:117, 132-133`, alle `target="_blank"` (10x in includes/templates, 0x met een melding)

**Probleem:** het admin-scherm is goed (axe: 1 moderate op 5 tabs), maar mist nog afwerking op premiumniveau:
- `<header class="bps-admin__head">` binnen `#wpbody-content` wordt een geneste banner-landmark (`landmark-banner-is-top-level`).
- 10 links openen een nieuw tabblad zonder dat te melden (WCAG 3.2.5-advies, G201).
- `.button-primary` wordt vast blauw gezet en negeert het kleurenschema dat de gebruiker in zijn profiel koos (ook de schema's met hoog contrast).
- De geselecteerde garenkleur valt alleen op door `border-color`. In Windows-modus "Hoog contrast" (`forced-colors`) is er dan geen zichtbaar verschil tussen aan en uit.

**Voorstel:**
- `header` → `div`.
- Voeg bij `_blank`-links `<span class="screen-reader-text"> (opent in een nieuw tabblad)</span>` toe, vertaalbaar, plus een icoon.
- Laat de primary-knop over aan het admin-kleurenschema, of gebruik `var(--wp-admin-theme-color)`.
- Voeg `@media (forced-colors: active)` toe met `outline: 3px solid Highlight` op de aangevinkte swatch en `forced-color-adjust: none` op de kleurchip.

**Acceptatie:**
- axe (alle impacts, inclusief best-practice) op de 5 tabs geeft 0 violations.
- Elke `target="_blank"` in includes/templates heeft een screen-reader-text-melding (grep-test).
- Een Playwright-screenshot met `forcedColors: 'active'` toont het verschil tussen aan- en uitgevinkte swatches.
