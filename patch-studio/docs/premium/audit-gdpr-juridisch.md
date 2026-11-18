# Audit "gdpr-juridisch": GDPR en Belgisch recht in de code (18-11-2026)

Scope: toestemming en uitleg bij upload, bewaartermijnen en automatische opruiming, WordPress-privacy-exporter en -eraser,
cookieloos werken, rechtenverklaring (auteursrecht) en de uitzondering op het herroepingsrecht (art. VI.53 WER).
Er is geen code gewijzigd. Wat de klant-UI nodig heeft, staat in `docs/handoff/premium-ui-needs.md`.

## Wat ik echt gedraaid heb
- `npm run test:php`: 652 checks, 0 fouten. `php tests/services/run-services-php.php`: 427/427 ok.
  `npm test`: 18 fouten, allemaal in de beeldanalyse (accuracy/perf), los van privacy.
- Lokale WP+Woo (`tests/e2e/start-wp.sh`), probes met `wp eval-file` (testdata daarna weer verwijderd):
  - Geregistreerde exporters/erasers: alleen `borduur-patch-studio`. **`borduur-patch-studio-ai` ontbreekt** en van de crons draait
    alleen `bps_cleanup`, omdat AI/Services/Nametape/Crosssell niet in `Plugin::COMPONENTS` staan. Met de filter `bps_components`
    (via `wp --require`) verschijnen ze wel.
  - Een naamstrookbestelling met "Jan Peeters O+": de Woo- en Patch Studio-exporter vinden niets, en na alle erasers staat
    `_bps_nt_batch` nog volledig in het order item.
  - Een offerte met de datum 3 jaar terug gezet, daarna `Cleanup::run()`: het ontwerp is weg, maar e-mail, telefoon en btw-nummer
    staan nog op de offerte.
  - Een bestelling met status `failed` zet het ontwerp toch op `ordered`, dus 3 jaar bewaren. Een ontwerp bij een `pending`-bestelling
    houdt de eraser bij ("loopt nog").
  - Een AI-eigenaarsrij met e-mail, `last_seen_at` 3 jaar terug gezet, daarna `AI\Cleanup::run()`: de rij en het e-mailadres blijven staan.

## Wat al goed is (behouden)
Analyse lokaal in de browser. Originelen staan privé (.htaccess/web.config + zelftest), previews worden opnieuw gecodeerd (EXIF weg).
Woo-exporter/eraser en een tekst voor de privacyverklaring bestaan. Er is een dagelijkse opruiming per status. Geen externe CDN's
of lettertypes. Analytics alleen na toestemming. Namen van naamstroken staan alleen in sessionStorage. De rechtenverklaring is
verplicht en nooit vooraf aangevinkt. De AI-pakketten hebben twee vakjes met versie en sha1 (art. VI.53, 13°). Diensten starten
pas na 14 dagen zonder vakje. IP-adressen worden alleen als HMAC bewaard en na 30 dagen gewist.

---

## Bevindingen

### gdpr-juridisch-1 · critical · Naamstroken: bloedgroep (gezondheidsgegeven) en namen van derden zonder export, wissen of termijn
**Bestanden:** `includes/nametape/class-cart.php` (r. 64-110, 680-700), `includes/nametape/class-order-tools.php` (cleanup),
`includes/nametape/class-rest.php` (PREVIEW_DIR), `assets/src/nametape/ui.js` + `messages.js`
**Probleem:** de bloedgroep is een gezondheidsgegeven (art. 9 AVG). Ze komt samen met de namen van werknemers of leden in
`_bps_nt_batch` en `bps_nt_blood` (order item meta), en in publieke PNG's onder `uploads/patch-studio/nametape/` (geen noindex,
link staat in mails). Er is geen exporter of eraser (getest: niets gevonden, na het wissen staat alles er nog) en geen bewaartermijn.
Previews die aan een bestelling hangen, blijven voor altijd staan. De klant krijgt geen uitleg dat hij toestemming van de betrokkenen
nodig heeft, en de toestemming voor de bloedgroep wordt niet expliciet vastgelegd.
**Voorstel:**
1. Een nieuwe `Nametape\Privacy`:
   - een exporter op het factuur-e-mailadres (rijen naam / regel 2 / bloedgroep per bestelling);
   - een eraser voor afgeronde of geannuleerde bestellingen: namen worden "[gewist]", de bloedgroep gaat weg, de preview-PNG wordt verwijderd.
2. Een termijn `nametape.retention.daysAfterComplete` (standaard 90). De cron anonimiseert daarna `_bps_nt_batch.rows[*].n/l2/b`
   en `bps_nt_blood` en verwijdert de preview.
3. Kiest de klant een bloedgroep, dan komt er een apart, niet vooraf aangevinkt vakje: "Ik heb van elke persoon op de lijst
   toestemming om zijn bloedgroep te laten borduren" / "J'ai l'accord de chaque personne de la liste pour faire broder son groupe
   sanguin". Bewaar het als `{v, at, text_sha1}`. De server weigert een bloedgroep zonder dat vakje.
4. Previews in de map zetten met `.htaccess` (`X-Robots-Tag: noindex`, `Options -Indexes`), of via een ondertekende REST-URL serveren.
**Acceptatie:** een test met een bestelling "Jan Peeters O+":
- de exporter geeft 1 item met "Jan Peeters" en "O+";
- na het wissen (bestelling completed) bevat `_bps_nt_batch` geen "Jan Peeters" en geen "O+", `bps_nt_blood` is leeg en de PNG bestaat niet meer;
- `POST /nametape/cart` met een bloedgroep zonder `bloodConsent` geeft 400;
- een cronrun na de termijn (datum terug gezet) anonimiseert de rijen.

### gdpr-juridisch-2 · high · Offertes: contactgegevens worden nooit gewist, de instelling "Offerteaanvragen X dagen" misleidt
**Bestanden:** `includes/woo/class-cleanup.php`, `includes/woo/class-quotes.php`, `includes/admin/views/tab-algemeen.php` (r. 157),
`includes/woo/class-privacy.php` (beleidstekst)
**Probleem:** `Cleanup::run()` verwijdert na `quoteDays` alleen het ontwerp. De `bps_quote`-post houdt naam, e-mail, telefoon,
bedrijf, btw-nummer en bericht voor altijd (getest met een offerte van 3 jaar oud). In het beheer heet de termijn "Offerteaanvragen",
dus de eigenaar denkt dat alles weg is. Dat strookt niet met de opslagbeperking (art. 5.1.e AVG).
**Voorstel:** een vijfde bucket in `Cleanup::run()`:
- offertes ouder dan `quoteDays` zonder gekoppelde bestelling: de `Quotes::CONTACT_META` gaan weg en `_bps_anonymised_gmt` wordt gezet
  (dezelfde routine als in de eraser, gedeeld in `Quotes::anonymise()`);
- het aantal komt in `$counts['quotes']`;
- het beheerlabel wordt "Offerteaanvragen (contactgegevens en bestanden)".

**Acceptatie:** na `Cleanup::run()` is een offerte met `post_date_gmt` ouder dan `quoteDays` zonder `_bps_email`, `_bps_phone`,
`_bps_vat`, `_bps_name` en `_bps_message`, en heeft ze `_bps_anonymised_gmt`. Een offerte van gisteren is onveranderd.

### gdpr-juridisch-3 · high · Privacy en opruiming van AI, diensten, naamstroken en cross-sell staan niet aan
**Bestanden:** `includes/class-plugin.php` (COMPONENTS), `includes/ai/class-module.php` (r. 38-70), `includes/services/class-module.php`,
`includes/nametape/class-module.php`
**Probleem:** in de testomgeving staan alleen de Woo-exporter en -eraser en de cron `bps_cleanup` geregistreerd. `AI\Privacy`,
`bps_ai_cleanup`, `bps_svc_daily` en `bps_nt_cleanup` hangen aan het laden van hun module. Wordt een module later weer uit de
componenten gehaald (of uitgezet), dan blijven de persoonsgegevens in `bps_ai_*`-tabellen en `uploads/patch-studio/ai*` staan.
Er is dan geen exporter, geen eraser en geen opruiming meer.
**Voorstel:**
1. Zet de modules in `Plugin::COMPONENTS` (dat staat al in de handoffs ai-services-needs #1 en extra-products-needs P1).
2. Een kleine, altijd geladen `Privacy_Registry` registreert exporters, erasers en crons voor elke datastore die bestaat
   (tabel `bps_ai_owners` aanwezig, of nametape-meta in orders), los van de vraag of de module aanstaat.

**Acceptatie:** `wp eval 'print_r(array_keys(apply_filters("wp_privacy_personal_data_erasers",[])));'` toont `borduur-patch-studio`,
`borduur-patch-studio-ai` en `borduur-patch-studio-nametape`. `wp cron event list` toont `bps_ai_cleanup`, `bps_nt_cleanup` en
`bps_svc_daily`. Met de AI-module uit de componenten gefilterd, maar de tabel nog aanwezig, staat de AI-eraser nog in de lijst.

### gdpr-juridisch-4 · high · Herroepingsmelding dekt niet alle maatwerk, en de toggle kan haar uitzetten
**Bestanden:** `includes/woo/class-cart.php` (r. 825-862 `has_design`/`checkout_notice`), `includes/woo/class-emails.php` (r. 132),
`includes/crosssell/class-cart.php` (`rows`, geen "Maatwerk"-regel), `includes/nametape/class-cart.php`,
`includes/admin/views/tab-algemeen.php` (r. 141-146), `config/ai-services.json` (`d6_sample`, `d3_file`)
**Probleem:** de uitzondering van art. VI.53, 3° WER geldt alleen als de klant vooraf geïnformeerd is (art. VI.45 §1, 11°) en de
bevestiging op een duurzame drager staat (VI.46 §7). Wat ontbreekt:
- melding D in de kassa en het mailblok verschijnen alleen bij `bps`-regels. Een mand met alleen naamstroken, alleen cross-sell
  (patch-regel uit de mand gehaald) of een fysiek staal krijgt geen melding in de kassa;
- cross-sell-regels tonen geen "Maatwerk"-regel;
- de toggle `legal.showWithdrawalNotice` laat de eigenaar de melding in de studio uitzetten;
- `d3_file` (borduurbestand, digitale inhoud) gebruikt de tekst voor diensten in plaats van de formule van art. VI.53, 13°.

**Voorstel:**
1. Een centrale `Legal::cart_has_custom()` en `Legal::order_has_custom()` die `bps`, `bps_nt`, `bps_cs` en maatwerkdiensten
   (vlag `customMade:true` in de config) herkennen. Kassa (klassiek + blok) en de e-mails gebruiken die.
2. Cross-sell `rows()` krijgt "Maatwerk → Speciaal voor jou gemaakt, geen herroepingsrecht" / "Sur mesure → Fabriqué pour vous,
   pas de droit de rétractation".
3. De toggle verdwijnt, of hij verbergt alleen de studiobadge en nooit de melding in de kassa of in de mail. Daarbij komt een
   waarschuwing in het beheer.
4. `d3_file` en `d3b_file_addon` krijgen de twee vakjes voor digitale inhoud (zoals bij de AI-pakketten).

**Acceptatie:**
- e2e: een mand met alleen naamstroken toont `.bps-legal-note` in de klassieke kassa en in de blokkassa;
- een mand met alleen een cross-sell-regel toont een regel "Maatwerk";
- de bevestigingsmail van zo'n bestelling bevat "geen herroepingsrecht";
- met `showWithdrawalNotice=false` staat de melding nog in de kassa.

### gdpr-juridisch-5 · medium · Onbetaalde of mislukte bestellingen zetten ontwerpen 3 jaar vast, en de eraser houdt ze eeuwig bij
**Bestanden:** `includes/woo/class-order.php` (r. 26-31, 183-210), `includes/woo/class-privacy.php` (r. 226-236), `includes/woo/class-cleanup.php`
**Probleem:**
- `mark_ordered` draait al op `checkout_order_processed`. Een bestelling die daarna `failed`, `cancelled` of eeuwig `pending` blijft,
  krijgt toch status `ordered`, dus het origineel blijft 3 jaar (getest);
- de eraser beschouwt `pending` en `on-hold` als "loopt nog", ook na maanden, en wist dan nooit;
- als WooCommerce zelf een bestelling verwijdert of anonimiseert (bewaarinstellingen voor pending, failed en cancelled orders),
  ruimt Patch Studio niets op.

**Voorstel:**
- Bij `woocommerce_order_status_failed|cancelled` (en `pending` ouder dan `woo.pendingDays`, standaard 14) zet je het ontwerp terug
  naar `carted`, zodat de klok van `orphanDays` loopt.
- De eraser behandelt `pending`/`failed` ouder dan 14 dagen als afgerond.
- Haak in op `woocommerce_privacy_remove_order_personal_data` en `woocommerce_before_delete_order`: daar roep je `expire_ordered`
  aan en verwijder je `_bps_email`.

**Acceptatie:** een test zet de bestelling op `failed`, daarna is de ontwerpstatus `carted`. Een cronrun 31 dagen later (datum terug
gezet) verwijdert het ontwerp. De eraser bij een `pending`-bestelling van 20 dagen geeft `items_removed:true`. Een WC-anonimisering
van de bestelling verwijdert het originele bestand.

### gdpr-juridisch-6 · medium · Afloop van bestelde ontwerpen is onvolledig: proefbeelden publiek in de mediabibliotheek, e-mail en teksten blijven
**Bestanden:** `includes/woo/class-cleanup.php` (`expire_ordered`), `includes/woo/class-designs.php` (`delete_files`),
`includes/admin/class-order-production.php` (r. 1533-1600 `send_proof`)
**Probleem:**
- `expire_ordered` verwijdert alleen de bestanden. De ontwerppost houdt `_bps_email`, `_bps_email_sent_to`, `_bps_payload`
  (teksten en notities, vaak namen of telefoonnummers) en `_bps_proof.history` (berichten en opmerkingen van de klant) voor altijd;
- digitale proeven worden als bijlage in de publieke mediabibliotheek gekozen. Ze vallen niet onder de bewaartermijn of de eraser,
  en hebben een publieke URL en een mogelijk indexeerbare bijlagepagina, met het logo van de klant erop.

**Voorstel:**
- `expire_ordered` verwijdert ook `_bps_email`, `_bps_email_sent_to` en `_bps_page_url`, maakt `payload.notes/texts` leeg en
  vervangt `proof.history[*].note` door "".
- Proefbeelden worden opgeslagen in `uploads/patch-studio/proofs/` (private + ondertekende URL), of de bijlage-id's komen in
  `_bps_proof.attachments`. Die bijlagen worden met `wp_delete_attachment( $id, true )` verwijderd bij afloop en bij wissen.

**Acceptatie:** na `expire_ordered` heeft de post geen `_bps_email`, `payload.notes` is "" en geen enkele `history.note` heeft nog
inhoud. Een proefbijlage die bij het ontwerp hoort, bestaat niet meer (`get_post($att)` geeft null).

### gdpr-juridisch-7 · medium · AI-eigenaarsrijen met e-mail verlopen nooit
**Bestanden:** `includes/ai/class-cleanup.php`, `includes/ai/class-store-wpdb.php` (r. 144-200), `includes/services/class-owner.php` (`remember`)
**Probleem:** `bps_ai_owners` bevat `email`, `user_id` en `email_verified_at`. De cleanup wist alleen `ip_hash`. Een gast die ooit
zijn e-mail bevestigde voor 2 gratis rondes, blijft voor altijd bewaard (getest met een rij van 3 jaar oud). Anonieme `a:`-rijen
zonder jobs blijven ook staan.
**Voorstel:** in `AI\Cleanup::run()` komt `owner_purge(before)`:
- rijen zonder open lots of jobs met `last_seen_at` ouder dan `retentionDays.ownerRows` (standaard 365; anoniem 30) worden verwijderd;
- rijen met boekhoudkundige lots houden alleen de key, `email` gaat naar NULL.

Neem de termijn op in `ai-legal.json` en in de beleidstekst.
**Acceptatie:** een test met een eigenaar (e-mail, `last_seen_at` 400 dagen oud, geen lots): na de run is de rij weg. Met een open
lot blijft de rij, maar `email` is NULL. Een anonieme rij van 31 dagen zonder jobs is weg.

### gdpr-juridisch-8 · medium · Rechtenverklaring: zwak bewijs (versie van de client, sha1 van een andere tekst)
**Bestanden:** `includes/rest/class-routes.php` (r. 476-497, 570-601), `includes/woo/class-cart.php` (r. 121-125),
`includes/woo/class-order.php` (r. 65-90, 113-119), `includes/woo/class-quotes.php` (r. 111-115), `includes/nametape/class-cart.php` (r. 106-110)
**Probleem:**
- de server bewaart `rights.v` zoals de client die stuurt, zonder te controleren tegen `legal.rightsTextVersion`;
- `text_sha1` wordt berekend uit een hardgecodeerde PHP-zin, niet uit de tekst die de studio echt toonde (die staat in
  `ui/messages.js`). Als die twee uit elkaar lopen, bewijst de sha1 niets;
- naamstroken tonen een andere zin ("deze namen en teksten") maar bewaren dezelfde versie, zonder sha1.

Bij een klacht over namaak (merk of auteursrecht) zijn dat net de bewijsstukken.
**Voorstel:**
- één bron: `config/legal-rights.json` met per context (`patch`, `nametape`, `quote`) `{v, nl, fr}`. De tekst gaat via
  `public_config` naar de UI, die geen eigen kopie meer heeft;
- de server zet `v` en `text_sha1` zelf uit die bron. Een afwijkende client-`v` wordt gelogd en vervangen;
- de naamstrook-rechten krijgen `text_sha1`.

**Acceptatie:**
- een unit test: `POST /cart` met `rightsConsent.v="bogus"` geeft een order item met `_bps_rights_consent.v === legal.rightsTextVersion`;
- `sha1(public_config.legal.rights.patch.nl)` is gelijk aan de opgeslagen `text_sha1`;
- het order item van een naamstrook heeft `_bps_rights_consent.text_sha1`.

### gdpr-juridisch-9 · medium · Woo-exporter onvolledig en niet gepagineerd voor grote klanten
**Bestanden:** `includes/woo/class-privacy.php` (r. 80-117, 150-275), `includes/crosssell/class-cart.php` (`order_meta`: names/plate)
**Probleem:** wat de export mist (art. 15/20):
- de rechtenverklaringen (datum, uur, versie);
- de proefgeschiedenis met de antwoorden en opmerkingen van de klant;
- een kopie of link van het eigen originele bestand (alleen de bestandsnaam);
- teksten op cross-sell-regels (namen op de rug, tekst op het gelaserde plaatje).

Daarbij: `design_ids()` haalt maximaal 500 ontwerpen en 200 bestellingen op, en de eraser geeft altijd `done:true`. Een
B2B-klant met veel bestellingen wordt dus deels geëxporteerd en gewist zonder dat iemand het merkt.
**Voorstel:**
- extra velden: "Rechtenverklaring" (uit `_bps_rights_consent` van het order item), "Proefrondes" (datum, status, opmerking klant)
  en "Origineel bestand (link 7 dagen geldig)" met een ondertekende download-URL;
- een groep `bps-crosssell` met names/plate;
- echte paginering via `page` in `design_ids()`/`quote_ids()` (offset), en de eraser zet `done` pas op true als de laatste pagina leeg is.

**Acceptatie:**
- een test met 120 ontwerpen op één e-mailadres: de export loopt over 3 pagina's (PAGE=50) en geeft 120 items;
- de eraser wordt met pagina 1..n aangeroepen tot `done:true`, daarna zijn er 0 ontwerpen;
- een export van een bestelling bevat "Rechtenverklaring" en de cross-sell-naam.

### gdpr-juridisch-10 · medium · Offerteformulier: verplicht "toestemmings"-vinkje is de verkeerde rechtsgrond en wordt niet bewaard
**Bestanden:** `includes/rest/class-routes.php` (r. 567-568), `includes/woo/class-quotes.php`, `assets/src/ui/messages.js` (`ui.quote.privacy`, alleen via handoff)
**Probleem:** `/quotes` weigert een aanvraag zonder `consent`. Een offerte beantwoorden is een stap vóór een overeenkomst
(art. 6.1.b AVG), geen toestemming (6.1.a). Een verplicht vinkje is geen vrije toestemming, en ze wordt ook niet bewaard
(geen versie of tijd), dus bewijzen kan niet.
**Voorstel:**
- server: `consent` is niet meer verplicht (backwards compatible: negeren als het meekomt);
- de UI toont een informatiezin met een link naar het privacybeleid in plaats van een vinkje (handoff);
- een optionele, aparte opt-in voor marketing alleen als die functie ooit komt, met `{v, at, text_sha1}`.

**Acceptatie:** `POST /quotes` zonder `consent` met geldige velden geeft 200. Het offerteformulier in de studio heeft geen verplicht
privacyvinkje meer, wel een link naar `contact.privacyUrl`.

### gdpr-juridisch-11 · medium · Cookies: een lees-oproep en "nee bedankt" zetten de 30-dagen-cookie `bps_ai_sid`
**Bestanden:** `includes/ai/class-rest.php` (r. 149-153 `status`), `includes/ai/class-identity.php` (r. 125-155, 189-215),
`includes/services/class-rest.php` (r. 225-235, 541-546 `store_dismissed`)
**Probleem:**
- `GET /ai/status` roept `Identity::current(true)` aan. Elke bezoeker die de studio opent (als de UI de status ophaalt), krijgt dan
  een cookie van 30 dagen en een eigenaarsrij met IP-hash, nog vóór een AI-actie. De beleidstekst zegt "bij je eerste AI-actie";
- een aanbod van een dienst wegklikken (`store_dismissed`) zet dezelfde cookie. Dat is een voorkeurscookie, niet strikt noodzakelijk,
  en dus toestemmingsplichtig (art. 10/2 wet 30-07-2018, voorheen art. 129 WEC).

**Voorstel:**
- `status` gebruikt `current(false)`, toont zonder cookie de standaard gratis rondes en schrijft geen eigenaarsrij;
- de cookie komt pas bij `generate`, `email/verify`, `pack add`;
- het weggeklikte aanbod gaat naar sessionStorage aan de clientkant (of de WC-sessie als die al bestaat), niet naar een nieuwe cookie.

**Acceptatie:**
- `curl -i <site>/wp-json/bps/v1/ai/status` zonder cookies geeft geen `Set-Cookie: bps_ai_sid`, en er komt geen nieuwe rij in `bps_ai_owners`;
- `POST` dismiss zonder cookies geeft geen `Set-Cookie: bps_ai_sid`;
- `POST /ai/generate` zet hem wel.

### gdpr-juridisch-12 · medium · Tekst voor het privacybeleid: onvolledig, vaste termijnen, "u", geen FR voor AI
**Bestanden:** `includes/woo/class-privacy.php` (r. 30-56), `includes/ai/class-privacy.php` (r. 197-209), `languages/borduur-patch-studio-fr_BE.*`
**Probleem:**
- de Woo-tekst gebruikt "u" (klantteksten: "je");
- naamstroken (namen van derden, bloedgroep), cross-sell, diensten, ontvangers (hosting, mail, betaalprovider) en de doorgifte
  buiten de EU naar OpenAI/Recraft (DPF/SCC) staan er niet in;
- de AI-tekst zet 30/365 dagen, 10 jaar en de cookieduur vast in de zinnen, terwijl `ai-legal.json`/`AI_Settings::retention`
  instelbaar zijn;
- de belofte over offertes klopt niet (zie -2);
- de AI-tekst bestaat niet in het Frans.

**Voorstel:**
- herschrijven in "je" met `sprintf()` uit de echte instellingen (`retentionDays.*`, `Identity::COOKIE_TTL`, `orderYears`, nametape-termijn);
- extra blokken: Naamstroken (incl. art. 9 en de verantwoordelijkheid van de besteller), Ontvangers en Doorgifte (provider + land
  + waarborg, uit de providerconfig);
- FR-vertaling in de .po/.l10n.

**Acceptatie:**
- `wp_add_privacy_policy_content` bevat geen " u " of " uw " meer;
- met `retentionDays.guestPromptsAndProposals=14` staat "14 dagen" in de AI-tekst;
- de tekst bevat "bloedgroep", "OpenAI" en "Verenigde Staten";
- met `fr_BE` is de AI-tekst Frans.

### gdpr-juridisch-13 · medium · "Alles verwijderen bij deïnstalleren" laat AI-, krediet- en naamstrookdata en crons achter
**Bestanden:** `uninstall.php`
**Probleem:**
- alleen `bps_cleanup` wordt uitgeschakeld. `bps_ai_cleanup`, `bps_svc_daily` en `bps_nt_cleanup` blijven gepland;
- met `deleteDataOnUninstall` blijven de tabellen `bps_ai_owners` (e-mail), `bps_ai_jobs` (beschrijvingen), `bps_ai_costs`,
  `bps_ai_lots`/`ledger` en de opties en transients van de modules staan.

De eigenaar denkt dat alles weg is.
**Voorstel:**
- alle crons wissen;
- met wipe: `DROP TABLE` voor de `bps_ai_*`-tabellen, behalve de ledger als er nog boekhoudkundig relevante rijen zijn jonger dan 10 jaar.
  In dat geval een melding geven en alleen `email`/`input` anonimiseren;
- `bps_ai_*`- en `bps_nt_*`-opties verwijderen;
- de beheertekst van de toggle aanpassen.

**Acceptatie:** na `wp plugin uninstall` met wipe op een site met AI-data is `SHOW TABLES LIKE '%bps_ai_owners%'` leeg (of zijn
alle `email` NULL). `wp cron event list | grep bps_` is leeg. Er staat geen `wp_options` met de prefix `bps_` meer.

### gdpr-juridisch-14 · low · Toestemming voor analytics alleen via CookieYes
**Bestanden:** `assets/src/ui/app.js` (r. 84-90, alleen via handoff), `includes/frontend/class-shortcode.php` (public config)
**Probleem:** `ctx.track` controleert alleen `window.getCkyConsent`. Een premium plugin moet met elke CMP werken
(Complianz, Cookiebot, Borlabs). Daarvoor bestaat de WP Consent API (`wp_has_consent('statistics')`).
**Voorstel:** de server stuurt `config.consent.statistics` (bool) via `function_exists('wp_has_consent') ? wp_has_consent('statistics') : null`
plus de filter `bps_has_statistics_consent`. De UI leest eerst `window.wp_has_consent?.('statistics')`, dan CookieYes, en anders
niets (handoff). Met `wp_set_consent_type` declareert de plugin dat ze cookieloos is.
**Acceptatie:** met Complianz en zonder CookieYes komt `bps_design_start` alleen in `dataLayer` na toestemming voor statistiek.
Zonder CMP komt er niets in `dataLayer`.

### gdpr-juridisch-15 · low · Geen geautomatiseerde tests voor privacy en opruiming
**Bestanden:** `tests/` (geen test voor `Woo\Privacy`, `Woo\Cleanup`, `AI\Privacy`, `AI\Cleanup`), `tests/run-all.sh`
**Probleem:** alle bevindingen hierboven heb ik handmatig met `wp eval-file` moeten aantonen. Een regressie (bv. een nieuwe meta-key
met persoonsgegevens) wordt niet opgemerkt.
**Voorstel:** `tests/privacy/run-privacy-php.php` (stubs zoals in `tests/ai/backend`), plus een e2e-scenario `09-privacy.e2e.cjs`.
Dat scenario maakt een ontwerp, een offerte en een naamstrookbestelling, draait de exporter en eraser via `wp_privacy_*` en de cron,
en controleert de bestanden op schijf. Het hangt in `npm run test:php` en `tests/run-all.sh`.
**Acceptatie:** `npm run test:php` meldt minstens 40 privacychecks met 0 fouten. Het e2e-scenario faalt als je de quote-bucket uit
-2 weghaalt.
