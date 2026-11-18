# P03 privacy-retention: wat er gedaan is (18-11-2026)

**Gewijzigd:** `includes/woo/class-privacy.php`, `includes/woo/class-cleanup.php`, `includes/nametape/class-order-tools.php`, `includes/nametape/class-rest.php`, `includes/nametape/class-shortcode.php`, `includes/nametape/class-settings-schema.php`, `assets/src/nametape/{ui,messages,state}.js`, `includes/ai/class-privacy.php`, `includes/ai/class-cleanup.php`, `includes/ai/class-store.php`, `includes/ai/class-store-wpdb.php`, `includes/ai/class-rest.php`, `includes/ai/class-identity.php`, `includes/services/class-rest.php`, `includes/services/class-withdrawal.php`, `config/ai-legal.json`, `tests/ai/backend/{fakes,run-backend-php}.php`.

**Nieuw:** `includes/woo/class-order-lifecycle.php`, `includes/woo/class-user-lifecycle.php`, `includes/nametape/class-privacy.php`, `tests/privacy/run-privacy-php.php`, `tests/privacy/php/wp-stubs.php`, `tests/privacy/privacy-php.test.js` (bridge), `tests/e2e/19-privacy.e2e.cjs`, `tests/premium/P03 privacy-retention/wp-smoke.php`.

**Handoff:** blok `## [P03] …` toegevoegd aan `docs/handoff/premium-ui-needs.md`.

## Testresultaten
- `php tests/privacy/run-privacy-php.php`: **491/491 ok**, verdeeld over 20 scenario's. De bridge `tests/privacy/privacy-php.test.js` draait mee in `npm test` en eist minstens 40 checks. Daarnaast controleert hij statisch dat de NL- en FR-tekst van het bloedgroepvakje in de UI gelijk is aan de servertekst.
- **Mutatietest:** haal ik de quote-bucket uit `Cleanup::run()`, dan faalt de runner (`gdpr-2 … FAIL`, 466/467). Na het herstel is alles weer groen.
- `php tests/ai/backend/run-backend-php.php`: **45 geslaagd, 0 mislukt**. Daarvan zijn er 3 nieuw: gdpr-7, gdpr-11 en gdpr-12.
- Ongewijzigd groen: `run-services-php.php` 472/472, `nametape/run-php.php` 102 ok, `security/run-security-php.php` 427/427, `npm run test:php` (pricing) 652 checks, 0 failed, en `node --test tests/nametape/*.test.js` 100/100.
- `npm test`: 431 tests, 424 pass, 3 TODO (accuracy, bekend) en 4 fails buiten dit pakket:
  - `tests/crosssell/wp.test.js`: `Logger` ontbreekt in de stubs van crosssell;
  - `tests/demo/demo.browser.test.js` ×3: de klant-UI wordt herbouwd door het andere team.
- `npm run build`: de naamstrookbundel is 28,8 kB gzip (budget 30 kB). De hoofdbundel zit boven budget, maar die is van het UI-team.
- **Echte WP + Woo (SQLite, eigen testsite op poort 8913):**
  - `tests/e2e/19-privacy.e2e.cjs`: **21/21 PASS**. Het scenario draait de smoke-test `wp-smoke.php` (19/19) met de echte SQL voor naamstrookkandidaten en AI-eigenaars, WC-statushooks, `wp_delete_user` en de date_query op offertes;
  - e2e 03, 04, 05, 06 en 08 blijven PASS;
  - met curl (AI-module aan, live): `GET /ai/status` zonder cookies geeft 200 **zonder** `Set-Cookie`, met 0 eigenaarsrijen en `balance.free = 1`. `POST /services/dismiss` geeft 200 zonder cookie. `POST /ai/generate` geeft 202 **met** `Set-Cookie: bps_ai_sid` en maakt 1 eigenaarsrij aan;
  - Playwright op een naamstrookpagina: met een bloedgroep verschijnt het vakje, niet aangevinkt, met de servertekst. De knop blijft geblokkeerd met "Vink aan dat je toestemming hebt voor de bloedgroepen." tot je het aanvinkt;
  - noscript en `helpLine` komen uit `Help_Line`.
- `php -l` is schoon op elk gewijzigd of nieuw PHP-bestand. De WPCS-sniffs `WordPress.Security.*`, `DB.PreparedSQL*` en `WP.I18n` geven 0 meldingen op de bestanden van dit pakket.

## Per bevinding

### php-kwaliteit-9 · export/erase zonder plafond, eraser gepagineerd
- `design_ids()` en `quote_ids()` hebben geen plafond meer en zijn gesorteerd op ID ASC:
  - ontwerpen komen uit `Designs::query(…, -1)`, uit de ontwerpen van het account (post_author) en uit alle bestellingen. Die bestellingen worden per 100 opgehaald, op `billing_email` én op `customer_id` van de gebruiker met dat e-mailadres;
  - order-items die naar een niet-bestaand ontwerp wijzen, vallen weg. Zonder die filter kon de eraser eindeloos blijven lussen.
- De export verwerkt per pagina `PAGE` (50) items, plus de proefitems per bestelling zodra P05 er is.
- De eraser maakt bij elke aanroep een werklijst van wat nog gewist moet worden. Gewiste items vallen eruit (`_bps_erased_gmt`, of geanonimiseerd met author 0). Hij verwerkt de eerste `PAGE` items en geeft `done = count(slice) < PAGE`.
  - Dat is stabiel, ook al krimpt de lijst bij elke aanroep. Een offset-paginering zou bij een krimpende lijst items overslaan.
  - De meldingen over items die bewaard blijven, staan bij de laatste aanroep.
- **Test:** 250 bestellingen en 520 offertes op één e-mailadres.
  - De export geeft alle 250 ontwerpen en 520 offertes, verdeeld over 16 pagina's en zonder dubbels.
  - De eraser geeft `done=false` tot de laatste pagina (≥ 16 aanroepen). Daarna zijn er 0 ontwerpen en 0 offertes met dat e-mailadres, en zijn alle 520 offertes geanonimiseerd.
  - Een tweede wisronde doet niets meer, en een nieuwe export bevat het e-mailadres niet meer.

### security-9 (c) · eraser wist de klanttekst op het ontwerp
- `Privacy::erase_design()`, bij een afgeronde bestelling:
  - eerst `Cleanup::expire_ordered( $id, false )`: origineel en voorbeeld weg, plus de scrub van gdpr-6;
  - `payload.texts[].text` wordt `[gewist]` als de tekst niet leeg was, en `payload.notes` wordt `[gewist]`;
  - `_bps_title` en `_bps_reminder` gaan weg, post_author wordt 0 en `_bps_erased_gmt` wordt gezet.
- De meta van het order-item blijft voor de anonimisering van WooCommerce zelf.
- **Test:** na het wissen bevat `_bps_payload` "Jan Peeters" en "0475" niet meer, staat er `[gewist]` en blijft een lege tekst leeg.
- (a) en (b), de uninstall, zijn van P02.

### gdpr-juridisch-2 · offertes na quoteDays geanonimiseerd
- `Cleanup::run()` heeft een nieuwe bucket `quotes` (de sleutel `$counts['quotes']`). Die neemt `bps_quote`-posts met `post_date_gmt < now - quoteDays` (date_query), zonder `_bps_anonymised_gmt` en zonder `_bps_order_id`, per BATCH.
- **Guard:** `Cleanup::anonymise_quote( $id )` roept `Quotes::anonymise( $id )` aan zodra P07 die levert (`is_callable`). Tot dan doet de fallback hetzelfde als de oude inline-code: de `CONTACT_META` weg en `_bps_anonymised_gmt` gezet.
- `Privacy::erase` gebruikt dezelfde methode en zet daarnaast post_author op 0.
- **Test:**
  - een offerte van 400 dagen mist daarna alle 6 contactvelden en heeft `_bps_anonymised_gmt`;
  - een offerte van gisteren en een offerte met `_bps_order_id` blijven onveranderd;
  - een tweede run vindt niets meer, en `quoteDays` is instelbaar;
  - een statische check bevestigt dat de eraser geen eigen kopie van de anonimisering meer heeft.
- **Voor P08, het beheerlabel in tab Algemeen:** "Offerteaanvragen (contactgegevens en bestanden)". Helptekst: "Na zoveel dagen wissen we de naam, het e-mailadres, het telefoonnummer, het bedrijf, het btw-nummer en het bericht van een offerteaanvraag zonder bestelling, samen met het ontwerp." FR: « Demandes de devis (coordonnées et fichiers) » / « Passé ce délai, nous effaçons le nom, l’adresse e-mail, le téléphone, la société, le numéro de TVA et le message d’une demande de devis sans commande, ainsi que le design. »

### klant-account-8 · accounttermijn en klein voorbeeld na afloop
- `Cleanup::settings()` leest `retention.*` met fallbacks: `accountSavedDays` 730 en `keepPreviewOnExpiry` true. P08 zet de sleutels in defaults.json en maakt er velden voor.
- Bewaarde ontwerpen:
  - gast (`author__in [0]`) na `savedDays`;
  - account (`author__not_in [0]`) na `accountSavedDays`;
  - drafts blijven op `orphanDays`.
- `expire_ordered()` met keepPreview: `Cleanup::small_preview()` maakt met GD een **PNG** van maximaal 300 px en maximaal 30 kB in `uploads/patch-studio/previews/`.
  - Het formaat is PNG omdat die map via .htaccess alleen PNG serveert. Is truecolor te groot, dan gebruikt het een palet (128 of 48 kleuren) en daarna 220, 160 en 120 px.
  - Daarna wordt het grote voorbeeld gewist, verwijst `_bps_preview` naar het kleine en krijgt `_bps_preview_url` op het order-item de nieuwe URL in plaats van ''.
  - Een aparte helper van P06 is daarvoor niet nodig. Komen er webp-varianten (C3), dan moet `Designs::delete_files()` die mee wissen.
- **Test:**
  - gast-saved van 91 dagen weg; account-saved van 91 dagen behouden, van 731 dagen weg, samen met de bestanden;
  - besteld met `ordered` 4 jaar oud, met een testbeeld van meer dan 30 kB: origineel en groot voorbeeld weg, en een klein voorbeeld van maximaal 300 px PNG en maximaal 30 kB;
  - de orderregel verwijst naar het kleine voorbeeld, en `Uploads::preview_path_from_url()` herkent het, dus de URL wordt geserveerd (200);
  - met `keepPreviewOnExpiry=false` is er geen voorbeeld meer;
  - de beleidstekst toont de ingestelde termijnen (20, 45, 500 en 200 dagen, 4 jaar).

### klant-account-12 · accountkoppeling en verwijderen van een gebruiker
- Export en erase vullen de lijst aan met post_author = de gebruiker met dat e-mailadres, voor ontwerpen én offertes.
- De export bevat extra velden: "Naam van het ontwerp" (`_bps_title`), "Herinnering" (`_bps_reminder`, scalar of array), "Bestelling" en "Proefgeschiedenis".
- **Proefgeschiedenis via `Proof_Service::export_for_order()` (C6, P05):** als de klasse bestaat, wordt ze per bestelling aangeroepen. Ze mag kant-en-klare exportitems (`group_id`/`data`) teruggeven, of rijen `{name, value}`. In het laatste geval wordt dat de groep "Patch Studio-proeven". Tot dan komt de proefgeschiedenis uit `_bps_proof.history` (datum · actie · ronde · opmerking).
- `User_Lifecycle` hookt `delete_user` en `wpmu_delete_user`, met prioriteit 5, vóór WordPress de posts herverdeelt:
  - niet-bestelde ontwerpen gaan weg via `wp_delete_post`, met hun bestanden;
  - ontwerpen met status ordered of quoted, of met een order-id of offerte-id, krijgen author 0, en `_bps_email`, `_bps_email_sent_to`, `_bps_title` en `_bps_reminder` worden verwijderd;
  - offertes van het account krijgen author 0.
- **Test:**
  - 2 bewaarde ontwerpen zonder `_bps_email` staan in de export, met titel, herinnering en proefnotitie;
  - na `wp_delete_user(7)` zijn er 0 ontwerpen met author 7 en zijn de bewaarde ontwerpen en hun bestanden weg;
  - het bestelde ontwerp bestaat nog, met author 0 en zonder e-mail, titel of herinnering, en het order-item verwijst er nog naar;
  - dezelfde flow is ook getest op de echte WP.

### gdpr-juridisch-6 · expire_ordered ruimt de persoonsgegevens op
- `Cleanup::scrub_personal_data()` doet het volgende, ook bij de cron:
  - `_bps_email`, `_bps_email_sent_to` en `_bps_page_url` gaan weg;
  - `payload.notes` wordt '' en elke `_bps_proof.history[*].note` wordt '';
  - de status en rondes van de proef blijven, net als de patchtekst (het productiedossier). Pas bij een wisverzoek wordt die `[gewist]`.
- Het mediabibliotheekdeel van de bevinding is bewust weggelaten.
- **Test:** alle velden zijn leeg, de proefstatus blijft `approved`.

### gdpr-juridisch-5 · `class-order-lifecycle.php`
- Bij `woocommerce_order_status_cancelled` en `_failed`:
  - alleen ontwerpen met status `ordered` waarvan `_bps_order_id` gelijk is aan déze bestelling, gaan naar `carted`;
  - daarbij worden `_bps_created_gmt` en `_bps_status_changed_gmt` op nu gezet en `_bps_order_id`, `_bps_order_item_id` en `_bps_ordered_gmt` gewist;
  - het schrijven gebeurt rechtstreeks in de meta, want `set_status` gaat nooit omlaag;
  - wordt de bestelling later toch betaald, dan zet `mark_ordered_by_id` het ontwerp weer op besteld.
- `Order_Lifecycle::is_finished()` is waar voor de statussen completed, cancelled, refunded en failed, en voor pending ouder dan 14 dagen (filter `bps_pending_order_days`). Deze methode wordt gebruikt door de Woo-eraser en door de naamstrook-eraser en -cron.
- Bij `woocommerce_privacy_remove_order_personal_data` en `woocommerce_before_delete_order` wordt `Cleanup::expire_ordered()` aangeroepen en `_bps_email` gewist. Een ontwerp dat intussen bij een andere bestelling hoort, blijft ongemoeid.
- **Test:**
  - na failed staat het ontwerp op carted met created op nu; met de klok +31 dagen verwijdert `Cleanup::run()` het met zijn bestand;
  - het ontwerp van een andere bestelling blijft `ordered`;
  - de eraser bij een pending bestelling van 20 dagen geeft `items_removed:true` en wist het origineel, terwijl een pending bestelling van 3 dagen en een on-hold-bestelling bewaard blijven;
  - na de WC-anonimisering en na het verwijderen van de bestelling is het origineel weg;
  - op de echte WP gaat `$order->update_status('failed')` naar carted.

### gdpr-juridisch-1 · naamstroken
- **`Nametape\Privacy`** wordt geregistreerd via `Module::register_always()`, dus ook als de module uit staat:
  - een exporter op het factuuradres, gepagineerd per 20 bestellingen. Per naamstrookregel komt er één item met naam / regel 2 / bloedgroep (aantal), organisatie, rechtenverklaring, bloedgroeptoestemming en de wisdatum;
  - een eraser: bij afgeronde bestellingen wordt de regel geanonimiseerd, bij lopende bestellingen komt er `items_retained` met een melding per bestelling.
- **Anonimiseren:** `n` wordt `[gewist]`, `l2` wordt `[gewist]` als die ingevuld was, `b` wordt '', en `org_note`, `review_words`, `preview_url` en `preview_file` worden leeg. De PNG wordt verwijderd, `bps_nt_blood`, `bps_nt_names` en `_bps_nt_preview` gaan weg en `_bps_nt_anonymised` wordt gezet (verborgen in het beheer). De aantallen blijven.
- **Termijn:** `nametape.retention.daysAfterComplete`, standaard 180 en begrensd op 30 tot 3650 in `Settings_Schema`. Het veld wordt alleen aangepast als het formulier het meestuurt.
- **Cron:** `bps_nt_cleanup` → `Order_Tools::cleanup()` roept eerst `Privacy::run_retention()` aan, daarna de oude opruiming van PNG's zonder bestelling.
  - De kandidaten komen uit één SQL-query op `woocommerce_order_items`/`itemmeta`, met `_bps_nt_batch` en zonder `_bps_nt_anonymised`. Die werkt met HPOS en met CPT.
  - De pre-filter `bps_nt_retention_candidates` is er voor andere opslag en voor tests.
  - Een regel wordt alleen gewist als `is_finished` waar is en de voltooiingsdatum (anders de wijzigingsdatum) ouder is dan de termijn.
- **Map:** `Privacy::protect_dir()` schrijft een `.htaccess` (`Options -Indexes -ExecCGI`, alleen PNG, `X-Robots-Tag: noindex, nofollow`, nosniff) en een `index.php`. Dat gebeurt in `Rest::preview_dir()` en bij de cron.
- **Toestemming:**
  - in `assets/src/nametape` staat een apart vakje, alleen als er een bloedgroep in de lijst staat. Het is niet vooraf aangevinkt, wordt niet bewaard in sessionStorage, staat in NL en FR en heeft een hint over het medische gegeven. Zonder vakje blokkeert de knop;
  - de exacte zin komt van de server (`cfg.privacy.bloodConsent`), met messages.js als terugval;
  - de REST-route `/nametape/cart` geeft 400 `bps_blood_consent_required` (field `bloodConsent`) bij een bloedgroep zonder `bloodConsent.accepted`;
  - met het vakje zet de route `$check['batch']['bloodConsent'] = {v, at, text_sha1, locale}`. Die waarden komen van de server en nooit van de client.
- **Test (stubs):**
  - "Jan Peeters O+": de exporter geeft 1 item met de naam en "O+";
  - na de eraser bij een completed bestelling bevat de batch geen naam, bloedgroep, organisatie of regel 2 meer, ontbreekt `bps_nt_blood` en is de PNG weg;
  - bij een lopende bestelling volgt `items_retained`; met 25 bestellingen loopt het wissen over 2 pagina's;
  - de cron wist na 181 dagen, terwijl een bestelling van 10 dagen en een lopende bestelling blijven; de termijn is instelbaar (5);
  - `.htaccess` bevat noindex en -Indexes;
  - de REST-route geeft 400 zonder vakje en bij 3 ongeldige varianten, maar niet met het vakje of zonder bloedgroep; versie en sha1 van het record komen van de server.
- **Test (echte WP):** SQL-kandidaten, exporter, cron, PNG en `.htaccess`.

### productisering-4 (naamstrookdeel)
- In de noscript van `Nametape\Shortcode` staat nu "Deze tool heeft JavaScript nodig." plus `Help_Line::text($locale)`, met een neutrale terugval.
- `cfg.helpLine` gaat naar de JS. `nt.error.generic` bevat geen "bel of WhatsApp" meer, en `ui.js` plakt `helpLine` erachter.
- **Test:** zonder WhatsApp staat er nergens in de shortcode of in messages.js een vaste "WhatsApp"; de terugval werkt.
- Op de echte WP levert de shop met zijn eigen contactgegevens "Bel of WhatsApp ons gerust op 0491 …".
- De handoff voor de studio-UI staat in premium-ui-needs.md.

### gdpr-juridisch-7 · AI-eigenaarsrijen verlopen
- `Store::owner_purge( known_before, anon_before, now, limit )`, in Store_Wpdb en in Memory_Store, raakt alleen rijen na hun termijn zonder open job (queued/running) en zonder lot met saldo (active, niet vervallen, used < total):
  - verwijst er nog een job, lot of ledger-rij naar de rij, dan blijft de sleutel en worden `email` en `email_verified_at` NULL;
  - anders wordt de rij verwijderd.
- `AI\Cleanup::run()` roept de methode aan na `expire()`, met de tellers `owners` en `ownerEmails`.
- In `ai-legal.json` staan `retentionDays.ownerRows` 365 en `anonymousOwnerRows` 30, met uitleg in `_ownerRows`.
- **Test (backend-runner):**
  - een eigenaar met e-mail, 400 dagen oud en zonder lots, is weg;
  - een eigenaar met een lot met saldo blijft met zijn e-mail;
  - een eigenaar met alleen ledgerrijen blijft met e-mail NULL, en een vervallen lot geeft dezelfde uitkomst;
  - een anonieme rij van 31 dagen is weg, een van 10 dagen blijft;
  - een eigenaar met een open job blijft ongemoeid;
  - `ownerRows=90` is instelbaar.
- De SQL is ook getest op de echte WP (SQLite).
- De beheervelden voor deze twee termijnen staan nog niet in de tab "Bewaren", want `includes/ai/class-admin.php` (`RETENTION_FIELDS`) valt buiten dit pakket. Het voorstel is `'ownerRows' => ['AI-gebruikersprofielen (na laatste bezoek)', 90, 1095]` en `'anonymousOwnerRows' => ['Anonieme AI-profielen', 7, 90]`.

### gdpr-juridisch-11 · geen cookie bij lezen of wegklikken
- `Rest::status()` gebruikt `current(false)` en roept `touch()` alleen aan als er al een eigenaar is.
  - Zonder eigenaar komen `firstUse: true` en de gratis anonieme ronde mee in `balance`.
  - Omdat de status geen eigenaars meer aanmaakt, kent `Rest::generate()` bij een anonieme eigenaar nu zelf de gratis anonieme ronde toe (`grant_free_anon`). Zonder die stap zou een nieuwe bezoeker 402 krijgen.
- `Services\Rest::store_dismissed()` gebruikt `owner(false)` en de **bestaande** WC-sessie (nooit een nieuwe). `dismissed_list()` voegt de transient en de sessie samen. Zonder beide wordt er niets op de server bewaard (zie de handoffregel).
- **Test:** backend-runner en curl op de echte WP, zie boven.

### gdpr-juridisch-12 · beleidsteksten
- De Woo-tekst (`Privacy::policy_html()`) staat in de je-vorm en haalt alle termijnen uit de instellingen: orphan, saved, account, offerte (contactgegevens en bestanden), orderjaren en het kleine voorbeeld.
  - Er is een blok "Naamstroken": namen van dragers, optionele bloedgroep als gezondheidsgegeven met toestemming en bewijs, de verantwoordelijkheid van de besteller, en de termijn uit de naamstrookinstelling.
  - Er is een blok over de AI-ontwerpstudio en de doorgifte buiten de EU: OpenAI en Recraft, de Verenigde Staten, EU-VS Data Privacy Framework/SCC.
- De AI-tekst staat in de je-vorm (FR in de vous-vorm, zoals ai-legal.json voorschrijft).
  - Alle termijnen komen uit `AI_Settings::retention()`, ook `ownerRows` en `anonymousOwnerRows`. De cookieduur komt uit `Identity::COOKIE_TTL` en de IP-termijn uit `Cleanup::IP_HASH_DAYS`.
  - De tekst noemt Recraft, en vermeldt dat de cookie er pas bij de eerste AI-actie komt.
  - `policy_html($locale)` zet de taal van de site eerst (bij fr_BE staat het Frans bovenaan). De FR-tekst stond al inline in de code.
- **Test:** geen " u " of " uw "; met `guestPromptsAndProposals=14` staat "14 dagen" (en "14 jours") in de AI-tekst; de tekst bevat bloedgroep, Verenigde Staten, OpenAI, Recraft en Data Privacy Framework; bij fr_BE staat het Frans eerst.
- **P11:** de nieuwe NL-strings in `__()` zijn vertaalklaar, met translators-commentaar bij elke sprintf.

### php-kwaliteit-5 (regel 340 van class-withdrawal.php)
- `REQUEST_METHOD` gaat door `wp_unslash` en `sanitize_text_field`, en daarna `strtoupper`. De NonceVerification-sniff krijgt een gerichte ignore, want het gaat alleen om een aanwezigheidscheck en de nonce wordt daarna gecontroleerd.
- Catch-blokken in de bestanden van dit pakket loggen via `Logger::error` (C1). AI\Cleanup houdt daarnaast `Module::log`.

### gdpr-juridisch-15 · tests
- De runner `tests/privacy/run-privacy-php.php` met eigen WP/Woo-stubs:
  - posts en meta met `wp_unslash`, een WP_Query met geneste meta_query, author__in/not_in en date_query;
  - WC_Order en items, `wc_get_orders` met paginering, users en `wp_delete_user`;
  - doubles voor Logger, Settings en Help_Line;
  - elke test eist 0 Logger-fouten.
- De bridge `privacy-php.test.js` volgt de conventie, zodat P12 hem oppikt via `run-*-php.php` en `npm test`.
- De AI-backendtest is uitgebreid voor gdpr-7, -11 en -12. E2e 19 is het optionele scenario.

## Voor andere pakketten
- **P04 (Nametape\Cart):** `data_from_batch()` neemt `batch['bloodConsent']` al over (gecontroleerd in `class-cart.php` r. 121). Het record komt dus in `_bps_nt_batch` op het order-item terecht, en de exporter toont het.
- **P07:** `Quotes::anonymise( int $id )` wordt automatisch gebruikt (guard in `Cleanup::anonymise_quote`). Laat de methode minstens `Quotes::CONTACT_META` wissen. Het zetten van `_bps_anonymised_gmt` mag, Cleanup vult het anders aan. Koppel je een offerte aan een bestelling, zet dan `_bps_order_id` op de offertepost: zo'n offerte slaat de cron over.
- **P08:** defaults `retention.accountSavedDays` (730) en `retention.keepPreviewOnExpiry` (true), met velden en LIMITS. Het label en de helptekst voor de offertetermijn staan hierboven, bij gdpr-juridisch-2. Een veld voor `nametape.retention.daysAfterComplete` hoort in het naamstrookbeheer (`includes/nametape/class-admin.php` en views, buiten dit pakket): `name="bps_nt[retention][daysAfterComplete]"`, 30 tot 3650. Het schema verwerkt het al.
- **P05:** `Proof_Service::export_for_order( $order )` geeft een lijst van exportitems of van rijen `{name, value}` terug (inclusief `ip_hash` en `ua`). Bij een fout wordt gelogd en wordt de proef overgeslagen.
- **P06:** de meta-sleutels `_bps_title` en `_bps_reminder` worden geëxporteerd en gewist. Voeg je previewvarianten toe (`cart`, `cart2x`, `mail`), wis die dan in `Designs::delete_files()`. `expire_ordered` maakt zelf het kleine PNG-voorbeeld en zet dat in `_bps_preview`, met `small: true`.
- **P02:** de uninstall-delen (a) en (b) van security-9. `Order_Lifecycle` en `User_Lifecycle` worden geregistreerd vanuit `Woo\Privacy::register()` (component Woo\Integration). Het naamstrookdeel loopt via `Module::register_always()`, dat al op `Nametape\Privacy` wachtte.
- **P13:** e2e 19 heeft geen mu-plugin nodig. Het roept `wp eval-file "tests/premium/P03 privacy-retention/wp-smoke.php"` aan, en dat script ruimt zelf op.
- **P11 (voorstellen FR):**
  - "Vink aan dat je de toestemming hebt van de mensen op de lijst om hun bloedgroep te borduren, of haal de bloedgroepen weg." → « Cochez que vous avez l’accord des personnes de la liste pour broder leur groupe sanguin, ou retirez les groupes sanguins. »;
  - "De naamstroken van bestelling %s worden nog gemaakt of geleverd. …" → « Les bandes nominatives de la commande %s sont encore en production ou en livraison. Nous conservons les noms jusqu’à la fin de la commande, puis nous les effaçons. »;
  - de Woo-beleidsblokken (Naamstroken, AI en doorgifte).
- `Help_Line` geeft in het Frans de tu-vorm, terwijl de naamstrookteksten de vous-vorm gebruiken (zie de handoff).
