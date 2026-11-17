
## Van auditor "security" (17-11-2026), zie docs/premium/audit-security.md
- **security-4 (kloon bij opnieuw bestellen):** `POST /cart` kan straks een **nieuw** `designId`, `token` en `ref` teruggeven als het ontwerp al besteld was. De UI neemt die over in haar state en sessionStorage, en gebruikt ze voor latere calls (`/email`, `/quotes`, herstel).
- **security-5 (tokens niet in URL's):**
  - (a) `GET /designs/{id}`: stuur het token als header `X-BPS-Design-Token` in plaats van `?token=`. De query-variant blijft voorlopig werken.
  - (b) Lees de herstellink liefst uit het fragment `#bps=ID.TOKEN`, of uit een cookie of redirect die de server zet. Haal het ontwerp-token zo vroeg mogelijk weg, vóór analytics laadt.
- **security-1 (optionele captcha):** komt er een Turnstile/hCaptcha-optie (standaard uit), dan heeft de UI een plek nodig voor de widget bij "Offerte aanvragen" en "Bewaar & mail". Het antwoordtoken gaat mee als veld `captcha`. Bij 400 `bps_captcha` toon je in het Vlaams: "Bevestig even dat je geen robot bent." / FR: "Confirme que tu n'es pas un robot."
- **security-3 (opslagquotum):** het nieuwe foutantwoord 507 `bps_storage` krijgt een vriendelijke melding met e-mail/WeTransfer-alternatief, net zoals bij `bps_too_big`.
- **security-8 (Origin-check):** het nieuwe foutantwoord 403 `bps_bad_origin` verschijnt niet bij normaal gebruik. Toon dan de algemene foutmelding en probeer niet opnieuw zoals bij `bps_bad_token`.
