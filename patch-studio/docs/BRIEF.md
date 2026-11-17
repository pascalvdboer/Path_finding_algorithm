# Opdracht: Patch Studio voor borduurservicebelgie.be

## Doel van de klant (eigenaar Borduurservice België / IMTA)
Een WordPress/WooCommerce-plugin waarmee klanten online hun eigen patch ontwerpen (alle soorten patches:
geborduurd, geweven, bedrukt/sublimatie, chenille, PVC/rubber, leder/gelaserd, ...). De tool moet:
- meteen tonen of het ontwerp te borduren is, of het beter een print/geweven/... wordt, en waarom;
- zo indrukwekkend zijn, met zulk perfect, eerlijk deskundig advies, dat een klant bijna niet anders kan dan op "Bestellen" te klikken;
- snel blijven (site gebruikt WP Rocket) en geen dure abonnementen/externe diensten vereisen (analyse in de browser);
- veel eenvoudiger in te stellen en te gebruiken zijn dan Fancy Product Designer (dat vonden ze te moeilijk).

## Wat we weten over de website (opgehaald 16-11-2026)
- WordPress + WooCommerce, thema **Hello Elementor** + **Elementor Pro**. Plugins: woocommerce-product-addons,
  woo-discount-rules, wp-rocket, jetpack, cookie-law-info, super-product-swatches, conditional-extra-fees-for-woocommerce, google-listings-and-ads.
- Elementor globale kleuren: primary #5926C5 (paars), secondary #FD5C48 (koraal/oranjerood), accent/tekst-donker #1E1B1B,
  #21A9E2 (blauw), #52D7B4 (mint), #323232, #959595 (tekst), #BDBDBD, #F5F5F5, #FCFCFC.
- Lettertype: **Sora** (koppen, 700).
- Toon: Vlaams Nederlands, mix van "je" (nieuwere teksten) en "u" (oudere). Nieuwe teksten gebruiken "je".
- Kernbeloftes (letterlijk): "Vanaf 1 stuk", "Digitale proef vooraf" / "Van elk ontwerp krijg je een digitale proef ter goedkeuring vóór de productie start",
  "Patches op maat – vanaf 1 stuk, met digitale proef vóór productie", "Gevestigd in Kalmthout", "Ouderwetse service",
  "Persoonlijke service op de traditionele manier", "Dankzij eigen apparatuur kunnen we flexibel leveren", "Actief in België en Nederland",
  "Je hoeft geen perfect bestand te hebben. Een logo, schets of idee is genoeg om te beginnen".
- Menu bevat o.a. Patches & Badges > Patches Laten Maken, Geborduurde Patches; Winkel > Patches; Offerte aanvragen; Kennisbank; Digitaliseren; Borduurproces.
- Er is al een "ontwerp het Zelf"-knop op de homepage.

## Referentie
- `reference/demo-v1.html`: eerste losse demo (analyse in browser: kleuren tellen, foto/verloop detectie, fijnste lijndikte via
  distance transform, oordeel per techniek, canvas-voorbeeld met texturen, prijs met staffel). Bouw hierop voort en overtref het.

## Eerlijkheid
Advies moet kloppen en eerlijk zijn: geen valse schaarste, nep-reviews of verzonnen cijfers. Overtuigen gebeurt via deskundigheid,
duidelijkheid, vertrouwen (digitale proef, vanaf 1 stuk, persoonlijke service) en een vlotte flow. Voorbeeldprijzen duidelijk configureerbaar.

## BELANGRIJKE FEEDBACK VAN DE EIGENAAR (na test van demo-v1 op gsm)
De eigenaar uploadde een eigen, volledig ontworpen RONDE patch (vierkante afbeelding met een cirkelvormige patch erin,
gedetailleerde illustratie met schaduwen, tekst rond de rand). demo-v1 deed dit fout:
1. Het snijdt de helft van het ontwerp weg: bij "foto" werd de afbeelding als "cover" over de patchvorm gelegd. NOOIT bijsnijden:
   het ontwerp moet altijd volledig zichtbaar zijn (contain).
2. De vorm werd niet herkend. Als het ontwerp zelf al een patchvorm is (rond, ovaal, rechthoek, schild, contour) moet de tool dat
   herkennen, automatisch die vorm kiezen en het ontwerp de patch laten vullen (de eigen rand van het ontwerp respecteren).
   Achtergrond rond de vorm (wit, zwart, transparant, effen kleur) moet als achtergrond herkend worden, ook als het ontwerp veel tinten heeft.
3. Het ontwerp werd "Foto" genoemd. Een gedetailleerde illustratie met schaduwen is GEEN foto: dit soort ontwerpen (militaire/club-patches)
   wordt in de praktijk vaak geborduurd of geweven na vereenvoudiging door de digitaliseerder. Maak onderscheid tussen
   vlak logo / illustratie met tinten / echte foto, en geef bij illustraties eerlijk vakadvies (bv. geweven = meeste detail,
   geborduurd = mits vereenvoudiging tot X garenkleuren, bedrukt = exact zoals het bestand).
Testbeeld: patch-studio/reference/eigenaar-ronde-patch.png
Tweede testbeeld van de eigenaar: patch-studio/reference/eigenaar-foto-patch.jpg = GSM-FOTO van een echte (bedrukte) ronde patch op
witte stof met plooien en ongelijk licht; de cirkel raakt bijna de zijkanten. Klanten zullen zulke foto's uploaden!
De analyse moet dit aankunnen (zie reference/demo-v2.html, die dit nu oplost): achtergrond = mediaan van de randpixels, flood fill
die alleen over zachte overgangen groeit (stop aan harde rand), check of de rand grotendeels effen is (anders full-bleed ontwerp),
losse vlekjes (stofstructuur) verwijderen, daarna vorm herkennen (rond/ovaal/rechthoek) en het ontwerp de patch laten vullen.
Idealiter ook: perspectief/scheefheid tolereren en de klant tonen "we hebben je patch uit de foto geknipt", met een knop om bij te stellen.

## EIS VAN DE EIGENAAR: altijd een mooie, volle patch
"Het moet ook altijd de achtergrond opvullen met de bestaande achtergrond tekening en/of kleur van het bestand (tenzij wit).
Het moet een mooie patch zijn ten alle tijden."
- Heeft het bestand een gekleurde of getekende achtergrond (niet wit), dan wordt die de achtergrond van de patch: uitgelijnd met
  het ontwerp, tot aan de rand van de patchvorm. Waar het bestand ophoudt, vult de achtergrondkleur verder.
- Witte (of transparante) achtergrond = papier, niet overnemen: kies automatisch de stofkleur waarop het ontwerp het best uitkomt.
- Nooit lege, rare of afgesneden stukken in het voorbeeld. Zie reference/demo-v2.html voor de huidige aanpak.

## EIS VAN DE EIGENAAR: zelf schikken en tekst toevoegen
"Ze moeten de afbeelding kunnen vergroten en verkleinen binnen de rand, en kunnen veranderen van positie. Misschien tekst toevoegen
in bepaald font of vorm etc."
- Ontwerp: grootte-schuifbalk (20-200%), slepen in het voorbeeld (muis + touch + pijltjestoetsen), knoppen Centreren / Passend maken.
  Alles blijft binnen de rand van de patch (geknipt op de vorm, rand erover).
- Tekstlagen: meerdere teksten, lettertypes (Sora, Oswald, Graduate, Roboto Slab, Cinzel, Pacifico), garenkleuren,
  letterhoogte in mm, vorm Recht / Boog boven / Boog onder (langs de rand, slepen = draaien en afstand tot rand).
  Ook een patch met alleen tekst (zonder afbeelding).
- De borduurcheck controleert ook de tekst: minimale letterhoogte per techniek (dunne/script-lettertypes strenger) en tekstkleuren
  tellen mee in het aantal garenkleuren. Zie reference/demo-v2.html voor de werkende aanpak.

## BESLISSING VAN DE EIGENAAR: premium-kwaliteit
"Dit moet echt een betaalde plug-in worden." Verduidelijking: **premium voor de eigen shop** (borduurservicebelgie.be),
van dezelfde kwaliteit als een betaalde commerciële plugin. Als hij later toch verkocht wordt, dan via de **eigen WooCommerce-winkel**
(licentiesleutel + update-server). Bouw dus: geen hardgecodeerde merkgegevens (alles via instellingen), nette
architectuur, WordPress Coding Standards, en een optionele, standaard uitgeschakelde licentie/update-client zodat verkoop later kan.

## WENSEN VAN DE EIGENAAR (17-11): gebruiksvriendelijk + verdienen met diensten
"Super gebruiksvriendelijk moet het zijn. Misschien ook met een ChatGPT grafische ontwerp sectie die ze kunnen gebruiken
tegen betaling. Dan verdienen we daar ook een beetje geld aan. Diensten leveren op de site. Slim."
1. **Gebruiksvriendelijkheid is eis nummer 1** voor alles: een leek moet zonder uitleg binnen 2 minuten een patch kunnen ontwerpen
   en bestellen, op gsm én desktop. Weinig keuzes tegelijk, slimme standaarden, gewone taal.
2. **AI-ontwerpstudio (betaald):** de klant beschrijft in gewone woorden wat hij wil ("een wolf met een bijl voor onze
   motorclub, rond, zwart-rood") en krijgt een paar ontwerpvoorstellen, gemaakt door een beeld-AI (bv. OpenAI's
   beeldmodel, de motor achter ChatGPT-afbeeldingen; via de API, sleutel blijft op de server). Voorstellen zijn
   meteen geschikt om te borduren (vlakke kleuren, beperkt palet, dikke lijnen, effen/transparante achtergrond) en
   gaan met één klik de studio in, inclusief de borduurcheck. Verder verfijnen via chat ("maak de wolf bozer").
   Verdienmodel (instelbaar): bv. ontwerpkredieten als WooCommerce-product (pakket van X generaties voor €Y), een paar
   gratis proefgeneraties, en het betaalde bedrag (deels) terug als korting bij een patchbestelling, zodat het de
   verkoop van patches stimuleert. Kostenbewaking (dag/maandlimiet, kost per generatie gelogd), misbruik- en
   inhoudsfilter, geen merken/logo's van anderen namaken, duidelijke voorwaarden over gebruiksrechten.
3. **Diensten op de site verkopen**, slim geïntegreerd in de flow wanneer ze echt helpen, bv.: "Laat onze ontwerper je
   logo hertekenen/vectoriseren", "Digitaliseren van je eigen borduurbestand", spoedlevering, extra proefronde,
   fysiek staal. Alles als instelbare WooCommerce-producten/opties; eerlijk aanbieden (alleen tonen als relevant).

## EXTRA PRODUCTEN GEKOZEN DOOR DE EIGENAAR (17-11)
1. **Naamstroken & klittenband**: een aparte, supersnelle modus in de studio voor naamstroken en kleine ID-patches
   (naam, bloedgroep, functie, vlag, rang/afdeling) op klittenband (haak + lus tegenstuk) of opnaai/opstrijk, voor werkkleding,
   hulpdiensten, beveiliging, airsoft, motorclubs. Standaardformaten (bv. 10x2,5 cm, 12x3 cm, 8x5 cm), stofkleuren (zwart,
   navy, olijf, coyote, multicam-achtig, wit, fluo), letterkleuren, lettertypes, rand; meerdere namen in één bestelling
   (lijst plakken/CSV: één regel per persoon), live voorbeeld, prijs per stuk en staffel.
2. **Zelfde ontwerp, meer producten** (cross-sell): na/tijdens het ontwerpen met één klik hetzelfde ontwerp tonen en bestellen
   als patch op pet/muts, op hoodie/jas genaaid, als PVC- of lederen sleutelhanger/tassenlabel, of ingekaderd als cadeau
   (kader/schaduwdoos met gelaserd naamplaatje). Realistische mockups, eerlijk geprijsd (digitaliseren al gedaan = voordeel),
   alleen tonen wat technisch past bij het ontwerp. Alles instelbaar als WooCommerce-producten.

## EISEN VAN DE EIGENAAR (17-11, avond) — zie docs/STUDIO-REDESIGN.md "HARDE EISEN VAN DE EIGENAAR (17-11, avond)"
Proactief tonen wat ze kunnen maken (galerij, sjablonen, suggesties), veel kunnen aanpassen (progressief), en bij borduren
altijd werken met de echte Madeira Polyneon-kleuren (nummer + naam, volledige kaart van 411 kleuren, automatische omzetting).
