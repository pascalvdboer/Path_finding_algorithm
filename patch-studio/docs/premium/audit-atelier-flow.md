# Audit atelier-flow: van bestelling tot werkbon

Auditor: atelier-flow (17-11-2026). Scope: productiefiche, digitale proef (versturen, goedkeuren, revisies), statussen,
notificaties, export voor de digitaliseerder, printbare werkbon, batchoverzicht. Er is geen code gewijzigd.

## Wat al goed is
- De proeflink is HMAC-ondertekend per ronde. Een GET wijzigt niets, alleen de POST van het formulier. Een oudere ronde toont "nieuwere proef verstuurd". De pagina is noindex en no-store.
- De productiefiche is volledig: specs, garencodes met aandeel, tekstlagen, aandachtspunten, analyse, download van het origineel en een kopieerknop voor de garencodes.
- De werkbon (A4) heeft een QR-code naar de bestelling, een voorbeeld op ± 1:1 met schaal en is zwart-wit leesbaar.
- De klantpagina werkt op 390 px zonder horizontaal scrollen en is tweetalig (nl/fr).

## Hoe getest
- `npm test`: 233 van 236 geslaagd, 0 mislukt. `npm run test:php`: 652 checks, 0 mislukt.
- Een eigen kopie van de testsite in de scratchpad, op poort 8941, zodat het gedeelde testsysteem op 8899 niet geraakt werd. Daarop:
  - proeven verstuurd met `Order_Production::send_proof()` en de mails gelezen in `wp-content/bps-mails`;
  - goedkeuren en aanpassing vragen via curl (POST);
  - de klantpagina vastgelegd met Playwright op 390×844.
- De sleutelbevinding (atelier-flow-1) is gereproduceerd: hetzelfde ontwerp in een tweede bestelling.

## Bevindingen (gesorteerd op ernst)

### atelier-flow-1 · kritiek · De proefstatus hangt aan het ontwerp, niet aan de orderregel
- **Bestanden:** `includes/admin/class-order-production.php` (`proof_of`, `send_proof`, `record_response`, `design_for_item`), `includes/woo/class-designs.php` (`update_proof`), `includes/woo/class-order.php` (`mark_ordered`)
- **Probleem:** `_bps_proof` staat op de ontwerp-post. Gereproduceerd: ontwerp 120 werd in bestelling 122 goedgekeurd. Daarna kwam hetzelfde ontwerp in nieuwe bestelling 132.
  - Bestelling 132 toont meteen "goedgekeurd, ronde 1", ook al is daar nooit een proef verstuurd.
  - Na een proef in 132 toont bestelling 122 "verstuurd, ronde 2, order 132".
  - De goedkeuring van 122 is daarmee weg uit de fiche, en de ronde-1-link van 122 geeft nu "nieuwere proef".
  - Dit gebeurt bij opnieuw bestellen, bij een bewaarde ontwerplink, of als het atelier een regel dupliceert.
- **Voorstel:** sla de proef op per orderregel (itemmeta `_bps_proof`, met HPOS-veilige CRUD), met de sleutel `{order_id, item_id}`.
  - Het token wordt `hash_hmac("proof|{order}|{item}|{round}")`.
  - `proof_of()` leest uit het item. Een migratie verplaatst de bestaande ontwerp-proef naar de regel die in `proof.order_id`/`item_id` staat.
  - Combineer dit met security-4 (kloon bij opnieuw bestellen).
- **Acceptatie:** een e2e-test met hetzelfde designId in 2 bestellingen.
  - Bestelling B start op "Nog geen proef".
  - Een proef plus goedkeuring in B laat de status, de ronde en de geschiedenis van A byte-identiek.
  - De link van A blijft "al goedgekeurd" tonen.

### atelier-flow-2 · hoog · De werkbon en de fiche tonen de tool-preview, niet de goedgekeurde proef
- **Bestanden:** `templates/admin/fiche-print.php` (r. ~150), `templates/admin/fiche-card.php` (r. 55), `class-order-production.php` (`fiche()`: `'preview_url' => $rec['preview_url']`)
- **Probleem:** als het atelier een eigen proefbeeld kiest (uit de digitaliseersoftware, via de mediabibliotheek), keurt de klant dat beeld goed. De werkbon en de kaart tonen toch nog de mockup uit de studio. De machine-operator werkt dus naar een ander beeld dan wat de klant goedkeurde.
- **Voorstel:** voeg in `fiche()` `approved_image_url` toe: het beeld van de ronde met status approved, anders het laatst verstuurde beeld, anders de preview. Toon het op de werkbon als hoofdbeeld met het label "Goedgekeurde proef (ronde n, datum)". De klant-preview wordt dan een kleine miniatuur.
- **Acceptatie:** stuur een proef met een eigen mediabeeld en keur goed. De print-HTML bevat dan de URL van dat mediabeeld als `.visual__img` en het label "Goedgekeurde proef". Zonder goedkeuring staat er "NOG NIET GOEDGEKEURD" in de kop van de werkbon.

### atelier-flow-3 · hoog · Geen bewijs van wat goedgekeurd werd: het beeld per ronde, IP en checklist ontbreken
- **Bestanden:** `class-order-production.php` (`send_proof`, `record_response`), `class-designs.php` (`update_proof`), `templates/admin/proof-status.php`
- **Probleem:** `image_url` wordt bij elke ronde overschreven en de `history`-items bewaren geen beeld. Na ronde 2 is dus niet meer te zien wat in ronde 1 verstuurd of afgekeurd werd. Bij een goedkeuring worden geen tijdstempel-bewijs, IP (gehasht), user-agent of hash van het beeld en de specificaties bewaard. Bij een klacht ("dit heb ik niet goedgekeurd") heeft de winkel dus geen dossier. Betaalde proof-tools bewaren dat wel.
- **Voorstel:** sla bij elke ronde in de geschiedenis op:
  - `image_url` en `image_sha256`;
  - een snapshot van de klantrijen (`customer_rows`);
  - het bericht.
  
  Sla bij een antwoord op: `ip_hash` (HMAC met salt, AVG-vriendelijk), `ua` (ingekort) en de bevestigde checklist (zie atelier-flow-7). Toon de rondes in de admin als tijdlijn met miniatuur per ronde. Voeg de knop "Goedkeuringsbewijs (PDF/print)" toe.
- **Acceptatie:** na 2 rondes plus een goedkeuring bevat `history` 3 items, waarvan 2 met `image_url` en `image_sha256`, plus 1 goedkeuring met `ip_hash`, `ua` en `checks[]`. De admin-tijdlijn toont 2 verschillende miniaturen. Het bewijsblad toont het goedgekeurde beeld, de specificaties, de datum, het uur en de ronde.

### atelier-flow-4 · hoog · Geen productiestatussen: een goedkeuring zet niets in gang
- **Bestanden:** `class-order-production.php` (`record_response`, `ajax_set_status`), `includes/woo/class-order.php`. Er zijn geen `register_post_status` of `wc_order_statuses`-filters.
- **Probleem:** na een goedkeuring bleef bestelling 122 op `on-hold` staan. Niets luistert naar `bps_proof_response` (grep: geen add_action). De werkvolgorde op de werkbon is een papieren afvinklijst zonder status in het systeem: digitaliseren, produceren, controleren, verpakken. Het atelier ziet in WooCommerce dus niet welke bestelling in productie is.
- **Voorstel:** maak een instelbare productiestatus per orderregel: `nieuw` → `proef` → `goedgekeurd` → `gedigitaliseerd` → `in productie` → `klaar`. Maak daarnaast optionele WC-orderstatussen "Proef verstuurd" (`wc-bps-proof`) en "In productie" (`wc-bps-production`), standaard uit, met auto-overgang:
  - proef verstuurd → "Proef verstuurd";
  - alle regels goedgekeurd én betaald → "In productie".
  
  Voorzie bulkacties in de orderlijst en filters `bps_production_status_changed`.
- **Acceptatie:** met de instelling aan zet goedkeuren van alle regels de bestelling op `wc-bps-production` (bij betaald) en komt er een ordernotitie. Met de instelling uit verandert de orderstatus niet (dat is het huidige gedrag). De productiestatus per regel is zichtbaar in de orderlijstkolom en per regel aanpasbaar, met een geschiedenis.

### atelier-flow-5 · hoog · Er is geen batch- of atelieroverzicht
- **Bestanden:** geen. Alleen de kolom "Patchproef" in de orderlijst (`list_cell`).
- **Probleem:** het atelier moet elke bestelling apart openen. Er is geen lijst van alle patchregels over bestellingen heen, gefilterd op status, techniek, maat, stofkleur of deadline. Er is geen bulk-print van werkbonnen, geen geaggregeerde garen-picklijst ("Polyneon 1801: 6 ontwerpen, ±42 000 steken") en geen weergave van wat te laat is.
- **Voorstel:** een submenu "Patch Studio › Atelier" met een `WP_List_Table` per orderregel:
  - kolommen: ref, miniatuur, klant, techniek, maat, aantal, garen, proefstatus, productiestatus, dagen sinds de proef, deadline;
  - filters en zoeken;
  - bulkacties: "werkbonnen afdrukken" (één print-URL voor meerdere items over orders heen), "zet op in productie" en "exporteer CSV";
  - een samenvattingsblok met de garenbehoefte per Polyneon-code voor de selectie.
- **Acceptatie:** bij 50 orders met 80 patchregels laadt het overzicht in < 1 s (paginering 25). Filter "goedgekeurd + geborduurd" toont exact de juiste regels. Bulk-print van 10 regels uit 7 orders geeft 10 A4-pagina's. De CSV opent in Excel met de kolommen ref;order;techniek;mm;aantal;garencodes. Dit is gedekt door een e2e-test.

### atelier-flow-6 · hoog · Geen exportpakket voor de digitaliseerder
- **Bestanden:** `class-designs.php` (`handle_download`, alleen het origineel), `templates/admin/fiche-card.php` (codes kopiëren)
- **Probleem:** de digitaliseerder krijgt alleen het origineel, en dat bestaat niet bij een patch met alleen tekst (item 109: `orig=-`). De previews zijn mockups van 1200×1200 met rand, stof en schaduw. Dat is geen schoon artwork en bij 200 mm maar 152 dpi. Er is geen kleurenlijst-bestand, geen SVG of PNG van de samengestelde compositie (beeld + tekst op positie) en geen fontinfo als bestand. "Codes kopiëren" geeft alleen kale nummers, zonder merk ("Madeira Polyneon") en zonder naam.
- **Voorstel:** een knop "Pakket voor digitaliseren (.zip)" per regel en in bulk. Inhoud:
  - `REF-origineel.ext`;
  - `REF-artwork.png`: schone compositie, transparant, zonder rand of stof, op 300 dpi van de echte mm-maat. Server-side gerenderd of in de browser bij de checkout bewaard als `_bps_artwork`;
  - `REF-artwork.svg` met tekst als `<text>` met font-family en een pad voor boogtekst;
  - `REF-garens.csv` (merk;code;naam;hex;aandeel;rol beeld/tekst/rand);
  - `REF-fiche.pdf/html`;
  - `fonts.txt` met de lettertypenamen en licentie.
  
  De UI-kant staat in `docs/handoff/premium-ui-needs.md`.
- **Acceptatie:** de ZIP voor een patch met alleen tekst bevat ≥ 4 bestanden. De artwork-PNG van 80 mm is ≥ 945 px breed (300 dpi) zonder randpixels, en de CSV-regels kloppen met de fiche. "Codes kopiëren" levert "Madeira Polyneon 1801 Wit".

### atelier-flow-7 · middel · De klantpagina voor goedkeuring voelt niet premium aan
- **Bestanden:** `templates/admin/proof-response.php`, `customer_rows()`, `templates/admin/emails/proof.php`
- **Probleem:** gemeten op 390×844:
  - het proefbeeld is 308 px breed (natuurlijk 1200 px), zonder in te zoomen of op volledig scherm te openen;
  - de goedkeurknop staat op 1325 px, bijna 2 schermen naar beneden;
  - tekstregels tonen geen lettertype, garenkleur of letterhoogte;
  - er is geen expliciete controle-checklist (spelling, kleuren, maat, aantal) vóór de knop;
  - er is geen vergelijking met de vorige ronde.
  
  Eén klik keurt dus goed, zonder bewijs dat de klant gecontroleerd heeft.
- **Voorstel:** maak het beeld klikbaar om in te zoomen (`<a>` naar het volledige beeld, met pinch). Zet een sticky actiebalk onderaan op mobiel (Goedkeuren / Aanpassen). Voeg 3 verplichte vinkjes toe: "Tekst en spelling kloppen", "Kleuren en maat kloppen", "Aantal klopt", in nl en fr. Toon tekstregels met font, kleur en code. Zet een schakelaar "vorige ronde" naast het beeld.
- **Acceptatie:** op 390×844 is de goedkeurknop zichtbaar zonder scrollen (sticky) en heeft hij een tap-target ≥ 48 px. De submit wordt geweigerd (ook server-side) zolang de vinkjes niet aangevinkt zijn. Het beeld opent op volle resolutie. axe geeft 0 serious/critical problemen.

### atelier-flow-8 · middel · Revisies zijn beperkt: geen bijlage van de klant, geen specwijziging, geen rondelimiet
- **Bestanden:** `proof-response.php`, `ajax_send_proof`, `assets/src/admin/production.js`
- **Probleem:** bij "aanpassing vragen" kan de klant alleen tekst sturen, geen referentiebeeld of nieuw logo. Het atelier kan de specificaties van de bestelling niet aanpassen wanneer de klant een andere kleur, maat of hoeveelheid vraagt, en er wordt geen prijsverschil berekend. Er is geen maximum aantal gratis rondes en de betaalde dienst "extra proefronde" uit de BRIEF ontbreekt. Er kan maar één proefbeeld per ronde mee (`multiple:false`): je kan bijvoorbeeld niet voor- en achterkant of 2 varianten sturen.
- **Voorstel:**
  - een optionele upload bij "aanpassen" (max 10 MB, dezelfde validatie als de studio, privé opgeslagen);
  - 1–4 proefbeelden per ronde, met optie "variant A/B, kies er één";
  - een instelling `proof.freeRounds` (standaard 2) en daarboven een betaallink naar het dienstproduct "extra proefronde";
  - een admin-actie "specs aanpassen", die de snapshot `_bps_payload` versioneert en het prijsverschil als ordernotitie of betaallink toont.
- **Acceptatie:** de klant kan een PNG bij een aanpassing voegen; die verschijnt als download in de admin-tijdlijn. Een ronde met 2 beelden toont 2 beelden in de mail en op de pagina. Na `freeRounds` toont de mail van de volgende ronde de betaallink voor een extra ronde. Met e2e gedekt.

### atelier-flow-9 · middel · Geen herinneringen of opvolging van openstaande proeven
- **Bestanden:** geen cron. `promises.proofDays` wordt alleen in de bestelmail gebruikt (`class-emails.php:122`).
- **Probleem:**
  - een proef op "wacht op klant" blijft eeuwig open, zonder herinnering aan de klant en zonder signaal voor de winkel;
  - de belofte "proef binnen X werkdagen" wordt nergens bewaakt;
  - een aanpassingsvraag heeft geen deadline voor het atelier.
- **Voorstel:** een WP-Cron-taak `bps_proof_followup`, dagelijks:
  - herinneringsmail aan de klant na N dagen (instelbaar, standaard 3), max 2 keer, met dezelfde geldige link;
  - een admin-digest (mail plus dashboardwidget): "3 bestellingen zonder proef ouder dan proofDays", "2 proeven wachten > 7 dagen", "1 aanpassingsvraag open".
  
  Herinneringen gaan via een WC_Email-klasse, zodat ze aan of uit kunnen.
- **Acceptatie:** zet een tijdreis-test `sent_at` op -4 dagen en run de cron. Er gaat dan precies 1 herinnering uit. Een tweede run dezelfde dag stuurt niets. De digest bevat de juiste tellingen en de widget toont dezelfde aantallen.

### atelier-flow-10 · middel · Een bestelling met meerdere patches geeft meerdere mails en meerdere goedkeuringen
- **Bestanden:** `send_proof`, `BPS_Email_Proof`, `proof-response.php`
- **Probleem:** bestelling 122 (2 patches) gaf 2 aparte mails met elk hun eigen knoppen. Bij naamstroken of sets van 5–10 patches wordt dat spam, en de klant kan er makkelijk één vergeten.
- **Voorstel:** "Proef voor hele bestelling versturen": 1 mail en 1 pagina met alle regels als kaarten. Per regel kies je goedkeuren of aanpassen, en er is één knop "alles goedkeuren". Behoud de huidige proef per regel als optie.
- **Acceptatie:** bij een bestelling met 3 patchregels zorgt één actie voor 1 mail. Op de klantpagina geeft "alles goedkeuren" 3 regels op approved, plus 1 ordernotitie en 1 winkelmail. Gemengde antwoorden worden per regel correct opgeslagen.

### atelier-flow-11 · middel · Beloftes "betalen na proef" en "annuleren tot proef" zitten niet in de proefflow
- **Bestanden:** `config/defaults.json` (`promises.payAfterProof`, `cancelUntilProof`), `class-settings-page.php`, `proof-response.php`, `fiche-card.php`
- **Probleem:**
  - `payAfterProof` is instelbaar maar wordt nergens gebruikt (grep);
  - `cancelUntilProof` wordt in de checkout beloofd, maar op de proefpagina staat geen annuleerknop;
  - de fiche toont de betaalstatus niet, terwijl proeven verstuurd werden voor bestellingen in `on-hold` (overschrijving nog niet ontvangen);
  - het atelier kan zo produceren voor een onbetaalde bestelling.
- **Voorstel:**
  - toon de betaalstatus (betaald/onbetaald + methode) op de kaart en de werkbon, en waarschuw bij "in productie zetten" zolang die onbetaald is;
  - bij `payAfterProof`: een betaallink (`$order->get_checkout_payment_url()`) op de pagina na goedkeuring en in de bevestigingsmail;
  - bij `cancelUntilProof`: de link "Bestelling annuleren" op de proefpagina, met bevestiging, die de order op cancelled zet en het atelier verwittigt.
- **Acceptatie:** met payAfterProof aan toont de pagina na goedkeuring van een onbetaalde bestelling de knop "Nu betalen" naar de pay-for-order-URL. Met cancelUntilProof aan zet annuleren vóór goedkeuring de order op cancelled; na goedkeuring is de knop weg. De werkbon van een onbetaalde order toont "ONBETAALD".

### atelier-flow-12 · middel · Proeftoken: lekgevoelig, verloopt niet, geen herstel
- **Bestanden:** `class-order-production.php` (`proof_url`, `handle_proof_response`)
- **Probleem:**
  - het token staat in de query-string van een themapagina (header/footer met analytics/pixels). GA4 en pixels sturen `page_location` met de volledige URL, en wie de URL heeft kan goedkeuren;
  - er is geen `Referrer-Policy`-header (gemeten: geen);
  - het token verloopt nooit;
  - wordt `wp_salt('auth')` geroteerd (bv. door een beveiligingsplugin), dan zijn alle openstaande links ongeldig. De klant ziet dan "link werkt niet" zonder knop "stuur me een nieuwe link".
- **Voorstel:**
  - bij een geldige GET: zet een HttpOnly SameSite=Lax cookie (of een eenmalige server-side sessie) en doe een 303-redirect naar `/?bps_proof_view=ID` zonder token;
  - stuur `Referrer-Policy: no-referrer` en zet `<meta name=referrer content=no-referrer>`;
  - gebruik een eigen geheim (`bps_proof_secret`-optie) in plaats van de auth-salt;
  - laat het token verlopen (instelbaar, standaard 90 dagen) en voeg op de ongeldig-pagina een formulier "nieuwe link aanvragen" toe (bestelnummer + e-mail, rate-limited).
- **Acceptatie:** na het openen van de mail-link bevat de adresbalk geen `t=`. De response-headers bevatten `Referrer-Policy: no-referrer`. Rotatie van de WP-salts laat links geldig. Een link na de verlooptermijn toont het herstelformulier, en dat stuurt een nieuwe mail naar het billing-adres (niet naar een ingevoerd adres).

### atelier-flow-13 · middel · De klant ziet zijn proef niet in Mijn account
- **Bestanden:** geen hooks op `woocommerce_order_item_meta_end` / `woocommerce_order_details_after_order_table`
- **Probleem:** de proefstatus en het proefbeeld zijn alleen bereikbaar via de maillink. Een klant die de mail kwijt is of in spam vond, ziet in Mijn account › Bestellingen niets: geen "proef wacht op jou" en geen knoppen. Premium-plugins maken dit centraal.
- **Voorstel:** toon per patchregel in de orderweergave (Mijn account én bedankpagina) de status, een miniatuur en, als de status `sent` is, de knoppen naar de bevestigingspagina, geautoriseerd via de ingelogde eigenaar van de order. In de orderlijst van Mijn account komt een badge "Proef wacht op jou".
- **Acceptatie:** ingelogd als de klant toont `/mijn-account/view-order/122/` de status "Wacht op jouw goedkeuring" en de knop "Proef bekijken", die naar de bevestigingspagina leidt. Een andere ingelogde klant krijgt 403.

### atelier-flow-14 · laag · Er ontbreekt planningsinfo op de werkbon
- **Bestanden:** `templates/admin/fiche-print.php`
- **Probleem:** de werkbon toont niet:
  - de leverdatum/deadline (`promises.deadline`, spoed-dienst);
  - de verzendmethode (afhalen in Kalmthout of verzenden);
  - de betaalstatus;
  - het telefoonnummer van de klant;
  - de productiestatus.
  
  De afvinklijst is statisch: "Gedigitaliseerd" en "Geproduceerd" worden nooit aangevinkt. Er staat geen scanbare item-code op voor de status bij de machine.
- **Voorstel:** voeg een feitenrij "Deadline · Verzending · Betaald · Spoed" toe en een tweede QR die naar een mobiele statuspagina per item verwijst (`admin-post.php?action=bps_item_status&item=…`, login vereist), met knoppen voor de volgende stap. Laat de vinkjes de productiestatus volgen (atelier-flow-4).
- **Acceptatie:** de werkbon van een order met verzendmethode en spoeddienst toont beide in de feitenrij. De item-QR decodeert naar de statusURL, en die zet na login met één tik "in productie", met een ordernotitie.

### atelier-flow-15 · laag · Absolute URL's opgeslagen en proefbeelden publiek in de mediabibliotheek
- **Bestanden:** `class-designs.php` (`_bps_preview.url`), `send_proof` (`image_url` = `wp_get_attachment_url`)
- **Probleem:** `preview_url` en `image_url` worden als absolute URL bewaard. In een kopie van de site wezen alle previews en proefbeelden nog naar `127.0.0.1:8899`. Na een domeinwissel, staging→live of http→https breken de werkbon, de mails en de proefpagina. Eigen proefbeelden belanden publiek in de mediabibliotheek (raadbare URL, geïndexeerd door attachment-pagina's), samen met het ontwerp van de klant.
- **Voorstel:** bewaar het relatieve pad of het attachment-ID en bouw de URL bij weergave. Upload proefbeelden naar de privémap `patch-studio/proofs` met een random bestandsnaam en `noindex`. Serveer ze via het ondertekende `bps_proof`-endpoint, of minstens als uitgesloten attachment (geen attachment-pagina).
- **Acceptatie:** na `wp search-replace` van het domein tonen de werkbon en de proefpagina beelden van het nieuwe domein. De DB bevat geen absolute preview-URL's meer. Een proefbeeld heeft geen publieke attachment-pagina (404).
