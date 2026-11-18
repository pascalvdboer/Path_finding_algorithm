# Audit "klant-account": premium klantfuncties aan de serverkant (17-11-2026)

Rol: auditor klant-account. Onderwerp: ontwerpen bewaren ("Mijn ontwerpen" in Mijn account), deel-link, ontwerp later afmaken
(met toestemming een e-mailherinnering), herbestellen met één klik, offerte omzetten naar bestelling en gastgebruikers.
Er is geen code gewijzigd. Wat de klant-UI nodig heeft, staat in `docs/handoff/premium-ui-needs.md` (sectie "klant-account").

## Hoe getest
- Code gelezen: `includes/woo/class-designs.php`, `class-quotes.php`, `class-cart.php`, `class-order.php`, `class-cleanup.php`,
  `class-privacy.php`, `class-product.php`, `emails/class-saved-design.php`, `templates/emails/save-design.php`,
  `includes/rest/class-routes.php`, en alleen lezend de UI-aanroepen in `assets/src/ui/flows.js`, `app.js` en `api.js`.
- Lokale testsite (`tests/e2e/start-wp.sh`, WP + Woo + SQLite), met curl tegen `patch-studio/v1`:
  1. `POST /designs` als gast → 201 (PS-2026-0055, id 132).
  2. `POST /designs/132/email` met `"reminder":true` → `{"ok":true}`. Daarna `wp post meta list 132`: er staat **geen enkele**
     herinneringsmeta, en `wp cron event list` toont alleen `bps_cleanup`. De mail ging wel weg, met de herstellink
     `?bps_design=132&bps_token=<64 hex>`.
  3. Klant `kaudit` (rol customer) aangemaakt, ingelogd met cookie, `POST /designs` → id 133, `post_author = 0`, geen user-meta.
  4. `wc_get_account_menu_items()` = dashboard, orders, downloads, edit-address, edit-account, customer-logout: er is **geen**
     endpoint "Mijn ontwerpen" of "Mijn offertes".
- `npm run test:php`: 47 vectoren en 652 checks groen, maar er zijn geen tests voor bewaren, herstellen, mailen of het account.
  `grep` vindt `Designs::`/`email_design` alleen in de e2e-scenario's 05 en `order-check.cjs`.
- Testdata (designs 132 en 133, gebruiker `kaudit`) is achteraf verwijderd.

## Wat al goed is
- Ontwerptokens zijn HMAC's die nooit opgeslagen worden en met `hash_equals` gecontroleerd worden. De bewaarmail is een echte `WC_Email`, in de taal van de klant.
- Per ontwerp kan maar naar maximaal 3 verschillende adressen gemaild worden (anti-relais). Bewaarde ontwerpen worden
  pas `savedDays` na de laatste opening gewist.
- De bewaarmail is eerlijk: "geen nieuwsbrief", er zit een echte prijs in en de bewaartermijn staat er duidelijk bij.
- Een ordered-snapshot (`_bps_payload` op het order-item) bestaat al. Dat is een goede basis voor herbestellen nadat cleanup gelopen heeft.

## Raakvlakken met andere audits (niet dubbel geteld)
- woo-compat-3: de Woo-knop "Opnieuw bestellen" werkt niet. Hier gaat het over herbestellen vanuit Mijn ontwerpen en over het datamodel.
- woo-compat-11: de admin-knop "Maak bestelling + betaallink". Hier gaat het over de levenscyclus van een offerte en over de klant die zelf aanvaardt.
- security-4 (kloon bij opnieuw bestellen) en security-5 (tokens in URL's, tokenversie): daarop bouwen klant-account-4 en -5 verder.
- atelier-flow-13 (proef in Mijn account): gebruikt hetzelfde account-endpoint als klant-account-2.

---

## Bevindingen (gesorteerd op ernst)

### klant-account-1 · kritiek · De herinnering die de studio belooft, wordt nooit verstuurd en de toestemming wordt niet vastgelegd
- **Bestanden:** `includes/rest/class-routes.php` (`email_design`), `includes/woo/emails/class-saved-design.php`,
  `templates/emails/save-design.php`, `includes/woo/class-cleanup.php` (en, alleen gelezen, `assets/src/ui/flows.js:214,229`).
- **Probleem:** de studio toont het vinkje "Stuur me over 3 dagen één herinnering." / "Envoyez-moi un seul rappel dans 3 jours."
  en stuurt `reminder: true`. De server leest dat veld nergens (gemeten: geen meta, geen geplande actie). Een premium plugin
  die iets belooft en het niet doet, is oneerlijk tegenover de klant. Daarnaast ontbreken de onderdelen die een herinnering
  wettelijk nodig heeft: vastgelegde toestemming (tijdstip, tekstversie, taal), een afmeldlink en annuleren zodra de klant
  besteld of een offerte gevraagd heeft.
- **Voorstel:**
  1. Lees in `email_design` het veld `reminder` (bool). Als het `true` is en `reminder.enabled` aan staat, bewaar dan
     `_bps_reminder = {email_hash, consent_v, consent_text_sha1, consent_locale, consent_at, due_gmt, sent_gmt:null, cancelled:null}`.
  2. Plan de herinnering met **Action Scheduler** (`as_schedule_single_action( due, 'bps_design_reminder', [id], 'patch-studio' )`).
     Action Scheduler zit al in Woo en werkt betrouwbaarder dan WP-Cron, wat ook prestatie-10 helpt.
  3. Bij het uitvoeren annuleer je als: status ∈ {carted, ordered, quoted}, er sinds het bewaren een order met dit e-mailadres
     bestaat, het ontwerp intussen nog geopend werd (`_bps_last_opened_gmt > consent_at`), de klant zich afgemeld heeft of
     het adres al een herinnering kreeg in de laatste `reminder.cooldownDays`. Er gaat altijd maar één herinnering weg per ontwerp.
  4. Voeg een nieuwe `WC_Email` `BPS_Email_Design_Reminder` toe (NL "Je patch-ontwerp {ref} wacht nog op je" / FR "Ton design de patch {ref} t'attend"),
     zonder kortingen of nep-urgentie, met een knop naar het ontwerp en een **afmeldlink**
     `GET /reminder/unsubscribe?d={id}&s={hmac}` (geldt voor één klik, en ook de header `List-Unsubscribe`).
  5. Staat `reminder.enabled` uit (standaard): zet in de publieke config `features.reminder=false`, zodat de UI het vinkje verbergt (handoff).
- **Acceptatie:** PHP-integratietest: `email_design` met `reminder:true` → `_bps_reminder.consent_at` gezet en precies één
  pending actie `bps_design_reminder` die over `delayDays` afloopt. Actie uitvoeren → 1 mail in `bps-mails/` met afmeldlink.
  Opnieuw uitvoeren → 0 mails. Zelfde scenario, maar eerst `POST /cart` + order → 0 mails, `cancelled = 'ordered'`.
  Afmeldlink → 200 met de tekst "Je krijgt geen herinneringen meer voor dit ontwerp." / "Tu ne recevras plus de rappel pour ce design."
  `reminder:true` terwijl de functie uit staat → geen actie, en `features.reminder === false` in de config.

### klant-account-2 · hoog · Er is geen "Mijn ontwerpen": ontwerpen worden niet aan de klant gekoppeld
- **Bestanden:** `includes/woo/class-designs.php` (`create`: `post_author => 0`), `includes/rest/class-routes.php`, nieuw `includes/account/class-account.php`.
- **Probleem:** ook een ingelogde klant krijgt `post_author = 0` (gemeten met design 133). Daardoor kan Mijn account geen
  lijst tonen, werkt een ontwerp niet op een ander toestel zonder de mail, en is de herstellink de enige sleutel. Premium
  productontwerpers (bv. FPD "Saved products", Zakeke "My designs") hebben dit standaard. Clubs en bedrijven komen
  jaarlijks terug. Zonder deze lijst bestellen ze niet opnieuw.
- **Voorstel:**
  1. Is de gebruiker ingelogd bij `POST /designs`, dan wordt `post_author = get_current_user_id()`. `post_author` is een
     geïndexeerde kolom, dus het lijstje is snel zonder meta_query. Ingelogd betekent hier: `X-WP-Nonce` ('wp_rest') of
     de bestaande `restore_logged_in_user()`, die verplaatst wordt naar `Guard` zodat ze voor alle schrijfroutes geldt.
  2. Voeg een Woo-account-endpoint `bps-designs` toe (`add_rewrite_endpoint` via de filter `woocommerce_get_query_vars`, plus
     `woocommerce_account_menu_items`). Het label is "Mijn ontwerpen" / "Mes designs" en de slug is instelbaar (`account.endpointSlug`).
     Per ontwerp een kaart met: preview (300 px-variant uit prestatie-1), titel of ref, techniek · maat · aantal, status
     ("Bewaard", "In offerte", "Besteld op …", aantal keer besteld) en de knoppen **Verder ontwerpen**, **Opnieuw bestellen**
     (alleen als besteld en de techniek `mode:'order'` heeft), **Delen**, **Naam wijzigen** en **Verwijderen**.
  3. REST (alles met `permission_callback` = ingelogd + eigenaar, zie de API-tabel onderaan): `GET /account/designs`,
     `GET /account/designs/{id}` (geeft ook een vers token terug zodat de studio kan laden), `PATCH /account/designs/{id}` {title},
     `DELETE /account/designs/{id}`.
  4. Server-gerenderde fallback (geen JS nodig) in `templates/myaccount/designs.php`, te overschrijven via het thema zoals de Woo-templates.
- **Acceptatie:** e2e: klant logt in → ontwerpt → "Bewaar" → `/mijn-account/bps-designs/` toont 1 kaart met de juiste ref en preview.
  "Verder ontwerpen" opent de studio met dezelfde maat en dezelfde teksten. Een tweede klant krijgt op
  `GET /account/designs/{id van klant 1}` een 404, nooit een 403, zodat het bestaan van het ontwerp niet uitlekt. Lijst met 200 ontwerpen: < 50 ms server
  en maximaal 3 DB-queries voor de lijst (Query Monitor of `SAVEQUERIES`).

### klant-account-3 · hoog · Een ontwerp kan niet bijgewerkt worden: verder werken geeft oude data of dubbele ontwerpen
- **Bestanden:** `includes/rest/class-routes.php` (alleen `POST /designs`, er is geen update), `includes/woo/class-designs.php`
  (en, alleen gelezen, `assets/src/ui/flows.js:70-76`, `app.js:640-656`).
- **Probleem:** de server kent alleen "nieuw ontwerp". Bij "later afmaken" gebeuren daardoor twee dingen:
  (a) Was het origineel niet in de browser te tonen (PDF/AI/EPS/PSD), of verliep de gesigneerde URL, dan heeft de UI geen
  `ctx.file`. Ze hergebruikt dan het **oude** `designId`, ook als de klant maat, aantal of tekst veranderde (`flows.js:76`). `/cart`
  gebruikt de payload op de server, dus de klant bestelt zijn **oude** keuzes. Dat is een prijs- en productiefout.
  (b) Zit het bestand er wel, dan maakt elke wijziging een nieuw ontwerp met een nieuwe ref en een opnieuw geüpload origineel.
  "Mijn ontwerpen" zou dan vol duplicaten staan en de bewaarmail verwijst naar een verouderde versie.
- **Voorstel:** voeg `POST /designs/{id}/revise` toe (multipart: `token`, `design`, `preview`, `original` optioneel; zonder
  `original` blijft het bestaande origineel staan).
  - Status `draft` of `saved` en niet in een order: werk het ontwerp **ter plaatse** bij (payload, preview, `_bps_revision`++,
    `_bps_updated_gmt`). Ref en token blijven gelijk.
  - Status `carted`, `quoted` of `ordered`: maak een **kind** met `_bps_parent_id` en kopieer het origineel naar een nieuwe
    willekeurige naam (zelfde aanpak als de kloon in security-4). Antwoord met het nieuwe `{designId, ref, token}`.
  - Een cartregel van het oude ontwerp wordt bij een update ter plaatse herberekend (`Cart::add_design` vervangt de regel al per design_id).
  - Antwoord: `{designId, ref, token, revision, previewUrl, cloned:bool}`. De UI gebruikt `revise` in plaats van `createDesign` als ze al een `designId` heeft (handoff).
- **Acceptatie:** PHP/e2e: ontwerp (draft, PDF-origineel) → `revise` met qty 25→50 zonder bestand → zelfde `designId`, `_bps_payload.qty = 50`,
  origineel ongewijzigd (zelfde sha1). `/cart` → cartregel met aantal 50. Een `ordered` ontwerp → `revise` → nieuw id, `_bps_parent_id` = oud id,
  proefstatus `none`, en het oude ontwerp blijft ongewijzigd (payload-sha1 vóór = na).

### klant-account-4 · hoog · De deel-link is een volledige sleutel: wie hem krijgt, kan bestellen, mailen en het origineel downloaden
- **Bestanden:** `includes/rest/class-routes.php` (`get_design`, `cart`, `request_quote`, `email_design`), `includes/woo/class-designs.php` (`token_for`, `restore_url`, `signed_original_url`).
- **Probleem:** er bestaat geen aparte deel-link. Klanten sturen dus de herstellink door (naar het bestuur van de club, een collega,
  de ontwerper). Met datzelfde token kan de ontvanger: het ontwerp in de **eigen** mand leggen (`/cart`), een offerte op naam van
  iemand anders doen (`/quotes` zet `_bps_email` van het ontwerp om), tot 3 adressen mailen, het **origineel** downloaden
  (`originalUrl`), en via `/cart` de status en de ordergegevens van het ontwerp overschrijven (security-4). Het token verloopt
  niet en kan niet ingetrokken worden.
- **Voorstel:**
  1. Voeg een apart, alleen-lezen deeltoken toe: `POST /designs/{id}/share` (eigenaar: token of ingelogde eigenaar) →
     `{shareUrl, expiresGmt}`. Bewaar alleen `_bps_share = {hash: sha256(token), created_gmt, expires_gmt, views}`. Het token
     is 128-bit random (`wp_generate_password(22,false)`). `DELETE /designs/{id}/share` trekt de link in.
  2. `GET /shared/{shareToken}` → `{ref, title, previewUrl, summary rows, payload zonder notes/e-mail}`, **zonder** `originalUrl`
     en zonder rechten op cart, quote of mail. Zet `noindex` en `Referrer-Policy: no-referrer`.
  3. `POST /shared/{shareToken}/copy` → een nieuw ontwerp voor de bezoeker (`_bps_parent_id`, origineel gekopieerd, eigenaar =
     ingelogde gebruiker of gast) en een eigen `{designId, token, ref}`. Zo kan een bestuurslid het ontwerp aanpassen of
     bestellen zonder het origineel te raken.
  4. Deelpagina: `?bps_share=<token>` op de studiopagina, of een fragment `#bps-share=…` zoals in security-5. De studio toont
     dan een alleen-lezen weergave met "Maak er een kopie van" (handoff).
  5. Instelling `share.enabled` (standaard aan) en `share.days` (standaard 30).
- **Acceptatie:** PHP-test: deeltoken → `GET /shared` 200 zonder `originalUrl`. `/cart`, `/quotes` en `/email` met het deeltoken → 403
  `bps_forbidden`. `copy` → nieuw id met andere ref, het oude ontwerp is onveranderd (status, `_bps_email`, payload). Na `DELETE /share`
  → 404. Na `share.days` → 410 met "Deze deel-link is verlopen. Vraag de eigenaar om een nieuwe." / "Ce lien de partage a expiré. Demande un nouveau lien au propriétaire."

### klant-account-5 · hoog · Herbestellen met één klik ontbreekt (vanuit Mijn ontwerpen én vanuit een order)
- **Bestanden:** `includes/woo/class-cart.php` (`add_design`, `add_fees`), `includes/woo/class-order.php`, `includes/woo/class-designs.php`, nieuw `includes/account/class-reorder.php`.
- **Probleem:** naast de kapotte Woo-knop (woo-compat-3) is er geen server-API om een besteld ontwerp opnieuw in de mand te
  leggen met een ander aantal. De eenmalige kosten (borduurkaart, instelkosten) worden opnieuw aangerekend, terwijl ze bij
  hetzelfde ontwerp niet opnieuw nodig zijn, en het datamodel kan "herbestelling van PS-…" niet vastleggen. Na `orderYears`
  zijn preview en `_bps_preview_url` gewist (`Cleanup::expire_ordered`), en dan kan zelfs Mijn account geen beeld meer tonen.
- **Voorstel:**
  1. `POST /designs/{id}/reorder` met `{qty?, rightsConsent}` en als autorisatie: ingelogde eigenaar van het ontwerp of van een order met dit ontwerp, óf `token`.
     - Kloon het ontwerp (security-4), met `_bps_reorder_of = <ref van het origineel>` en `_bps_parent_id`.
     - Zijn de bestanden weg: bouw de kloon uit de `_bps_payload`-snapshot van het order-item. De preview komt uit de bewaarde
       kleine versie (zie klant-account-8).
     - Leg in de mand met `bps.reorder_of` en geef `{ok, cartUrl, cartCount, quote, designId, ref, token}` terug.
  2. `Cart::add_fees` slaat de fee-groep over voor regels met `reorder_of` zolang `fees.waiveOnReorder` aan staat (standaard aan) en de
     techniek, maat, vorm en tekst gelijk bleven (vergelijk een hash van de productiebepalende velden, `_bps_prod_hash`).
     Verandert er iets productiebepalends, dan geldt de normale fee en meldt de server dat eerlijk in `quote.notes`.
  3. Ordermeta `bps_reorder_of` (zichtbaar: "Herbestelling van PS-2026-0007") zodat het atelier de bestaande borduurkaart pakt.
  4. Knop "Opnieuw bestellen" in Mijn account › Bestellingen › order (`woocommerce_order_details_after_order_table`) en op de ontwerpkaart.
- **Acceptatie:** e2e: voltooide order (ontwerp X, 25 st.) → `POST /designs/X/reorder {qty:50}` als de eigenaar → mand met 1 regel
  van 50 st., nieuwe ref, `bps_reorder_of = ref X`, **zonder** de fee "Borduurkaart en instelkosten", en prijs = `Pricing::quote` met
  `feesAlreadyCharged`. Een andere ingelogde klant → 404. Na `Cleanup::expire_ordered(X)` werkt de herbestelling nog steeds en toont de mand een miniatuur.

### klant-account-6 · hoog · Een offerte heeft geen levenscyclus: de klant kan een offerte niet zelf aanvaarden en omzetten naar een bestelling
- **Bestanden:** `includes/woo/class-quotes.php`, `templates/emails/quote-customer.php`, `includes/woo/class-order.php`.
- **Probleem:** `bps_quote` bewaart alleen de aanvraag. Er is geen status (nieuw / in behandeling / offerte verstuurd / aanvaard /
  verlopen / omgezet), geen aangeboden prijs, geen geldigheidsdatum en geen link voor de klant. De klantmail bevat alleen een
  preview, zonder link. De winkel moet dus manueel mailen en manueel een order maken, en de klant ziet zijn offertes niet in
  Mijn account. In premium offerte-plugins (YITH Request a Quote, Addify) is "offerte aanvaarden → betalen" de kern.
  (Voor de admin-knop zie woo-compat-11. Deze bevinding legt het datamodel en de klantkant vast.)
- **Voorstel (datamodel en API):**
  - Meta op `bps_quote`: `_bps_quote_status` ∈ {`new`,`in_review`,`offered`,`accepted`,`declined`,`expired`,`converted`},
    `_bps_offer = {version, unit_excl, qty, fees:[{id,label,excl}], total_excl, vat_mode, valid_until, note_nl, note_fr, sent_gmt}`,
    `_bps_order_id`, `post_author` = klant-id (als ingelogd of later geclaimd), `_bps_accept_ver` (tokenversie).
  - Admin "Offerte versturen": e-mail `BPS_Email_Quote_Offer` met de prijs, de geldigheid en de knop **"Offerte bekijken en aanvaarden"** →
    `GET /?bps_quote={id}&t={hmac(quote|id|version|accept_ver)}`. Volg het PRG-patroon uit security-5: het token gaat niet in de
    URL na een redirect.
  - `POST /quotes/{id}/accept` met `{t, rightsConsent}`:
    - geldig en niet verlopen → maak een order (status `pending`, via `wc_create_order`, regel met dezelfde meta als `Order::line_item_meta`,
      prijs = `_bps_offer`, niet herberekend) en een ontwerpkloon;
    - antwoord `{orderId, payUrl: $order->get_checkout_payment_url()}`;
    - quote → `converted`, ontwerp → `ordered` zodra de betaling start (`mark_ordered`).
  - Een verlopen offerte geeft 410 met "Deze offerte is verlopen. Vraag gerust een nieuwe prijs, we helpen je snel verder." / "Ce devis a expiré. Demande un nouveau prix, nous t'aidons rapidement."
  - Mijn account-endpoint `bps-quotes` ("Mijn offertes" / "Mes devis") met status, prijs, geldig tot en de knop Aanvaarden.
  - Action Scheduler zet `offered` → `expired` op `valid_until`. Een eerlijke herinnering 3 dagen vóór het verlopen mag alleen met dezelfde toestemmingsregels als klant-account-1.
- **Acceptatie:** PHP/e2e: aanvraag → admin stuurt een offerte (unit 3,20, 100 st., geldig 14 dagen) → mail met acceptlink →
  `accept` → order `pending` met 1 patchregel, `bps_ref` = kloon-ref, totaal = offertebedrag + btw, `payUrl` 200 →
  betalen met cheque → ontwerp `ordered`, quote `converted`. Tweede `accept` → 409 `bps_already_converted`. Na `valid_until` → 410.
  Een andere ingelogde klant ziet de offerte niet in `bps-quotes`.

### klant-account-7 · hoog · Gastgebruikers: ontwerpen en offertes verhuizen niet mee naar een account, en een gast heeft geen overzicht
- **Bestanden:** `includes/woo/class-designs.php`, `includes/woo/class-quotes.php` (beide `post_author => 0`), `includes/woo/class-order.php` (`mark_ordered`). Er zijn geen hooks op `woocommerce_created_customer`, `user_register` of `wp_login`.
- **Probleem:** de meeste patchklanten beginnen als gast (gastcheckout staat aan, accountaanmaak bij de kassa niet). Maakt een gast
  later een account aan of logt hij in, dan blijven zijn ontwerpen, offertes en geordende ontwerpen anoniem. Woo koppelt oude
  orders zelf (`wc_update_new_customer_past_orders`), wij niet. Ontwerpen blind koppelen op e-mailadres is gevaarlijk: wie
  een account aanmaakt met andermans adres, zou dan andermans ontwerpen zien.
- **Voorstel:**
  1. **Veilig claimen, zonder e-mailraden:**
     - (a) Bij `woocommerce_checkout_order_processed` en de Store API: is de koper ingelogd, dan krijgen de ontwerpen in die
       order `post_author` = de klant.
     - (b) Bij login of registratie: ontwerpen in de huidige Woo-sessie (cartregels) en het laatst gebruikte ontwerp-token van de
       studio (handoff: de UI stuurt `POST /account/claim {designId, token}` na het inloggen) krijgen de klant als eigenaar.
       Bezit van het token is genoeg bewijs.
     - (c) Op e-mailadres alleen nadat Woo het adres geverifieerd heeft (de klant betaalde een order met dat adres als ingelogde
       klant), of via een bevestigingsmail "Wil je deze 3 ontwerpen aan je account koppelen?" met een eenmalige link.
  2. **Overzicht voor gasten via magic link:** `POST /designs/lookup {email}` antwoordt **altijd** `{ok:true}`, zodat niemand kan
     raden welk adres bestaat, en heeft een strenge rate-limit. Bestaan er bewaarde of geordende ontwerpen voor dat adres, dan
     vertrekt één mail met een lijst en per ontwerp een herstellink (12 u geldig, eenmalig). Zo is "geen account nodig" (de belofte in de studio) ook voor terugkerende klanten waar.
  3. Offertes: `post_author` zetten bij een ingelogde aanvraag. Ze worden op dezelfde manier geclaimd als ontwerpen.
- **Acceptatie:** e2e: gast bewaart ontwerp A en bestelt ontwerp B → registreert → `POST /account/claim` voor A → Mijn ontwerpen toont A
  en, na een ingelogde betaalde order, ook de nieuwe ontwerpen. Een klant die zich registreert met het e-mailadres van een ander ziet
  **0** ontwerpen tot hij de bevestigingslink opent. `lookup` met een onbekend adres → 200 en 0 mails. Met een bekend adres → 200 en
  1 mail. Het 6e verzoek binnen het uur → 429.

### klant-account-8 · middel · De bewaartermijnen botsen met de accountfuncties
- **Bestanden:** `includes/woo/class-cleanup.php`, `config/defaults.json` (`retention`), `includes/woo/class-privacy.php` (`policy_content`).
- **Probleem:** cleanup wist `saved` na 90 dagen zonder opening, `draft` na 30 dagen en de preview van `ordered` na 3 jaar
  (`delete_files($id, false)`). Voor "Mijn ontwerpen" en herbestellen betekent dat: een club die jaarlijks bestelt, vindt zijn
  bewaarde ontwerp niet meer terug, en na 3 jaar staat er een lege kaart zonder beeld. Kiest de eigenaar langere termijnen, dan
  houdt hij ook de gastbestanden langer bij, wat slecht is voor privacy.
- **Voorstel:** maak de termijnen afhankelijk van het account.
  - Nieuwe instellingen:
    - `retention.accountSavedDays`: standaard 730. 0 betekent "zolang het account bestaat".
    - `retention.keepThumbOnExpiry`: standaard aan. Bij het verlopen van een besteld ontwerp blijft een kleine webp van 300 px
      zonder metadata staan, terwijl het origineel en de grote preview gewist worden.
  - Cleanup filtert gast-ontwerpen op `post_author = 0`. Ontwerpen met een eigenaar volgen `accountSavedDays`, en een ontwerp
    dat de klant "verwijdert" (DELETE in Mijn account) gaat binnen 24 u weg.
  - Pas de privacytekst automatisch aan.
- **Acceptatie:** PHP-test met een vaste klok:
  - gast-`saved`, 91 dagen niet geopend → gewist;
  - account-`saved`, 91 dagen → blijft; 731 dagen → gewist;
  - `ordered` na `orderYears` → origineel weg, `_bps_thumb` blijft (≤ 30 kB) en Mijn ontwerpen toont het beeld;
  - DELETE door de klant → de volgende cleanup-run wist het ontwerp (als het niet besteld is).

### klant-account-9 · middel · Het statusmodel is één rij met rangorde en overschrijft de geschiedenis
- **Bestanden:** `includes/woo/class-designs.php` (`STATUSES`, `RANK`, `set_status`), `includes/woo/class-order.php` (`mark_ordered`), `includes/woo/class-quotes.php`.
- **Probleem:** `_bps_status` is één waarde die nooit terug kan zakken. Er is geen plaats voor: bewaard én besteld, twee keer besteld
  (de tweede `mark_ordered` overschrijft `_bps_order_id`), offerte én bewaard, door de klant verborgen, of herbestelling van. Voor
  Mijn ontwerpen ("2× besteld, laatst op …"), herinneringen ("al besteld? dan niet mailen") en de privacy (welke order bewaart dit
  ontwerp?) is die geschiedenis nodig.
- **Voorstel:** houd `_bps_status` voor compatibiliteit en vul aan met:
  - `_bps_orders` = lijst `{order_id, item_id, at}`, waaraan alleen toegevoegd wordt;
  - `_bps_quote_ids` = lijst;
  - `_bps_saved_gmt`, `_bps_hidden_gmt`, `_bps_updated_gmt`, `_bps_revision`, `_bps_parent_id`, `_bps_reorder_of`, `_bps_prod_hash`;
  - een publieke helper `Designs::history(int $id): array`.
  Geen eigen tabel nodig: met `post_author` + `_bps_status` is de lijstquery snel genoeg. Na kloon/kind uit security-4 hoort elk
  ontwerp bij hoogstens één order. `_bps_orders` blijft dan nuttig voor oude data en voor de migratie (`bps_db_version` ophogen, migratie in batches via Action Scheduler).
- **Acceptatie:** PHP-test: ontwerp bewaard → besteld (order A) → (oude data zonder kloon) besteld (order B) → `history()` geeft
  `saved_gmt` + 2 orders in volgorde. De migratie op een DB met 1.000 ontwerpen eindigt in batches van 100 zonder timeouts, en
  `_bps_orders` is gevuld uit de bestaande `_bps_order_id`.

### klant-account-10 · middel · Een ingelogde klant moet zijn e-mailadres typen om te bewaren, en kan niets beheren
- **Bestanden:** `includes/rest/class-routes.php` (`email_design` eist `email`), `templates/emails/save-design.php`.
- **Probleem:** "Bewaar" bestaat alleen als "Bewaar & mail". Een ingelogde klant moet zijn adres opnieuw typen en krijgt een mail
  terwijl hij gewoon in zijn account wil bewaren. Hij kan ook geen naam geven ("Clubpatch seizoen 2027"), niet verwijderen
  (AVG-zelfbediening) en niet zien welke versie de laatste is. De bewaarmail verwijst nooit naar Mijn account.
- **Voorstel:** `POST /designs/{id}/save` met `{token, title?, email?}`:
  - ingelogd → eigenaar = de klant, status `saved`, `_bps_title`, **geen** mail tenzij `email` meegegeven wordt;
  - gast → zelfde gedrag als nu (mail verplicht).
  Titel ≤ 80 tekens via `sanitize_text_field`. De bewaarmail krijgt voor accountklanten een tweede link "Al je ontwerpen in Mijn
  account" / "Tous tes designs dans Mon compte". `PATCH` en `DELETE /account/designs/{id}` voor naam en verwijderen. Een besteld
  ontwerp wordt alleen verborgen (`_bps_hidden_gmt`), niet gewist, omdat de order het nodig heeft.
- **Acceptatie:** REST-test: ingelogd `save {title:"Club 2027"}` zonder email → 200, `post_author` = klant, `_bps_title` gezet, 0 mails.
  Als gast zonder email → 400 `bps_invalid`. DELETE op een besteld ontwerp → 200, verdwijnt uit de lijst, order-item en fiche tonen het nog.

### klant-account-11 · middel · De accountroutes hebben een echt toegangsmodel nodig, niet de cookie-truc van `/cart`
- **Bestanden:** `includes/rest/class-routes.php` (`restore_logged_in_user`), `includes/rest/class-guard.php`.
- **Probleem:** REST draait zonder `X-WP-Nonce` als gebruiker 0. Voor `/cart` lost de code dat op door de gebruiker uit de
  `logged_in`-cookie te herstellen, maar alleen voor die route. Neem je dat patroon over voor accountdata (lijst, verwijderen,
  claimen), dan hangt de bescherming tegen CSRF uitsluitend af van de eigen header (security-8 noemt dat al fragiel). Voor
  persoonsgegevens is dat te weinig, en er is geen eigenaarscontrole die elke route op dezelfde manier toepast.
- **Voorstel:**
  - `Guard::account()` als `permission_callback`: vereist `is_user_logged_in()` via de standaard `X-WP-Nonce` (`wp_rest`) én
    `X-BPS-Token`. Geef de nonce mee in de publieke config, alleen voor ingelogde bezoekers en buiten de paginacache: WP Rocket
    cachet geen ingelogde pagina's, dus dat kan.
  - `Guard::owns_design( $user_id, $design_id )`: eigenaar als `post_author` = gebruiker, of als het ontwerp in een order van die klant zit (`wc_get_orders(['customer_id'=>…])` → item-meta).
  - Elke `/account/*`-route geeft 404 bij een vreemd id, schrijft een audit-notitie (`_bps_events`) bij delen, verwijderen en claimen,
    en valt onder de rate-limit (`woo.rateLimits.account`).
- **Acceptatie:** REST-tests:
  - zonder nonce → 401 `rest_not_logged_in`;
  - met nonce van gebruiker A op een ontwerp van B → 404;
  - cross-site POST zonder headers → 401/403;
  - `GET /account/designs` antwoordt met `Cache-Control: no-store, private`.

### klant-account-12 · middel · Privacy kent alleen het e-mailadres; accountkoppeling en accountverwijdering ontbreken
- **Bestanden:** `includes/woo/class-privacy.php` (`design_ids`, `quote_ids`), `includes/woo/class-designs.php` (`delete_with_user => false`).
- **Probleem:** de exporter en eraser zoeken op `_bps_email` en op orders met dat factuuradres. Een ontwerp dat alleen aan een account
  hangt (bewaard zonder mail, klant-account-10), of waarvan de klant een ander e-mailadres heeft dan zijn account, valt erbuiten.
  Er is geen `delete_user`-hook: verwijdert de klant zijn account, dan blijven zijn ontwerpen en bestanden anoniem staan tot de cleanup (of
  voor altijd als ze `ordered` zijn).
- **Voorstel:**
  - Exporter en eraser: haal ook `get_user_by('email')` → `post_author` op.
  - `delete_user`/`wpmu_delete_user`:
    - niet bestelde ontwerpen van die gebruiker → `wp_delete_post` (bestanden mee);
    - bestelde ontwerpen → `post_author = 0`, plus anonimiseren volgens security-9 punt 4.
  - Neem `_bps_reminder`, `_bps_share` en `_bps_title` mee in de export (groep "Patch Studio-ontwerpen").
- **Acceptatie:** PHP-test: gebruiker met 2 bewaarde ontwerpen zonder `_bps_email` → de export bevat beide. `wp user delete` → 0
  ontwerpen met die `post_author`, bewaarde ontwerpen en hun bestanden weg, bestelde ontwerpen anoniem (`post_author = 0`) en nog gekoppeld aan de order.

### klant-account-13 · middel · Geen automatische tests voor bewaren, herstellen, mailen en accountflows
- **Bestanden:** `tests/` (alleen e2e 01–08; geen PHP-integratietests buiten Pricing, zie ook php-kwaliteit-11).
- **Probleem:** precies de flows die klanten terugbrengen, hebben geen test: bewaarmail, herstellink, herinnering, (toekomstig) Mijn
  ontwerpen, delen, herbestellen en offerte aanvaarden. Bevinding klant-account-1 (vinkje zonder effect) en de stille fout van
  klant-account-3a zouden met één test opgevallen zijn.
- **Voorstel:**
  - Een REST-integratiesuite op de testsite (PHP via `wp eval-file` of Node+fetch) met fixtures (gast, klant A, klant B), die de
    acceptatiecriteria van klant-account-1 t/m -12 afdekt.
  - E2e `09-account.e2e.cjs`: inloggen → bewaren → Mijn ontwerpen → delen → kopie → herbestellen.
  - Opnemen in `tests/run-all.sh`. Gebruik een vaste klok (filter `bps_now`) voor de termijnen.
- **Acceptatie:** `bash tests/run-all.sh` draait ≥ 30 nieuwe checks voor deze functies, allemaal groen. Het verwijderen van de
  `reminder`-afhandeling of van de eigenaarscontrole laat minstens één test falen (mutatiecontrole met de hand).

### klant-account-14 · laag · Instellingen, admin-zicht en schakelaars voor de accountfuncties ontbreken
- **Bestanden:** `config/defaults.json`, `includes/class-settings.php`, `includes/admin/class-settings-page.php`, `includes/woo/class-config.php` (`public_config`).
- **Probleem:** een betaalde plugin laat de winkel kiezen wat aan staat. Daarnaast wil de winkelier bij een klant (gebruikersprofiel,
  order) zijn ontwerpen en offertes zien. Daar is nu niets voor.
- **Voorstel:**
  - Nieuwe sleutels, standaard veilig:
    - `account.enabled` (aan), `account.endpointSlug` (`bps-designs`), `account.quotesEndpoint` (aan);
    - `share.enabled` (aan), `share.days` (30);
    - `reminder.enabled` (**uit**), `reminder.delayDays` (3), `reminder.cooldownDays` (30);
    - `reorder.enabled` (aan), `fees.waiveOnReorder` (aan);
    - `retention.accountSavedDays` (730), `retention.keepThumbOnExpiry` (aan).
  - In `public_config` alleen de vlaggen: `features.account`, `features.share`, `features.reminder`, `features.reorder` en `account.url`.
  - Tab "Klanten" in de beheerpagina met deze velden en uitleg in gewone taal.
  - Een metabox "Patch-ontwerpen van deze klant" op `user-edit.php` en in de order-zijbalk.
  - Flush de rewrite rules bij het wijzigen van de slug.
- **Acceptatie:** met `account.enabled=false` geven `/mijn-account/bps-designs/` en `/account/*` 404 en zijn de menu-items weg.
  `features.reminder` staat standaard op `false`. De slug wijzigen naar `ontwerpen` werkt zonder handmatige flush. Het profiel van een klant toont
  zijn ontwerpen met een link naar de order.

---

## Vastgelegd: API-endpoints (namespace `patch-studio/v1`)

Gemeenschappelijk: JSON, `Cache-Control: no-store`, Vlaamse/Franse foutboodschappen zoals in SPEC §6.2. `T` = ontwerptoken
(liefst als header `X-BPS-Design-Token`, security-5). `A` = ingelogd + `X-WP-Nonce` + eigenaar (`Guard::account`). `S` = deeltoken.
Alle schrijfroutes houden `X-BPS-Token` + rate-limit.

| Methode + pad | Auth | Body | Antwoord | Fouten | Bevinding |
|---|---|---|---|---|---|
| `POST /designs` (bestaand) | public_write | + koppelt `post_author` als ingelogd | ongewijzigd | | 2 |
| `POST /designs/{id}/revise` | T of A | multipart `design`, `preview`, `original?` | `{designId, ref, token, revision, previewUrl, cloned}` | 403, 409 `bps_locked` (nooit; kloont), 413/415 | 3 |
| `POST /designs/{id}/save` | T of A | `{title?, email?, reminder?, locale}` | `{ok, saved:true, mailed:bool}` | 400 (gast zonder email) | 10, 1 |
| `POST /designs/{id}/email` (bestaand) | T | `{email, reminder, locale}`; `reminder` wordt nu echt verwerkt | `{ok, reminderScheduled:bool}` | 400, 429 | 1 |
| `GET /reminder/unsubscribe?d=&s=` | HMAC | — | HTML-bevestiging (noindex) + 1-klik POST | 410 | 1 |
| `POST /designs/{id}/share` | T of A | `{days?}` | `{shareUrl, expiresGmt}` | 403 | 4 |
| `DELETE /designs/{id}/share` | T of A | — | `{ok}` | | 4 |
| `GET /shared/{s}` | S | — | `{ref, title, previewUrl, rows, payload (zonder notes)}` | 404, 410 | 4 |
| `POST /shared/{s}/copy` | S + public_write | — | `{designId, ref, token}` (201) | 404, 410 | 4 |
| `POST /designs/{id}/reorder` | A of T | `{qty?, rightsConsent}` | `{ok, cartUrl, cartCount, quote, designId, ref, token, reorderOf}` | 404, 409 `bps_quote_only`, 400 `bps_min_qty` | 5 |
| `GET /account/designs` | A | `?page&per_page≤50&status&search` | `{items:[DesignCard], total, pages}` | 401 | 2 |
| `GET /account/designs/{id}` | A | — | zoals `GET /designs/{id}` + `token`, `title`, `history` | 404 | 2, 9 |
| `PATCH /account/designs/{id}` | A | `{title}` | `{ok}` | 404 | 10 |
| `DELETE /account/designs/{id}` | A | — | `{ok, hidden:bool}` | 404 | 10 |
| `POST /account/claim` | A | `{designId, token}` (of meerdere, max 20) | `{claimed:[ids]}` | 403 | 7 |
| `POST /designs/lookup` | public_write | `{email, locale}` | altijd `{ok:true}` | 429 | 7 |
| `GET /account/quotes` | A | — | `{items:[{quoteId, ref, status, offer, validUntil, previewUrl}]}` | 401 | 6 |
| `POST /quotes/{id}/accept` | quote-HMAC of A | `{t?, rightsConsent}` | `{orderId, payUrl}` | 409 `bps_already_converted`, 410 `bps_quote_expired` | 6 |

`DesignCard = {designId, ref, title, status, previewUrl, thumbUrl, technique, techniqueLabel, sizeCm, widthMm, heightMm, qty,
updatedGmt, savedGmt, orders:[{orderId, number, date, viewUrl}], reorderable, shareActive, revision}`.

## Vastgelegd: datamodel

**`bps_design` (CPT, post meta; uitbreiding van SPEC §6.1)**

| Veld | Type | Doel |
|---|---|---|
| `post_author` | int (kolom, geïndexeerd) | eigenaar (0 = gast) |
| `_bps_title` | string ≤ 80 | naam die de klant geeft |
| `_bps_revision`, `_bps_updated_gmt` | int, datetime | bijwerken ter plaatse |
| `_bps_parent_id` | int | kloon, kind of kopie van |
| `_bps_reorder_of` | string (ref) | herbestelling |
| `_bps_prod_hash` | sha1 | productiebepalende velden (fee-vrijstelling bij herbestelling) |
| `_bps_orders` | list `{order_id,item_id,at}` | geschiedenis (`_bps_order_id` blijft = laatste, voor compatibiliteit) |
| `_bps_quote_ids` | list int | geschiedenis van offertes |
| `_bps_saved_gmt`, `_bps_hidden_gmt` | datetime | bewaard, verborgen door de klant |
| `_bps_share` | `{hash, created_gmt, expires_gmt, views}` | deel-link (alleen de hash) |
| `_bps_token_ver` | int | intrekken van herstellinks (security-5) |
| `_bps_reminder` | `{email_hash, consent_v, consent_text_sha1, consent_locale, consent_at, due_gmt, sent_gmt, cancelled}` | herinnering + bewijs van toestemming |
| `_bps_thumb` | `{path,url}` webp 300 px | blijft na het verlopen van de bestanden |
| `_bps_events` | list `{at, what, by}` (max 50) | audit: gedeeld, geclaimd, verwijderd, herinnering |

**`bps_quote` (aanvulling)**: `post_author`, `_bps_quote_status`, `_bps_offer{…}`, `_bps_accept_ver`, `_bps_order_id`, `_bps_valid_until`.

**Order-item (aanvulling)**: zichtbare `bps_reorder_of` en `bps_quote_ref`, verborgen `_bps_prod_hash`.

**Geplande acties (Action Scheduler, groep `patch-studio`)**: `bps_design_reminder[id]`, `bps_quote_expire[id]`, `bps_migrate_history[batch]`.

## Prioriteit voor de bouw
1. klant-account-1 (eerlijkheid: het vinkje doet nu niets). Snelle tussenoplossing tot de functie af is: `features.reminder=false` en het vinkje verbergen.
2. -3 (foute bestelling na herstellen), daarna -2, -11 en -7 (de basis van het account).
3. -5 en -6 (omzet: herbestellen en offerte → order), -4 (delen).
4. -8, -9, -10, -12, -13 en -14.
