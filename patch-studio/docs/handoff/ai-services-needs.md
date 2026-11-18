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


## Commerce: rondes, pakketten, korting en diensten (bouwer `commerce`, code: `includes/services/`)

Klaar en getest (`php tests/services/run-services-php.php`, 427 checks; ook via `npm test` met
`tests/services/services-php.test.js`). Alles registreert zich via `Services\Module::register()`. Wat er nog nodig
is in bestaande code of bij andere teams:

1. **Laden** — `'BorduurPatchStudio\Services\Module'` in `Plugin::COMPONENTS` (Architectuur punt 1). Werkt ook
   zonder de AI-module: diensten en pakketten verkopen gaat los, `AI\Credits_Gateway` vindt `Services\Credits`
   via de filter `bps_ai_credits` die de module zelf zet. Zonder WooCommerce: enkel REST-catalogus en beheer.
2. **`uninstall.php` (premium-team)** — naast de AI-module ook
   `if ( class_exists( '\BorduurPatchStudio\Services\Module' ) ) { \BorduurPatchStudio\Services\Module::uninstall(); }`
   (opties `bps_svc_db_version`, `bps_svc_products`, `bps_svc_rewrite_v`, cron `bps_svc_daily`; tabellen enkel als
   `AI\Schema` ontbreekt). Woo-producten en coupons blijven bewust staan (orders verwijzen ernaar).
3. **AI-beheerpagina (ai-backend, `AI\Admin`)** — de module levert twee secties via
   `apply_filters( 'bps_ai_admin_sections', [] )`, vorm per sectie:
   `[ 'title' => string, 'render' => callable(): void, 'save' => callable( array $post_unslashed ): string[] meldingen,
   'reset' => callable(): void ]`. De schil print per sectie een `<form>` met eigen nonce, roept `render()` binnen het
   formulier, bij POST `save( wp_unslash( $_POST ) )` (toont de teruggegeven meldingen als admin notice) en bij
   "Standaard terugzetten" `reset()`. **Fire `do_action( 'bps_ai_admin_ready' )` in `AI\Admin` vóór `admin_menu` prio
   99**: zolang die actie niet gevuurd is, toont commerce een eigen noodpagina *WooCommerce → Rondes & diensten*
   zodat de eigenaar nooit vastzit.
4. **Studio-UI (studio-team)** — `config.services` staat in `public_config` (filter `bps_public_config`):
   `{ catalog: ServiceDef[], offerRules, routes: { cart: 'services/cart', dismiss: 'services/dismiss' } }`.
   Dienstkaart: `POST /services/cart {serviceId, designRef}` (headers zoals `/cart`: `X-BPS-Token`, `X-WP-Nonce` als
   ingelogd, `X-BPS-Locale`), "Nee, dank je" → localStorage + optioneel `POST /services/dismiss {serviceId, designRef}`.
   Antwoorden en foutcodes: SPEC §4.2. `designRef` = de `ref` van het ontwerp (zelfde als `bps.ref` in de mand);
   verplicht voor regelopties (D3b, D4, D7), die hangen aan de patchregel en verdwijnen er automatisch mee.
5. **Diensten-JS (frontend, `assets/src/services/index.js`)** — `evaluate()` moet dezelfde uitkomst geven als
   `Services::evaluate()` in PHP: gebruik `tests/services/offer-vectors.json` (20 + 3 vectoren, incl. spoed aan) als
   gedeelde test. Regels in het kort: D2 enkel bij niet-AI (`minSidePx < 300`, `photoOfLogo`, `jpegNoise > 0,35`),
   D2b bij `sketch`, D1 enkel `isAi && check.status !== 'ok' && !autoFixAvailable`, D3 bij `selfEmbroider`, D3b op
   `thanks`, D4 enkel aan + `rushDate <= neededBy < normalDate` + techniek toegelaten, D6 bij `qty >= 50 ||
   totalIncl >= 250 || technique === 'pvc'`; volgorde = volgorde in `ai-services.json`; max 1, nooit vooraf gekozen.
6. **AI-paneel (frontend)** — pakket-sheet: `POST /ai/checkout {packId}` → `checkoutUrl` openen; saldo opnieuw
   via `GET /ai/credits` (of `/ai/status`) bij `focus`/`visibilitychange`. Vergrendelde betaalde rondes
   (`balance.paidLocked > 0`): sheet met de twee vakjes (`legal.ai.pack_consent_start/ack`) →
   `POST /ai/credits/consent {start:true, ack:true}`. E-mail: `POST /ai/email {email}` → `POST /ai/email/verify
   {email, code}` → `{balance, granted}`; foutcodes `bps_ai_code_wrong` (`data.triesLeft`), `bps_ai_code_expired`,
   `bps_ai_slow_down` (`data.retryAfter`).
7. **Orderbeheer (premium-team)** — commerce voegt zelf een metabox **"Diensten (Patch Studio)"** toe op het
   orderscherm (klassiek én HPOS) met status per dienstregel (wacht op herroepingstermijn → ingepland → bezig →
   proef verstuurd → geleverd / geannuleerd), "klaar rond"-datum en een optioneel bericht dat mee gemaild wordt.
   Interne itemmeta (`_bps_service`, `_bps_svc_*`, `_bps_ai_pack`, `_bps_ai_coupon`, `_bps_ai_fulfilled`) is
   verborgen via `woocommerce_hidden_order_itemmeta`. Wil de productiefiche (`class-order-production.php`) diensten
   tonen: lees `Services\Service_Status::of_item( $item )` (geeft `{id, svc, status, readyBy, startAfter, note}`),
   en hook op `bps_svc_status_changed( $order, $item, $from, $to )`.
8. **Kassa-volgorde (premium-team / maatwerktekst)** — onze vakjes staan op `woocommerce_review_order_before_submit`
   prio 25 (maatwerk-tekst D: prio 20). Blokkassa: drie optionele checkboxvelden (`borduur-patch-studio/ai-consent-
   start`, `…/ai-consent-ack`, `…/svc-consent`, locatie `order`), altijd zichtbaar met de uitleg "Enkel van
   toepassing als je ontwerprondes of een dienst koopt" (Woo kan ze nog niet verbergen op basis van de mand).
9. **Herschrijfregels** — het Mijn-account-endpoint `ontwerprondes` (FR-slug via filter `bps_svc_account_slug`)
   doet één keer `flush_rewrite_rules()` per versie (optie `bps_svc_rewrite_v`), nooit per request.
10. **Bestaande no-store-filter** — `Rest\Routes::post_dispatch` zet `no-store` op alle routes in de namespace;
    commerce zet daarna (prio 99) `GET /services/catalog` terug op `public, max-age=300`. Geen wijziging nodig.
11. **Eigenaar / boekhouder (niet-code)** — dienstprijzen D1–D7 bevestigen; spoed (D4) en extra proefronde (D5)
    aanzetten zodra capaciteit/proefbeleid vastligt; btw-behandeling pakket + korting (handelskorting) nakijken;
    gateways zonder automatische terugbetaling: herroeping stuurt dan een taakmail naar `admin_email`.


## ai-backend (bron: docs/ai/BACKEND.md) — precies wat de integratie nog moet doen

De backend is klaar en getest (`php tests/ai/backend/run-backend-php.php`, ook via `npm test`; rooktest in de lokale
WordPress met de echte `Services\Credits` groen). Nodig in bestaande code:

1. **Laden (premium-team, `includes/class-plugin.php`)** — zie §Architectuur 1: `'BorduurPatchStudio\AI\Module'` in
   `Plugin::COMPONENTS` (na `Rest\Routes`). De module zet zelf alle hooks; standaardmodus is `off`.
2. **Ontwerp-payload (premium-team, `includes/woo/class-payload.php`)** — `Payload::sanitize()` laat `source.ai`
   nu weg, waardoor `_bps_ai` nooit op ontwerp/orderitem komt. Toevoegen in het `source`-blok:
   ```php
   'ai' => ( isset( $raw['source']['ai']['jobId'], $raw['source']['ai']['index'] )
       && is_string( $raw['source']['ai']['jobId'] )
       && preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', strtoupper( $raw['source']['ai']['jobId'] ) )
       && is_numeric( $raw['source']['ai']['index'] ) )
       ? array( 'jobId' => strtoupper( $raw['source']['ai']['jobId'] ), 'index' => max( 0, min( 3, (int) $raw['source']['ai']['index'] ) ) )
       : null,
   ```
   `AI\Design_Link` controleert daarna op `bps_design_created` dat de job van dezelfde eigenaar is en dat precies dit
   voorstel gekozen werd; anders wordt de claim genegeerd.
3. **`package.json` (integrator)** — `test:php` aanvullen met `&& php tests/ai/backend/run-backend-php.php`
   (`npm test` draait hem al via `tests/ai/backend.test.js`).
4. **Beheer (premium-team, optioneel)** — de AI-instellingen staan op een eigen pagina **WooCommerce → Patch Studio AI**
   (`admin.php?page=bps-ai`, `admin_menu` prio 61). Wil je ze als tab in de bestaande pagina: in
   `Settings_Page::render()` na de `<nav>` een link `<a class="nav-tab" href="admin.php?page=bps-ai">AI-ontwerpstudio ↗</a>`
   als `class_exists( '\BorduurPatchStudio\AI\Admin' )`. Andere bouwers voegen tabs toe met `bps_ai_admin_sections`
   (contract in de docblock van `includes/ai/class-admin.php`; commerce: "Rondes & pakketten" order 35, "Diensten" 40).
5. **Orderbeheer (premium-team)** — §Architectuur 5 blijft: `_bps_ai` (`{jobId, index, provider, model, legalVersion,
   humanFinish:false, review:null}`) tonen + nakijkvinkje.
6. **Studio/frontend** — REST-contract zoals SPEC §4.3, met deze details:
   - gasten sturen `X-BPS-Token` (zelfde token als `/designs`), ingelogden `X-WP-Nonce`; zonder token: 401 `bps_bad_token`;
   - stuur `lang` (nl|fr) mee in elke body/query; het antwoord volgt die taal;
   - beeld-URL's zijn ondertekend en ± 1 u geldig: na lang wachten opnieuw `GET /ai/jobs/{id}` of `/ai/history`;
   - `blocked`-antwoorden zijn een JobResponse met `blocked:true, code, message`; StatusResponse heeft extra
     `testMode` + `testNotice` (toon die regel altijd in testmodus);
   - annuleren geeft de ronde ook terug als de job al liep (`charged:0`, melding "Geannuleerd. Je ronde is teruggezet.").
7. **Testsite (testenv)** — de rooktest liet in de lokale WordPress de tabellen `wp_bps_ai_*` en één testjob achter;
   `DISABLE_WP_CRON` staat aan, dus de dagelijkse opruiming draait daar niet vanzelf (`wp cron event run bps_ai_cleanup`).
8. **Eigenaar (geen code)** — OpenAI-sleutel als `define( 'BPS_OPENAI_API_KEY', '…' );` in `wp-config.php` (of in de
   beheerpagina, versleuteld), optioneel `BPS_RECRAFT_API_KEY` voor terugval/vectoriseren; prijzen in
   `config/ai-providers.json` nakijken en `verified` op `true`; OpenAI-DPA aanvaarden; nginx: blok
   `location ^~ /wp-content/uploads/patch-studio/ai/ { deny all; }`.


## Prompt-adapter (bouwer `prompt`, 18-11; code: `includes/ai/class-prompt-adapter.php`, uitleg: `docs/ai/05-prompt-adapter.md`)

Klaar en getest (`php tests/ai/run-prompt-adapter-php.php`, 440 checks; `php tests/ai/run-prompt-php.php`, 653
checks incl. de testset van 30 klantvragen; beide ook via `npm test`). `AI\Jobs` gebruikt de adapter al automatisch
(`Jobs::adapter_input()` en `Jobs::understood()` kijken met `class_exists` of `Prompt_Adapter` er is). Wat er nog nodig is:

1. **ai-backend, `includes/ai/class-jobs.php` (alt-tekst, regel ±853)** — de alt van een voorstel nu opbouwen met
   `Prompt_Adapter::alt( $brief, $lang, $i )` i.p.v. `sprintf( 'Voorstel %d: %s', … $und['summary'] )`. De summary
   bevat ook de stijl ("· stijl club"), de alt volgens SPEC §1.4 niet: "Voorstel 2: wolf met een bijl, rond, zwart en rood".
2. **ai-backend, `Jobs::refine()` (LocalRefine)** — `Prompt_Adapter::local_refine( $res, self::cost( 'refine' ) )`
   geeft het volledige `LocalRefine`-antwoord (§4.3) terug, met een gegarandeerd geldige studio-actie
   (`LOCAL_ACTIONS` = §10.3), hex-kleuren uit `ai-prompts.json colorNames` en een gevalideerde vorm. De huidige eigen
   opbouw werkt ook (dezelfde velden); dit voorkomt drift.
3. **ai-backend, ChooseResponse.hints** — `Prompt_Adapter::hints( $brief )` (vorm, maxColors, hex-kleuren, textMode,
   tekst, plaats, `background:'transparent'`).
4. **ai-backend, `Module` → `public_config.ai.styles`** — mag `Prompt_Adapter::public_styles( $lang )` gebruiken
   (zelfde vorm `{id,label,hint}`, nooit de Engelse fragmenten).
5. **frontend, `assets/src/ai/panel.js` ("Zo begrepen we je")** — `understood` heeft naast de §4.3-velden nu ook
   `subject` (in de woorden van de klant), `colorLabels` (nl/fr), `chips: [{kind: subject|shape|colors|maxColors|style|text, label}]`
   en `notes: [{code, message}]` (klaar vertaalde uitleg bij `warnings`, o.a. `personal_data_removed`). Chips tonen
   = `chips[].label`; `notes[].message` in de `aria-live`-regio. Geen eigen vertaling van warning-codes nodig.
6. **studio-team, `ctx.getStudio()`** — als de klant de vorm **zelf** koos in de studio, `shapeChosen: true`
   meesturen (en de REST-body `context.shape` + `context.shapeChosen`; ai-backend moet die twee velden dan in
   `Jobs::validate_generate()` doorlaten). Zonder die vlag negeert de adapter de studiovorm bewust: anders zou de
   standaardvorm van de studio elke stijlkeuze (mascotte → op contour, militair → schild) overschrijven. Een vorm in de
   beschrijving ("…, schild") wint altijd van de studio.
7. **ai-backend, `Jobs::validate_generate()`** — `context.fabric` mag ook een studio-id (`bottle`, `sand`) of `#hex`
   zijn; de adapter zet dat om naar een `fabricColors`-sleutel (`fabricAliases`, dichtste stofkleur). De huidige
   filter `preg_replace('/[^a-z_]/', …)` verwijdert `#` en cijfers: laat `#[0-9a-f]{6}` door als je hex wil sturen.
8. **`package.json` (integrator)** — in `test:php` ook `php tests/ai/run-prompt-adapter-php.php` (de Node-bridge
   `tests/ai/prompt-adapter.test.js` draait hem al in `npm test`).
9. **ai-backend, `config/ai-mock.json`** — door `prompt` aangepast (SPEC §7 taak 3): de Engelse trefwoorden volgen
   nu de Engelse woordenlijst (elk onderwerp valt op een sjabloon, geen dode trefwoorden; test bewaakt dat) en
   `colorHex` kreeg `mustard yellow`, `rust`, `fuchsia`, `lilac` (anders vielen die kleuren weg in `Jobs::colors_hex`).
   Hex-waarden staan nu ook in `ai-prompts.json colorNames`; een test houdt beide gelijk.
