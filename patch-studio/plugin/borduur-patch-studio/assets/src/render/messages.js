/**
 * Patch Studio — render catalogue (NL "je" / FR "vous"): canvas descriptions for aria-label and view names.
 */
export default {
  nl: {
    'render.alt.patch': 'Voorbeeld van je patch: {technique}, {shape}, {w} × {h} cm, {edge}.',
    'render.alt.patchNoEdge': 'Voorbeeld van je patch: {technique}, {shape}, {w} × {h} cm.',
    'render.alt.garment': 'Je patch van {w} × {h} cm {garment}, op ware grootte.',
    'render.alt.texts': { one: 'Met 1 tekst: {list}.', other: 'Met {n} teksten: {list}.' },
    'render.alt.empty': 'Nog geen ontwerp. Upload een bestand of start met tekst.',
    'render.garment.polo_chest': 'op de borst van een polo',
    'render.garment.cap_front': 'op de voorkant van een pet',
    'render.garment.jacket_back': 'op de rug van een jas',
    'render.disclaimer': 'Voorbeeld ter illustratie. Garen- en drukkleuren kunnen licht afwijken van je scherm. Je digitale proef toont de definitieve versie.',
  },
  fr: {
    'render.alt.patch': 'Aperçu de votre écusson : {technique}, {shape}, {w} × {h} cm, {edge}.',
    'render.alt.patchNoEdge': 'Aperçu de votre écusson : {technique}, {shape}, {w} × {h} cm.',
    'render.alt.garment': 'Votre écusson de {w} × {h} cm {garment}, à taille réelle.',
    'render.alt.texts': { one: 'Avec 1 texte : {list}.', other: 'Avec {n} textes : {list}.' },
    'render.alt.empty': 'Pas encore de design. Téléchargez un fichier ou commencez avec du texte.',
    'render.garment.polo_chest': 'sur la poitrine d’un polo',
    'render.garment.cap_front': 'sur l’avant d’une casquette',
    'render.garment.jacket_back': 'dans le dos d’une veste',
    'render.disclaimer': 'Aperçu à titre indicatif. Les couleurs de fil et d’impression peuvent légèrement différer de votre écran. Votre épreuve numérique montre la version définitive.',
  },
};
