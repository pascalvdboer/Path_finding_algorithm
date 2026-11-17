# 05 — Conversie: wat een productconfigurator laat converteren (eerlijk)

Onderzoeker: "conversie". Opgesteld 16-11-2026. Voor de bouwers van Patch Studio is dit het enige document over flow, prijs-UX, vertrouwen en microcopy.
Lees het samen met `01-merk-en-site.md` (merk, toon, echte beloftes) en `02-huidig-aanbod.md` (echte prijzen, staffels, kosten).

> **Over de bronnen:** baymard.com en nngroup.com waren vanuit deze sessie niet rechtstreeks op te halen (DNS geblokkeerd).
> De bevindingen hieronder komen uit zoekresultaten (WebSearch) die de officiële pagina's samenvatten. De URL's staan in §11.
> Ik heb alleen cijfers overgenomen die in meerdere bronnen terugkomen of van de bron zelf komen. Alle cijfers zijn **voor ons intern**
> en mogen **nooit** als claim in klantgerichte tekst staan.

---

## 0. TL;DR voor bouwers (lees dit als je maar 1 minuut hebt)

1. **Uploaden eerst, al de rest daarna.** Het wow-moment (analyse + mooi voorbeeld) komt in de eerste 10 seconden. Maat, aantal en afwerking volgen pas daarna.
2. **Er is altijd één ingevuld, aanbevolen pad.** Elke keuze heeft een verstandige standaardwaarde die ons advies is. Mensen nemen de standaardwaarde vaak over, dus die moet in het belang van de klant zijn.
3. **Je ziet de prijs live en volledig.** Toon de prijs per stuk en het totaal, inclusief eenmalige kosten en verzending, al vóór de winkelmand. Onverwachte extra kosten zijn de belangrijkste reden waarom mensen een bestelling afbreken.
4. **Het advies klinkt als een vakman aan de toonbank.** Geef eerst het oordeel, dan de reden en dan wat de klant kan doen. Een kort zinnetje volstaat, met cijfers uit de analyse. Hou het eerlijk, ook als de klant daardoor een goedkopere techniek kiest.
5. **De risico's zijn weg bij de knop.** Zet "Digitale proef vóór productie, pas na jouw akkoord starten we" onder de bestelknop. Dat is onze echte garantie.
6. **Wie twijfelt, haken we niet af.** Bied twee volwaardige uitwegen: "Bewaar en mail mijn ontwerp" en "Vraag een vakman om mee te kijken" (offerte). De offerte hoort ook bij de conversie.
7. **Op mobiel staat er een sticky bestelbalk** met de prijs en één knop. Die balk mag de bestaande `.bsb-mob-balk` niet overlappen.
8. **Verboden:** nep-reviews, sterren, aftelklokken, "nog 2 plaatsen", vooraf aangevinkte extra's en verzonnen percentages.

---

## 1. Wat het onderzoek zegt (kort, met bron)

| # | Bevinding | Wat het voor ons betekent | Bron |
|---|---|---|---|
| B1 | Te hoge extra kosten (verzending, btw, toeslagen) zijn de belangrijkste reden om een bestelling af te breken. In Baymards meest recente cijfers is dat ±40% van de afbrekers (zonder de "ik keek maar wat rond"-groep). Oudere edities kwamen op ±48%. | Toon de borduurkaart/instelkosten, de PVC-mal en de verzending **in de configurator**, niet pas in de kassa. | Baymard cart abandonment (§11 [1]) |
| B2 | Bij complexe configuraties zie je geen totaal en kun je niet vergelijken als de prijzen van varianten en opties verborgen blijven. | Zet de meerprijs op elke optie ("+ € 1,82/st.") en laat een lopend totaal zien. | Baymard guideline 3108 [2] |
| B3 | ±19% heeft een bestelling afgebroken omdat een account aanmaken verplicht was. | Bewaren of mailen gaat **zonder account**. Bestellen als gast moet kunnen (WooCommerce-instelling, aan de eigenaar te bevestigen). | Baymard checkout [3] |
| B4 | ±19% brak in 3 maanden een betaling af omdat ze de site niet vertrouwden met hun kaartgegevens. Het veiligheidsgevoel is grotendeels een "onderbuikgevoel", gestuurd door hoe verzorgd en afgebakend het formulier eruitziet. | Een verzorgd ontwerp, gegroepeerde vertrouwenselementen bij de knop en geen rommelige zegels. Echte gegevens zoals adres, btw-nummer en een persoon. | Baymard perceived security [4] |
| B5 | Directe (inline) validatie spaart tijd en fouten, mits ze niet te vroeg komt, verdwijnt zodra het opgelost is en positieve bevestiging toont. Een foutmelding is specifiek per regel. | Valideer tijdens het typen of na het verlaten van een veld, niet bij de eerste toets. Zeg wat er mis is en hoe je het oplost. | Baymard inline validation [5], adaptive messages [6] |
| B6 | Een foutmelding staat naast het element, is zichtbaar zonder extra klik en geeft een oplossing die weinig moeite kost. | Uploadfouten tonen we in de dropzone zelf, met een knop die het probleem oplost. | NN/g error-message guidelines + rubric [7][8] |
| B7 | Een wizard werkt voor beginners en voor processen die je zelden doet. Elke stap krijgt een label en de huidige stap is duidelijk. Een nadeel is dat vergelijken over stappen heen moeilijk is. | Werk met 4 stappen en labels. De samenvatting en het voorbeeld blijven altijd zichtbaar, zodat de klant niet moet terugbladeren. | NN/g wizards [9] |
| B8 | Progressive disclosure: secundaire opties gaan naar een tweede laag. Zo is het makkelijker te leren en maak je minder fouten. | Rugzijde, randtype en kleurkeuze per garen zitten achter "Aanpassen". Het advies staat al ingevuld. | NN/g progressive disclosure [10] |
| B9 | Standaardwaarden worden vaak gelezen als aanbeveling. Een duidelijk "aanbevolen" plan verlaagt de beslisdrempel (CXL/Speero-test op een prijspagina). | Gebruik maximaal één "Ons advies"-badge per keuze, altijd gebaseerd op de analyse en nooit op de marge. | UX Mag/defaults [11], CXL/Speero [12] |
| B10 | Keuzestress bestaat, maar onder voorwaarden. De meta-analyse van Scheibehenne e.a. (50 experimenten) vond gemiddeld bijna geen effect, wel een groot effect als mensen geen voorkennis of voorkeur hebben. | Onze klanten zijn leken. Toon daarom per stap weinig opties met één advies, en laat ze niet een catalogus doorbladeren. | Scheibehenne 2010 [13], Decision Lab [14] |
| B11 | Mensen willen een **leverdatum**, geen "verzendsnelheid". | Toon "Verwachte levering rond {datum} na goedkeuring van je proef" zodra de eigenaar levertijden instelt. Anders: "We bevestigen de levertijd samen met je digitale proef." | Baymard delivery date [15] |
| B12 | Reviews werken (5 reviews vs. 0 = veel hogere koopkans). Perfecte 5,0-scores wekken wantrouwen, de piek ligt rond 4,2–4,5. | Wij **hebben** geen patch-reviews (zie 01 §4.4). Toon dus geen sterren. Gebruik de echte getuigenis van Jasper en later echte patch-reviews die de eigenaar verzamelt. | Spiegel Research Center [16] |
| B13 | Raakvlakken zijn minstens 1 × 1 cm op mobiel, met ruimte ertussen. | Knoppen, chips en stalen zijn ≥ 44 px hoog. Stalen staan ≥ 8 px uit elkaar. | NN/g touch target size [17] |
| B14 | Sinds 2022 verbiedt de EU nep- of ongecontroleerde reviews (Omnibus/UCPD Annex I). Het CPC-netwerk en de geplande Digital Fairness Act viseren aftelklokken, valse schaarste en drip pricing. | Dit is zowel juridisch als ethisch een harde grens. Zie regel 20. | EU-UCPD [18], analyses [19][20] |
| B15 | In België toon je aan consumenten één totaalprijs inclusief btw. | Toon standaard prijzen incl. btw, met optioneel "excl. btw" klein ernaast voor bedrijven. Gebruik nooit alleen excl. btw. | WER Boek VI / FOD Economie [21] |
| B16 | Maatwerk volgens specificaties van de consument valt buiten het herroepingsrecht. Kiezen uit een paar standaardopties telt niet als maatwerk. | Een eigen ontwerp is maatwerk. Zeg dat eerlijk en vriendelijk vóór de bestelling (zie §7.4), en verstop het niet. | Unizo / Test-Aankoop [22][23] |
| B17 | Een sticky add-to-cart op mobiel geeft in A/B-testen wisselende resultaten: soms meer conversie, soms meer mandjes maar niet meer verkopen. Een rustige balk voelt als gemak, een opdringerige als druk. | Maak de sticky balk slank, met prijs en knop en zonder animatie of geflits. Meet het effect. | cleancommit A/B-tests [24], wiserreview [25] |

---

## 2. De 20 ontwerpregels (concreet, ethisch, bouwbaar)

Elke regel volgt hetzelfde stramien: **Regel**, dan **Waarom**, dan **Zo bouw je het**, dan **Niet doen**.

### Flow en structuur

**R1. Begin met de upload en beloon die meteen.**
- *Waarom:* bij een wizard voor een eenmalige taak telt het begin het meest [9]. Ons onderscheid is de analyse ([03-concurrenten §1]).
- *Zo:* stap 1 is één grote dropzone: "Sleep je logo, tekening of foto hierheen". Een secundaire link "Nog geen ontwerp? Start met tekst" leidt naar de tekstmodus. Na de upload start de analyse, met een animatie die echt werk toont: "Achtergrond herkend… vorm herkend… 7 kleuren gevonden…". Binnen 3 s staat er een mooi patchvoorbeeld.
- *Niet:* eerst om maat, aantal of e-mail vragen. Geen kunstmatige "analyse"-vertraging: de animatie volgt de echte stappen en is snel klaar.

**R2. Werk met vier gelabelde stappen. Voorbeeld en prijs blijven altijd zichtbaar.**
- *Stappen:* **1 Ontwerp** → **2 Techniek** (advies) → **3 Maat & aantal** → **4 Afwerking** → (overzicht + bestellen).
- *Zo:* een stappenbalk met namen, niet alleen met nummers. De huidige stap is vet en blauw `#21A9E2`, afgewerkte stappen krijgen een vinkje en zijn klikbaar. Op desktop staan voorbeeld en prijskaart links of rechts vast. Op mobiel staat het voorbeeld bovenaan en de prijs in de sticky balk.
- *Niet:* het aantal stappen onderweg laten veranderen ("2 van 3" dat ineens "2 van 6" wordt) [9].

**R3. Alles staat vooraf ingevuld, met ons advies.**
- *Waarom:* standaardwaarden worden als aanbeveling gelezen [11]. Dat is een verantwoordelijkheid.
- *Zo:* na de analyse staan techniek, vorm, maat (afgeleid uit de verhouding en het detailniveau, standaard 8 cm), aantal (standaard 10, of 1 als de eigenaar dat kiest, zie 02 "Aantal"-default), rand (merrow bij rond of ovaal, gesneden bij contour), rugzijde (strijklaag) en stofkleur (best contrasterend) klaar. De klant kan **meteen** op "Bestellen" drukken.
- *Niet:* betaalde extra's vooraf aanvinken die het advies niet nodig heeft. Een duurdere optie mag alleen de standaard zijn als het ontwerp ze echt vraagt, en dan zeg je waarom.

**R4. Toon per keuze 3 tot 5 opties. Al de rest zit achter "Meer opties".**
- *Waarom:* keuzestress treft vooral leken zonder voorkeur [13]. Progressive disclosure [10].
- *Zo:* techniekkaarten tonen enkel de 3 best passende technieken. "Toon alle technieken (6)" klapt de rest open. Garenkleuren (411!) toon je nooit als lijst: de tool kiest ze automatisch, en "Kleuren aanpassen" opent een zoekbaar palet.
- *Niet:* opties verbergen die goedkoper zijn of beter passen.

**R5. Verlies nooit werk.**
- *Zo:* bewaar de staat (ontwerp + keuzes) automatisch in `localStorage` na elke wijziging, met try/catch. Bij terugkeer: "Welkom terug. Je ontwerp staat nog klaar." met de knoppen [Verder gaan] en [Opnieuw beginnen]. De terugknop van de browser gaat één stap terug, niet de pagina uit (History API).
- *Niet:* een upload opnieuw vragen na een foutmelding elders [Baymard: velden behouden bij fout, 5].

### Prijs

**R6. Een live prijs, altijd volledig, altijd incl. btw.**
- *Waarom:* B1, B2, B15.
- *Zo:* de prijskaart toont:
  ```
  € 5,98 per stuk  ·  25 stuks
  Patches                       € 149,50
  Borduurkaart (eenmalig)        € {setupFee}   ⓘ
  Verzending                     € 12,10  / gratis
  ─────────────────────────────────────
  Totaal incl. btw              € 216,05
  ```
  Bij elke wijziging telt het bedrag zacht op of af (200 ms), zonder flits. Gebruik exact de rekenregel uit 02 (variatie + add-ons) × randkorting × staffel × aantal, zodat de kassa **hetzelfde bedrag** toont. Een verschil tussen tool en kassa vernietigt vertrouwen.
- *Niet:* "vanaf"-prijzen die in de praktijk nooit gelden, en prijzen excl. btw als hoofdbedrag.

**R7. Meerprijs per optie, in gewone woorden.**
- *Zo:* elke optie toont haar effect op de stuksprijs: "Klittenband (haak + lus) **+ € 1,21/st.**", "Zelf uitknippen **− 10%**". Is de optie gratis: "inbegrepen".
- *Niet:* opties zonder prijs die pas in het totaal opduiken.

**R8. Staffels zijn een hulpmiddel, geen druk.**
- *Zo:* onder het aantal staat een kleine staffelbalk (1 · 10 · 25 · 50 · 100) waarop het huidige aantal is gemarkeerd. Eén zin: "Bij **25 stuks** betaal je **€ 5,66** per stuk (nu € 5,98)." Toon die alleen als het volgende niveau binnen 2× het huidige aantal ligt en het totaal daardoor niet absurd stijgt. Gratis verzending: "Nog € 18,40 tot gratis verzending." alleen tussen € 100 en € 150.
- *Altijd ernaast:* "Heb je er maar 10 nodig? Bestel er dan 10. Je kunt later even snel bijbestellen, want we bewaren je borduurkaart."
- *Niet:* "Bespaar 20%!" in koraalrood knipperend, of een staffel tonen voor gesublimeerd zolang de kassa daar geen korting geeft (02 §3, punt 3). Dat zou een valse belofte zijn.

**R9. Anker op eerlijke vergelijkingen, niet op verzonnen "was"-prijzen.**
- *Zo:* het enige anker dat we gebruiken is echt: de prijs per stuk bij 1 stuk tegenover die bij het gekozen aantal, en de technieken naast elkaar ("Geborduurd € 5,98 · Geprint € 5,18 · Geweven: offerte"). Zet de eenmalige kosten in perspectief: "€ {setupFee} eenmalig, voor 25 stuks is dat € {x} per stuk. Bij een herbestelling betaal je dit niet opnieuw." (alleen tonen als de eigenaar dat bevestigt, zie 02 §8).
- *Niet:* doorgestreepte prijzen die nooit gegolden hebben. Dat is in België ook verboden (prijsverminderingsregels, B15).

### Advies en vertrouwen

**R10. Elke beoordeling heeft drie delen: oordeel, reden, actie.**
- *Zo:* elke techniekkaart krijgt een status (groen, oranje of rood, zie §4) met precies:
  1. **Oordeel** in max. 6 woorden ("Ideaal om te borduren").
  2. **Reden** met een gemeten feit uit de analyse ("Je fijnste lijn is 1,4 mm, ruim boven het minimum van 1 mm.").
  3. **Actie** als knop of zin ("Kies 8 cm of groter" / [Toon hoe het eruitziet]).
- *Niet:* vage adjectieven ("Perfect!", "Super!") zonder reden, of een oordeel zonder uitweg.

**R11. Eén "Ons advies"-badge per keuze, en alleen als die verdiend is.**
- *Waarom:* B9. Het werkt omdat het denkwerk wegneemt, en het blijft alleen werken als het klopt.
- *Zo:* de badge "Ons advies" staat op de techniek met de beste score voor **dit** ontwerp. Er staat een korte uitleg onder ("Meeste detail voor jouw illustratie"). Is het verschil klein, dan zeg je dat: "Geborduurd en geweven zijn hier allebei goed. Borduren voelt rijker aan, weven toont meer detail."
- *Niet:* "Populairst" of "Bestseller" (geen data), en een badge op de duurste optie omdat ze duur is.

**R12. Zeg eerlijk wat er verandert, en laat het zien.**
- *Waarom:* klachten bij concurrenten gaan over "proef ≠ resultaat" (03 §1 pt 8).
- *Zo:* bij vereenvoudiging toon je "Jouw bestand" naast "Zo wordt het geborduurd" (gereduceerd tot N garenkleuren). Benoem wat wegvalt: "De zachte schaduw onder de adelaar wordt een vlak in donkergrijs. Kleine tekst onder 5 mm laten we weg of maken we groter. Dat zie je terug op je digitale proef."
- *Niet:* een gesimuleerd voorbeeld tonen als foto van het eindproduct. Zet er altijd "Voorbeeld ter indicatie" bij, klein maar zichtbaar.

**R13. Bundel de vertrouwensbeloftes bij de bestelknop (en nergens dubbel).**
- *Waarom:* B4. Afgebakende, verzorgde vertrouwenselementen bij de beslisplek werken beter dan zegels die over de pagina verspreid staan.
- *Zo:* direct onder de knop komen 3 regels met kleine iconen (lijn-iconen in `#21A9E2`):
  - ✓ **Digitale proef vóór productie**: pas na jouw akkoord starten we.
  - ✓ **Vanaf 1 stuk**, gemaakt op eigen machines in Kalmthout.
  - ✓ **Vragen?** We antwoorden meestal binnen één werkdag · WhatsApp
- *Niet:* "100% tevredenheidsgarantie", "geld-terug" of "gratis retour" (maatwerk, niet waar), en geen betaallogo's die niet actief zijn.

**R14. Sociale bewijskracht: alleen echt, alleen relevant.**
- *Zo:* toon maximaal één echte getuigenis bij de bestelstap: Jasper, "Super service. Zelfs voor mijn kleine bestelling van 3 stuks kreeg ik professioneel advies." (letterlijk, met naam en rol zoals op de site; instelbaar, standaard **uit** tot de eigenaar het bevestigt). Echte voorbeeldfoto's van patches die de eigenaar uploadt in een "Zo zien onze patches er echt uit"-strip. Voorzie in de admin een veld voor echte reviews (naam, tekst, datum, bron) zodat dat later kan groeien.
- *Niet:* sterren, "500+ tevreden klanten", "12 mensen bekijken dit nu" of verzonnen namen.

**R15. Een persoon achter de tool.**
- *Zo:* in de adviesbox staat een klein portret of logo met "Advies van ons atelier in Kalmthout" en "Dit advies komt uit onze analyse. Bij je digitale proef kijkt een vakman er nog eens naar." Dat is waar en het geruststelt.
- *Niet:* een verzonnen chatbot-persona met een naam.

### Twijfel, opvolging en mobiel

**R16. Altijd twee uitwegen voor twijfelaars: bewaren en vakman.**
- *Zo:* onder de bestelknop staat een secundaire rij:
  - [Bewaar & mail mijn ontwerp]: één veld (e-mail), geen account. Je krijgt een mail met het voorbeeld (PNG), alle keuzes, de prijs en een link die de configurator in exact dezelfde staat heropent.
  - [Laat een vakman meekijken]: maakt een offerteaanvraag met ontwerp, analyse en keuzes vooraf ingevuld. De klant voegt enkel een opmerking toe.
- Bij oranje of rood, bij een ontwerp groter dan 12 cm, of bij geweven, chenille of leder (geen webshopproduct, 02 §2) wordt de **offerte de hoofdknop**, met eerlijke tekst (zie §5.3).
- *Niet:* exit-popups, een mail zonder vinkje voor nieuwsbrief, of verplicht een account.

**R17. Opvolging: één nuttige mail, geen spamreeks.**
- *Zo:* de bewaarmail is transactioneel (de klant vroeg erom). Een herinnering mag alleen met expliciete opt-in ("Stuur me over 3 dagen een herinnering", standaard uit). Bij de offerte: bevestiging met de verwachte reactietermijn.
- *Niet:* automatische "Je mandje wacht nog!"-reeksen zonder toestemming (GDPR).

**R18. Mobiel eerst, met een sticky bestelbalk die niets overlapt.**
- *Zo:* de balk zit onderaan `position: sticky/fixed`, 64 px hoog, wit met een bovenrand. Links "€ 149,50 · 25 st." (Sora 700), rechts één knop "Bestellen" (`#21A9E2`, ≥ 44 px) [17]. Op de studio-pagina verberg je `.bsb-mob-balk` of je zet je balk erboven (zie 01 §1). Ruimte onderaan voor `env(safe-area-inset-bottom)`. De balk verschijnt pas zodra er een ontwerp is. Stalen en chips zijn ≥ 44 px, gebruik `inputmode="numeric"` voor het aantal en een stepper (− / +).
- *Niet:* een balk die stuitert of pulseert, of twee sticky balken op elkaar.

**R19. Fouten: in de buurt, mild en met een oplossing.**
- *Waarom:* B5, B6.
- *Zo:* de fout staat in de context (dropzone, aantal-veld), met het oranje of rode icoon en de tekst. Ze verdwijnt meteen als het opgelost is. Uploadfouten bieden altijd een alternatief: "Mail of WhatsApp het bestand gewoon." Zie §6 voor alle teksten.
- *Niet:* `alert()`, technische meldingen ("MIME type invalid") of de klant de schuld geven ("Je bestand is slecht").

**R20. Overtuig met duidelijkheid, nooit met druk (harde grens).**
- *Verboden in Patch Studio:* aftelklokken, "nog maar X beschikbaar", "X mensen bekijken dit", verzonnen kortingen of was-prijzen, vooraf aangevinkte betaalde opties, confirmshaming ("Nee, ik wil geen mooie patch"), verborgen kosten die pas in de kassa verschijnen, nep-reviews of sterren, en exit-intent-popups.
- *Waarom:* het is verboden of geviseerd door EU-recht (B14) en het past niet bij "ouderwetse service". Bovendien beschadigt het het vertrouwen waar een maatwerkbestelling op draait.
- *Wel toegestaan als het waar is en de eigenaar het instelt:* echte deadlines ("Bestel vóór 10 december voor levering vóór Kerstmis", alleen als die planning echt klopt) en echte capaciteit.

---

## 3. Stappen en microcopy per stap (Vlaams NL, "je")

Algemene stijl: korte zinnen, actieve werkwoorden, getallen als cijfers, € met spatie (`€ 5,98`), komma als decimaalteken.

### Stap 1 — Ontwerp
| Element | Tekst |
|---|---|
| Titel | **Ontwerp je patch** |
| Subtitel | Upload je logo, tekening of zelfs een foto van een bestaande patch. Wij kijken meteen of het te borduren is. |
| Dropzone | **Sleep je bestand hierheen** of *kies een bestand* · PNG, JPG, SVG of PDF · max. 20 MB |
| Geruststelling | Je hoeft geen perfect bestand te hebben. Een schets of foto is genoeg om te beginnen. |
| Alternatief | Nog geen ontwerp? **Start met tekst** |
| Analyse bezig (echte stappen) | Achtergrond zoeken… · Vorm herkennen… · Kleuren tellen… · Fijnste lijnen meten… |
| Analyse klaar | **Klaar. Dit hebben we gezien:** rond ontwerp · 9 kleuren · fijnste lijn 0,8 mm · illustratie met schaduwen |
| Foto van een patch herkend | We hebben je patch uit de foto geknipt. Klopt de rand niet helemaal? **Bijstellen** |
| Vorm herkend | Je ontwerp is al rond, dus we maken er een ronde patch van. *Andere vorm kiezen* |
| Witte achtergrond | Je ontwerp heeft een witte achtergrond. Die laten we weg en we kiezen een stofkleur waarop alles goed uitkomt: **marineblauw**. *Andere stofkleur* |
| Gekleurde achtergrond | We nemen de achtergrond van je ontwerp mee tot aan de rand van de patch. |
| Knop | **Bekijk ons advies →** |

### Stap 2 — Techniek (het hart: zie ook §4)
| Element | Tekst |
|---|---|
| Titel | **Zo zouden wij het maken** |
| Intro (1 zin, dynamisch) | Voor een gedetailleerde illustratie zoals de jouwe raden we **geweven** aan. Borduren kan ook, mits we het wat vereenvoudigen. |
| Badge | Ons advies |
| Vergelijkingslink | **Vergelijk naast elkaar** (opent voorbeeld van de 3 technieken naast elkaar) |
| Meer | Toon alle technieken (6) |
| Voorbeeldlabel | Voorbeeld ter indicatie. Je krijgt altijd eerst een digitale proef. |
| Knop | **Kies maat en aantal →** |

### Stap 3 — Maat & aantal
| Element | Tekst |
|---|---|
| Titel | **Hoe groot en hoeveel?** |
| Maat-uitleg | Maat = de langste zijde van je patch. |
| Maatadvies (dynamisch) | Wij raden **8 cm** aan: dan blijft je kleinste tekst 4 mm hoog en goed leesbaar. |
| Referentie | 8 cm ≈ de breedte van een bankkaart. (bankkaart = 8,56 cm breed, ISO/IEC 7810) |
| Groter dan 12 cm | Groter dan 12 cm maken we ook, tot 30 cm. Dat gaat via een snelle offerte. **Vraag prijs aan** |
| Aantal-hint | Vanaf 1 stuk. Eén stuk om te testen? Dat kan gewoon. |
| Staffelhint | Bij **25 stuks** betaal je **€ 5,66** per stuk (nu € 5,98). |
| Eerlijke tegenhint | Heb je er maar 10 nodig? Bestel er dan 10. Bijbestellen kan altijd. |
| PVC-minimum | PVC maken we vanaf 50 stuks, per 10. We hebben je aantal op **50** gezet. |
| Verzendhint | Nog € 18,40 tot gratis verzending (België en Nederland). |
| Knop | **Afwerking kiezen →** |

### Stap 4 — Afwerking
| Element | Tekst |
|---|---|
| Titel | **Laatste details** |
| Rand – merrow | **Omgezoomde rand** (merrow): de klassieke, stevige rand voor ronde en ovale patches. *Ons advies voor jouw vorm* |
| Rand – gesneden | **Gesneden rand**: volgt je contour precies. Ideaal voor grillige vormen. |
| Rug – strijklaag | **Opstrijkbaar**: makkelijk zelf aan te brengen op katoen en katoen-polyester. |
| Rug – klittenband | **Klittenband**: handig als je de patch wilt kunnen wisselen (haak, lus of beide). |
| Rug – opnaaien | **Zonder rug (opnaaien)**: het sterkst op leer en kleding die vaak gewassen wordt. |
| Stofadvies | Op fleece of waterafstotende stof hecht strijken minder goed. Kies daar opnaaien of klittenband. |
| Knop | **Bekijk overzicht →** |

### Overzicht & bestellen
| Element | Tekst |
|---|---|
| Titel | **Jouw patch** |
| Samenvatting | Geborduurd · rond · 8 cm · 25 stuks · omgezoomde rand · opstrijkbaar · stof marineblauw |
| Bewerken | *Wijzig* (per regel, springt naar de stap) |
| Wat er nu gebeurt (3 stappen) | **1.** Je bestelt. **2.** Binnen {x} werkdagen krijg je je digitale proef in je mailbox. **3.** Jij keurt goed, wij starten de productie. |
| Hoofdknop | **Bestellen: € 216,05** |
| Onder de knop | Pas na jouw akkoord op de digitale proef starten we de productie. |
| Secundair | Bewaar & mail mijn ontwerp · Laat een vakman meekijken |
| Maatwerk-info | Je patch wordt speciaal voor jou gemaakt. Daarom kun je hem na productie niet retourneren, maar je ziet en keurt alles eerst goed op je proef. |

> **Let op voor de bouwers:** "{x} werkdagen" komt uit een instelling van de eigenaar. Is die leeg, dan wordt het: "Je krijgt je digitale proef zo snel mogelijk in je mailbox." Belovingen als "je betaalt pas na de proef" mag je **alleen** tonen als de eigenaar dat zo instelt (zie 01 §3, geruststelling bij de knop).

---

## 4. Statussen: groen / oranje / rood, met vakmansadvies

### 4.1 Betekenis (één definitie, overal hetzelfde)
| Status | Betekenis | Visueel | Gedrag |
|---|---|---|---|
| **Groen** "Goed om te maken" | Het ontwerp kan zoals het is, met een resultaat dat de klant herkent. | mint `#52D7B4` bolletje + ✓, tekst donker `#1E1B1B` (mint is te licht als tekstkleur) | Bestellen is de hoofdknop. |
| **Oranje** "Kan, met een kleine aanpassing" | Het lukt na een concrete, benoemde aanpassing (vereenvoudigen, groter maken, minder kleuren). | amber/`#F5A623`-achtig (of secondary `#FD5C48` zacht) + ! | Bestellen blijft mogelijk. De aanpassing staat er altijd bij, met de knop "Toon wat er verandert". |
| **Rood** "Raden we af" | Het resultaat zal tegenvallen of is technisch niet haalbaar in deze techniek of maat. | `#D93B3B` + ✕ (niet alleen kleur: icoon + tekst, voor kleurenblinden) | De techniek kan niet gekozen worden, of enkel via "Toch vragen aan een vakman". Er is altijd een groen alternatief. |

Toegankelijkheid: kleur is nooit de enige drager. Gebruik altijd icoon + woord, en `aria-live="polite"` voor de analyse-uitkomst.

### 4.2 Microcopy per techniek × status (sjablonen; {…} = analysewaarden)

**Geborduurd**
- 🟢 **Ideaal om te borduren.** Je ontwerp heeft {n} duidelijke kleuren en lijnen van minstens {minLine} mm. Dat geeft een strak, rijk borduurwerk.
- 🟠 **Te borduren, met een kleine vereenvoudiging.** Je ontwerp heeft {n} tinten. Op een patch van {size} cm werken we met maximaal {k} garenkleuren en worden zachte schaduwen vlakken. *[Toon wat er verandert]*
- 🟠 **Te borduren vanaf {minSize} cm.** Je fijnste lijn is {minLine} mm. Op {size} cm wordt die te dun voor garen (minimum ±1 mm). Op {minSize} cm lukt het wel. *[Maak {minSize} cm]*
- 🔴 **Borduren raden we hier af.** Dit is een foto met vloeiende kleurovergangen. Garen kan die niet natekenen, het wordt vlekkerig. **Bedrukt** toont je foto precies zoals hij is. *[Kies bedrukt]*

**Geweven**
- 🟢 **Meeste detail.** Geweven garen is veel fijner dan borduurgaren. Kleine tekst en details tot {x} mm blijven scherp. Ideaal voor gedetailleerde club- of militaire patches.
- 🟠 **Geweven kan, met maximaal {k} kleuren.** We brengen je {n} tinten terug naar {k} garenkleuren. Verlopen worden een fijn raster. *[Toon wat er verandert]*
- Altijd erbij (want geen webshopproduct): Geweven maken we vanaf 100 stuks en via een offerte. Je ontwerp en keuzes sturen we mee. *[Vraag prijs aan]*

**Bedrukt / gesublimeerd**
- 🟢 **Exact zoals je bestand.** Elke kleur, schaduw en verloop komt er precies op. Ideaal voor foto's en illustraties met veel tinten.
- 🟠 **Kan, maar zorg voor een scherper bestand.** Je afbeelding is {px} px breed. Op {size} cm wordt dat licht korrelig. Heb je een groter bestand of PDF? *[Ander bestand uploaden]* Of gewoon zo laten: we zeggen het je op de proef.
- 🔴 *(zelden; bijv. enkel neon of metallic garen gevraagd)* **Bedrukken kan geen neon of metallic.** Wil je dat effect, dan kies je beter geborduurd.

**PVC / rubber**
- 🟢 **Sterk en waterbestendig, met reliëf.** Je ontwerp heeft {n} vlakke kleuren en duidelijke lijnen, ideaal voor PVC.
- 🟠 **PVC kan met {k} kleuren.** Schaduwen worden vlakken. Let op: PVC maken we vanaf 50 stuks, en er komt eenmalig een mal bij.
- 🔴 **PVC raden we af voor foto's en verlopen.** PVC werkt met vlakke, gegoten kleuren.

**Chenille / leder** (offerte-technieken)
- 🟢/🟠 tekst zoals hierboven + **"Dit maken we op aanvraag. Je ontwerp gaat mee in je aanvraag."**

### 4.3 Samenvattende adviesbox bovenaan stap 2 (sjablonen per ontwerptype)
- **Vlak logo:** "Een helder logo met {n} kleuren. Dit is precies waar borduren voor gemaakt is. Wij zouden het **borduren op {size} cm**."
- **Illustratie met tinten (geval eigenaar):** "Een gedetailleerde illustratie met schaduwen. Zulke patches worden in de praktijk meestal **geweven** (meeste detail) of **geborduurd na vereenvoudiging** tot ±{k} garenkleuren. Wil je het exact zoals je bestand? Dan is **bedrukt** de keuze."
- **Echte foto:** "Dit is een foto. Garen kan geen fotografische overgangen maken, dus **bedrukt** geeft hier het mooiste resultaat."
- **Foto van een bestaande patch:** "We herkenden een foto van een bestaande patch en hebben die uitgeknipt. Wil je een **nabestelling**? Dan maken we hem zo getrouw mogelijk na. Op je proef zie je hoe."

---

## 5. Hoe "advies als een vakman" klinkt

### 5.1 Stemregels
1. **Eerst het oordeel, dan het waarom.** Een vakman zegt "Dat borduur ik je op 8 cm, op 6 wordt die tekst te klein." en niet "Er zijn meerdere factoren…".
2. **Meten, niet bewieroken.** Vervang "Prachtig ontwerp!" door "Je lijnen zijn 1,4 mm dik, dat borduurt strak." Het compliment zit in de deskundigheid.
3. **Ik/wij-vorm met eigenaarschap:** "Wij zouden…", "Wij raden aan…", "Bij ons atelier…". Nooit "Het systeem adviseert".
4. **Vakwoord + uitleg tussen haakjes, één keer:** "merrow (omgezoomde rand)", "borduurkaart (het borduurprogramma voor jouw ontwerp)".
5. **Eerlijk over beperkingen en over goedkopere alternatieven.** Een goede vakman zegt ook "Dat hoef je niet te kopen". Dat bouwt het vertrouwen dat de bestelling binnenhaalt.
6. **Concreet alternatief bij elk "nee".** Laat nooit een doodlopend rood oordeel staan.
7. **Rustig en zeker.** Geen uitroeptekens in het advies (max. 1 per scherm, bv. "Klaar!"). Geen hoofdletters als nadruk.
8. **Kort.** Advieszinnen ≤ 20 woorden, adviesblok ≤ 3 zinnen. Meer staat achter "Waarom?".

### 5.2 Wel / niet
| Niet | Wel |
|---|---|
| "Helaas is uw ontwerp ongeschikt." | "Borduren raden we hier af: de foto heeft te veel kleurovergangen. Bedrukt toont hem precies zoals hij is." |
| "Perfect!!! 🎉" | "Ideaal om te borduren. Je ontwerp heeft 4 heldere kleuren." |
| "Algoritme detecteert 47 kleuren." | "We zien veel tinten (een illustratie met schaduwen). Bij borduren worden dat ±8 garenkleuren." |
| "Upgrade naar Premium-rand!" | "Een omgezoomde rand maakt ronde patches extra stevig. Dat is ons advies voor jouw vorm." |
| "Slechts vandaag 20% korting!" | "Bij 25 stuks betaal je € 5,66 per stuk." |
| "Wij garanderen 100% tevredenheid." | "Je krijgt eerst een digitale proef. Pas na jouw akkoord starten we." |

### 5.3 Teksten voor het offerte-pad (eerlijk, geen tweederangs-gevoel)
- Kop: **Laat een vakman meekijken**
- Tekst: We bekijken je ontwerp persoonlijk en sturen je een voorstel met prijs. Je ontwerp, onze analyse en je keuzes sturen we al mee. Je hoeft niets opnieuw uit te leggen.
- Velden: naam, e-mail, (telefoon optioneel), opmerking ("Waar ga je de patch voor gebruiken?").
- Knop: **Verstuur mijn aanvraag**
- Bevestiging: **Bedankt, {voornaam}.** We hebben je ontwerp goed ontvangen en antwoorden meestal binnen één werkdag. Liever sneller? Stuur ons een WhatsApp: 0491 06 32 01.

### 5.4 Bewaren & mailen
- Knop: **Bewaar & mail mijn ontwerp**
- Paneel: Je krijgt een mail met je voorbeeld, je keuzes en een link om verder te gaan waar je stopte. Geen account nodig.
- Vinkje (standaard uit): Stuur me over 3 dagen één herinnering.
- Bevestiging: **Verstuurd naar {e-mail}.** Je ontwerp staat klaar via de link in die mail.
- Mailonderwerp: *Je patchontwerp bij Borduurservice België*
- Mailtekst (kern): Hallo, hier is je patch zoals je hem samenstelde: {samenvatting}, {prijs} incl. btw. Klik op **Verder met mijn ontwerp** om te bestellen of iets aan te passen. Vragen? Antwoord gewoon op deze mail. — Het team van Borduurservice België, Kalmthout

---

## 6. Foutmeldingen en randgevallen (in context, met oplossing)

| Situatie | Status | Tekst |
|---|---|---|
| Bestandstype niet ondersteund | 🟠 | Dit bestandstype kunnen we hier niet openen. Gebruik PNG, JPG, SVG of PDF, of **mail** of **WhatsApp** het ons gewoon. |
| Bestand te groot | 🟠 | Dit bestand is groter dan 20 MB. Probeer een kleinere versie, of stuur het via **mail**. |
| Zeer lage resolutie | 🟠 | Je afbeelding is klein ({px} px). We kunnen ermee verder, maar een groter bestand geeft een scherper resultaat. *[Ander bestand]* *[Zo verdergaan]* |
| Upload mislukt (netwerk) | 🔴 | Het uploaden lukte niet. Controleer je verbinding en probeer opnieuw. *[Opnieuw proberen]* |
| Analyse twijfelt (vorm niet zeker) | 🟠 | We zijn niet helemaal zeker van de vorm. Klopt dit? *[Rond]* *[Ovaal]* *[Rechthoek]* *[Contour]* |
| Leeg/bijna wit ontwerp | 🟠 | We zien bijna niets in je bestand. Is het misschien wit op transparant? *[Toon op donkere achtergrond]* |
| Aantal onder minimum (PVC) | 🟠 | PVC maken we vanaf 50 stuks. We hebben je aantal op 50 gezet. *[Liever minder? Kies geborduurd]* |
| Aantal geen getal / 0 | 🟠 (na verlaten veld) | Vul een aantal in vanaf 1. |
| Maat > 12 cm | 🟠 | Op deze maat maken we graag een prijs op maat. *[Vraag prijs aan]* |
| Winkelmand-fout (Woo) | 🔴 | Er liep iets mis bij het toevoegen aan je winkelmand. Je ontwerp is bewaard. *[Opnieuw proberen]* of *[Stuur als aanvraag]* |
| Geen JS / oude browser | — | Je browser ondersteunt de ontwerptool niet. Vraag je patch eenvoudig aan via ons **offerteformulier**. |
| Geldig veld (positief) | 🟢 | ✓ (enkel icoon, geen tekst nodig) |

Regels: de melding staat naast het element. Toon ze niet bij de eerste toetsaanslag, maar na het verlaten van het veld of na een pauze van 600 ms. Ze verdwijnt zodra het opgelost is en de focus blijft behouden.

---

## 7. Specifieke componenten

### 7.1 Prijskaart (desktop rechts, sticky; mobiel in sticky balk + uitklapbaar "Prijsdetails")
- Hoofdbedrag: Sora 700, 28–32 px, `#1E1B1B`. Daaronder: "incl. btw · {prijs per stuk} per stuk".
- Toggle (optioneel, door de eigenaar aan te zetten): "Prijzen excl. btw tonen (voor bedrijven)".
- Uitklapbaar "Hoe is deze prijs opgebouwd?" met alle regels (R6).

### 7.2 Sticky mobiele balk
```
┌─────────────────────────────────────────────┐
│ € 216,05            [   Bestellen   ]       │
│ 25 st. · incl. btw                          │
└─────────────────────────────────────────────┘
```
- Vóór stap 4 heet de knop "Volgende" (scrollt of springt naar de volgende stap). Vanaf het overzicht heet hij "Bestellen".
- Tik op het bedrag opent de prijsdetails (bottom sheet).

### 7.3 Vergelijken naast elkaar
Drie kleine voorbeelden (geborduurd, geweven, bedrukt) met elk 1 regel en prijs per stuk. Dit vervangt de nood om terug te bladeren tussen stappen (B7).

### 7.4 Maatwerk/herroeping (eerlijk, op het overzicht)
"Je patch wordt speciaal voor jou gemaakt. Daarom kun je hem na productie niet retourneren, maar je ziet en keurt alles eerst goed op je digitale proef." Juridische formulering laten nakijken door de eigenaar of de algemene voorwaarden (B16).

---

## 8. Franse vertaling van de sleutelzinnen (FR-BE, "vous", zakelijk-vriendelijk)

> Op de site is geen Franse versie gevonden (01 §1). Zet alles in de `.pot`/vertaalbestanden zodat FR later kan. In het Frans is "vous" de norm voor een webshop, ook als het Nederlands "je" gebruikt.

| NL | FR |
|---|---|
| Ontwerp je patch | Créez votre écusson |
| Sleep je bestand hierheen of kies een bestand | Glissez votre fichier ici ou choisissez un fichier |
| Je hoeft geen perfect bestand te hebben. Een schets of foto is genoeg om te beginnen. | Pas besoin d'un fichier parfait : un croquis ou une photo suffit pour commencer. |
| Bekijk ons advies | Voir notre conseil |
| Zo zouden wij het maken | Voici comment nous le réaliserions |
| Ons advies | Notre conseil |
| Ideaal om te borduren. | Idéal pour la broderie. |
| Te borduren, met een kleine vereenvoudiging. | Brodable, avec une légère simplification. |
| Borduren raden we hier af. | Nous déconseillons la broderie pour ce design. |
| Meeste detail. | Le plus de détails. |
| Exact zoals je bestand. | Exactement comme votre fichier. |
| Toon wat er verandert | Voir ce qui change |
| Hoe groot en hoeveel? | Quelle taille et combien ? |
| Vanaf 1 stuk. | À partir d'une pièce. |
| Bij 25 stuks betaal je € 5,66 per stuk. | À partir de 25 pièces, vous payez 5,66 € par pièce. |
| Nog € 18,40 tot gratis verzending. | Plus que 18,40 € pour la livraison gratuite. |
| Laatste details | Derniers détails |
| Omgezoomde rand (merrow) | Bordure surjetée (merrow) |
| Opstrijkbaar | Thermocollant |
| Klittenband | Velcro (scratch) |
| Jouw patch | Votre écusson |
| Bestellen | Commander |
| Pas na jouw akkoord op de digitale proef starten we de productie. | La production ne démarre qu'après votre validation de l'épreuve numérique. |
| Digitale proef vóór productie | Épreuve numérique avant production |
| Bewaar & mail mijn ontwerp | Enregistrer et m'envoyer mon design |
| Laat een vakman meekijken | Demander l'avis d'un artisan |
| We antwoorden meestal binnen één werkdag. | Nous répondons généralement sous un jour ouvrable. |
| Voorbeeld ter indicatie. | Aperçu à titre indicatif. |
| Je patch wordt speciaal voor jou gemaakt… | Votre écusson est fabriqué spécialement pour vous… |

(Prijsnotatie FR: `5,66 €`. NL: `€ 5,66`. Gebruik `Intl.NumberFormat('nl-BE'|'fr-BE', {style:'currency',currency:'EUR'})`.)

---

## 9. Meten (zonder externe diensten, wel compatibel met de bestaande Jetpack/GA)

Stuur lichte events via `dataLayer.push` (als dat bestaat) of een eigen WP-REST-teller. Zonder persoonsgegevens:
`ps_upload`, `ps_analysis_done{type,shape}`, `ps_technique_select{technique,was_recommended}`, `ps_step{n}`, `ps_price_view`,
`ps_add_to_cart{technique,qty,total}`, `ps_save_email`, `ps_quote_request`, `ps_error{code}`.
Kerncijfers voor de eigenaar: upload→advies (%), advies→mand (%), hoe vaak het advies gevolgd wordt (%), offertes uit de tool en de meest voorkomende fouten.
Test (indien gewenst) slechts één ding tegelijk, bv. de sticky balk aan of uit (B17).

---

## 10. Ethische checklist (vóór elke release afvinken)

- [ ] Tool-prijs = kassaprijs (testcase per techniek, maat en staffel; zie de rekenregel in 02).
- [ ] Alle eenmalige kosten en de verzending staan zichtbaar vóór "Bestellen".
- [ ] Geen sterren, geen tellers, geen aftelklokken, geen schaarste die niet echt is.
- [ ] Getuigenissen zijn letterlijk, echt, met naam/rol en door de eigenaar bevestigd. Standaard uit.
- [ ] De "Ons advies"-badge volgt uit de analyse, niet uit de marge. De goedkopere techniek mag winnen.
- [ ] Geen vooraf aangevinkte betaalde extra's zonder uitleg.
- [ ] Een herinneringsmail alleen na een expliciete opt-in.
- [ ] Er staat "Voorbeeld ter indicatie" bij het voorbeeld.
- [ ] Het maatwerk- of retourbeleid wordt vóór de bestelling vermeld.
- [ ] Levertijd en "betalen na proef" worden alleen getoond als de eigenaar ze heeft ingesteld.
- [ ] Kleur is nooit de enige drager van een status (icoon + tekst).

---

## 11. Bronnen

1. Baymard — Cart abandonment rate (redenen, "extra costs too high"): https://baymard.com/lists/cart-abandonment-rate · https://baymard.com/blog/cart-abandonment-rate · samenvatting eMarketer: https://www.emarketer.com/content/extra-costs-are-the-top-reason-consumers-abandon-online-carts
2. Baymard — Price transparency of configuration options (guideline 3108): https://baymard.com/guidelines/3108-price-transparency-of-configuration-options
3. Baymard — Current state of checkout UX / reduce cart abandonment (19% account): https://baymard.com/blog/current-state-of-checkout-ux · https://baymard.com/blog/reduce-cart-abandonment
4. Baymard — Perceived security of payment form: https://baymard.com/blog/perceived-security-of-payment-form · CXL trust seals: https://conversionxl.com/research-study/trust-seals/
5. Baymard — Inline form validation: https://baymard.com/blog/inline-form-validation
6. Baymard — Adaptive validation error messages: https://baymard.com/blog/adaptive-validation-Error-messages
7. NN/g — Error-message guidelines: https://www.nngroup.com/articles/error-message-guidelines/
8. NN/g — Error messages scoring rubric: https://www.nngroup.com/articles/error-messages-scoring-rubric/
9. NN/g — Wizards: definition and design recommendations: https://www.nngroup.com/articles/wizards/
10. NN/g — Progressive disclosure: https://www.nngroup.com/videos/progressive-disclosure/
11. UX Magazine — The psychology of defaults: https://uxmag.com/articles/the-psychology-of-defaults-how-pre-selected-options-influence-behavior
12. CXL/Speero — Pricing page study (highlight recommended plan): https://conversionxl.com/research-study/pricing-page-study-highlight/ · https://speero.com/post/the-effects-of-highlighting-a-recommended-pricing-plan-original-research
13. Scheibehenne, Greifeneder & Todd (2010), Can there ever be too many options? (meta-analyse): https://abcwest.cogs.indiana.edu/pmwiki/pdf/scheibehenne.options.2010.pdf
14. The Decision Lab — Choice overload: https://thedecisionlab.com/biases/choice-overload-bias
15. Baymard — Shipping speed vs. delivery date: https://baymard.com/blog/shipping-speed-vs-delivery-date
16. Spiegel Research Center — How online reviews influence sales: https://spiegel.medill.northwestern.edu/How-Online-Reviews-Influence-Sales
17. NN/g — Touch target size: https://www.nngroup.com/articles/touch-target-size/
18. Europese Commissie — Unfair Commercial Practices Directive: https://commission.europa.eu/law/law-topic/consumer-protection-law/unfair-commercial-practices-law/unfair-commercial-practices-directive_en
19. Makeinfluence — Fake and manipulated reviews: what the EU Omnibus directive bans: https://www.makeinfluence.com/en/academy/fake-and-manipulated-reviews-what-the-eu-omnibus-directive-bans
20. Slaughter and May — UK and EU shifts in consumer protection (DFA, drip pricing, scarcity): https://www.slaughterandmay.com/horizon-scanning/2026/digital/uk-and-eu-shifts-in-consumer-protection/
21. Legalnews.be — FOD Economie over prijsvermelding (één totaalprijs incl. btw, WER): https://legalnews.be/bouw-vastgoed/fod-economie-verduidelijkt-prijsvermelding-bij-vernieuwbouw-gsj-advocaten/ · Altius over prijsverminderingen: https://www.altius.com/en/news/retail-promotions-pricing-legal-essentials-for-retailers-part-1-price-reduction-announcements-the-legal-framework-and-best-practices/
22. Unizo — Herroepingsrecht bij e-commerce (uitzondering maatwerk): https://www.unizo.be/advies/welke-gevallen-heeft-de-consument-geen-herroepingsrecht
23. Test-Aankoop — Afzien van een aankoop: https://www.test-aankoop.be/familie-prive/consumentenrechten/dossier/afzien-van-een-aankoop-een-automatisch-recht
24. Clean Commit — A/B-tests sticky add-to-cart (mobiel): https://cleancommit.io/ab-tests/mobile-bottom-sticky-add-to-cart-button/ · https://cleancommit.io/blog/sticky-add-to-cart-buttons/
25. WiserReview — Sticky add to cart (rustig vs. opdringerig): https://wiserreview.com/glossary/sticky-add-to-cart/
26. Interne bronnen: `docs/research/01-merk-en-site.md` (toon, beloftes, getuigenissen, contact), `02-huidig-aanbod.md` (prijzen, staffel, kosten, kassagedrag), `03-concurrenten.md`.

## 12. Open punten voor de eigenaar
1. Mogen de getuigenissen (vooral die van Jasper) in de tool? Verzamel je voortaan patch-reviews (echt, met toestemming)?
2. Wat is de typische tijd tot digitale proef, en de levertijd per techniek? (Nodig voor de leverdatum-tekst, B11.)
3. Bestellen als gast in WooCommerce: staat dat aan?
4. Betaalt de klant bij bestelling of na goedkeuring van de proef? (Bepaalt de tekst onder de knop.)
5. Standaardaantal: 10 (eerste staffel) of 1 ("eerst één testen")?
6. Mag een herinneringsmail na opt-in? Welk afzenderadres?
