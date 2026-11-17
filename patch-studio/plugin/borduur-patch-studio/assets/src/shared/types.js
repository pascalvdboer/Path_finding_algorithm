/**
 * Patch Studio — JSDoc-contracten (eigenaar: architect, BEVROREN; SPEC.md §7 is leidend).
 * Dit bestand bevat GEEN code; importeer types met:  /** @typedef {import('../shared/types.js').DesignState} DesignState *\/
 * Eenheden: mm voor lengtes op de patch, "px" = analysepixels (pass B/C, langste zijde ≤ config.analysis.sizes.b),
 * bedragen in EUR, "Excl" = zonder btw, "Incl" = met btw.
 */

/* ============================== Algemeen ============================== */

/** @typedef {'embroidered'|'woven'|'printed'|'sublimated'|'pvc'|'chenille'|'leather'} TechniqueId */
/** @typedef {'circle'|'oval'|'rect'|'rounded'|'shield'|'contour'} ShapeId */
/** @typedef {'ok'|'warn'|'bad'} Status */
/** @typedef {{x:number,y:number,w:number,h:number}} Box */
/** @typedef {{nl:string, fr:string}} L10n */
/** @typedef {{key:string, params:Object, text:string}} Msg */

/* ============================== Analyse ============================== */

/**
 * @typedef {Object} PaletteColor
 * @property {string} hex                 '#rrggbb' representatieve kleur (gemiddelde van het cluster)
 * @property {[number,number,number]} lab
 * @property {number} share               aandeel van de ontwerppixels (0..1), som ≈ 1
 * @property {boolean} thin               dunne-lijn-uitzondering (kleur < minShare maar echte lijn)
 */

/**
 * @typedef {Object} TextLine
 * @property {Box} box                    in analysepx
 * @property {number} capPx               hoofdletterhoogte (80e percentiel) in analysepx
 * @property {number|null} xPx            x-hoogte als kleine letters herkend zijn
 * @property {number} strokePx            mediane lijndikte van de letters
 * @property {boolean} arc                boogtekst
 * @property {boolean} serifOrScript      ongelijke lijndikte (CV(sw) ≥ 0.4)
 * @property {number} paletteIndex        kleur van de tekst (index in palette)
 * @property {number} confidence          0..1
 */

/**
 * @typedef {Object} AnalysisResult
 * @property {1} v
 * @property {number} job                 job-id (oplopend per upload)
 * @property {'raster'|'svg'} source
 * @property {{w:number,h:number}} src    bronafmeting in pixels
 * @property {{w:number,h:number}} size   afmeting van het analysecanvas (pass B/C)
 * @property {number} pxPerSrcPx          size.w / src.w
 * @property {'flat'|'gradient'|'illustration'|'photo'} kind
 * @property {boolean} hasSoftShading
 * @property {boolean} isMockup           afbeelding van een patch (stofstructuur), 07 §6.3
 * @property {boolean} isPatchPhoto       gsm-foto van een echte patch (uitgeknipt)
 * @property {{type:'transparent'|'white'|'solid'|'none', hex:string|null, keep:boolean}} background
 *           keep=true: gekleurde/getekende achtergrond → wordt patchachtergrond (BRIEF "altijd volle patch")
 * @property {{type:ShapeId|null, confidence:number, fillsPatch:boolean, aspect:number, box:Box}} shape
 *           fillsPatch=true: ontwerp is zelf een patchvorm → vult de patch, eigen rand gerespecteerd
 * @property {Box} contentBox             omhullende van alle ontwerppixels (analysepx)
 * @property {Box} artBox                 wat in de patch geplaatst wordt: shape.box als fillsPatch, anders contentBox
 * @property {PaletteColor[]} palette     zonder achtergrond, gesorteerd op share aflopend, max 16
 * @property {number|null} fineLinePx     fijnste lijn (gewogen 4e percentiel, rand van de patchvorm genegeerd)
 * @property {number|null} fineGapPx      kleinste tussenruimte tussen kleurvlakken
 * @property {TextLine[]} textLines
 * @property {{fillAreaPx2:number, satinLenPx:number, runLenPx:number, objects:number}} regions  voor estimateStitches
 * @property {{cover:number, hard:number, chg:number, fake:number, residual:number, texture:number}} features
 * @property {{effectiveLongestPx:number, lowRes:boolean}} resolution  effectiveLongestPx = bronpx van artBox
 * @property {string[]} warnings          codes: 'low_res'|'transparent_shadow'|'mockup'|'patch_photo_cut'|'nearly_empty'|'white_on_transparent'|'shape_uncertain'|'svg_gradient'|'svg_embedded_raster'
 * @property {{width:number,height:number,data:Uint8ClampedArray}} preview  RGBA uitsnede van artBox (achtergrond alfa 0, halo ontsmet), langste zijde ≤ config.analysis.sizes.preview
 * @property {Uint8Array} labels          zelfde afmeting als preview: paletindex per pixel, 255 = achtergrond
 * @property {{[stage:string]:number}} timings  ms per pass
 */

/**
 * Wat analysis doorgeeft tijdens het werk (onProgress / worker-berichten).
 * @typedef {{job:number, stage:'decode'|'background'|'shape'|'colors'|'details'|'preview'|'done', partial:Partial<AnalysisResult>}} AnalysisProgress
 */

/* ============================== Garen & kleuren ============================== */

/**
 * @typedef {Object} Thread
 * @property {string} code                fabrikantnummer, bv. '1747'
 * @property {L10n} name                  eigen neutrale naam
 * @property {string} hex                 schermkleur
 * @property {string} family              'red'|'orange'|'yellow'|'green'|'blue'|'purple'|'pink'|'brown'|'grey'|'black'|'white'|'metallic'
 * @property {boolean} [stock]            op voorraad (standaard true)
 * @property {'metallic'|'fluo'|null} [special]
 */

/**
 * @typedef {Object} ThreadMatch
 * @property {Thread} thread
 * @property {number} deltaE              ΔE2000 (NIET aan klant tonen)
 * @property {'very_good'|'good'|'approx'|'none'} quality   <2 | <5 | <10 | ≥10
 * @property {Thread[]} alternatives      volgende 2 beste
 */

/**
 * @typedef {Object} ReducedPalette
 * @property {{hex:string, lab:number[], share:number, sources:number[], thread:ThreadMatch}[]} colors  gereduceerde kleuren
 * @property {number[]} map               paletindex → index in colors
 * @property {{from:number[], into:number, deltaE:number}[]} merges
 * @property {number} k                   aantal garenkleuren na reductie
 */

/**
 * @typedef {Object} ContrastCheck
 * @property {number} index               index in de (gereduceerde) kleurenlijst, of -1 voor tekstlaag
 * @property {string} hex
 * @property {number} deltaE              ΔE2000 tegen de stofkleur
 * @property {number} ratio               WCAG-contrast
 * @property {'ok'|'low'|'bad'} level     bad: ΔE<8 én share≥5 % (onleesbaar), low: ΔE<15
 */

/* ============================== Ontwerpstaat ============================== */

/**
 * @typedef {Object} TextLayer
 * @property {string} id
 * @property {string} text                max 60 tekens
 * @property {string} font                id uit config.fonts
 * @property {number} heightMm            hoofdletterhoogte in mm (3..40)
 * @property {string} threadCode          garencode of 'auto'
 * @property {'straight'|'arc_top'|'arc_bottom'} path
 * @property {{x:number,y:number}} offsetMm   centrum t.o.v. patchcentrum (straight)
 * @property {number} angleDeg            middelpunt op de boog (arc), 0 = boven/onder midden
 * @property {number} insetMm             afstand basislijn tot buitenrand (arc)
 * @property {number} letterSpacing       em, -0.05..0.5
 */

/**
 * @typedef {Object} DesignState
 * @property {1} v
 * @property {number|null} designId       na upload naar server
 * @property {string|null} token          HMAC-token van de server
 * @property {string|null} ref            'PS-2026-0042'
 * @property {{kind:'upload'|'text', fileName:string|null, fileType:string|null, fileBytes:number|null, hash:string|null}} source
 * @property {TechniqueId} technique
 * @property {boolean} techniquePicked    klant koos zelf (anders volgt het advies)
 * @property {ShapeId} shape
 * @property {boolean} shapePicked
 * @property {number} aspect              breedte/hoogte (circle = 1)
 * @property {number} sizeCm              langste zijde
 * @property {number} qty
 * @property {string[]} options           optie-id's uit config.options
 * @property {string} fabric              'auto' | fabric-id
 * @property {string} edgeColor           'auto' | garencode
 * @property {{[paletteIndex:string]: string}} threadOverrides  paletindex → garencode
 * @property {number} maxThreadColors     doel bij vereenvoudiging (6|8|10|12)
 * @property {{scale:number, offsetMm:{x:number,y:number}, rotation:0|90|180|270}} art
 * @property {TextLayer[]} texts          max 10
 * @property {'none'|'polo_chest'|'cap_front'|'jacket_back'} mockup
 * @property {string} notes               opmerking klant, max 2000
 * @property {string} locale              'nl-BE'|'fr-BE'
 */

/**
 * Wat naar de server gaat (POST /designs, veld `design`) en wat production toont. Alle velden verplicht tenzij [].
 * @typedef {Object} DesignPayload
 * @property {1} v
 * @property {string} locale
 * @property {TechniqueId} technique
 * @property {number} sizeCm
 * @property {number} widthMm
 * @property {number} heightMm
 * @property {ShapeId} shape
 * @property {number} qty
 * @property {string[]} options
 * @property {{id:string, hex:string, label:string}} fabric
 * @property {{code:string, hex:string, name:string}|null} edgeColor
 * @property {{code:string, name:string, hex:string, share:number}[]} threads   gekozen garenkleuren (na reductie + tekst)
 * @property {{text:string, font:string, heightMm:number, threadCode:string, path:string}[]} texts
 * @property {{scale:number, offsetMm:{x:number,y:number}, rotation:number}} art
 * @property {{kind:string, hasSoftShading:boolean, colors:number, fineLineMm:number|null, minTextMm:number|null,
 *            stitches:{min:number,max:number}|null, warnings:string[], shape:string|null, background:string}|null} analysis
 * @property {{recommended:TechniqueId, chosenStatus:Status, statuses:{[t:string]:Status}, headline:string}} advice
 * @property {{totalIncl:number, unitExcl:number}} priceClient     enkel ter controle; server rekent zelf
 * @property {{fileName:string|null, fileType:string|null}} source
 * @property {string} [notes]
 * @property {{v:string, accepted:boolean}} [rightsConsent]
 */

/* ============================== Advies ============================== */

/**
 * @typedef {Object} Fix
 * @property {'resize'|'switch_technique'|'reduce_colors'|'change_edge'|'change_fabric'|'upload_better'|'quote'|'enlarge_text'} code
 * @property {Msg} msg                    knoptekst/zin, bv. "Maak 8 cm"
 * @property {{type:'set', patch:Partial<DesignState>}|{type:'quote'}|{type:'upload'}} action
 */

/**
 * @typedef {Object} Reason
 * @property {string} code                bv. 'text_too_small', 'line_too_thin', 'too_many_colors', 'photo', 'gradient', 'min_qty', 'size_too_small', 'size_quote', 'low_contrast', 'no_dark_carrier', 'merrow_shape'
 * @property {Status} severity
 * @property {Msg} msg                    "Je kleinste tekst is 3,1 mm hoog; borduren vraagt minstens 4 mm."
 * @property {number} [measured]
 * @property {number} [limit]
 * @property {'mm'|'colors'|'cm'|'pcs'} [unit]
 */

/**
 * @typedef {Object} Verdict
 * @property {TechniqueId} technique
 * @property {Status} status
 * @property {number} score               0..100 (ranking)
 * @property {'order'|'quote'} mode       order = direct bestelbaar, quote = via offerte
 * @property {boolean} orderable          mode==='order' en status!=='bad' en maat ≤ maxOrderCm
 * @property {Msg} headline               ≤ 6 woorden: "Ideaal om te borduren"
 * @property {Reason[]} reasons           gemeten feiten, belangrijkste eerst
 * @property {Fix[]} fixes                minstens 1 bij warn/bad
 * @property {number|null} threadColors   aantal garenkleuren na vereenvoudiging (draadtechnieken)
 * @property {Msg[]} notes                extra (levertijd, minimum, wat er verandert)
 */

/**
 * @typedef {Object} Upsell
 * @property {'puff3d'|'metallic'|'velcro'|'iron_on'|'on_film'|'size_up'|'qty_tier'} code
 * @property {Msg} msg
 * @property {{type:'set', patch:Partial<DesignState>}|{type:'quote'}} action
 */

/**
 * @typedef {Object} AdviceResult
 * @property {TechniqueId} recommended
 * @property {TechniqueId[]} ranking      beste eerst (alle ingeschakelde technieken)
 * @property {{[t:string]:Verdict}} verdicts
 * @property {{headline:Msg, text:Msg}} summary           adviesbox bovenaan stap 2
 * @property {{recommendedCm:number, reason:Msg|null, minCmByTechnique:{[t:string]:number|null}}} size
 * @property {{maxThreadColors:number, reason:Msg}|null} colors
 * @property {Upsell[]} upsells           max 2, enkel als relevant
 * @property {Msg[]} trust                vertrouwensregels bij de knop
 * @property {Msg[]} warnings             algemene waarschuwingen (lage resolutie, mock-up, …)
 * @property {{designFill:string, fabric:string|null}} autoChoices  aanbevolen vorm/stof-id's
 */

/* ============================== Prijs ============================== */

/**
 * @typedef {Object} PriceInput
 * @property {TechniqueId} technique
 * @property {number} sizeCm
 * @property {number} qty
 * @property {string[]} options
 * @property {number} [poolQty=0]         extra stuks in dezelfde staffelpool (andere mandregels)
 * @property {string[]} [feesAlreadyCharged=[]]  fee-groepen die de mand al bevat
 * @property {number} [otherItemsInclVat=0]      overige artikelen in de mand (voor verzendhint)
 * @property {boolean} [withLadder=true]
 */

/**
 * @typedef {Object} PriceLine
 * @property {string} key                 'item' | 'fee:<group>' | 'extra:<optionId>' | 'shipping'
 * @property {Msg|string} label
 * @property {number} excl
 * @property {number} tax
 * @property {number} incl
 */

/**
 * @typedef {Object} PriceQuote
 * @property {boolean} ok
 * @property {'order'|'quote'} mode
 * @property {{code:string, params:Object}[]} errors   'unknown_technique'|'quote_only'|'size_too_large'|'size_too_small'|'below_min_qty'|'qty_step'|'invalid_option'|'missing_required_option'|'option_conflict'
 * @property {TechniqueId} technique
 * @property {number} sizeCm
 * @property {number} pricedSizeCm
 * @property {number} qty
 * @property {{min:number, discount:number}} tier
 * @property {number} tierQty             qty + poolQty (als techniek in de pool zit)
 * @property {number} unitExcl            round4 — wat de server als productprijs zet
 * @property {number} unitIncl            round2(lineIncl/qty) — enkel weergave
 * @property {number} lineExcl            round2(unitExcl × qty)
 * @property {number} lineTax             round2(lineExcl × vat)
 * @property {number} lineIncl
 * @property {PriceLine[]} extras         per-regel-opties (eenmalig per ontwerp)
 * @property {PriceLine[]} fees           eenmalige kosten (borduurkaart, instelkosten, mal)
 * @property {number} subtotalExcl        line + extras + fees
 * @property {number} subtotalIncl
 * @property {{excl:number, tax:number, incl:number, free:boolean, remainingForFreeInclVat:number|null}} shipping  schatting
 * @property {number} totalExcl           subtotal + shipping
 * @property {number} totalIncl
 * @property {number} perPieceAllInIncl   round2(subtotalIncl / qty)
 * @property {{min:number, discount:number, unitIncl:number, perPieceAllInIncl:number, totalIncl:number, savingPerPieceIncl:number}|null} nextTier
 * @property {{min:number, discount:number, unitIncl:number, perPieceAllInIncl:number, totalIncl:number}[]} ladder  per staffeltrede (qty = max(min, minQty))
 * @property {PriceLine[]} breakdown      alle regels voor "Hoe is deze prijs opgebouwd?"
 */

/* ============================== Render ============================== */

/**
 * @typedef {Object} RenderArt
 * @property {CanvasImageSource|null} image      uitsnede (preview van analyse, als canvas)
 * @property {{width:number,height:number,data:Uint8Array}|null} labels
 * @property {string[]} paletteHex               originele paletkleuren
 * @property {number[]|null} reducedMap          paletindex → gereduceerde index
 * @property {string[]|null} reducedHex          garenkleur per gereduceerde index
 */

/**
 * @typedef {Object} RenderOptions
 * @property {DesignState} design
 * @property {import('./geometry.js').Layout} layout   uit layoutPatch()
 * @property {RenderArt|null} art
 * @property {string} fabricHex                  achtergrond van de patch (stof/drager of overgenomen achtergrond)
 * @property {string|null} backgroundHex         analysis.background.keep → deze kleur vult tot de rand
 * @property {string} edgeHex
 * @property {{[layerId:string]:string}} textHex  kleur per tekstlaag
 * @property {'patch'|'garment'} view
 * @property {'polo_chest'|'cap_front'|'jacket_back'} [garment]
 * @property {string} [garmentHex]
 * @property {boolean} [reduced]                 toon garen-vereenvoudiging (true bij draadtechnieken)
 * @property {'fast'|'high'} [quality]           fast tijdens slepen
 * @property {number} pxSize                     langste zijde canvas in CSS-px × devicePixelRatio
 * @property {boolean} [transparent]             geen scène-achtergrond (export voor winkelmand)
 * @property {{safeZone?:boolean, boxesMm?:Box[]}} [guides]
 * @property {string} [technique]                override (vergelijk-weergave)
 */

/**
 * @typedef {Object} RenderResult
 * @property {number} ms
 * @property {number} pxPerMm
 * @property {{x:number,y:number}} originPx      canvaspositie van patch-mm (0,0)
 */

export {};
