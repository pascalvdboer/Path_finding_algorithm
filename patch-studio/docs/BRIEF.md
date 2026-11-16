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
