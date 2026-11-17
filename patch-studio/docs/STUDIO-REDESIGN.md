# Herontwerp: van formulier naar ontwerpstudio

## Oordeel van de eigenaar over versie 1.0 (dist/patch-studio-demo.html)
"Het is te basic en niet goed doordacht." Op de vraag wat het meest stoort: **"Ziet er niet indrukwekkend uit"**.
Gewenst gevoel: **Ontwerpstudio** — groot canvas centraal, alles live aanpasbaar, advies als slimme assistent ernaast.
"Zoals Canva, maar voor patches." Geen voorbeeldsite: "verras me, maar duidelijk beter dan de concurrenten".

## Wat er nu mis is (eigen analyse, screenshots in docs/screenshots/)
- Het voelt als een invulformulier met 5 genummerde stappen en veel tekst; het voorbeeld is een kleine, vlakke afbeelding opzij.
- De patch-render oogt vlak: geen echte garenglans, geen steekrichting, geen reliëf, geen licht, geen stofstructuur van dichtbij.
- Advies = lijstje vinkjes. Je ziet nergens zij-aan-zij hoe JOUW patch geborduurd / geweven / bedrukt / PVC / ... wordt.
- De tool kiest zelf een techniek (bv. gesublimeerd) zonder dat de klant het verschil ziet.
- Tegenstrijdige meldingen (groen "uitgeknipt" + oranje "dit lijkt een patch").
- Geen voorbeeld op een kledingstuk dat indruk maakt. Pijltjesknoppen-kruis en schuifbalken ogen als een admin-paneel.
- Prijsblok domineert; de patch zelf moet de held zijn.

## Doel van versie 2
Een klant moet bij het openen denken "wauw". De patch is de held: groot, fotorealistisch, met licht en diepte,
draaibaar/zoombaar, en meteen op een kledingstuk te zien. De studio voelt als een premium creatief product.

### Studio-indeling (desktop)
- Centraal: groot canvas (≥ 60% van de breedte) met de patch in hoge kwaliteit op een mooie, zachte achtergrond (stof/tafel),
  zoomen (scroll/pinch), direct manipuleren (slepen, schaal-handgrepen, tekst dubbelklikken om te bewerken), undo/redo.
- Weergave-schakelaar boven het canvas: "Close-up" / "Op polo" / "Op pet" / "Op jas" / "Op rugzak" (realistische mockups,
  zonder externe afbeeldingen: canvas/SVG met belichting en plooien).
- Links: compacte gereedschapsbalk met iconen (Ontwerp, Tekst, Vorm, Rand, Kleuren, Achterkant) die een paneel openen.
- Rechts: de **assistent** — vakadvies als gesprek/kaarten in de toon van een vakman uit het atelier in Kalmthout,
  met een **techniekvergelijker**: kleine live renders van JOUW ontwerp in elke techniek naast elkaar (klik = kiezen),
  elk met 1 zin waarom, prijs per stuk en levertijd. Aanbeveling duidelijk maar de klant ziet waarom.
- Onder: slanke actiebalk met maat, aantal (staffelhint), totaalprijs en "Bestellen" + "Offerte / proef aanvragen".
- Mobiel: canvas bovenaan (sticky), gereedschap als onderste tabbalk met bottom sheets, assistent als uitklapbare kaart.

### Rendering (de "wow")
- Geborduurd: satijnsteek met steekrichting per vlak (langs de vorm), garenglans die met de lichtrichting meebeweegt
  (normal map / anisotrope highlight), fill-steken voor grote vlakken, lichte reliëfschaduw, onderliggende twill zichtbaar.
- Merrow-rand als echte opgerolde rand met wikkelsteken en volume; hot-cut als strakke geschroeide rand.
- Geweven: fijne, vlakke weefstructuur, scherpe details. Bedrukt/sublimatie: zacht textiel met lichte glans.
- Chenille: pluizige lussen. PVC: rubber met afgeronde randen, zachte glans, 2D/3D niveaus. Leder: geperst/gegraveerd met diepte.
- Subtiele animatie bij wisselen van techniek (crossfade/"stitch-in"), respecteer prefers-reduced-motion.
- Snel: live slepen in "snelle" kwaliteit, bij loslaten hoge kwaliteit (≤ 150 ms bij 1200 px op een gewone laptop).

### Wat blijft
Analyse-, advies-, kleuren- en prijsengines, PHP/WooCommerce-kant, server-side prijs, productiefiche, vertalingen,
alle eisen uit BRIEF.md (vorm herkennen, nooit bijsnijden, achtergrond behouden tenzij wit, zoom/slepen, tekstlagen).
Bestaande unit- en e2e-tests moeten groen blijven (pas e2e-selectors aan waar de UI verandert).
Merk: zie docs/research/01-merk-en-site.md (blauw #21A9E2 / navy #0A1F44, Sora), maar mag premium en eigen ogen.
Geen nep-reviews of valse schaarste.
