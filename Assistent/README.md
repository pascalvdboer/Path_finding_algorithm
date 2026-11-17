# Assistent – je eigen Claude-assistent op de iPhone

Een native iPhone-app (SwiftUI, iOS 17+) die werkt als Siri, met Claude als brein. Hij kan praten, mailen, bellen, appen, plannen, opzoeken, adviseren en andere apps aansturen.

## Wat kan hij?

| Gebied | Voorbeelden | Hoe |
|---|---|---|
| 🎙️ **Praten** | Tik op de microfoon, of zeg *"Hé Siri, vraag Assistent"* | Spraakherkenning in het Nederlands; antwoorden worden voorgelezen |
| 📧 **E-mail (Gmail)** | "Heb ik belangrijke mail?", "Lees de laatste mail van Jan voor", "Antwoord dat donderdag goed is", "Archiveer de nieuwsbrieven" | Gmail API. Versturen gebeurt pas nadat je op het scherm bevestigt |
| 📞 **Bellen** | "Bel mama", "FaceTime Lisa" | Zoekt het nummer in je contacten; iOS vraagt nog één tik ter bevestiging |
| 💬 **Berichten** | "App Lisa dat ik later ben", "SMS Jan: ben er om 8 uur" | WhatsApp of Berichten, met de tekst al ingevuld. Jij tikt op versturen |
| 📅 **Agenda** | "Wat heb ik morgen?", "Plan vrijdag 10 uur de tandarts", "Verwijder de afspraak van vanmiddag" | Agenda-app (ook Google-agenda's die op je iPhone staan) |
| ✅ **Herinneringen** | "Herinner me om 8 uur aan de vuilnisbak", "Wat staat er op mijn lijst?" | App Herinneringen, met melding op het tijdstip |
| 👤 **Contacten** | "Wanneer is Pieter jarig?", "Wat is het adres van oma?" | Contacten-app |
| 📍 **Locatie** | "Waar ben ik?", "Navigeer naar het station", "Wat is er hier in de buurt open?" | GPS + Apple Kaarten + internet |
| ⚙️ **Instellingen en apps** | "Zet de wekker op 7 uur", "Zet Niet storen aan", "Lampen uit", "Open Spotify" | Via je eigen **Opdrachten** (Shortcuts) en app-links, zie hieronder |
| 🔎 **Opzoeken en advies** | "Wat voor weer wordt het?", "Laatste nieuws over…", "Welke wijn past bij risotto?" | Zoekt op internet en leest webpagina's |
| 👀 **Bekijken** | Stuur een foto of schermafbeelding mee met 📎: "Wat staat hier?", "Vat dit samen", "Wat moet ik antwoorden?" | Claude kan beelden lezen |
| 🧠 **Geheugen** | "Onthoud dat mijn vrouw Lisa heet", "Ik werk in Utrecht" | Hij leert je kennen. Je ziet en wist het in Instellingen |
| 🎧 **Gesprekken opnemen** | Tabblad *Opnames* → opnemen. Daarna: "Wat zei Jan over het budget?", "Zet de actiepunten in mijn herinneringen" | Onbeperkt lang, ook met het scherm uit. Volledig uitgeschreven per spreker (AssemblyAI), met titel, samenvatting, besluiten en actiepunten (Claude) |
| ☀️ **Meedenken** | Bij openen of met ☀️: "Je volgende afspraak is om 14:00 in Utrecht; vertrek om 13:20. Jan wacht nog op antwoord over de offerte. Zal ik een reactie opstellen?" | Combineert agenda, mail, opnames en wat hij over je weet |

### Wat Apple niet toestaat (en hoe het hier toch werkt)
Op de iPhone mag een app **niet in andere apps kijken of daarin knoppen indrukken**. Geen enkele app kan dat, ook Siri niet volledig. Daarom werkt deze assistent zo:
- **E-mail lezen** gaat rechtstreeks via Gmail, niet via de Mail-app.
- **Iets bekijken uit een andere app?** Maak een schermafbeelding en stuur die mee met 📎.
- **Instellingen veranderen** (wekker, Focus, helderheid, wifi, slimme lampen…) gaat via **Opdrachten**. Die mogen dat wel, en de assistent start ze voor je.
- **Berichten en bellen** worden klaargezet, en jij geeft de laatste tik. Apple verplicht dat; zo kan er ook nooit per ongeluk iets weg.

## Gesprekken opnemen en uitschrijven

1. Maak een gratis account op [assemblyai.com](https://www.assemblyai.com) en kopieer je API-sleutel. Plak die in de app onder ⚙️ → *Gespreksopnames*. Zet daar eventueel *EU-servers* aan.
2. Ga naar het tabblad **Opnames** en tik op **Gesprek opnemen**, of zeg *"Hé Siri, neem op met Assistent"*. Je kunt de opname ook onder de Actieknop zetten (*Start opname*).
3. Leg de telefoon midden op tafel. Je kunt het scherm uitzetten; de opname loopt door. Er is geen tijdslimiet: een uur opnemen kost ongeveer 15 MB opslag.
4. Tik op ⏹. De app uploadt de audio, AssemblyAI schrijft hem uit met sprekers (A, B, C…), en Claude maakt een titel, samenvatting, besluiten en actiepunten. Hij raadt ook de namen van sprekers als die in het gesprek vallen. Namen kun je zelf aanpassen.
5. Vraag daarna in het tabblad Assistent bijvoorbeeld: *"Wat hebben we gisteren afgesproken met de aannemer?"*.

Goed om te weten:
- **Kosten:** AssemblyAI rekent ongeveer $0,15–0,40 per uur audio; nieuwe accounts krijgen tegoed. De samenvatting door Claude kost een paar cent per uur gesprek.
- **Telefoongesprekken** kan geen enkele app opnemen; Apple staat dat niet toe. Gebruik daarvoor de ingebouwde opname van de Telefoon-app (iOS 18.1+, knop linksboven tijdens een gesprek). Videogesprekken op een andere computer of tablet kun je wel opnemen door de iPhone ernaast te leggen.
- Wordt een opname onderbroken door een binnenkomend gesprek, dan gaat hij daarna automatisch verder.
- **Vraag toestemming** aan de anderen voordat je opneemt. Dat is netjes en voorkomt gedoe.

## Installeren op je iPhone

**Snelste manier** (op je Mac, met je iPhone via de kabel aangesloten en ontgrendeld):
```bash
git clone https://github.com/pascalvdboer/Path_finding_algorithm.git
cd Path_finding_algorithm && git checkout claude/quirky-maxwell-ye2nea
cd Assistent
./install.sh <JOUW_TEAM_ID>
```
Je Team ID staat op [developer.apple.com/account](https://developer.apple.com/account) onder *Membership details*. Het script installeert XcodeGen, bouwt de app en zet hem op je iPhone. Met een betaald developer-account blijft hij een jaar werken.

**Of via Xcode:**

Je hebt een **Mac met Xcode 15 of nieuwer** nodig. Een gratis Apple ID is genoeg om de app op je eigen telefoon te zetten. Met een gratis account moet je de app wel elke 7 dagen opnieuw installeren; met een betaald developer-account ($99 per jaar) is dat een jaar.

1. **Haal de code op je Mac en maak het project aan**
   ```bash
   git clone https://github.com/pascalvdboer/Path_finding_algorithm.git
   cd Path_finding_algorithm && git checkout claude/quirky-maxwell-ye2nea
   cd Assistent
   brew install xcodegen
   xcodegen
   open Assistent.xcodeproj
   ```
2. **Ondertekenen:** ga in Xcode naar *Signing & Capabilities*, kies je Apple ID als *Team* en maak de bundle-ID uniek, bijvoorbeeld `com.<jouwnaam>.assistent`.
3. **Sluit je iPhone aan** en druk op ▶︎. Is het de eerste keer? Ga dan op de iPhone naar *Instellingen → Algemeen → VPN en apparaatbeheer* en vertrouw je profiel. Zet ook *Privacy en beveiliging → Ontwikkelaarsmodus* aan.
4. **Claude API-sleutel:** maak er een aan op [platform.claude.com](https://platform.claude.com) en plak hem in de app onder ⚙️.
5. Geef toestemming voor microfoon, spraak, agenda, herinneringen, contacten en locatie.

## Gmail koppelen (eenmalig, ±10 minuten)

1. Ga naar [console.cloud.google.com](https://console.cloud.google.com) en maak een nieuw project aan, bijvoorbeeld "Assistent".
2. Ga naar *APIs & Services → Library*, zoek **Gmail API** en klik op **Enable**.
3. Ga naar *APIs & Services → OAuth consent screen*:
   - User type: **External**. Vul een app-naam en je e-mailadres in.
   - Voeg bij **Test users** je eigen Gmail-adres toe.
4. Ga naar *APIs & Services → Credentials → Create credentials → OAuth client ID*:
   - Application type: **iOS**.
   - Bundle ID: dezelfde als in Xcode, bijvoorbeeld `com.<jouwnaam>.assistent`.
5. Kopieer de **Client ID** (eindigt op `.apps.googleusercontent.com`). Plak hem in de app bij ⚙️ → Gmail en tik op **Koppel Gmail**.

Let op: zolang de Google-app in de "Testing"-status staat, verloopt de koppeling na 7 dagen en moet je opnieuw op *Koppel Gmail* tikken. Wil je dat niet, zet de app dan onder *OAuth consent screen* op **In production**. Voor eigen gebruik hoef je hem niet door Google te laten keuren; je ziet dan alleen een waarschuwing "app niet geverifieerd" die je kunt doorklikken.

## Opdrachten maken voor instellingen (aanrader)

Maak in de app **Opdrachten** een paar opdrachten aan. Zet hun namen daarna in ⚙️ → *Mijn opdrachten*, één per regel. De assistent geeft je gesproken invoer als tekst mee.

| Naam (voorbeeld) | Acties in de opdracht |
|---|---|
| `Wekker zetten` | *Ontvang invoer* → *Maak wekker* (tijd = Opdracht-invoer) |
| `Timer` | *Start timer* (duur = Opdracht-invoer, in minuten) |
| `Niet storen` | *Stel Focus in* → Niet storen aan/uit |
| `Helderheid` | *Stel helderheid in* (waarde = invoer) |
| `Lampen` | *Bedien mijn woning* → je HomeKit-scène |
| `Muziek` | *Speel muziek af* / *Zoek in Apple Music* (invoer) |
| `Wifi` | *Stel wifi in* aan/uit |

Daarna kun je zeggen: *"Zet de wekker op half zeven"*. De assistent voert dan `Wekker zetten` uit met invoer `06:30`.

## Snel bereikbaar maken
- **Siri:** *"Hé Siri, vraag Assistent"*. Agenda, mail lezen, contacten, internet en geheugen werken ook zonder de app te openen.
- **Actieknop** (iPhone 15 Pro en nieuwer): *Instellingen → Actieknop → Opdracht → "Open en luister"*. De app opent en begint meteen te luisteren.
- **Tik op de achterkant:** *Instellingen → Toegankelijkheid → Aanraken → Tik op achterkant → "Open en luister"*.
- Zet de app in je Dock.

## Kosten
Je betaalt per gebruik aan Anthropic. Een gewone vraag kost met Claude Opus 5.5 meestal 1 à 2 cent. Vragen met e-mail of zoeken op internet kosten iets meer (zoeken: $10 per 1000 zoekopdrachten). Overschakelen naar Claude Sonnet 5.5 in ⚙️ maakt hem sneller en ongeveer de helft goedkoper. Gmail en de rest zijn gratis.

## Privacy
- De API-sleutel en de Google-koppeling staan in de sleutelhanger van je iPhone.
- Spraakherkenning gebeurt waar mogelijk op de telefoon zelf.
- Wat je vraagt, en de mails, afspraken of contacten die de assistent daarvoor opzoekt, gaan naar de Claude API om een antwoord te maken. Er draait geen eigen server tussen.
- Versturen van e-mail en verwijderen van afspraken gebeurt alleen nadat je op het scherm bevestigt. Berichten en bellen moet je altijd zelf afronden.

## Hoe het werkt

```
stem / tekst / foto
      │
      ▼
AssistantAgent ──► Claude Messages API (claude-opus-5-5) ── web_search / web_fetch (bij Anthropic)
      ▲    │ tool_use
      │    ▼
   ToolExecutor
     ├─ EventKit ........ agenda, herinneringen
     ├─ Contacts ........ contacten
     ├─ CoreLocation .... locatie, Kaarten
     ├─ GmailClient ..... zoeken, lezen, versturen (OAuth via GoogleAuth)
     ├─ tel: / facetime: / wa.me / Berichten
     ├─ shortcuts://run-shortcut ... wekker, Focus, woning, …
     └─ MemoryStore ..... wat hij over je onthoudt
      │
      ▼
antwoord ──► voorlezen (AVSpeechSynthesizer)
```

| Bestand | Rol |
|---|---|
| `AssistantAgent.swift` | Systeemprompt (met geheugen en opdrachten), gespreksgeschiedenis, tool-use-lus |
| `ToolExecutor.swift` | Alle tooldefinities en de uitvoering ervan |
| `ClaudeClient.swift` | HTTP-aanroep naar de Messages API |
| `GoogleAuth.swift`, `GmailClient.swift` | Gmail-koppeling (OAuth + PKCE) en Gmail API |
| `Services.swift` | Contacten, locatie, geheugen |
| `SpeechManager.swift` | Spraakherkenning en voorlezen |
| `Recordings.swift`, `RecordingsView.swift` | Opnemen, AssemblyAI-transcriptie met sprekers, samenvatting, opnamescherm |
| `SiriIntent.swift` | Siri-zinnen en de opdrachten "Vraag de assistent", "Open en luister" en "Start opname" |
| `ContentView.swift`, `SettingsView.swift`, `MailComposer.swift`, `MessageComposer.swift` | Interface |
| `Keychain.swift` | Veilige opslag van sleutels |

## Nog te doen / ideeën
- **Andere mailboxen** (Outlook, Hostinger): Outlook kan via Microsoft Graph, andere via IMAP.
- **Deelmenu-extensie:** vanuit elke app op *Deel → Assistent* tikken om een mail, webpagina of tekst direct te laten samenvatten.
- **Ochtendbriefing met geluid:** maak in Opdrachten een *Automatisering* → *Tijd van de dag 07:30* → actie *Vraag de assistent* met tekst "Geef mijn ochtendbriefing". Siri leest hem dan elke ochtend voor.
- **Live meelezen** tijdens een opname (tekst die meeloopt terwijl er gepraat wordt).
- **TestFlight**, zodat je de app zonder kabel kunt bijwerken.
