// Formation « Vendre en live » : 12 modules en 4 parties ; les vidéos sont dans public/videos/live
export const LIVE_TRAINING = [
  { part: 'Préparer son live', modules: [
    {
      n: 1, title: 'Pourquoi vendre en live marche', seconds: 68,
      points: ['Vos clients voient le vrai produit, porté, en vraie couleur : la confiance s\'installe', 'Ils posent leurs questions et achètent tout de suite : les pièces sont limitées', 'Codes, réservations, liste d\'attente et commandes : Jibiat Marchand s\'en occupe'],
      checklist: ['Je sais pourquoi le live fait vendre', 'J\'ai choisi le jour et l\'heure de mon premier live'],
    },
    {
      n: 2, title: 'Choisir ses articles', seconds: 60,
      points: ['8 à 15 articles qui passent bien en vidéo : ils se portent, de la couleur, une belle matière', 'Stock vérifié (tailles comprises) et un prix spécial live, un peu plus bas', 'D\'abord un article qui attire, puis on alterne ; une surprise pour la fin'],
      checklist: ['J\'ai choisi 8 à 15 articles', 'J\'ai vérifié les pièces et les tailles disponibles', 'Chaque article a son prix du live', 'Mon article surprise est prévu', 'Mes articles et leurs cartons sont prêts, dans l\'ordre'],
      action: { label: 'Préparer un live', prepare: true },
    },
    {
      n: 3, title: 'Préparer votre live dans Jibiat Marchand', seconds: 61,
      points: ['Ventes › Vendre en live › « Préparer un live »', 'Ajoutez vos articles dans l\'ordre : chacun reçoit son code (A1, A2…)', 'Prix du live et pièces proposées : épuisé, la liste d\'attente prend le relais'],
      checklist: ['Mon live a un nom', 'Mes articles sont dans l\'ordre de présentation', 'Chaque article a son prix du live et ses pièces'],
      action: { label: 'Préparer un live', prepare: true },
    },
    {
      n: 4, title: 'Installer son coin live', seconds: 69,
      points: ['Lumière face à vous (fenêtre le jour, lampe ou anneau le soir), téléphone sur trépied, vertical', 'Fond simple et rangé, endroit calme ; un micro-cravate aide beaucoup', 'Connexion testée, téléphone branché, un 2e téléphone pour les réservations et le code'],
      checklist: ['Je suis face à la lumière', 'Mon téléphone est sur un trépied, à hauteur des yeux', 'Mon fond est rangé et l\'endroit est calme', 'Connexion testée, téléphone branché', 'J\'ai fait un essai de 5 minutes'],
    },
  ] },
  { part: 'Afficher les bonnes informations', modules: [
    {
      n: 5, title: 'Montrer le code de l\'article', seconds: 61,
      points: ['Le code en très grand, avec le prix : carton ou 2e téléphone (touchez le code sur l\'écran du live)', 'Au moins 5 secondes, à côté de l\'article, face à la caméra', 'Dites-le à voix haute ; pour plusieurs pièces : « A3 x2 »'],
      checklist: ['Mes cartons de codes sont prêts (ou un 2e téléphone)', 'Je sais montrer le code 5 secondes', 'Je répète le code et le prix à voix haute'],
    },
    {
      n: 6, title: 'Le lien dans la bio et le message épinglé', seconds: 66,
      points: ['« Copier le lien » et collez-le dans votre bio (TikTok, Instagram, Facebook)', 'Pendant le live, épinglez le message tout prêt en commentaire', 'Répétez : « Touchez le lien, choisissez le code, appuyez sur Je prends »'],
      checklist: ['Le lien est dans ma bio', 'J\'ai copié le message à épingler', 'Je sais l\'épingler en commentaire'],
      action: { label: 'Copier le lien de vos lives', link: true },
    },
    {
      n: 7, title: 'Ce qu\'il faut dire et répéter', seconds: 65,
      points: ['Des gens arrivent sans arrêt : répétez le code, le prix et comment réserver', 'Pour chaque article : « C\'est la A3, robe en wax, 20 000 F, tailles S et M »', 'Remerciez par le prénom, répondez aux questions à voix haute, pas de prix en privé'],
      checklist: ['Je répète le code et le prix de chaque article', 'Je dis comment réserver : lien en bio ou code sur WhatsApp', 'Je remercie ceux qui réservent par leur prénom'],
    },
  ] },
  { part: 'Animer un live efficace', modules: [
    {
      n: 8, title: 'Le déroulé idéal d\'un live de 45 minutes', seconds: 72,
      points: ['5 minutes d\'accueil, puis un article à la fois (≈ 3 min chacun)', 'Une relance toutes les 5 à 10 minutes : lien, comment réserver, ce qui part vite', 'L\'article surprise vers la 35e minute, puis récap et rendez-vous au prochain live'],
      checklist: ['Mon premier article attire, à bon prix', 'J\'ai gardé un article surprise pour la fin', 'Je connais la date de mon prochain live'],
    },
    {
      n: 9, title: 'Créer l\'envie et l\'urgence', seconds: 62,
      points: ['Montrez l\'article porté, de près, et racontez son histoire (occasion, assortiment)', 'Annoncez les pièces restantes et lisez les réservations à voix haute', 'Prix du live valable seulement pendant le live ; épuisé : liste d\'attente. Toujours honnête'],
      checklist: ['Je sais raconter chacun de mes articles', 'J\'annonce les pièces restantes', 'Je propose la liste d\'attente quand c\'est épuisé'],
    },
    {
      n: 10, title: 'Travailler à deux', seconds: 56,
      points: ['Une personne présente face à la caméra, l\'autre gère sur le 2e téléphone', 'L\'assistant saisit les réservations vues en commentaire (mode assistant) et suit les pièces', 'Il épingle le lien, répond aux questions, prépare l\'article suivant ; des signes convenus avant'],
      checklist: ['J\'ai quelqu\'un pour m\'assister', 'Mon assistant connaît le mode assistant', 'Nous avons convenu de nos petits signes'],
    },
  ] },
  { part: 'Après le live', modules: [
    {
      n: 11, title: 'Transformer les réservations en livraisons', seconds: 61,
      points: ['Regardez le bilan du live : réservations, commandes, quartiers encore attendus', 'Confirmez dans Ventes › À confirmer (adresse et moment) ; un client = une seule commande', 'Confiez les livraisons, livrez dès le lendemain et suivez jusqu\'à la Caisse'],
      checklist: ['J\'ai regardé le bilan de mon live', 'J\'ai confirmé les commandes du live', 'Les livraisons sont confiées pour le lendemain'],
    },
    {
      n: 12, title: 'Progresser d\'un live à l\'autre', seconds: 63,
      points: ['Après chaque live : réservations, commandes livrées, montant encaissé', 'Repérez ce qui part vite et ce qui reste ; changez une seule chose à la fois', 'Un rendez-vous fixe, annoncé à l\'avance, et un merci après la livraison'],
      checklist: ['J\'ai noté les chiffres de mon dernier live', 'J\'ai choisi la chose à changer la prochaine fois', 'Mon prochain live est annoncé'],
    },
  ] },
]

export const trainingVideo = (n) => `/videos/live/formation-${String(n).padStart(2, '0')}.mp4`
export const trainingPoster = (n) => `/videos/live/formation-${String(n).padStart(2, '0')}.jpg`

// Avant de lancer le live (écran d'un live en préparation)
export const BEFORE_LIVE = [
  'Chaque article a son code, son prix du live et ses pièces',
  'Le lien de vos lives est dans votre bio',
  'Le message à épingler est copié',
  'Les codes sont prêts à montrer (cartons ou 2e téléphone)',
  'Votre WhatsApp officiel est connecté (Réglages › WhatsApp), pour les codes et les captures',
]

// Cases cochées, gardées sur cet appareil (commodité : rien ne se perd si le stockage est indisponible)
export function loadChecks(key) {
  try {
    return JSON.parse(localStorage.getItem(`jm_live_check_${key}`) || '[]')
  } catch {
    return []
  }
}

export function saveChecks(key, values) {
  try {
    localStorage.setItem(`jm_live_check_${key}`, JSON.stringify(values))
  } catch {
    // stockage indisponible : les cases ne sont pas gardées
  }
}
