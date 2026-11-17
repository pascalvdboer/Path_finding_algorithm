# 01 — Beeldgeneratie-API's voor de AI-ontwerpstudio (onderzoek nov 2026)

Rol: onderzoeker "ai-apis". Doel: kiezen welke beeld-API de betaalde AI-ontwerpstudio van Patch Studio aandrijft
(zie `docs/BRIEF.md`, "WENSEN VAN DE EIGENAAR (17-11)", punt 2).

> **Betrouwbaarheid van de cijfers.** Dit onderzoek is gedaan op 17-11-2026 met WebSearch. De officiële prijs- en
> docpagina's (platform.openai.com, recraft.ai, bfl.ai, ai.google.dev, ideogram.ai, stability.ai) waren vanuit deze
> sessie **niet rechtstreeks op te halen** (netwerkbeperking), dus de cijfers komen uit zoekresultaten die die pagina's
> citeren plus derde partijen (aggregators, resellers). Waar bronnen elkaar tegenspreken staat dat erbij.
> **Vóór livegang: elke prijs in `config/ai-providers.json` nakijken op de officiële pagina** en de werkelijke kost per
> generatie meten via het kostenlogboek (de response van OpenAI geeft `usage` terug).

## Wat een patch-ontwerp nodig heeft (beoordelingscriteria)

Borduurbaar betekent: vlakke kleuren, beperkt palet (≤ 6–8 garens), dikke lijnen, geen verlopen/fotorealisme, effen of
transparante achtergrond, korte en grote tekst. Daaruit volgen de criteria, in volgorde van belang:

1. **Stijlsturing** naar vlak/illustratief logo (prompt-volgzaamheid).
2. **Transparante achtergrond** of minstens effen, zodat de studio het motief kan uitknippen.
3. **Vectoroutput (SVG)** of goedkope vectorisatie: de studio en de borduurcheck werken met vlakken.
4. **Tekst in beeld** ("MC WOLVES 1998"): veel patches bevatten een naam.
5. **Edit / inpainting** voor "verfijn via chat" ("maak de wolf bozer") zonder alles opnieuw te genereren.
6. **Kost per voorstel** (we tonen er 3–4 per ronde) en **snelheid** (< 20 s per ronde voelt nog vlot).
7. **EU/GDPR, eigendom van output, commercieel gebruik, training op onze data, contentfilters.**

## Overzichtstabel

Prijzen in USD per beeld (excl. btw), ~1024 px / 1 MP tenzij anders vermeld. "?" = niet bevestigd.

| Provider | Model (API-id) | Prijs per beeld | Transparant | Vector | Tekst | Edit/inpaint | Snelheid |
|---|---|---|---|---|---|---|---|
| OpenAI | `gpt-image-2` (snapshot `gpt-image-2-2026-04-21`); nieuwer: `gpt-image-2.5-flare` (snel), `gpt-image-2.5-sunburst` (precisie-edit), sinds 08-09-2026 | Tokenprijs: $8/M image-in, $30/M image-out, $5/M tekst-in. Schatting 1024²: ca. $0.006 (low) – $0.05 (medium) – $0.21 (high); resellers: $0.03 (1K) / $0.05 (2K) / $0.06 (4K) | Ja, **preview** sinds ~20-08-2026 (`background: "transparent"`, PNG/WebP) | Nee (raster) | Zeer goed | Ja: `/v1/images/edits` met masker; multi-turn via Responses API | 10–40 s (high trager) |
| Google | `gemini-3-pro-image` ("Nano Banana Pro"); `gemini-3.1-flash-image` ("Nano Banana 2"); `gemini-2.5-flash-image` | Pro: $0.134 (1K/2K), $0.24 (4K). NB2: ~$0.045–0.067 (1K), $0.151 (4K). 2.5 Flash: $0.039. Batch −50 % | Nee (geen alfakanaal; effen achtergrond + zelf uitknippen) | Nee | Pro: zeer goed | Ja, conversationeel (beeld + instructie) | 5–30 s |
| Google | Imagen 4 (Fast/Std/Ultra) | was $0.02/$0.04/$0.06 | — | — | — | — | **Uitgefaseerd** (Gemini API shutdown 17-08-2026); niet meer kiezen |
| Black Forest Labs | `flux-2-pro`, `flux-2-flex`, `flux-2-max`, `flux-2-klein` (4B/9B) | Per megapixel: Pro vanaf $0.03, Pro-edit $0.045, Flex $0.05–0.06, Max vanaf $0.07, Klein $0.014–0.015; referentiebeelden extra | Nee (native) | Nee | Goed (Flex/Max beter) | Ja, beeld-edit met tot ~8 referenties (Max) | Snel: 2–10 s (Klein < 2 s) |
| Ideogram | Ideogram 4.0 (sinds juni 2026); 3.0 nog beschikbaar | 4.0: $0.03 Turbo / $0.06 Default / $0.10 Quality. Transparant 4.0: zelfde prijs op 1K/2K. 3.0 transparant: $0.04/$0.07/$0.10. Instructional Edit: $0.20 | Ja ("Generate w/ Transparent") | Nee | **Beste in klasse** (typografie) | Ja: Edit (masker), Remix, Reframe, Instructional Edit | 5–15 s |
| Recraft | Recraft V4 / V4.1 (+ Pro, + Styles) | Raster V4.1 ~$0.035–0.04; **Vector V4 $0.08**, V4 Pro Vector $0.30, V4 Styles Vector $0.05; **vectorize $0.01**, achtergrond verwijderen $0.01, inpaint $0.04 (raster) / $0.08 (vector) | Ja (+ apart endpoint remove-background) | **Ja, echte SVG** | Goed | Ja: `/v1/images/inpaint` (masker), ook op vector | ~15 s (vector) |
| Stability AI | Stable Image Ultra, Stable Image Core, SD 3.5 Large/Turbo/Medium/Flash | Ultra $0.08, Core $0.03, SD3.5 Large $0.065, Turbo $0.04, Medium $0.035, Flash $0.025 (1 credit = $0.01) | Via apart remove-background-endpoint | Nee | Matig | Ja: inpaint, search-and-replace, sketch/structure-control | 3–10 s |

## Per provider

### OpenAI — het beeldmodel achter ChatGPT
- **Modellen.** `gpt-image-2` (april 2026) en de opvolgers `gpt-image-2.5-flare` (snel, volume) en
  `gpt-image-2.5-sunburst` (precisie-edits), uitgebracht 08-09-2026 samen met "ChatGPT Images 2.5". `gpt-image-1` is
  op 23-10-2026 uitgeschakeld — niet meer gebruiken.
- **Prijs.** Per token, niet per beeld. 2.5 heeft dezelfde tokenprijzen als 2 ($30/M output). OpenAI publiceert voor
  2.5 nog geen tokenverbruik per beeld, dus **zelf meten** via `usage.output_tokens`. Voor een 1024²-logo op `quality:
  "medium"` rekenen we voorzichtig met **$0.05 per beeld**; `low` is bruikbaar voor snelle schetsen.
- **Transparant.** `background: "transparent"` met `output_format: "png"|"webp"` werkt voor gpt-image-2 sinds eind
  augustus 2026 **als preview** (oudere docpagina's zeggen nog "niet ondersteund"). Alleen de parameter garandeert een
  alfakanaal, niet de prompt. Fallback: effen witte achtergrond vragen en server-side uitknippen.
- **Edit/verfijn.** `/v1/images/edits` met beeld + optioneel masker (alfakanaal = te wijzigen zone) + prompt. Ideaal
  voor "maak de wolf bozer". Bij gpt-image-2 is input altijd high fidelity; `input_fidelity` meesturen kan een 400 geven
  → niet meesturen.
- **Tekst.** Zeer goed, korte namen bijna altijd correct gespeld.
- **Contentfilter.** `moderation: "auto"` (standaard) of `"low"`; wij gebruiken `auto`. Weigert o.a. herkenbare
  personen en veel merk-/handelsmerkverzoeken — dat past bij onze regel "geen logo's van anderen namaken".
- **EU/GDPR.** Data residency in Europa bestaat voor de API (nieuw project, regio Europa; met zero data retention).
  **Niet bevestigd** of de image-endpoints daaronder vallen; standaard bewaart OpenAI API-data tot 30 dagen voor
  misbruikcontrole en traint niet op API-data. DPA beschikbaar. Wij sturen alleen de ontwerpbeschrijving, nooit naam,
  e-mail of bestelgegevens (zie "Privacyregels" onderaan).
- **Eigendom.** Volgens de OpenAI-voorwaarden krijgt de gebruiker de rechten op de output; commercieel gebruik toegestaan.
- **Plus:** sterkste prompt-begrip, past bij de wens van de eigenaar ("ChatGPT-ontwerp"), goede edit-flow.
- **Min:** geen SVG, prijs per beeld minder voorspelbaar, transparantie nog preview.

### Google — Gemini-beeldmodellen (Imagen is uitgefaseerd)
- `gemini-3-pro-image` (Nano Banana Pro) $0.134 per 1K/2K-beeld: duur voor 4 voorstellen per ronde.
  `gemini-3.1-flash-image` (Nano Banana 2) vanaf ~$0.045 is betaalbaar. 50 % korting via Batch (niet bruikbaar voor
  live studio).
- Geen echte alfa-transparantie; sterk in conversationeel bewerken en in tekst (Pro).
- Alle beelden dragen een onzichtbaar **SynthID**-watermerk (geen probleem voor borduren).
- Betaalde tier: data niet gebruikt voor training; gratis tier wel → **alleen betaalde tier gebruiken**.
- Imagen 4: shutdown op de Gemini API 17-08-2026; niet inplannen.

### Black Forest Labs — FLUX.2 (Duits bedrijf)
- Prijs per megapixel; 1 credit = $0.01. Pro $0.03 is goedkoop en snel; Klein is extreem goedkoop/snel maar minder
  precies. Prijsverhogingen in april/mei 2026 → nakijken.
- Geen native transparantie, geen vector. Sterke multi-referentie-edits (stijlconsistentie over voorstellen).
- Help-center: API-output heeft volledige commerciële gebruiksrechten. **Let op:** een samenvatting van de API-voorwaarden
  vermeldt een brede licentie voor BFL op in- en output, inclusief gebruik voor training, met opt-out per e-mail. Wie
  BFL activeert: opt-out aanvragen en DPA opvragen. EU-vestiging is een plus, maar EU-verwerking niet bevestigd.

### Ideogram — de tekstspecialist
- Ideogram 4.0 (juni 2026): $0.03 / $0.06 / $0.10 per beeld; transparant zonder meerprijs op 1K/2K.
- **Beste tekstweergave** (OCR-score 0.97 op X-Omni volgens release notes) → ideaal voor patches met club- of
  bedrijfsnaam, rond- en boogtekst.
- Edit met masker, Remix, Reframe; Instructional Edit (zonder masker) $0.20 is duur.
- Ideogram claimt geen eigendom op output; commercieel gebruik toegestaan binnen de gebruiksregels (niet om concurrerende
  modellen te trainen). API-voorwaarden dateren van 08-2024; training/retentie op API-data **niet gevonden** → navragen.

### Recraft — echte SVG
- **Enige provider met native vectoroutput.** V4 Vector $0.08, V4 Styles Vector $0.05 (eigen huisstijl als "style"),
  V4 Pro Vector $0.30. Raster V4.1 ~$0.035.
- **Tools:** `vectorize` $0.01 en `remove background` $0.01 per call → hiermee kan elk rasterbeeld van een andere
  provider voor ~1 cent naar SVG. Recraft raadt aan: eerst achtergrond verwijderen, dan vectoriseren.
- Inpainting ook op vector ($0.08) → verfijnen zonder kwaliteitsverlies.
- Stijlen voor "vlak logo / embleem / lijnillustratie" sluiten direct aan op borduurbaarheid.
- Betaald (API): volledige eigendom en commerciële rechten; output mag niet gebruikt worden om AI te trainen.
  GDPR/DPA: **niet gevonden** → Developer Terms en privacybeleid nalezen.
- Billing: prepaid units ($1 = 1000 units).

### Stability AI
- Goedkoop (Core $0.03), snel, volwassen API met veel edit-tools (inpaint, search-and-replace, sketch-to-image,
  achtergrond verwijderen). Zwakker in tekst en in "vlak logo"-stijl dan de rest.
- Commercieel gebruik via de API toegestaan (Community/Enterprise-licentie geldt voor zelf-hosten; voor API niet nodig).
- Goede noodreserve, geen eerste keus voor patches.

## Advies

### Standaard: OpenAI `gpt-image-2` (instelbaar naar `gpt-image-2.5-flare`) + Recraft als vectorstap
1. **Genereren: OpenAI**, `quality: "medium"`, `size: "1024x1024"`, `background: "transparent"`, `output_format:
   "png"`, `moderation: "auto"`, n = 3–4. Reden: beste begrip van gewone-taalbeschrijvingen, sterke tekst, edit-endpoint
   voor de verfijn-chat, en het is letterlijk wat de eigenaar vraagt ("ChatGPT-ontwerp").
   Prompt-sjabloon dwingt borduurstijl af ("flat vector-style embroidered patch design, max 6 solid colours, thick
   outlines, no gradients, no photo-realism, centred, plain background").
2. **Verfijnen: OpenAI** `/v1/images/edits` met het gekozen voorstel (+ masker als de klant een zone aanduidt).
3. **Naar de studio: Recraft `vectorize`** ($0.01) op het gekozen voorstel → SVG-vlakken voor de renderer en de
   borduurcheck. Alleen op het voorstel dat de klant kiest, niet op alle 4.
   (Als de Recraft-sleutel ontbreekt: lokale kleurkwantisatie + tracing als terugval — voor het studio-team.)

### Fallback: Recraft V4 (Styles) Vector — rechtstreeks SVG
Bij OpenAI-storing, -weigering die geen beleidsweigering is, of budgetoverschrijding: Recraft V4 Styles Vector ($0.05)
of V4 Vector ($0.08). Levert direct borduurvriendelijke SVG. Een beleidsweigering (merknamen, personen) wordt **niet**
naar de fallback doorgestuurd — anders omzeilen we ons eigen inhoudsfilter.

### Optioneel derde provider: Ideogram 4.0 voor tekstzware ontwerpen
Als de beschrijving vooral tekst bevat (clubnaam + jaartal + boogtekst) levert Ideogram Default ($0.06, transparant) de
netste letters. Instelbaar als "tekstprovider", standaard uit.

### Niet kiezen (nu)
- Google Imagen 4 (uitgefaseerd); Nano Banana Pro te duur voor 4 voorstellen per ronde en geen alfakanaal.
- FLUX.2 en Stability: technisch prima, maar zonder transparantie/vector en met minder sterke tekst; BFL-
  trainingslicentie vraagt extra juridisch werk.

## Kostenraming per ronde (voor het verdienmodel)

Uitgaande van 4 voorstellen + 1 vectorisatie:

| Scenario | Berekening | Kost per ronde |
|---|---|---|
| OpenAI medium (schatting $0.05/beeld) + Recraft vectorize | 4 × 0.05 + 0.01 | **≈ $0.21** |
| OpenAI high (schatting $0.21/beeld) | 4 × 0.21 + 0.01 | ≈ $0.85 → niet als standaard |
| Verfijnstap (1 edit, medium) | 1 × ~0.05–0.07 | ≈ $0.06 |
| Fallback Recraft V4 Styles Vector | 4 × 0.05 | $0.20 |
| Tekstprovider Ideogram 4.0 Default transparant | 4 × 0.06 + 0.01 | $0.25 |

Een klantsessie van 3 rondes + 3 verfijningen kost dus ruwweg **$0.80–1.00**. Een ontwerpkredietpakket van bv. 10
rondes voor €4,95 dekt de API-kost ruim, ook met een paar gratis proefrondes; de eigenaar stelt de prijs zelf in.
Kostenbewaking: per generatie de werkelijke kost loggen (OpenAI `usage`, Recraft units), dag- en maandplafond in euro.

## Gevolgen voor de provider-abstractie (`includes/ai/`)

Elke provider declareert **capabilities**, zodat de studio weet wat kan:
`transparent_bg`, `vector_output`, `edit_mask`, `edit_instruction`, `text_quality` (1–3), `max_n`,
`cost_estimate_usd(size, quality)`. Voorstel voor `config/ai-providers.json`:

```json
{
  "default": "openai",
  "fallback": "recraft",
  "vectorizer": "recraft",
  "text_provider": null,
  "providers": {
    "openai":  { "model": "gpt-image-2", "quality": "medium", "size": "1024x1024", "background": "transparent",
                 "output_format": "png", "moderation": "auto", "est_cost_usd": 0.05, "verified": false },
    "recraft": { "model": "recraftv4_vector", "est_cost_usd": 0.08, "vectorize_cost_usd": 0.01, "verified": false },
    "ideogram":{ "model": "ideogram-4.0", "speed": "DEFAULT", "est_cost_usd": 0.06, "verified": false },
    "mock":    { "est_cost_usd": 0 }
  }
}
```

`verified: false` = prijs nog niet op de officiële pagina gecontroleerd; de admin toont dan een waarschuwing.

## Privacy- en inhoudsregels (gelden voor elke provider)
- Sleutels alleen server-side (WordPress-opties, nooit in JS/HTML/REST-responses).
- Naar de provider gaat **alleen** de ontwerpprompt (+ eventueel het eerder gegenereerde beeld en masker). Geen naam,
  e-mail, adres, bestel- of klant-ID; geen geüploade klantfoto's van personen. Een server-side filter verwijdert
  e-mailadressen en telefoonnummers uit de prompt vóór verzending.
- Eigen vooraf-filter op merknamen/logo's van derden en verboden inhoud, vóór het providerfilter; weigeringen worden
  niet naar een andere provider doorgezet.
- Gebruiksvoorwaarden voor de klant: de klant mag het ontwerp commercieel gebruiken zoals de provider toelaat; geen
  garantie op exclusiviteit (gelijkaardige output is mogelijk) en geen merkrechtelijke toets.
- EU AI Act art. 50 (transparantie bij AI-gegenereerde beelden): voorstellen in de UI duidelijk labelen als
  "gemaakt met AI" / "créé avec l'IA".

## Open punten (na te kijken vóór livegang)
1. Officiële prijzen van alle gekozen providers bevestigen (vooral OpenAI-tokenverbruik per 1024²-beeld bij gpt-image-2
   en 2.5, en Recraft V4/V4.1-vectorprijzen).
2. OpenAI: valt `/v1/images/*` onder EU data residency / zero data retention? Transparantie nog "preview": gedrag
   testen.
3. Recraft en Ideogram: DPA, retentie en training op API-data navragen.
4. BFL alleen activeren na training-opt-out en DPA.

## Bronnen (via WebSearch, 17-11-2026)
- OpenAI tokenprijzen gpt-image-2 / 2.5: https://pricepertoken.com/gpt-image-pricing ,
  https://www.aipricing.guru/models/gpt-image-2/ , https://grida.co/docs/models/gpt-image-2.5 ,
  https://www.orcarouter.ai/blog/gpt-image-2-5-vs-gpt-image-2 , https://docs.apiyi.com/en/news/gpt-image-2-5-launch
- OpenAI transparantie (preview): https://community.openai.com/t/transparent-backgrounds-are-now-available-in-preview-for-gpt-image-2-in-the-api/1391541 ,
  https://aicatchup.com/news/openai-gpt-image-2-transparent-backgrounds-preview
- OpenAI edits/moderation: https://developers.openai.com/api/reference/resources/images/methods/edit ,
  https://developers.openai.com/api/docs/models/gpt-image-2.md
- OpenAI EU data residency: https://openai.com/index/introducing-data-residency-in-europe/ ,
  https://help-lb.openai.com/en/articles/9903489-data-residency-and-inference-residency
- Google Gemini-beeldprijzen en Imagen-shutdown: https://benchlm.ai/media-pricing/nano-banana ,
  https://www.developersdigest.tech/models/image-generation/google-gemini-api ,
  https://www.developersdigest.tech/models/image-generation/google-vertex
- Google data/SynthID: https://ai.google.dev/gemini-api/terms_preview ,
  https://discuss.ai.google.dev/t/does-gemini-api-text-output-carry-synthid-watermarking-gemini-2-5-flash-lite-gemini-3-1-flash-lite-eu-ai-act-art-50-2/177241
- BFL FLUX.2: https://docs.bfl.ai/quick_start/pricing.md , https://developer.puter.com/tutorials/flux-api-pricing/ ,
  https://costbench.com/changelog/black-forest-labs-price-increase-2026-04/ ,
  https://help.bfl.ai/articles/4375863104-can-i-use-the-api-for-a-commercial-application ,
  https://bfl.ai/legal/developer-terms-of-service
- Ideogram: https://developer.puter.com/tutorials/ideogram-api-pricing/ ,
  https://www.usagepricing.com/blueprint/activity/ideogram-2026-06-15-40-api-launch ,
  https://usagepricing.com/blueprint/activity/ideogram-2026-08-25-price-change , https://ideogram.ai/licensing/ ,
  https://about.ideogram.ai/api-tos
- Recraft: https://www.recraft.ai/docs/api-reference/pricing , https://www.recraft.ai/docs/recraft-models/recraft-V4.md ,
  https://openrouter.ai/recraft/recraft-v4-styles-vector , https://www.recraft.ai/docs/plans-and-billing/commercial-rights-and-ownership ,
  https://www.recraft.ai/docs/api-reference/tools/vectorize.md
- Stability: https://developer.puter.com/tutorials/stability-ai-api-pricing/ ,
  https://fast.io/resources/stability-ai-review-2026/
