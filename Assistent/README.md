# Assistent – je eigen Claude-assistent op de iPhone

Een native iPhone-app (SwiftUI, iOS 17+) die werkt als Siri, maar met Claude als brein.

## Wat kan hij?

| Functie | Hoe |
|---|---|
| **Praten en luisteren** | Tik op de microfoon, praat Nederlands, tik nogmaals. Het antwoord wordt voorgelezen. Spraakherkenning gebeurt waar mogelijk op de telefoon zelf. |
| **Siri** | Zeg *"Hé Siri, vraag Assistent"*. Siri vraagt wat je wilt weten en leest het antwoord voor, zonder dat je de app opent. |
| **Agenda** | "Wat staat er morgen op de planning?" / "Zet vrijdag om 10 uur de tandarts in mijn agenda." |
| **Herinneringen** | "Herinner me vanavond om 8 uur aan de vuilnisbak." / "Welke taken heb ik nog?" / "Vink 'boodschappen' af." |
| **E-mail** | "Mail jan@voorbeeld.nl dat ik later kom." Er wordt een concept geopend, en je verstuurt het zelf. |
| **Algemene vragen** | Alles wat je Claude kunt vragen, plus zoeken op internet (weer, nieuws, openingstijden). |

## Installeren op je iPhone

Je hebt een **Mac met Xcode 15 of nieuwer** nodig. Een gratis Apple ID is genoeg om de app op je eigen telefoon te zetten. Met een gratis account moet je de app wel elke 7 dagen opnieuw installeren; met een betaald developer-account ($99 per jaar) is dat een jaar.

1. **Haal de code op je Mac**
   ```bash
   git clone https://github.com/pascalvdboer/Path_finding_algorithm.git
   cd Path_finding_algorithm
   git checkout claude/quirky-maxwell-ye2nea
   cd Assistent
   ```
2. **Maak het Xcode-project aan** met [XcodeGen](https://github.com/yonaskolb/XcodeGen):
   ```bash
   brew install xcodegen
   xcodegen
   open Assistent.xcodeproj
   ```
   *Zonder XcodeGen:* maak in Xcode een nieuw iOS-app-project (SwiftUI) met de naam `Assistent` en sleep alle `.swift`-bestanden uit de map `Assistent/` erin. Voeg daarna onder *Info* de vier privacy-teksten uit `project.yml` toe (microfoon, spraakherkenning, agenda, herinneringen).
3. **Ondertekenen:** klik in Xcode op het project, ga naar *Signing & Capabilities*, kies je Apple ID als *Team* en maak de bundle-ID uniek, bijvoorbeeld `com.<jouwnaam>.assistent`.
4. **Sluit je iPhone aan**, kies hem bovenaan als bestemming en druk op ▶︎.
   Is het de eerste keer? Ga dan op de iPhone naar *Instellingen → Algemeen → VPN en apparaatbeheer* en vertrouw je ontwikkelaarsprofiel. Zet ook *Instellingen → Privacy en beveiliging → Ontwikkelaarsmodus* aan.
5. **API-sleutel:** maak een sleutel aan op [platform.claude.com](https://platform.claude.com) en plak hem in de app onder ⚙️ Instellingen. De sleutel wordt alleen in de sleutelhanger van je iPhone bewaard.
6. Geef bij de eerste start toestemming voor microfoon, spraakherkenning, agenda en herinneringen.

## Kosten

Je betaalt per gebruik aan Anthropic. Een gewone vraag kost met Claude Opus 5.5 meestal minder dan een cent. Gebruikt hij agenda-tools of zoekt hij op internet, dan kost het iets meer (zoeken: $10 per 1000 zoekopdrachten). In Instellingen kun je overschakelen naar Claude Sonnet 5.5; die is sneller en ongeveer de helft goedkoper.

## Hoe het werkt

```
stem ──► SpeechManager (spraak→tekst, nl-NL)
           │
           ▼
      AssistantAgent ──► Claude Messages API (claude-opus-5-5)
           ▲    │             │  wil een tool gebruiken
           │    ▼             ▼
           │  ToolExecutor: agenda / herinneringen (EventKit), e-mailconcept (MessageUI)
           │                web_search draait bij Anthropic
           ▼
      antwoord ──► AVSpeechSynthesizer (voorlezen)
```

| Bestand | Rol |
|---|---|
| `ClaudeClient.swift` | HTTP-aanroep naar de Messages API (effort `low` voor snelheid, automatische fallback bij weigering) |
| `AssistantAgent.swift` | Systeemprompt, gespreksgeschiedenis, tool-use-lus |
| `ToolExecutor.swift` | Tooldefinities en uitvoering op de telefoon |
| `SpeechManager.swift` | Spraakherkenning en voorlezen |
| `SiriIntent.swift` | App Intent + App Shortcut voor "Hé Siri, vraag Assistent" |
| `ContentView.swift`, `SettingsView.swift`, `MailComposer.swift` | Interface |
| `Keychain.swift` | Veilige opslag van de API-sleutel |

## Beperkingen en ideeën

- **E-mail lezen** kan niet via iOS zelf: Apple geeft apps geen toegang tot de Mail-app. Daarvoor is een koppeling met de Gmail- of Outlook-API (OAuth) nodig. Dat kan een volgende stap zijn.
- **De Siri-knop vervangen** kan niet: Apple staat dat niet toe. De **Actieknop** (iPhone 15 Pro en nieuwer) kun je wel aan deze opdracht koppelen: *Instellingen → Actieknop → Opdracht → Vraag de assistent*.
- Wil je een andere naam dan "Assistent"? Pas `PRODUCT_NAME` en `CFBundleDisplayName` aan in `project.yml`. De Siri-zin past zich automatisch aan.
- De API-sleutel staat op je telefoon. Dat is prima voor persoonlijk gebruik. Wil je de app met anderen delen, zet dan een eigen server tussen de app en de API.
