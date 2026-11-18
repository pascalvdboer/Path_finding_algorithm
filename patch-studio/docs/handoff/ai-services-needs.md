# Handoff: wat AI/diensten nodig hebben in bestaande code


## Juridisch (bron: docs/ai/04-juridisch.md, teksten: config/ai-legal.json)

1. **Kassa (WooCommerce, klassiek)** — de AI/services-bestanden registreren zelf deze hooks, maar ze delen de kassa met
   maatwerk-tekst D uit docs/research/08 §1.4. Afspraak nodig over volgorde (prioriteit 20 voor maatwerk, 25 voor AI/diensten):
   - `woocommerce_review_order_before_submit`: toon `legal.ai.pack_consent_start` + `legal.ai.pack_consent_ack` (2 aparte
     vakjes, niet vooraf aangevinkt) als er een AI-pakket in de mand zit; `legal.svc.consent` als er dienst D1/D2/D3/D5/D7 in zit.
   - `woocommerce_checkout_create_order`: meta `_bps_ai_withdrawal_consent` = `{v, start, ack, at, locale, text_sha1}`
     en per dienst-orderitem `_bps_svc_start_after` (datum +14 d als het vakje leeg bleef).
   - Geen blokkerende validatie: lege vakjes zijn toegestaan (rondes pas bruikbaar na 14 dagen).
   - `woocommerce_email_after_order_table`: `legal.ai.pack_email` bij AI-pakketten.
2. **Mijn account** — endpoint "Ontwerprondes" (`add_rewrite_endpoint` + `woocommerce_account_menu_items`) met herroepknop
   `legal.ai.withdraw_button` → `legal.ai.withdraw_summary` → `legal.ai.withdraw_confirm`; gastlink met HMAC-getekende URL.
   Proportionele refund via `wc_create_refund`, coupon van het pakket laten vervallen.
3. **Beheer orderweergave (premium-team)** — blok "AI-ontwerp: nakijken op merken/emblemen/echte personen" met vinkje
   "nagekeken" → `_bps_ai.review = {user_id, at}`. Proef niet versturen zolang dit niet aangevinkt is (zacht: waarschuwing).
4. **Studio-UI (studio-team)** — label `legal.ai.label` op elke AI-tegel (DOM-tekst, niet canvas), `legal.ai.provider_notice`
   onder de voorstellen, `legal.ai.chat_notice` boven de verfijn-chat, `legal.ai.privacy_line` onder het tekstvak,
   knop `legal.ai.own_logo_cta` bij weigeringen `brand`/`copy` (opent de gewone upload), badge "AI-voorstel" in de studio
   zolang `_bps_ai.human_finish=false`, winkelmandregel `legal.ai.cart_line`.
5. **config/ai-prompts.json (ai-team)** — `safety.messages.*.fr` gebruikt "tu/ton": omzetten naar "vous". Extra termen
   toevoegen: officialInsignia `rode kruis|croix-rouge|red cross|olympisch|olympique|olympic rings|wapenschild`;
   copyIntent `zoals het logo van|comme le logo de|namaak|contrefaçon|replica`.
6. **Privacy** — `wp_privacy_personal_data_exporters/erasers` uitbreiden met AI-gegevens (prompts, ontwerpen, rondesaldo,
   toestemmingen); `wp_add_privacy_policy_content` met tekst §6.7 van 04-juridisch.md.
7. **Niet-code (eigenaar)** — AV-artikel §4.7 en privacytekst §6.7 plaatsen; OpenAI-DPA aanvaarden; boekhouder: OSS, Peppol,
   10 jaar bewaren.


## Prompt-engine (bron: docs/ai/02-prompting.md, code: includes/ai/class-ai-prompt-engine.php, data: config/ai-prompts.json)

Klaar en getest (`php tests/ai/run-prompt-php.php`, ook via `npm test`). Wat er nog nodig is in andere code:

1. **Laden van de klasse** — `includes/ai/class-ai-prompt-engine.php` (namespace `BorduurPatchStudio\AI`, klasse
   `Prompt_Engine`, alleen statische methodes, geen hooks). De AI-loader/REST-klasse van het ai-team doet
   `require_once` ervan. Geen wijziging in `class-plugin.php` nodig zolang die loader zich zelf registreert.
2. **REST (ai-team / premium-team voor de guard)** — de route "genereer" ontvangt alleen ruwe velden
   (`description, lang, style, shape, technique, maxColors, text, textMode, background, fabric, sizeMm, edge`), roept
   `Prompt_Engine::brief($input, Prompt_Engine::load_config(), $defaults)` aan met `$defaults` = de samengevoegde
   instellingen (zelfde vorm als `config/defaults.json`), en:
   - bij `blocked` → HTTP 200 met `{blocked:true, code, message}` en **geen krediet afboeken**;
   - anders `Prompt_Engine::build($brief, $cfg)` → provider. **Brief server-side opslaan bij de job** (transient of
     tabel, met job-id). Nooit een kant-en-klare prompt of brief uit de browser aanvaarden.
   - Antwoord aan de browser: beelden + `brief.understood`, `style`, `shape`, `colors`, `maxColors`, `textMode`,
     `text`, `placement`, `warnings` (voor de chips "Zo begrepen we je" en de studio-import). Niet de prompt zelf.
3. **REST "verfijn"** — ontvangt `jobId, proposalIndex, instruction, forceAi`. Laadt de opgeslagen brief en de
   geschiedenis, roept `Prompt_Engine::refine($brief, $instruction, $cfg, $history, $forceAi)` aan:
   - `route=local` → geen API-call, geen krediet; geef `action` (`open_text|open_colors|reduce_colors|open_size|
     open_shape|open_arrange`), `message`, en eventueel `text`/`colors`/`shape` terug;
   - `route=ai_edit` → provider `images/edits` met het gekozen voorstel + `prompt` + `params`; daarna `history`
     opslaan bij de job;
   - `route=blocked` → melding, geen krediet.
4. **Provider (ai-team)** — `model` uit de instellingen; `moderation: "auto"`; `size/background/quality/
   output_format/n` uit `build()['params']`. **Geen** klant-id/e-mail in het `user`-veld; als er een id moet zijn:
   `hash_hmac('sha256', $customer_or_session_id, wp_salt('auth'))`. Valt transparant weg (oud model/fout), dan opnieuw
   met `background=fabric` in de brief (engine aanroepen met `'background' => 'fabric'`).
5. **Studio-UI (studio-team)** — in het AI-paneel:
   - Tekstvak "Wat wil je op je patch?" (max 400 tekens) + **apart veld "Tekst op de patch (optioneel)"** met hint
     *"Zet geen namen of persoonsgegevens in je beschrijving. Tekst voor op de patch typ je hier."* /
     *"Ne mettez pas de noms ou de données personnelles dans la description. Le texte de l'écusson se tape ici."*
   - Stijlkaarten uit `config.ai.styles` (label + hint nl/fr, 7 stuks) met "Laat ons kiezen" als standaard.
   - Na import van een voorstel met `textMode=studio`: automatisch een tekstlaag aanmaken met `text`, op de plek
     `placement` (`arc_top` → boog boven, `arc_bottom` → boog onder, `banner_bottom|banner_top|center|below` → recht),
     letterhoogte volgens de bestaande advies-engine.
   - Bij `textMode=ai`: vraag na import *"Klopt de tekst «…»? Ja / Nee, zet hem in de studio"* (Nee = AI-tekst
     verwijderen kan niet; dan opnieuw genereren met `textMode=studio`, kost geen krediet: terugbetalen).
   - Verfijn-chat: bij `route=local` de melding tonen met knop naar het juiste paneel + knop "Toch met AI (1 krediet)"
     (`forceAi=true`).
   - `warnings`: `personal_data_removed` → *"We hebben persoonlijke gegevens uit je beschrijving gehaald. Die zijn niet
     nodig voor het ontwerp."* / *"Nous avons retiré des données personnelles de votre description. Elles ne sont pas
     nécessaires pour le design."*; `text_too_long_for_ai` → *"Lange tekst zetten we in de studio, dan is hij zeker
     juist gespeld."* / *"Un texte long est ajouté dans le studio, pour qu'il soit sûrement bien orthographié."*
6. **Publieke config (buildPublicConfig)** — `config.ai.styles` = `styles.*.label/hint` (alleen klantteksten, geen
   Engelse fragmenten, geen veiligheidslijsten) en `limits.descriptionMaxChars`.
7. **Admin (premium-team)** — later: `ai-prompts.json` overschrijfbaar via instellingen (merkenlijst aanvullen,
   stijlteksten), zelfde deep-merge als defaults. Optioneel log van `brief.unknown` (losse woorden, geen klanttekst)
   om de woordenlijst te laten groeien.
8. **npm-script** — `test:php` draait nu alleen `tests/pricing/run-php.php`. Voorstel:
   `"test:php": "php tests/pricing/run-php.php && php tests/ai/run-prompt-php.php"` (de Node-test roept de AI-test
   al aan als PHP aanwezig is).
9. **Punt 5 van Juridisch hierboven** is verwerkt in `config/ai-prompts.json`: Franse meldingen in "vous";
   `olympic rings/olympische ringen/anneaux olympiques` bij officialInsignia; `zoals het logo van|comme le logo de|
   namaak|contrefaçon|contrefait` bij copyIntent. "rode kruis/croix-rouge/red cross" staan al in `brandsHard`,
   "olympisch/olympique" in `brandsSoft` (blok samen met logo/merk). **Niet** toegevoegd: `wapenschild`, want een
   eigen familie- of clubwapen is net een gewone, toegelaten vraag (stijl `club`).


## Architectuur (bron: docs/ai/SPEC-AI-DIENSTEN.md) — wat de integratie precies moet doen

De AI- en dienstenmodule registreert zich volledig via hooks. Dit zijn de **enige** wijzigingen in bestaande code.
Tot ze gebeuren, laadt de module niet (veilig om parallel te bouwen).

1. **`includes/class-plugin.php` (premium-team)** — twee regels toevoegen aan `Plugin::COMPONENTS` (na `Rest\Routes`):
   ```php
   'BorduurPatchStudio\AI\Module',
   'BorduurPatchStudio\Services\Module',
   ```
   De bestaande autoloader vindt `includes/ai/class-module.php` en `includes/services/class-module.php`. Beide
   `register()`-methodes doen zelf `add_action/add_filter`, eigen dbDelta-installatie op `init` (optie
   `bps_ai_db_version`), en doen niets als `bps_ai_settings.mode = off` behalve admin en REST-status.
   Alternatief zonder codewijziging: een mu-plugin met `add_filter( 'bps_components', fn( $c ) => array_merge( $c, [ … ] ) );`.
2. **`scripts/build.mjs` (studio-team/integrator)** — entry en budget toevoegen:
   ```js
   { in: 'ai/index.js', out: 'patch-studio-ai.js', globalName: 'BPSAI' },
   // BUDGET: 'patch-studio-ai.js': 25 * 1024
   ```
   De PHP-kant registreert het script zelf (`wp_register_script('bps-ai', …dist/patch-studio-ai.js)`) en geeft de URL
   mee in `public_config.ai.bundleUrl` via de bestaande filter `bps_public_config`. Niet enqueuen: de studio laadt
   het lui. Voor WP Rocket: `patch-studio-ai.js` toevoegen aan `rocket_delay_js_exclusions` (of de module doet dat
   zelf via dezelfde filter — geen wijziging nodig in `class-plugin.php`).
3. **Studio-UI (studio-team, `assets/src/ui/**`)** — zie SPEC §1 en §10:
   - Lege toestand: kaart "✨ Nog geen logo? Laat AI iets maken" + regel `{free} gratis` (uit `GET /ai/status`, pas
     opvragen na de eerste interactie, niet bij laden — prestatie-6). Alleen tonen als `config.ai` bestaat.
   - Desktop: knop "✨ AI" in de gereedschapsbalk; gsm: in de tab **Ontwerp** "✨ Nieuwe voorstellen".
   - Bij tik: `loadScriptOnce(config.ai.bundleUrl)` → `BPSAI.mount(el, ctx)` → `panel.open({entry})`. `el` = bottom
     sheet (gsm) of de assistent-kolom (desktop). Prefetch bij `requestIdleCallback` als `config.ai.prefetch`.
   - `ctx` leveren: `host` (root `.bps-studio`), `config`, `locale`, `api {base, nonce, token}` (zelfde token als
     `/designs`), `getStudio()` (shape, technique, sizeMm, fabric, edge, maxColors, hasDesign), optioneel
     `quickCheck(file)` (snelle analyse + borduurcheck → `{status, reasons}`) en `renderOnPatch(src|null)`.
   - Luisteren op `bps:ai:design:chosen` → `ctx.handleFile(detail.file)`; `state.source.ai = detail.ai` (komt mee in
     het ontwerp-payload naar `/designs` en `/cart`); hints toepassen op velden die de klant nog niet koos; tekstlaag
     maken bij `hints.textMode='studio'` op `hints.placement`; badge "AI-voorstel" zolang `ai.humanFinish=false`.
   - Luisteren op `bps:ai:local:action` (paneel openen/waarde zetten), `bps:ai:upload:requested` (gewone upload),
     `bps:ai:preview` (tijdelijk op de patch tonen, `url:null` = terug), `bps:ai:close` (assistent terug),
     `bps:ai:service:suggested` (dienstkaart D1).
   - Diensten: `import { evaluate, card } from '../services/index.js'` en na analyse / prijs / bedankpagina
     `evaluate(signals, config.services, dismissed)` → max. 1 kaart in het assistentkanaal (SPEC §10.5).
4. **`package.json` (integrator)** —
   `"test:php": "php tests/pricing/run-php.php && php tests/ai/run-prompt-php.php && php tests/ai/run-ai-php.php && php tests/services/run-services-php.php"`.
   (`npm test` draait ze ook al via de Node-bridges als PHP aanwezig is.)
5. **Premium-team (orderbeheer, `includes/admin/class-order-production.php`)** — orderitem-meta `_bps_ai`
   (`{jobId, index, provider, model, legalVersion, humanFinish, review}`) tonen in de fiche met het label "Gemaakt met
   AI" en het nakijk-vinkje (Juridisch punt 3). Dienstregels (`_bps_service`) tonen als aparte taken met levertijd.
   Filter `bps_fiche_sections` volstaat als die een sectie kan toevoegen; dan doet de AI-module het zelf.
6. **`uninstall.php` (premium-team)** — als "gegevens wissen bij verwijderen" aan staat:
   `if ( class_exists( '\BorduurPatchStudio\AI\Module' ) ) { \BorduurPatchStudio\AI\Module::uninstall(); }`
   (tabellen `bps_ai_*`, opties `bps_ai_*`, `bps_svc_products`, cron `bps_ai_cleanup`, map `uploads/patch-studio/ai`).
7. **Demo/dev (eigenaars van `dev/**`, `demo/**`, `scripts/build-demo.mjs`)** — in de demo `BPSAI.mount(el, {…, mockApi:true})`
   gebruiken: werkt volledig in de browser met voorbeeldontwerpen uit `config/ai-mock`, met het label "Testmodus".
