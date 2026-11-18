/**
 * Diensten — klantteksten voor de dienstkaart (svc.*). Vlaams NL met "je", FR met "vous" (SPEC-AI-DIENSTEN §14).
 * De inhoud per dienst (label, korte uitleg, reden, gratis alternatief) komt uit de catalogus (config/ai-services.json
 * via config.services.catalog), zodat de eigenaar ze in de beheerpagina kan aanpassen. Eerlijk: nooit vooraf
 * aangevinkt, altijd "Nee, dank je", geen druk of schaarste.
 */
const nl = {
  'svc.no': 'Nee, dank je',
  'svc.add': 'Voeg toe · {price}',
  'svc.added': 'Toegevoegd aan je winkelmand.',
  'svc.adding': 'Even toevoegen…',
  'svc.why': 'Waarom zie ik dit?',
  'svc.why.body': 'We tonen dit enkel omdat het bij jouw ontwerp of bestelling past. Het is nooit verplicht.',
  'svc.free_alt': 'Gratis alternatief: {text}',
  'svc.lead': { one: 'Klaar in 1 werkdag', other: 'Klaar in {n} werkdagen' },
  'svc.error': 'Toevoegen lukte niet. Probeer het opnieuw of bestel zonder deze dienst.',
  'svc.no_design': 'Bewaar eerst je ontwerp, dan kan je deze dienst toevoegen.',
  'svc.cart': 'Bekijk winkelmand',
  'svc.label': 'Extra dienst',
  'svc.good_to_know': 'Goed om te weten',
};
const fr = {
  'svc.no': 'Non merci',
  'svc.add': 'Ajouter · {price}',
  'svc.added': 'Ajouté à votre panier.',
  'svc.adding': 'Ajout en cours…',
  'svc.why': 'Pourquoi je vois ceci ?',
  'svc.why.body': 'Nous vous le proposons uniquement parce que cela correspond à votre design ou à votre commande. Ce n’est jamais obligatoire.',
  'svc.free_alt': 'Alternative gratuite : {text}',
  'svc.lead': { one: 'Prêt en 1 jour ouvrable', other: 'Prêt en {n} jours ouvrables' },
  'svc.error': 'L’ajout n’a pas fonctionné. Réessayez ou commandez sans ce service.',
  'svc.no_design': 'Enregistrez d’abord votre design, vous pourrez ensuite ajouter ce service.',
  'svc.cart': 'Voir le panier',
  'svc.label': 'Service en plus',
  'svc.good_to_know': 'Bon à savoir',
};
export default { nl, fr };
