# 08 — Juridisch, privacy, btw, toegankelijkheid en vertaling (NL/FR)

Onderzoeker: "juridisch-i18n-a11y" · 16-11-2026 · voor de bouwers van `plugin/borduur-patch-studio`.

> **Status:** praktisch onderzoek, geen juridisch advies. De wettelijke kern (herroeping, prijs incl. btw, GDPR, knoptekst)
> is stabiel en goed gedocumenteerd. Punten die de eigenaar of een boekhouder/jurist moet bevestigen staan als **[EIGENAAR]** of **[NAKIJKEN]**.
> Franse teksten zijn door mij geschreven in Belgisch-zakelijk Frans ("vous"). Laat ze vóór livegang nalezen door een moedertaalspreker **[EIGENAAR]**.

Wat ik live heb gecontroleerd (curl, 16-11-2026):
- **Algemene voorwaarden** (`/algemene-voorwaarden/`, "Laatst bijgewerkt: juni 2025"): art. 2 zegt al dat gepersonaliseerde producten "niet onder het herroepingsrecht (zie artikel VI.53, Wetboek van Economisch Recht)" vallen
  en "niet geannuleerd, geruild of terugbetaald worden zodra de productie gestart is". Art. 6: "Alle prijzen zijn inclusief btw". Art. 7: "Kleine afwijkingen in positie, kleur of afmetingen … zijn mogelijk".
  **Er staat niets in over intellectuele eigendom van aangeleverde logo's** → zie §3 (voorstel AV-artikel).
- **Privacybeleid** (`/privacy-policy/`, "Laatst bijgewerkt: 13 november 2026"): verwerkingsverantwoordelijke **IMTA Technics BV (Borduurservice België), Boomgaardstraat 22, 2920 Kalmthout, BE 1012.263.492**.
  Noemt al "uw ontwerpen en bestanden" als gegeven bij offertes/bestellingen, en bewaartermijnen: facturen 7 jaar, "Offertes en klantgegevens: zolang de klantrelatie loopt, en daarna maximaal 3 jaar".
  Klachten: Gegevensbeschermingsautoriteit (GBA). Het beleid gebruikt "u"; de plugin gebruikt "je" (zie BRIEF). Het e-mailadres is door Cloudflare versleuteld in de HTML: gebruik in de plugin een **instelling** (standaard `get_option('admin_email')`), niet hardcoden.
- Cookiebanner: **CookieYes / cookie-law-info** is actief.
- `<html lang="nl-BE">`, geen Franse versie, geen WPML/Polylang actief.

---

## 0. TL;DR — 14 regels voor de bouwers

1. **Maatwerk = geen herroepingsrecht (art. VI.53, 3° WER)**, maar je **moet het vóór de bestelling duidelijk melden** (art. VI.45 §1 WER / art. 6(1)(k) Richtlijn 2011/83). Toon het op 4 plekken: configurator (bij de knop), winkelmandregel, kassa (vlak boven de betaalknop), bevestigingsmail. Microcopy §1.4.
2. Geen verplichte "ik verzaak aan mijn herroepingsrecht"-checkbox nodig: de uitzondering geldt van rechtswege. Een **informatieregel** volstaat. Wettelijke garantie (2 jaar, conformiteit) blijft wél gelden: zeg dat ook.
3. **Knoptekst in de kassa**: WooCommerce zegt "Bestelling plaatsen". Zet die via `woocommerce_order_button_text` op **"Bestellen en betalen"** / **"Commander et payer"** (knopvereiste art. 8(2) Richtlijn 2011/83, HvJ C-249/21). Onze configuratorknop voegt alleen toe aan de winkelmand en mag dus "Bestel deze patch" heten, maar mag niet suggereren dat de bestelling al geplaatst is.
4. **Rechten-checkbox** (auteursrecht/merk) is verplicht aan te vinken **vóór "In winkelmand"/"Offerte aanvragen"**, niet vóór de analyse (geen drempel bij het proberen). Nooit vooraf aangevinkt. Bewaar tekstversie + tijdstip in de ordermeta. Microcopy §3.2.
5. **Privacyzin naast de upload** (art. 13 GDPR, gelaagde info) met link naar `/privacy-policy/`. Kies de variant die klopt met de echte architectuur (analyse lokaal vs. meteen uploaden), §2.4. Beloof niets wat de code niet doet.
6. **Bewaartermijnen** (instelbaar): uploads zonder bestelling **30 dagen**; opgeslagen ontwerp-links **90 dagen na laatste opening**; bestanden van offertes zonder bestelling **12 maanden**; bestanden van bestellingen **3 jaar na de laatste bestelling** (herbestellen). WP-Cron ruimt op. §2.3.
7. Haak in op de **WordPress-privacytools**: `wp_add_privacy_policy_content`, `wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers`. §2.6.
8. **Prijzen voor consumenten altijd incl. 21 % btw** als hoofdbedrag (art. VI.4 WER, AV art. 6). Excl. btw mag als tweede, kleiner bedrag of via een schakelaar "Toon prijzen excl. btw" voor bedrijven. Nooit alleen excl. §4.
9. **Geen trackers in de plugin** zonder toestemming via CookieYes. Lokaal bewaren van een ontwerp (localStorage) alleen na een klik van de klant ("Bewaar op dit toestel"); standaard `sessionStorage`. §2.7.
10. **Toegankelijkheid: bouw volgens WCAG 2.2 AA**. EAA geldt sinds 28-06-2025 voor e-handel; micro-ondernemingen (< 10 werknemers én ≤ € 2 mio omzet of balans) hebben in België uitstel tot 28-06-2030 **[EIGENAAR: valt IMTA Technics BV daaronder?]**, maar bouw het toch: het is de juiste klant-ervaring en de plugin moet toekomstvast zijn. §5.
11. Kritieke WCAG 2.2-punten voor deze tool: **slepen heeft altijd een alternatief** (2.5.7), **doelen ≥ 24×24 px** (2.5.8, wij nemen 44 px), **focus niet verborgen achter een sticky prijsbalk** (2.4.11), oordeel als **tekst in de DOM** (niet enkel op canvas), `role="status"` voor het analyseresultaat. §5.3.
12. **Merkkleuren en contrast**: `#5926C5` op wit = 8,37:1 (prima voor tekst en knoppen). `#FD5C48` op wit = 3,09:1 → **niet voor tekst**; koraal enkel met donkere tekst `#1E1B1B` erop (5,54:1) of als decor. `#959595` op wit = 3,0:1 → **niet voor broodtekst**; gebruik `#6B6B6B` (5,33:1). Invoerranden `#BDBDBD` (1,88:1) falen 1.4.11 → `#8A8A8A` of donkerder. §5.4.
13. **i18n**: text domain `borduur-patch-studio`, bronstrings in **Vlaams Nederlands** (veilige terugval voor deze site), vertalingen `fr_BE` (+ kopie `fr_FR`). Alle JS-teksten via één string-tabel die PHP met `__()` vult (JSON in de widget-HTML), of via `wp_set_script_translations` met handle-gebaseerde JSON. §6.
14. **WPML/Polylang**: lever een `wpml-config.xml` mee (beide lezen dat), vertaal product-ID's via `wpml_object_id` / `pll_get_post`, geef de taal expliciet mee aan onze REST-calls, en verstuur mails in de taal van de klant met `switch_to_locale()`. §7.

---

## 1. Belgisch consumentenrecht: maatwerk en herroepingsrecht

### 1.1 De regel
- **Herroepingsrecht** bij verkoop op afstand: 14 dagen (art. VI.47 WER).
- **Uitzondering** art. VI.53, 3° WER: geen herroepingsrecht voor "de levering van volgens specificaties van de consument vervaardigde goederen, of die duidelijk voor een specifieke persoon bestemd zijn".
  Een patch naar een eigen logo/ontwerp/tekst is daar het schoolvoorbeeld van. Bronnen [1][2][3].
- **Grens**: kiezen uit een paar standaardopties (maat, kleur van een standaardartikel) is géén maatwerk. Elke patch die de Patch Studio maakt
  (eigen upload of eigen tekst) is wél maatwerk. Verkoopt de winkel ook kant-en-klare patches (zonder eigen ontwerp), dan geldt daar het gewone herroepingsrecht (AV art. 4 regelt dat al).
- **Informatieplicht**: vóór de consument gebonden is, moet je meedelen dat er geen herroepingsrecht is (art. VI.45 §1 WER, omzetting van art. 6(1)(k) Richtlijn 2011/83/EU [4]).
  "In de algemene voorwaarden" alleen is zwak: de Europese Commissie vraagt dat de info "duidelijk en begrijpelijk" en vóór de bestelling gegeven wordt [5]. Daarom tonen we het in de flow zelf.
- **Wat blijft gelden**: de wettelijke garantie (conformiteit, 2 jaar voor consumenten). Een patch die afwijkt van de goedgekeurde proef is niet conform. Dat eerlijk zeggen versterkt het vertrouwen.
- **Annulatie vóór productie** is geen wettelijke plicht maar een commerciële keuze. AV art. 2 zegt "zodra de productie gestart is" kan er niet meer geannuleerd worden; impliciet dus wel ervóór.
  **[EIGENAAR]** Bevestig of je "kosteloos annuleren tot je de digitale proef goedkeurt" wilt beloven. Het is een sterk, eerlijk verkoopargument (de proef is de echte beslismoment) en het past bij het verwerken pas na betaling.
  Hieronder staan beide varianten; de plugin krijgt een instelling `bps_cancel_until_proof` (bool, standaard **uit** tot de eigenaar beslist).

### 1.2 Knopvereiste in de kassa
Art. 8(2) Richtlijn 2011/83 (in België art. VI.46 WER): de knop die de bestelling plaatst moet ondubbelzinnig zeggen dat je een betalingsverplichting aangaat ("bestelling met betalingsverplichting" of gelijkwaardig).
Het Hof van Justitie besliste in **C-249/21 (Fuhrmann-2, 2022)** dat **alleen de woorden op de knop zelf** tellen, niet de context [6]. "Bestelling plaatsen" (WooCommerce-standaard NL) zegt dat niet duidelijk.

```php
add_filter( 'woocommerce_order_button_text', function () {
    return __( 'Bestellen en betalen', 'borduur-patch-studio' ); // FR: "Commander et payer"
} );
```
Let op: de site gebruikt de **klassieke** kassa (zie 06-woocommerce-tech.md). In de blok-kassa zit de knoptekst in de blokinstellingen, niet in deze filter. Dit raakt de hele winkel, niet enkel patches → **[EIGENAAR]** akkoord vragen; zet het achter een plugin-instelling (`bps_fix_order_button`, standaard aan).

Onze **configuratorknop** voegt toe aan de winkelmand. Tekstvoorstel: **"Bestel deze patch"** met een kleine subregel **"Je kijkt alles nog na in je winkelmand"**. Zo blijft het eerlijk: er wordt nog niets betaald.

### 1.3 Andere regels die de UI raken
| Regel | Wat het betekent voor de tool | Bron |
|---|---|---|
| Totaalprijs incl. btw en alle kosten aan consumenten (art. VI.4 en VI.45 §1 WER) | Eenmalige kosten (borduurkaart/instelkosten, PVC-mal) en verzending **in de configurator** tonen, niet pas in de kassa. Geen "vanaf"-prijs die nooit geldt. | [7][8] |
| Prijsverminderingen (art. VI.18 WER, Omnibus): bij een aangekondigde korting moet de referentieprijs de laagste prijs van de voorbije 30 dagen zijn | Staffelkortingen (meer stuks = lagere stukprijs) zijn een prijsstructuur, geen tijdelijke korting: gewoon de staffel tonen. Toon **geen** doorstreepte "was"-prijzen tenzij die aan VI.18 voldoen. | [9] |
| Verbod op misleidende praktijken (UCPD, art. VI.97 e.v. WER; Annex I: nep-reviews, valse schaarste, valse aftelklokken) | Geen "nog 2 plaatsen deze week", geen verzonnen klantaantallen of sterren. Alleen echte gegevens van de eigenaar. | [10] |
| Kleurafwijkingen (AV art. 7) | Voorbeeld-disclaimer bij het canvas (§1.4 tekst H). | AV |
| Automatisch advies | Geen wettelijke plicht, wel eerlijk: zeg dat het advies automatisch is en dat een mens het bij de proef nakijkt. | — |

### 1.4 Exacte microcopy (NL "je" / FR "vous")

| # | Plaats | NL | FR |
|---|---|---|---|
| A | Configurator, vlak boven/onder de bestelknop (altijd zichtbaar, 14 px, `#323232`, met icoon "schaar/maatwerk") | **Speciaal voor jou gemaakt.** Je patch wordt op maat geproduceerd. Daarom geldt het herroepingsrecht niet (art. VI.53 WER). Je keurt eerst je digitale proef goed; pas daarna starten we de productie. | **Fabriqué spécialement pour vous.** Votre écusson est produit sur mesure. Le droit de rétractation ne s'applique donc pas (art. VI.53 CDE). Vous validez d'abord votre épreuve numérique ; la production ne démarre qu'après votre accord. |
| A+ | Variant **als** `bps_cancel_until_proof` aan staat (voeg toe als tweede zin) | Tot je de proef goedkeurt, kun je je bestelling kosteloos annuleren. | Tant que vous n'avez pas validé l'épreuve, vous pouvez annuler votre commande sans frais. |
| B | Uitklapper "Wat betekent dat?" onder A | Een patch met jouw logo of tekst kunnen we aan niemand anders verkopen. Daarom kun je hem na productie niet zomaar terugsturen. Klopt de patch niet met de proef die je goedkeurde, of is er een productiefout? Dan lossen we dat op: daarvoor geldt gewoon de wettelijke garantie. | Un écusson à votre logo ou à votre texte ne peut être revendu à personne d'autre. C'est pourquoi il ne peut pas être retourné après production. S'il ne correspond pas à l'épreuve validée ou présente un défaut de fabrication, nous trouvons une solution : la garantie légale s'applique normalement. |
| C | Winkelmandregel (item data, label → waarde) | **Maatwerk** → Geen herroepingsrecht · digitale proef vóór productie | **Sur mesure** → Pas de droit de rétractation · épreuve numérique avant production |
| D | Kassa, `woocommerce_review_order_before_submit` (alleen als er een Patch Studio-item in de mand zit) | Je bestelling bevat maatwerk. Voor op maat gemaakte patches geldt geen herroepingsrecht (art. VI.53 WER). Je ontvangt eerst een digitale proef; de productie start pas na jouw akkoord. | Votre commande contient des articles sur mesure. Les écussons fabriqués sur mesure sont exclus du droit de rétractation (art. VI.53 CDE). Vous recevez d'abord une épreuve numérique ; la production ne démarre qu'après votre accord. |
| E | Kassaknop | Bestellen en betalen | Commander et payer |
| F | Bevestigingsmail klant, onder de bestelde items (`woocommerce_email_after_order_table`, enkel bij Patch Studio-items) | **Wat gebeurt er nu?** Binnen {proef_termijn} krijg je je digitale proef per e-mail. Pas na jouw akkoord starten we de productie. Je patch wordt op maat gemaakt; daarom geldt het herroepingsrecht niet. | **Et maintenant ?** Vous recevrez votre épreuve numérique par e-mail sous {proef_termijn}. La production ne démarre qu'après votre accord. Votre écusson est fabriqué sur mesure ; le droit de rétractation ne s'applique donc pas. |
| G | Offerte-flow (geweven/chenille/…) | Een offerte is vrijblijvend. Je zit pas vast als je de offerte en daarna de digitale proef goedkeurt. | Le devis est sans engagement. Vous ne vous engagez qu'en acceptant le devis, puis l'épreuve numérique. |
| H | Onder het canvas-voorbeeld | Voorbeeld ter illustratie. Garen- en drukkleuren kunnen licht afwijken van je scherm. Je digitale proef toont de definitieve versie. | Aperçu à titre indicatif. Les couleurs de fil et d'impression peuvent légèrement différer de votre écran. Votre épreuve numérique montre la version définitive. |
| I | Bij het advies-paneel | Automatisch advies op basis van je bestand. Onze digitaliseerder kijkt alles na voor je proef. | Conseil automatique basé sur votre fichier. Notre digitaliseur vérifie tout avant votre épreuve. |

`{proef_termijn}` = instelling (bv. "2 werkdagen" / "2 jours ouvrables") **[EIGENAAR]**. Geen termijn tonen als die niet ingevuld is.
Afkorting FR: in Belgisch Frans heet het WER "Code de droit économique" (**CDE**).

**AV-wijziging die ik aanraad [EIGENAAR]**: in AV art. 2 "gepersonaliseerde kleding" vervangen door "gepersonaliseerde producten (kleding, patches, badges …)", want de tekst spreekt nu enkel over kleding.

```php
// Kassa-melding alleen als er maatwerk in de mand zit.
add_action( 'woocommerce_review_order_before_submit', function () {
    if ( ! WC()->cart ) { return; }
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( ! empty( $item['bps_design_id'] ) ) {
            echo '<p class="bps-legal-note" role="note">' . esc_html__( 'Je bestelling bevat maatwerk. …', 'borduur-patch-studio' ) . '</p>';
            return;
        }
    }
} );
```

---

## 2. GDPR voor geüploade logo's en ontwerpen

### 2.1 Is een logo een persoonsgegeven?
Een bedrijfslogo op zich meestal niet. Maar: (1) de upload hangt aan een persoon (sessie, bestelling, e-mail); (2) klanten uploaden foto's met gezichten, namen, rugnummers, clubfoto's, handtekeningen;
(3) bij foto's van een patch kan EXIF-data (GPS, toestel) meekomen. **Behandel elke upload dus als persoonsgegeven.** Rechtsgrond: **uitvoering van de overeenkomst / precontractuele stappen** (art. 6.1.b GDPR) — geen toestemming nodig om het bestand te verwerken voor het advies, de proef en de productie. Bron [11][12].

### 2.2 Technische maatregelen (dataminimalisatie, art. 5 en 25 GDPR)
- **Analyse in de browser** (zoals gepland): het bestand verlaat het toestel niet tot de klant iets doet (winkelmand, offerte, "bewaar mijn ontwerp"). Dat is een echte privacywinst; vermeld het.
- **EXIF strippen**: de publieke preview-PNG wordt via canvas her-gecodeerd (dat verwijdert EXIF). Het **origineel** in de privémap behoudt EXIF voor de digitaliseerder: oké, maar nooit publiek tonen.
- **Privémap** niet bereikbaar via URL (`.htaccess` deny, zie 06 §7), willekeurige bestandsnamen, preview met onraadbare naam.
- Bestanden **nooit** in e-mails als bijlage naar derden; leveranciers (geweven/PVC uitbesteed) krijgen enkel het ontwerp, geen klantgegevens (dat staat al zo in het privacybeleid).
- Logging: geen bestandsnamen van klanten in PHP-errorlogs (bestandsnamen bevatten vaak namen).

### 2.3 Bewaartermijnen (instelbaar in de plugin, standaardwaarden)

| Soort | Standaard | Waarom | Instelling |
|---|---|---|---|
| Upload zonder bestelling/offerte (verlaten winkelmand, sessie) | **30 dagen** na upload | Lang genoeg om een verlaten mand af te maken; daarna geen doel meer | `bps_retention_orphan_days` (7–90) |
| "Bewaar mijn ontwerp"-link (e-mail) | **90 dagen** na laatste opening | De klant vroeg dit zelf; daarna vervalt de link | `bps_retention_saved_days` |
| Offerte zonder bestelling: bestanden | **12 maanden** na laatste contact | Offertes worden vaak later opgevolgd; gegevens zelf volgen het privacybeleid (max. 3 jaar) | `bps_retention_quote_days` |
| Bestelling: originele bestanden + borduurkaart/preview | **3 jaar** na de **laatste** bestelling van dat ontwerp | Herbestellen is een kernbelofte ("we gebruiken die borduurkaart opnieuw"); sluit aan bij privacybeleid "klantgegevens max. 3 jaar" | `bps_retention_order_years` |
| Ordermeta (tekstuele samenvatting, prijs, toestemmings-log) | Zolang de order bestaat (boekhouding 7 jaar) | Bewijs van wat besteld en verklaard werd | — |

**Uitvoering**: dagelijkse WP-Cron `bps_cleanup` (gebruik `wp_schedule_event`, uurlijks niet nodig). Verwijder fysieke bestanden + verwijs-meta; laat in de order een regel "Ontwerpbestand verwijderd op {datum} (bewaartermijn)" staan.
De klant kan altijd vroeger vragen om verwijdering (eraser, §2.6). WP-Cron loopt pas bij bezoek; WP Rocket-cache verhindert dat niet (cron draait via `wp-cron.php`), maar raad de eigenaar een echte server-cron aan **[NAKIJKEN hosting]**.

### 2.4 Privacyzin bij de upload (art. 13 GDPR, gelaagd)
Kort, direct onder de uploadknop (13–14 px, `#6B6B6B`), met link. **Kies de variant die de code waarmaakt:**

| Variant | NL | FR |
|---|---|---|
| **V1** — analyse lokaal, upload pas bij winkelmand/offerte/bewaren (aanbevolen) | Je bestand wordt eerst alleen op je eigen toestel geanalyseerd. Pas als je bestelt, een offerte vraagt of je ontwerp bewaart, sturen we het naar ons om je patch te maken. We bewaren het niet langer dan nodig. [Privacybeleid] | Votre fichier est d'abord analysé uniquement sur votre appareil. Il ne nous est envoyé que si vous commandez, demandez un devis ou enregistrez votre design, afin de fabriquer votre écusson. Nous ne le conservons pas plus longtemps que nécessaire. [Politique de confidentialité] |
| **V2** — upload meteen naar de server | We gebruiken je bestand alleen om je patch te analyseren, te tonen en te maken. Bestel je niet, dan wissen we het automatisch na {30} dagen. [Privacybeleid] | Nous utilisons votre fichier uniquement pour analyser, afficher et fabriquer votre écusson. Sans commande, il est automatiquement supprimé après {30} jours. [Politique de confidentialité] |
| Bij "Bewaar mijn ontwerp" (e-mailveld) | We mailen je één link naar je ontwerp. Geen nieuwsbrief. De link werkt {90} dagen. | Nous vous envoyons un seul lien vers votre design. Pas de newsletter. Le lien reste valable {90} jours. |
| Tip bij foto-upload (optioneel) | Tip: staan er herkenbare personen op je foto? Zorg dat zij akkoord gaan. | Astuce : des personnes reconnaissables figurent sur votre photo ? Assurez-vous qu'elles sont d'accord. |

Geen apart toestemmingsvakje voor de verwerking zelf (rechtsgrond is de overeenkomst; een overbodige toestemming maakt het juridisch zelfs zwakker).
Marketing alleen met een **apart, niet aangevinkt** vakje — en liever helemaal niet in deze flow (het privacybeleid regelt klantmails al via soft opt-in).

### 2.5 Tekst voor het privacybeleid (voor de eigenaar, via `wp_add_privacy_policy_content`)
Het bestaande beleid gebruikt "u"; ik geef de aanvulling in dezelfde "u"-stijl zodat het pagina-breed klopt.

**NL**
> **Patch Studio (patches online ontwerpen)**
> Wanneer u in onze Patch Studio een afbeelding opent, analyseert uw browser die afbeelding op uw eigen toestel. Uw bestand wordt pas naar ons verzonden wanneer u uw patch in de winkelmand legt, een offerte aanvraagt of uw ontwerp bewaart.
> *Gegevens:* uw afbeelding of logo, de tekst en keuzes van uw ontwerp, en — bij bewaren of een offerte — uw e-mailadres.
> *Doel en rechtsgrond:* uw patch adviseren, een digitale proef maken, produceren en eventueel later opnieuw maken (uitvoering van de overeenkomst of stappen op uw verzoek vóór een overeenkomst).
> *Bewaartermijn:* bestanden zonder bestelling wissen wij automatisch na 30 dagen; bewaarde ontwerpen 90 dagen na uw laatste bezoek aan de link; bestanden van een offerte zonder bestelling na 12 maanden; bestanden van een bestelling 3 jaar na uw laatste bestelling van dat ontwerp, zodat u het makkelijk opnieuw kunt bestellen. U kunt altijd vragen om ze eerder te wissen.
> *Verklaring over rechten:* wij bewaren bij uw bestelling uw verklaring dat u het ontwerp mag gebruiken, met datum en tijd.

**FR**
> **Patch Studio (création d'écussons en ligne)**
> Lorsque vous ouvrez une image dans notre Patch Studio, votre navigateur l'analyse sur votre propre appareil. Votre fichier ne nous est transmis que lorsque vous ajoutez votre écusson au panier, demandez un devis ou enregistrez votre design.
> *Données :* votre image ou logo, le texte et les choix de votre design et — en cas d'enregistrement ou de devis — votre adresse e-mail.
> *Finalité et base juridique :* vous conseiller, réaliser une épreuve numérique, produire votre écusson et éventuellement le refaire plus tard (exécution du contrat ou mesures précontractuelles prises à votre demande).
> *Durée de conservation :* les fichiers sans commande sont supprimés automatiquement après 30 jours ; les designs enregistrés 90 jours après votre dernière visite du lien ; les fichiers d'un devis sans commande après 12 mois ; les fichiers d'une commande 3 ans après votre dernière commande de ce design, pour faciliter une nouvelle commande. Vous pouvez toujours demander une suppression anticipée.
> *Déclaration de droits :* nous conservons avec votre commande votre déclaration selon laquelle vous êtes autorisé à utiliser le design, avec la date et l'heure.

### 2.6 WordPress-privacytools (code)
```php
add_action( 'admin_init', function () {
    if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
        wp_add_privacy_policy_content( 'Patch Studio', wp_kses_post( bps_privacy_policy_text() ) );
    }
} );
add_filter( 'wp_privacy_personal_data_exporters', function ( $e ) {
    $e['borduur-patch-studio'] = [ 'exporter_friendly_name' => __( 'Patch Studio-ontwerpen', 'borduur-patch-studio' ), 'callback' => 'bps_export_personal_data' ];
    return $e;
} );
add_filter( 'wp_privacy_personal_data_erasers', function ( $e ) {
    $e['borduur-patch-studio'] = [ 'eraser_friendly_name' => __( 'Patch Studio-ontwerpen', 'borduur-patch-studio' ), 'callback' => 'bps_erase_personal_data' ];
    return $e;
} );
// Callbacks: zoek ontwerpen/offertes op e-mailadres (ook gast-orders via wc_get_orders( [ 'billing_email' => $email ] )),
// exporteer: datum, techniek, maat, preview-URL; wis: bestanden + e-mail in bps_quote/bps_saved.
// Bestanden van een order die nog in productie is: niet wissen, retourneer 'items_retained' => true met uitleg.
```

### 2.7 Cookies en lokale opslag (ePrivacy art. 5(3))
- `localStorage`/`sessionStorage` vallen onder dezelfde regel als cookies (EDPB Richtlijnen 2/2023 [13]). Vrijgesteld zijn enkel opslag die **strikt nodig** is voor een dienst die de gebruiker uitdrukkelijk vraagt.
  WP29 Advies 04/2012 noemt "user-input"-opslag (zoals een winkelmand of formulier) vrijgesteld, typisch **voor de duur van de sessie** [14].
- **Dus**: tussentijds ontwerp standaard in `sessionStorage`. Persistent bewaren (`localStorage`) alleen nadat de klant op **"Bewaar op dit toestel"** klikt (= uitdrukkelijke vraag). Wis het bij "Nieuw ontwerp".
  Tekst bij de knop: NL "We bewaren je ontwerp alleen in deze browser, niet bij ons." / FR "Nous enregistrons votre design uniquement dans ce navigateur, pas chez nous."
- **Geen analytics/marketing-events** vanuit de plugin, tenzij CookieYes toestemming geeft. CookieYes biedt `getCkyConsent()` en het event `cookieyes_consent_update` [15].
  Wil de eigenaar funnel-events (dataLayer), stuur ze enkel als `getCkyConsent().categories.analytics === true` **[NAKIJKEN: exacte API-versie op de site]**.
- Google Fonts voor de tekstlagen (Oswald, Graduate, …): **lokaal hosten** in de plugin. Google Fonts via CDN laden stuurt het IP naar Google; een Duitse rechtbank (LG München, 20-01-2022, 3 O 17493/20) oordeelde dat onrechtmatig zonder toestemming [16]. Lokaal is ook sneller (WP Rocket).

---

## 3. Auteursrecht en merkrecht bij uploaden

### 3.1 Kader
- Logo's, illustraties en personages zijn meestal auteursrechtelijk beschermd (Boek XI WER) en/of als merk ingeschreven (Benelux-Verdrag inzake de intellectuele eigendom, BVIE; EU-merk) [17][18].
  Een patch maken van een beschermd logo zonder toestemming is een reproductie; ook de **maker** (borduurder) kan aangesproken worden, niet alleen de klant.
- Een verklaring van de klant **verhindert niet** dat de rechthebbende de borduurder aanspreekt, maar ze (1) maakt de klant bewust, (2) geeft een contractuele vrijwaring/regres, (3) toont goede trouw. Daarom: checkbox + log + AV-artikel + recht om te weigeren.
- Tegenover consumenten moet een vrijwaringsbeding redelijk blijven (onrechtmatige bedingen, art. VI.82–VI.84 WER). Formuleer het neutraal, niet als boete.

### 3.2 Checkbox-tekst (verplicht, niet vooraf aangevinkt)
Plaats: in de stap "Overzicht", vlak boven "Bestel deze patch" en in het offerteformulier. Niet bij de upload zelf (anders schrik je mensen af vóór ze het advies zien).
Alleen nodig als er een **afbeelding** is geüpload of tekst die een merknaam kan zijn: eenvoudiger = **altijd tonen** bij een eigen ontwerp.

| # | NL | FR |
|---|---|---|
| Checkbox | Ik bevestig dat ik dit ontwerp (logo, afbeelding, tekst) mag laten maken: het is van mij, of ik heb toestemming van de eigenaar. | Je confirme être autorisé(e) à faire réaliser ce design (logo, image, texte) : il m'appartient ou j'ai l'accord de son propriétaire. |
| Link erna ("Meer info") | Waarom vragen we dit? | Pourquoi cette question ? |
| Uitleg (popover/uitklapper) | Logo's, merken en personages zijn vaak beschermd. We maken alleen patches van ontwerpen die je mag gebruiken. Bij twijfel (bv. het logo van een club of een bekend merk) vraag je best eerst toestemming. We behouden ons het recht voor een ontwerp te weigeren dat inbreuk maakt op rechten van anderen of aanstootgevend is; je betaalt dan niets. | Les logos, marques et personnages sont souvent protégés. Nous ne réalisons que des designs que vous êtes autorisé(e) à utiliser. En cas de doute (par ex. le logo d'un club ou d'une marque connue), demandez d'abord l'autorisation. Nous nous réservons le droit de refuser un design qui porte atteinte aux droits de tiers ou qui est offensant ; vous ne payez alors rien. |
| Foutmelding (bij klikken zonder vinkje) | Vink aan dat je dit ontwerp mag laten maken. Zo weten we dat alles in orde is. | Cochez la case pour confirmer que vous pouvez faire réaliser ce design. |
| Ordermeta-label (beheer) | Rechtenverklaring | Déclaration de droits |
| Ordermeta-waarde | Bevestigd op {datum} om {uur} (tekstversie {v}) | Confirmé le {date} à {heure} (version du texte {v}) |

Implementatie:
- `<input type="checkbox" id="bps-rights" required aria-describedby="bps-rights-help">` + echte `<label for>`; foutmelding met `aria-invalid="true"` + tekst in `#bps-rights-error` (gekoppeld via `aria-describedby`), focus naar de checkbox. **Server-side** opnieuw valideren (`woocommerce_add_to_cart_validation` / REST-route offerte) — de client kan omzeild worden.
- Bewaar in cart item data en daarna als order item meta: `_bps_rights_consent = [ 'v' => '2026-11-1', 'at' => gmdate('c'), 'text_sha1' => sha1($tekst), 'locale' => determine_locale() ]` + een zichtbare meta "Rechtenverklaring: bevestigd …".
- Verandert de checkboxtekst, verhoog `v`.

### 3.3 Voorstel AV-artikel (nieuw art. 3bis) **[EIGENAAR / jurist]**
**NL** — *3bis. Aangeleverde ontwerpen.* Wie een logo, afbeelding of tekst aanlevert, verklaart dat hij die mag laten reproduceren en dat dit geen rechten van derden (zoals auteurs- of merkenrechten) schendt. Borduurservice België mag een ontwerp weigeren dat rechten van derden schendt, onwettig of aanstootgevend is; reeds betaalde bedragen worden dan terugbetaald. Spreekt een derde ons aan omdat een door de klant aangeleverd ontwerp zijn rechten schendt, dan kunnen wij de redelijke kosten daarvan op de klant verhalen, voor zover de klant wist of redelijkerwijs moest weten dat hij het ontwerp niet mocht gebruiken. Aangeleverde ontwerpen blijven eigendom van de klant; wij gebruiken ze uitsluitend voor zijn bestellingen en tonen ze nooit als referentie zonder zijn toestemming.

**FR** — *3bis. Designs fournis.* Le client qui fournit un logo, une image ou un texte déclare être autorisé à le faire reproduire et que cela ne porte pas atteinte aux droits de tiers (tels que droits d'auteur ou droits de marque). Borduurservice België peut refuser un design qui porte atteinte aux droits de tiers, est illégal ou offensant ; les montants déjà payés sont alors remboursés. Si un tiers nous met en cause parce qu'un design fourni par le client porte atteinte à ses droits, nous pouvons répercuter les frais raisonnables sur le client, dans la mesure où celui-ci savait ou devait raisonnablement savoir qu'il n'était pas autorisé à utiliser ce design. Les designs fournis restent la propriété du client ; nous les utilisons exclusivement pour ses commandes et ne les montrons jamais comme référence sans son accord.

Optionele, eerlijke opt-in voor portfolio (apart, niet aangevinkt, niet verplicht): NL "Jullie mogen een foto van mijn patch als voorbeeld tonen." / FR "Vous pouvez montrer une photo de mon écusson en exemple."

---

## 4. Btw-weergave (particulier vs. bedrijf)

### 4.1 Regels
- **Consumenten**: prijs = totaalprijs **incl. btw** (art. VI.4 WER; FOD Economie "prijsaanduiding") [7][8]. AV art. 6 bevestigt het. WooCommerce staat al zo (opslaan excl., tonen incl., suffix "incl. btw", zie 02-huidig-aanbod.md).
- **Bedrijven**: geen wettelijke plicht incl. of excl.; bedrijven denken excl. Een **tweede bedrag excl. btw** of een schakelaar is klantvriendelijk, maar het hoofdbedrag blijft incl. zolang consumenten op dezelfde pagina kopen.
- **Grensoverschrijdend [NAKIJKEN boekhouder]**:
  - B2B naar Nederland met geldig btw-nummer (VIES): **verlegging** (art. 196 btw-richtlijn 2006/112/EG), factuurvermelding "Btw verlegd" / "Autoliquidation". WooCommerce doet dat niet uit zichzelf; daarvoor is een EU-VAT-plugin nodig. Toon in de configurator dan geen bedrag "excl. btw voor NL-bedrijven" dat de kassa niet waarmaakt.
  - B2C naar Nederland: boven € 10.000 EU-afstandsverkopen per jaar is NL-btw verschuldigd via OSS [19]. NL-tarief is ook 21 %, dus de getoonde prijs verandert niet. Niets in de tool aan doen, wel de eigenaar laten checken.
- Bedragen in de configurator moeten **exact** gelijk zijn aan wat de kassa rekent (afronding op regelniveau; 02-huidig-aanbod.md §"Afronding"). Een verschil van € 0,01 tussen tool en kassa schaadt vertrouwen meer dan het lijkt.

### 4.2 UI-afspraak
- Hoofdbedrag (groot): totaal **incl. btw**; daaronder "incl. btw · {stukprijs} per stuk".
- Optionele schakelaar (instelling `bps_show_vat_toggle`, standaard **aan**): "Toon prijzen excl. btw". Als aan: hoofdbedrag blijft incl.; er komt een tweede regel "€ x excl. btw". Keuze onthouden in `sessionStorage` (functioneel).
  Als de eigenaar liever volledig omschakelt voor bedrijven: dan **beide** bedragen tonen met labels, nooit een bedrag zonder "incl."/"excl."-label.
- Gebruik `wc_get_price_including_tax()` / `wc_get_price_excluding_tax()` en `wc_price()` in PHP; in JS de door PHP doorgegeven btw-voet (`WC_Tax::get_rates()` voor de winkel-basislocatie), niet hardcoden op 21.

### 4.3 Microcopy
| # | NL | FR |
|---|---|---|
| Label hoofdprijs | Totaal incl. btw | Total TVA comprise |
| Subregel | incl. btw · {€ 4,20} per stuk | TVAC · {4,20 €} par pièce |
| Tweede bedrag | {€ 173,60} excl. btw | {173,60 €} HTVA |
| Schakelaar | Toon prijzen excl. btw (voor bedrijven) | Afficher les prix HTVA (pour les entreprises) |
| Eenmalig | Eenmalige instelkosten (borduurkaart): {€ 29,95} incl. btw — bij herbestelling niet opnieuw | Frais de mise en route uniques (programme de broderie) : {29,95 €} TVAC — pas à nouveau lors d'une recommande |
| Verzending | Verzending: {€ 12,10} incl. btw · gratis vanaf {€ 150} | Livraison : {12,10 €} TVAC · gratuite dès {150 €} |
| Factuur | Factuur met btw-nummer? Vul je bedrijfsgegevens in bij het afrekenen. | Facture avec numéro de TVA ? Indiquez les données de votre entreprise lors du paiement. |

Notatie: **NL-BE "€ 12,10"**, **FR-BE "12,10 €"** (Belgische Franstalige norm: bedrag, spatie, €). Gebruik `Intl.NumberFormat('fr-BE', {style:'currency',currency:'EUR'})` en `nl-BE`; laat WooCommerce de kassa formatteren.
"TVAC/HTVA" zijn gangbaar in België (Franstalig); "TVA comprise" is voluit.
De "borduurkaart herbestelling"-zin alleen tonen als de eigenaar/het systeem dat echt doet (01-merk §4.1 zegt het letterlijk op de site).

---

## 5. Toegankelijkheid: WCAG 2.2 AA en de European Accessibility Act

### 5.1 Wettelijk
- **EAA** (Richtlijn (EU) 2019/882) [20]: van toepassing sinds **28 juni 2025** op o.a. **e-handelsdiensten** voor consumenten. In België omgezet via o.a. de wet van 5 november 2023 en opgenomen in het WER; toezicht: **FOD Economie, Economische Inspectie** [21][22].
- **Micro-ondernemingen** (< 10 werknemers én jaaromzet of balanstotaal ≤ € 2 mio) die diensten leveren: de richtlijn vrijstelt ze (art. 4(5)); Belgische bronnen spreken van **uitstel tot 28 juni 2030** [21][23]. **[EIGENAAR]** IMTA Technics BV moet zelf nagaan of het een micro-onderneming is (de BV doet ook werkkleding en machineonderhoud: tel alle activiteiten).
- Vermoeden van conformiteit via **EN 301 549**, dat voor web naar **WCAG 2.1 AA** verwijst; nieuwe versie richting **WCAG 2.2**. Bouw dus volgens **WCAG 2.2 AA** [24][25].
- EAA vraagt ook informatie over de toegankelijkheid van de dienst (bijlage V): een korte **toegankelijkheidsverklaring** (§5.6).

### 5.2 Wat de Patch Studio moeilijk maakt
Een canvas-editor met slepen, kleur als informatie, live-analyse, een sticky prijsbalk en kleine knopjes op gsm: exact de plekken waar WCAG 2.2 strenger werd.

### 5.3 Checklist per onderdeel (succescriterium → concrete eis)

| Onderdeel | SC | Eis |
|---|---|---|
| Upload | 1.3.1, 3.3.2, 2.1.1 | Echte `<input type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp,application/pdf">` met zichtbaar `<label>`. Dropzone is een extra, niet de enige manier. De hele zone is een `<label for>` → met Enter/Spatie bedienbaar. |
| Analyse bezig/klaar | 4.1.3 | Eén `<div role="status" aria-live="polite">` die korte zinnen krijgt: "Je ontwerp wordt geanalyseerd…" → "Analyse klaar: geschikt om te borduren, 5 garenkleuren." Niet bij elke schuifbalkstap iets aankondigen (debounce 500 ms). Fouten in `role="alert"`. |
| Oordeel per techniek | 1.1.1, 1.4.1 | Oordeel **als tekst in HTML** (niet enkel op canvas). Nooit alleen kleur (groen/oranje/rood): altijd icoon **en** woord ("Aanbevolen", "Kan, met aanpassing", "Niet geschikt"). |
| Techniekkeuze | 1.3.1, 4.1.2 | `<fieldset><legend>Techniek</legend>` + radioknoppen (eventueel als kaarten gestyled; de input blijft bestaan, visueel verborgen met `.screen-reader-text`-patroon, niet `display:none`). Zelfde voor vorm, maat, rand, bevestiging. |
| Canvas-voorbeeld | 1.1.1 | `<canvas role="img" aria-label="…">` met dynamische samenvatting: "Voorbeeld: ronde geborduurde patch van 8 cm, rode rand, je logo in het midden, tekst ‘FC Kalmthout’ in een boog bovenaan." Update bij wijziging (debounced). |
| Ontwerp verplaatsen | **2.5.7** (nieuw), 2.1.1 | Slepen is nooit de enige manier: knoppen ↑ ↓ ← → (verschuiven per 1 mm, Shift = 5 mm), "Centreren", "Passend maken"; schuifbalk Grootte (`<input type="range">` met `aria-valuetext="120 %"`). Canvas zelf focusbaar (`tabindex="0"`) met pijltjestoetsen als extra. |
| Tekst in boog draaien | 2.5.7 | Ook een schuifbalk "Positie op de rand" (graden) en "Afstand tot rand" (mm), niet enkel slepen. |
| Knopgrootte | **2.5.8** (nieuw, min. 24×24 px) | Alle bedieningselementen ≥ **44×44 px** op touch (ruim boven het minimum); kleurstalen ≥ 32 px met 8 px tussenruimte. |
| Sticky prijsbalk op gsm | **2.4.11** (nieuw) | Gefocuste elementen mogen niet volledig onder de balk verdwijnen: `html{scroll-padding-bottom: <hoogte balk + 16px>}` en de balk niet hoger dan ~25 % van het scherm. |
| Focus | 2.4.7, 1.4.11 | Zichtbare focus: `outline: 3px solid #5926C5; outline-offset: 2px;` (op donkere achtergrond: wit). Nooit `outline:none` zonder vervanger. |
| Kleurstalen (garenkleuren) | 1.1.1, 1.4.1 | Elke staal is een knop/radio met tekstnaam: `aria-label="Koningsblauw (Polyneon 1535)"`; geselecteerd = `aria-checked`/`aria-pressed` + vinkje, niet alleen een rand. |
| Tooltips/uitleg | 1.4.13 | Uitleg ("Waarom?") open via klik/Enter, sluitbaar met Esc, niet enkel hover. |
| Formulier (offerte, bewaren) | 3.3.1, 3.3.3, **3.3.7**, 1.3.5 | Foutmelding in tekst bij het veld; `autocomplete="email"`, `name`, `organization`, `tel`. Geen e-mail twee keer vragen; gegevens die de klant al gaf (maat, aantal) niet opnieuw laten typen. |
| Anti-spam | **3.3.8** (nieuw) | Geen puzzel-CAPTCHA. Honeypot + rate-limit (zoals 06 voorstelt) is conform. |
| Hulp | **3.2.6** (nieuw) | Contactlink/WhatsApp op een vaste plek in elke stap ("Hulp nodig? …"). |
| Zoom/herschikking | 1.4.4, 1.4.10 | Werkt op 320 px breed en bij 200 % tekstzoom zonder horizontaal scrollen (behalve het canvas zelf, dat schaalt). Eenheden in `rem`. |
| Beweging | 2.3.3 (AAA, toch doen) | Animaties (steek-animatie, confetti …) uit bij `prefers-reduced-motion: reduce`. Niets wat > 3× per seconde flitst (2.3.1). |
| Taal | 3.1.1, 3.1.2 | De widget erft `lang` van de pagina. Toon je een Franse zin op een Nederlandse pagina: `lang="fr-BE"` op dat element. |
| Tijdslimiet | 2.2.1 | Geen tijdslimieten in de flow (REST-token: stil verversen, niet de klant buitengooien). |
| Koppen/structuur | 1.3.1, 2.4.6 | Stappen als `<h2>`/`<h3>` met duidelijke namen; stappenindicator als `<ol>` met `aria-current="step"`. |
| Modaal (bijsnijden/"uit foto geknipt") | 2.1.2, 2.4.3 | Gebruik `<dialog>` met `showModal()`: focus erin, Esc sluit, focus terug naar de knop. |
| Contrast | 1.4.3, 1.4.11 | Zie §5.4. |

### 5.4 Merkkleuren: gemeten contrast (WCAG-formule, berekend 16-11-2026)

| Kleur | op wit | op #F5F5F5 | Gebruik |
|---|---|---|---|
| #5926C5 paars | **8,37** | 7,68 | ✔ tekst, links, knoppen (witte tekst op paars = 8,37) , focusrand |
| #FD5C48 koraal | 3,09 | 2,83 | ✘ tekst op wit. ✔ knop/badge **met tekst #1E1B1B** (5,54). ✔ groot decor/iconen (≥ 3:1 voor UI-componenten op wit: net). Wil je koraal als tekstkleur: **#C2412F** (5,13) |
| #1E1B1B | 17,1 | 15,69 | ✔ broodtekst |
| #323232 | 12,82 | 11,76 | ✔ broodtekst |
| #959595 | 3,0 | 2,75 | ✘ broodtekst/hulptekst. Vervang door **#6B6B6B** (5,33) of #767676 (4,54) |
| #BDBDBD | 1,88 | 1,72 | ✘ rand van invoervelden/kaarten die bediening aanduiden (1.4.11 vraagt 3:1) → **#8A8A8A** of donkerder; ✔ puur decoratieve scheidingslijnen |
| #21A9E2 blauw | 2,68 | 2,46 | ✘ tekst op wit; ✔ met donkere tekst erop (6,38) |
| #52D7B4 mint | 1,79 | 1,64 | ✘ op wit; ✔ als vlak met #1E1B1B-tekst (9,56) — goed voor "Aanbevolen"-badge |

Advies voor oordeel-badges (tekst + icoon, nooit alleen kleur):
- Aanbevolen: achtergrond #52D7B4, tekst #1E1B1B.
- Kan, met aanpassing: achtergrond #FFE3A3 (of #FDE7E3), tekst #1E1B1B.
- Niet geschikt: tekst #C2412F op wit met ✕-icoon, of achtergrond #FD5C48 met tekst #1E1B1B.

### 5.5 Testen (door de QA-agent)
- **axe-core in Playwright**: `npm i -D @axe-core/playwright`, dan `new AxeBuilder({ page }).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze()` op de shortcode-pagina in elke stap (na upload, na analyse, met dialoog open). 0 "serious/critical" als doel [26].
- **Toetsenbord-run**: hele flow tot "Bestel deze patch" zonder muis (Tab, Shift+Tab, pijltjes, Enter, Spatie, Esc).
- **Schermlezer**: NVDA + Firefox, VoiceOver iOS Safari (gsm is het hoofdkanaal volgens de eigenaar).
- 320 px breed + 200 % zoom; Windows Hoog Contrast (`forced-colors: active`: canvas-rand en focus blijven zichtbaar, gebruik `CanvasText`/`Highlight`).
- axe vindt ± 30–40 % van de problemen; de handmatige runs zijn nodig.

### 5.6 Microcopy toegankelijkheid (aria-labels en statusberichten)

| Sleutel | NL | FR |
|---|---|---|
| upload.label | Kies je logo, afbeelding of foto | Choisissez votre logo, image ou photo |
| upload.hint | PNG, JPG, SVG, WEBP of PDF · max. {20} MB · sleep je bestand hierheen of tik om te kiezen | PNG, JPG, SVG, WEBP ou PDF · max. {20} Mo · glissez votre fichier ici ou touchez pour choisir |
| status.analyzing | Je ontwerp wordt geanalyseerd… | Analyse de votre design en cours… |
| status.done | Analyse klaar. Ons advies: {techniek}. {n} garenkleuren. | Analyse terminée. Notre conseil : {techniek}. {n} couleurs de fil. |
| status.shape | We herkenden een {ronde} patch en hebben die vorm gekozen. | Nous avons reconnu un écusson {rond} et choisi cette forme. |
| status.cutout | We hebben je patch uit de foto geknipt. Klopt het niet? Kies "Bijstellen". | Nous avons détouré votre écusson sur la photo. Ce n'est pas correct ? Choisissez « Ajuster ». |
| error.file | Dit bestand kunnen we niet openen. Probeer een PNG of JPG, of stuur het ons gewoon via de offerte. | Nous ne pouvons pas ouvrir ce fichier. Essayez un PNG ou un JPG, ou envoyez-le-nous via la demande de devis. |
| canvas.label | Voorbeeld van je patch: {vorm}, {maat} cm, {techniek}, rand {randkleur}{, tekst “…”}. | Aperçu de votre écusson : {forme}, {taille} cm, {technique}, bordure {couleur}{, texte « … »}. |
| move.up / down / left / right | Ontwerp omhoog / omlaag / naar links / naar rechts | Déplacer le design vers le haut / le bas / la gauche / la droite |
| move.center | Ontwerp centreren | Centrer le design |
| move.fit | Ontwerp passend maken | Ajuster le design à l'écusson |
| size.label | Grootte van het ontwerp | Taille du design |
| size.valuetext | {120} procent | {120} pour cent |
| text.add | Tekst toevoegen | Ajouter un texte |
| text.remove | Tekst “{tekst}” verwijderen | Supprimer le texte « {texte} » |
| text.curve | Vorm van de tekst: recht, boog boven, boog onder | Forme du texte : droit, arc en haut, arc en bas |
| text.angle | Positie op de rand (graden) | Position sur le bord (degrés) |
| verdict.ok | Aanbevolen | Recommandé |
| verdict.warn | Kan, met aanpassing | Possible, avec adaptation |
| verdict.no | Niet geschikt | Déconseillé |
| help | Hulp nodig? Stuur ons een bericht, we helpen je persoonlijk verder. | Besoin d'aide ? Envoyez-nous un message, nous vous aidons personnellement. |
| steps.label | Stap {1} van {4}: {Ontwerp} | Étape {1} sur {4} : {Design} |

**Toegankelijkheidsverklaring** (korte pagina of blok, NL/FR) **[EIGENAAR]**:
NL — "We willen dat iedereen een patch kan ontwerpen en bestellen. Onze Patch Studio is gebouwd volgens de WCAG 2.2-richtlijnen (niveau AA): je kunt hem volledig met het toetsenbord en met een schermlezer gebruiken, en alles wat je kunt slepen, kun je ook met knoppen doen. Loop je toch ergens vast? Laat het ons weten via {e-mail} of WhatsApp {nummer}; we helpen je persoonlijk en passen het aan. Laatst nagekeken: {datum}."
FR — "Nous voulons que chacun puisse créer et commander un écusson. Notre Patch Studio est conçu selon les règles WCAG 2.2 (niveau AA) : vous pouvez l'utiliser entièrement au clavier et avec un lecteur d'écran, et tout ce qui se glisse peut aussi se faire avec des boutons. Vous rencontrez malgré tout un obstacle ? Signalez-le-nous via {e-mail} ou WhatsApp {numéro} ; nous vous aidons personnellement et corrigeons le problème. Dernière vérification : {date}."
Schrijf "gebouwd volgens" pas als de tests van §5.5 slagen. Niet "volledig conform" claimen zonder audit.

---

## 6. Franse vertaling via WordPress-i18n

### 6.1 Beslissingen
- **Text domain**: `borduur-patch-studio` (= mapnaam = slug), header `Text Domain: borduur-patch-studio`, `Domain Path: /languages`.
- **Brontaal van de strings = Vlaams Nederlands** (`nl_BE`). Afwijking van de wordpress.org-conventie (Engels), maar bewust: de site is `nl-BE`; ontbreekt een vertaling, dan valt de klant terug op correct Vlaams i.p.v. Engels. Het is een privé-plugin (niet op wordpress.org). Zet in de plugin-header een opmerking `// Source strings: nl_BE`.
  WPML: in String Translation de brontaal van de text domain op Nederlands zetten (WPML › String Translation › "Change the language" per domain).
- **Locales meeleveren**: `languages/borduur-patch-studio-fr_BE.po/.mo` **en** een kopie als `fr_FR` (WordPress valt **niet** automatisch van fr_BE naar fr_FR terug, en veel Franstalige Belgische sites draaien op `fr_FR`). Optioneel `nl_NL` niet nodig (bron is al NL).
  Sinds WP 6.5 ook `.l10n.php` genereren (`wp i18n make-php`) — sneller laden [27].
- **Laden**: WP ≥ 6.7 geeft een notice `_load_textdomain_just_in_time` als vertalingen te vroeg geladen worden. Roep `__()` nergens aan vóór `init` (dus niet in constructor-code die op `plugins_loaded` draait) [28].
```php
add_action( 'init', function () {
    load_plugin_textdomain( 'borduur-patch-studio', false, dirname( plugin_basename( BPS_FILE ) ) . '/languages' );
} );
```

### 6.2 Regels voor strings (voor alle bouwers)
- Altijd volledige zinnen, geen aan elkaar geplakte stukjes ("Je patch is " + adj). Placeholders met `sprintf`/`printf` en genummerd als er meer zijn: `/* translators: 1: aantal garenkleuren, 2: techniek */ __( '%1$d garenkleuren voor %2$s', … )`.
- Meervoud: `_n( '%d stuk', '%d stuks', $n, 'borduur-patch-studio' )`; in JS `_n()` van `wp.i18n` of `Intl.PluralRules`. Frans meervoud begint bij 2 ("1 pièce", "0 pièce" is ook enkelvoud in het Frans) — gettext regelt dat via `Plural-Forms: nplurals=2; plural=(n > 1);`.
- Context waar een woord dubbel is: `_x( 'Rand', 'patch border', … )` vs `_x( 'Rand', 'edge of image', … )`.
- Escapen bij output: `esc_html__()`, `esc_attr__()`; HTML in vertalingen alleen via `wp_kses_post( sprintf( … ) )`.
- Getallen/maten: `number_format_i18n()` in PHP; in JS `new Intl.NumberFormat(locale)` met `locale = document.documentElement.lang || 'nl-BE'` of uit het data-attribuut (`str_replace('_','-', determine_locale())`). "8 cm", "2,5 mm" (komma in NL én FR).
- Geen tekst in afbeeldingen of in canvas-only labels zonder vertaalbare bron.
- Elementor-widgetlabels en instellingen in het beheer ook via `__()`.
- Garennamen (Polyneon) in de JSON (01-garenkleuren-polyneon.json): kleurnaam vertaalbaar maken via een PHP-lijst met `__()`, of `name_nl` + `name_fr` velden in de JSON. Het nummer blijft gelijk.

### 6.3 JavaScript-vertalingen: twee opties
**Optie A (aanbevolen voor deze site): string-tabel uit PHP in de widget-HTML.**
De render-functie zet een `<script type="application/json" id="bps-i18n">{…}</script>` in de widget met alle UI-strings, opgebouwd met `__()`.
Voordelen: één `.po` voor PHP én JS; werkt met WPML/Polylang String Translation zonder extra stap; geen afhankelijkheid van `wp-i18n` (dat WP Rocket "Delay JS" anders ook moet uitsluiten); geen md5-bestandsnaam-gedoe; JSON-scripts worden door WP Rocket niet uitgevoerd of vertraagd.
Nadeel: ± 3–6 kB extra HTML op de configuratorpagina (verwaarloosbaar; alleen op die pagina).
```php
$strings = [
  'statusAnalyzing' => __( 'Je ontwerp wordt geanalyseerd…', 'borduur-patch-studio' ),
  /* translators: %s: name of the technique */
  'statusDone'      => __( 'Analyse klaar. Ons advies: %s.', 'borduur-patch-studio' ),
  // …
];
echo '<script type="application/json" id="bps-i18n">' . wp_json_encode( $strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>';
```
`wp i18n make-pot` vindt deze strings gewoon in de PHP.

**Optie B: `wp_set_script_translations` (WordPress-standaard voor JS met `wp.i18n`).**
```php
wp_register_script( 'bps-studio', plugins_url( 'assets/js/studio.js', BPS_FILE ), [ 'wp-i18n' ], BPS_VERSION, [ 'strategy' => 'defer', 'in_footer' => true ] );
wp_set_script_translations( 'bps-studio', 'borduur-patch-studio', plugin_dir_path( BPS_FILE ) . 'languages' );
```
- In JS: `const { __, _n, sprintf } = wp.i18n; __( 'Tekst toevoegen', 'borduur-patch-studio' )`.
- JSON maken: `wp i18n make-json languages/ --no-purge`. Bestandsnaam = `{domain}-{locale}-{md5(relatief pad van het bronscript)}.json` [29][30].
  Valkuil: de md5 moet gemaakt zijn van het pad van het **niet-geminificeerde bronbestand zoals geregistreerd** (`assets/js/studio.js`). Bundel je met een build-stap naar een ander pad, dan klopt de hash niet. Veiliger: hernoem naar **`borduur-patch-studio-fr_BE-bps-studio.json`** (handle-naam); WordPress zoekt die naam eerst als je `$path` meegeeft.
- `wp-i18n` + `bps-studio` uitsluiten van WP Rocket Delay JS (zie 06 §9), anders is `wp.i18n` niet klaar.
- WPML/Polylang vertalen deze JSON-strings **niet** via String Translation; ze komen enkel uit de meegeleverde bestanden.

### 6.4 Workflow
```bash
cd plugin/borduur-patch-studio
wp i18n make-pot . languages/borduur-patch-studio.pot --domain=borduur-patch-studio --exclude=node_modules,vendor,tests \
   --headers='{"Language":"nl_BE","Report-Msgid-Bugs-To":"","Language-Team":""}'
# fr_BE.po maken/bijwerken (msgmerge of Poedit/Loco Translate), dan:
wp i18n make-mo languages/
wp i18n make-php languages/        # WP >= 6.5
cp languages/borduur-patch-studio-fr_BE.po languages/borduur-patch-studio-fr_FR.po   # + .mo/.l10n.php
wp i18n make-json languages/ --no-purge   # alleen bij optie B
```
WP-CLI staat niet standaard op de server; lokaal: `composer require --dev wp-cli/i18n-command` of de `wp-cli.phar` [31]. Loco Translate (gratis plugin) laat de eigenaar zelf Franse teksten aanpassen; vertel dat hij eigen versies in `wp-content/languages/loco/plugins/` zet, anders overschrijft een plugin-update ze.

### 6.5 Belgisch Frans: woordkeuze (consistent gebruiken)
| NL | FR (BE) | Opmerking |
|---|---|---|
| patch (geborduurd/geweven/bedrukt/gesublimeerd) | écusson brodé / tissé / imprimé / sublimé | "patch" mag als synoniem; titel: "écusson (patch)" |
| digitale proef | épreuve numérique (BAT) | "bon à tirer" kennen bedrijven |
| offerte | devis | |
| strijklaag / opstrijkbaar | thermocollant | |
| klittenband | velcro (scratch) | "velcro" is gangbaar in BE |
| kettelrand / merrow | bordure merrow (surjetée) | |
| borduurkaart / digitaliseren | programme de broderie / digitalisation | |
| garenkleur | couleur de fil | |
| vanaf 1 stuk | dès 1 pièce | |
| incl./excl. btw | TVAC / HTVA (TVA comprise / hors TVA) | |
| gsm | GSM | Belgisch; geen "portable" nodig |
| e-mail | e-mail | geen "courriel" (dat is Québec/FR-officieel) |
| winkelmand | panier | |
| Bestel deze patch | Commander cet écusson | |
| In winkelmand | Ajouter au panier | |
| Bestellen en betalen | Commander et payer | |
| Kalmthout (eigen productie) | fabriqué à Kalmthout, dans notre propre atelier | |
| WER (Wetboek van Economisch Recht) | CDE (Code de droit économique) | |
Getallen: in België zeg je "septante/nonante" — enkel relevant als bedragen voluit geschreven worden (niet doen; cijfers gebruiken).

---

## 7. WPML- en Polylang-compatibiliteit

De site gebruikt vandaag geen van beide; dit is toekomstvastheid. Kost weinig als het van in het begin goed zit.

### 7.1 `wpml-config.xml` in de plugin-root
Beide plugins lezen dit bestand (Polylang ondersteunt de `wpml-config.xml`-secties custom fields, post types, taxonomies en admin-texts) [32][33].
```xml
<wpml-config>
  <custom-types>
    <custom-type translate="0">bps_quote</custom-type>      <!-- offertes: niet vertalen -->
    <custom-type translate="0">bps_design</custom-type>
  </custom-types>
  <custom-fields>
    <custom-field action="copy">_bps_enabled</custom-field>          <!-- product-instellingen kopiëren naar vertaling -->
    <custom-field action="copy">_bps_technique</custom-field>
    <custom-field action="ignore">_bps_design_id</custom-field>
  </custom-fields>
  <admin-texts>
    <key name="bps_settings">
      <key name="legal_note_extra" />      <!-- vrije teksten die de eigenaar invult -->
      <key name="proof_lead_time" />
      <key name="help_text" />
      <key name="accessibility_url" />
    </key>
  </admin-texts>
</wpml-config>
```
Regel: **vrije teksten die de eigenaar in de instellingen typt** (disclaimer-aanvulling, proeftermijn, hulptekst) → `admin-texts`. Vaste UI-teksten → gettext (`__()`), niet in opties.

### 7.2 Code-patronen
```php
// Taal van de huidige pagina (werkt zonder, met WPML of met Polylang).
function bps_current_lang(): string {
    if ( function_exists( 'pll_current_language' ) && pll_current_language() ) { return pll_current_language( 'locale' ); }
    $wpml = apply_filters( 'wpml_current_language', null );
    if ( $wpml ) { return apply_filters( 'wpml_locale', determine_locale(), $wpml ) ?: determine_locale(); }
    return determine_locale();
}
// Product-ID van de patchproducten (29902, 30484, …) in de taal van de pagina.
function bps_translated_product_id( int $id ): int {
    if ( function_exists( 'pll_get_post' ) ) { return (int) ( pll_get_post( $id ) ?: $id ); }
    return (int) apply_filters( 'wpml_object_id', $id, 'product', true );
}
```
- **Product-/variatie-ID's nooit hardcoden** zonder deze vertaalstap (anders legt een Franse bezoeker het Nederlandse product in de mand; WooCommerce Multilingual verwacht het vertaalde product).
- **Opties met vrije tekst** lezen via `apply_filters( 'wpml_translate_single_string', $value, 'admin_texts_bps_settings', '[bps_settings]legal_note_extra' )` — bij Polylang via de admin-texts uit `wpml-config.xml` (Polylang vertaalt `get_option` automatisch) of `pll__()` na `pll_register_string()` in het beheer.
- **REST-calls** van onze JS dragen geen taalcontext. Geef de taal expliciet mee: data-attribuut `data-bps-lang="fr_BE"` op de widget → JS stuurt `?lang=fr` (Polylang en WPML herkennen allebei `lang`) **en** onze eigen header/param `bps_locale`; in de REST-callback `switch_to_locale( $locale )` vóór je teksten opbouwt, `restore_previous_locale()` daarna.
- **E-mails** (offerte, "bewaar mijn ontwerp"): sla `_bps_locale` op bij de aanvraag en verstuur met `switch_to_locale( $locale )` rond het opbouwen + versturen. Woo-ordermails: WooCommerce Multilingual regelt dat zelf (taal van de order).
- **Caching**: WP Rocket cachet per URL; WPML/Polylang gebruiken taal-URL's (`/fr/…`), dus de string-tabel (§6.3 optie A) in de HTML is per taal correct. Gebruik **geen** taalkeuze via cookie voor onze widget.
- **Elementor**: onze widget-instellingen (bv. starttechniek) worden met de pagina mee vertaald door WPML/Polylang; strings in widget-controls die de eigenaar intypt registreer je voor WPML via de filter `wpml_elementor_widgets_to_translate` [34].
- Mollie, CookieYes en WooCommerce hebben eigen Franse vertalingen (`fr_BE`/`fr_FR` via translate.wordpress.org); controleer na het inschakelen dat de kassa en de cookiebanner ook in het Frans staan **[EIGENAAR bij FR-lancering]**.

---

## 8. Overzicht: wat de bouwers moeten implementeren (afvinklijst)

- [ ] Microcopy §1.4 A–I (NL+FR) op de juiste plekken; instelling `bps_cancel_until_proof`, `bps_fix_order_button`, `bps_proof_lead_time`.
- [ ] Kassamelding D (alleen met maatwerk in de mand) + mailblok F.
- [ ] Rechten-checkbox §3.2: client + server-validatie, log in order item meta met versie.
- [ ] Privacyzin §2.4 (variant die klopt), `wp_add_privacy_policy_content`, exporter + eraser, cron `bps_cleanup` met instelbare termijnen.
- [ ] `sessionStorage` standaard; `localStorage` enkel na "Bewaar op dit toestel"; geen trackers zonder CookieYes-toestemming; fonts lokaal.
- [ ] Prijsweergave incl. btw als hoofdbedrag; optionele excl.-regel; bedragen = kassa.
- [ ] WCAG 2.2 AA-checklist §5.3, kleuren §5.4, axe-test §5.5, aria-teksten §5.6, toegankelijkheidsverklaring.
- [ ] i18n §6: text domain, laden op `init`, strings volgens §6.2, JS-strings via optie A, `.pot` + `fr_BE` + `fr_FR` (+ `.l10n.php`).
- [ ] `wpml-config.xml`, `bps_current_lang()`, `bps_translated_product_id()`, taal in REST en e-mails.

## 9. Open punten voor de eigenaar
1. Kosteloos annuleren tot de proef goedgekeurd is: ja/nee? (§1.1)
2. Kassaknop wijzigen naar "Bestellen en betalen" voor de hele winkel: akkoord? (§1.2)
3. AV aanpassen: art. 2 "producten" i.p.v. "kleding" en nieuw art. 3bis (§3.3) — laten nakijken.
4. Privacybeleid aanvullen met §2.5; bewaartermijnen bevestigen.
5. Micro-onderneming in de zin van de EAA? (bepaalt deadline, niet de bouwkwaliteit) (§5.1)
6. Proeftermijn ("binnen 2 werkdagen"?) en e-mail/WhatsApp voor hulp en toegankelijkheidsmeldingen.
7. Grensoverschrijdend B2B (verlegging) en OSS: met de boekhouder bekijken (§4.1).
8. Franse teksten laten nalezen door een Franstalige (Belgische) lezer.
9. Mag een geslaagde patch (met opt-in) als voorbeeld getoond worden? (§3.3)

---

## Bronnen
1. Wetboek van economisch recht, art. VI.47 en VI.53 (Justel): https://www.ejustice.just.fgov.be/cgi_loi/change_lg.pl?language=nl&la=N&cn=2013022819&table_name=wet — gecontroleerd via secundaire bronnen hieronder; de eigen AV van de site verwijzen ook naar VI.53.
2. Your Europe — Retouren en herroepingsrecht (uitzondering "op maat gemaakte of gepersonaliseerde producten"): https://europa.eu/youreurope/citizens/consumers/shopping/returns/index_nl.htm
3. Forum for the Future (FOD Economie-blog) — Herroepingsrecht in 10 vragen: https://blog.forumforthefuture.be/nl/article/herroepingsrecht-lees-dus-zeker-alles-wat-je-hierover-moet-weten-in-10-vragen-en-antwoorden./31247 ; voorbeeldclausule VI.53, 3°: https://merch.gentjazz.com/returns
4. Richtlijn 2011/83/EU consumentenrechten (art. 6(1)(k), 8(2), 16(c)): https://eur-lex.europa.eu/legal-content/NL/TXT/?uri=CELEX:32011L0083
5. Europese Commissie, Guidance on Directive 2011/83/EU (2021/C 525/01): https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:52021XC1229(04)
6. HvJ EU 7 april 2022, C-249/21 (Fuhrmann-2-GmbH), knoptekst: https://curia.europa.eu/juris/liste.jsf?num=C-249/21
7. FOD Economie — Prijsaanduiding: https://economie.fgov.be/nl/themas/verkoop/reglementering-van-de-verkoop/prijsaanduiding
8. Unizo — Regels webshop, verplichte informatie: https://www.unizo.be/advies/regels-webshop-welke-informatie-moet-u-bij-verkoop-een-webshop-verplicht-meedelen-aan-de
9. FOD Economie — Prijsverminderingen (Omnibus, laagste prijs 30 dagen): https://economie.fgov.be/nl/themas/verkoop/reglementering-van-de-verkoop/prijsverminderingen
10. Richtlijn 2005/29/EG (UCPD) zoals gewijzigd door Richtlijn (EU) 2019/2161, bijlage I: https://eur-lex.europa.eu/legal-content/NL/TXT/?uri=CELEX:02005L0029-20220528
11. AVG/GDPR (Verordening (EU) 2016/679), art. 5, 6, 13, 17, 25: https://eur-lex.europa.eu/legal-content/NL/TXT/?uri=CELEX:32016R0679
12. Gegevensbeschermingsautoriteit — Bewaartermijnen / AVG voor kmo's: https://www.gegevensbeschermingsautoriteit.be/burger/thema-s/
13. EDPB Guidelines 2/2023 on Technical Scope of Art. 5(3) ePrivacy Directive: https://www.edpb.europa.eu/our-work-tools/documents/public-consultations/2023/guidelines-22023-technical-scope-art-53-eprivacy_en
14. WP29 Opinion 04/2012 on Cookie Consent Exemption (WP194): https://ec.europa.eu/justice/article-29/documentation/opinion-recommendation/files/2012/wp194_en.pdf
15. CookieYes — Consent API (getCkyConsent, cookieyes_consent_update): https://www.cookieyes.com/documentation/events-on-cookie-banner-interactions/
16. LG München I, 20-01-2022, 3 O 17493/20 (Google Fonts): https://rewis.io/urteile/urteil/lhm-20-01-2022-3-o-1749320/
17. FOD Economie — Auteursrecht (Boek XI WER): https://economie.fgov.be/nl/themas/intellectuele-eigendom/intellectuele-eigendomsrechten/auteursrecht-en-naburige
18. BOIP — Benelux-merken: https://www.boip.int/nl/ondernemers/merken
19. Your Europe — Btw bij afstandsverkopen / OSS (drempel € 10.000): https://europa.eu/youreurope/business/taxation/vat/vat-rules-rates/index_nl.htm
20. Richtlijn (EU) 2019/882 (European Accessibility Act): https://eur-lex.europa.eu/eli/dir/2019/882/oj?locale=nl
21. VLAIO — Nieuwe regels rond digitale toegankelijkheid voor e-handelsdiensten: https://www.vlaio.be/nl/nieuws/nieuwe-regels-rond-digitale-toegankelijkheid-voor-e-handelsdiensten
22. Legalnews — Voldoet je website al aan de nieuwe European Accessibility Act?: https://legalnews.be/it-ip/voldoet-je-website-al-aan-de-nieuwe-european-accessibility-act-mr-franklin/ ; Vlaanderen — EU-richtlijn 2019/882 voor B2C: https://www.vlaanderen.be/inter/overheden-en-professionelen/werken-aan-digitale-toegankelijkheid/eu-richtlijn-2019-882-voor-b2c-eaa
23. Vlaanderen — Moeten je diensten digitaal toegankelijk zijn?: https://www.vlaanderen.be/inter/overheden-en-professionelen/werken-aan-digitale-toegankelijkheid/moeten-je-diensten-digitaal-toegankelijk-zijn
24. W3C — WCAG 2.2 (Recommendation) en "What's New in WCAG 2.2": https://www.w3.org/TR/WCAG22/ · https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/
25. ETSI EN 301 549: https://www.etsi.org/human-factors-accessibility/en-301-549-v3-the-harmonized-european-standard-for-ict-accessibility
26. Deque — @axe-core/playwright: https://github.com/dequelabs/axe-core-npm/tree/develop/packages/playwright
27. Make WordPress Core — I18N improvements in 6.5 (Performant Translations, .l10n.php): https://make.wordpress.org/core/2024/02/27/i18n-improvements-6-5-performant-translations/
28. Make WordPress Core — Internationalization improvements in 6.7 (just-in-time loading notice): https://make.wordpress.org/core/2024/10/21/i18n-improvements-6-7/
29. WordPress Developer — Internationalization in JavaScript (wp_set_script_translations, make-json): https://developer.wordpress.org/block-editor/how-to-guides/internationalization/
30. Core Trac #45488 (md5-bestandsnamen JSON): https://core.trac.wordpress.org/ticket/45488
31. WP-CLI — wp i18n commands: https://developer.wordpress.org/cli/commands/i18n/
32. WPML — Language configuration files (wpml-config.xml): https://wpml.org/documentation/support/language-configuration-files/
33. Polylang — The wpml-config.xml file / Function reference (pll_get_post, pll_register_string, pll_current_language): https://polylang.pro/doc/the-wpml-config-xml-file/ · https://polylang.pro/doc/function-reference/
34. WPML — Elementor widgets: registering custom widgets for translation: https://wpml.org/documentation/plugins-compatibility/elementor/how-to-add-wpml-support-to-custom-elementor-widgets/
35. Eigen site (curl 16-11-2026): https://borduurservicebelgie.be/algemene-voorwaarden/ · https://borduurservicebelgie.be/privacy-policy/

Bronnen die ik niet rechtstreeks kon openen vanuit deze sessie (netwerkbeperking van WebFetch; inhoud via zoekresultaten en eigen kennis): 1, 5–9, 12–18, 22–25, 27–28, 32–34. De URL's zijn de officiële vindplaatsen; laat een mens de wetsartikelen (VI.45, VI.46, VI.53) nog één keer op Justel aanklikken. **[NAKIJKEN]**
