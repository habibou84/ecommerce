/**
 * Contenu des vidéos « Les étapes essentielles pour commencer » : une vidéo par étape, dans l'ordre de
 * App\Support\StartSteps. Chaque scène a un titre sur deux lignes, une phrase, et ce que montre le téléphone :
 *
 *   ecran       un vrai écran de l'application : { url, zone, cadre, place, avant }
 *                 zone   'boutique' (gestion et boutique en ligne, par défaut), 'livreur' ou 'site'
 *                 cadre  ce qu'il faut entourer en vert (sélecteur Playwright) ; place : 'center', 'start' ou 'end'
 *                 avant  gestes à faire avant la photo : async (page) => { ... }
 *   menu        un menu du navigateur dessiné par-dessus l'écran : { lignes, choix, bas }
 *   discussion  une discussion WhatsApp dessinée : { nom, messages: [{ texte, moi, boutons }] }
 *   accueilTelephone  l'écran d'accueil du téléphone avec l'icône de l'application (nom sous l'icône)
 *
 * Quand un écran change dans l'application : corrigez la scène ici, puis relancez fabriquer.cjs pour cette étape.
 * Les textes vouvoient, comme toute l'application.
 */
const carte = (texte) => `.card:has-text("${texte}")`

const ETAPES = [
  {
    n: 1, titre: ['Créez votre', 'espace marchand'], accroche: 'Votre boutique en 2 minutes, depuis votre téléphone.', suite: 'créer votre espace marchand',
    scenes: [
      { titre: ['Votre boutique', 'en 2 minutes'], texte: 'Nom de la boutique, votre nom, votre numéro, un mot de passe.', note: 'jibiat-marchand.com',
        ecran: { zone: 'site', url: '/creer-ma-boutique', cadre: 'form', place: 'start' } },
      { titre: ['Vérifiez votre numéro', 'par WhatsApp'], texte: 'Un message déjà écrit : envoyez-le sans le modifier, rien à recopier.', note: 'votre WhatsApp',
        discussion: { nom: 'Jibiat Marchand', messages: [{ moi: true, texte: 'Code de vérification : 833717' }, { texte: '✅ C\'est bon, votre numéro est vérifié.\nRetournez sur la page : votre boutique se crée toute seule.' }] } },
      { titre: ['Votre boutique', 'est prête'], texte: 'Le guide vous accompagne, une étape à la fois.',
        ecran: { url: '/gestion/demarrage', cadre: carte('Bien démarrer avec Jibiat Marchand'), place: 'start' } },
      { titre: ['Encore plus simple :', 'écrivez « Inscription »'], texte: 'Sur le WhatsApp de Jibiat Marchand : votre boutique se crée dans la discussion.', note: 'votre WhatsApp',
        discussion: { nom: 'Jibiat Marchand', messages: [{ moi: true, texte: 'Inscription' }, { texte: 'Bienvenue sur Jibiat Marchand 👋' }] } },
    ],
  },
  {
    n: 2, titre: ['Jibiat Marchand', 'sur votre téléphone'], accroche: 'Et sur ordinateur, sans rien installer.', suite: 'installer l\'application sur votre téléphone',
    scenes: [
      { titre: ['Sur Android,', 'installez-la'], texte: 'Dans Chrome, touchez ⋮ puis « Installer l\'application ».', note: 'Android, dans Chrome',
        ecran: { url: '/gestion' }, menu: { lignes: ['Nouvel onglet', 'Historique', 'Favoris', 'Partager…', 'Installer l\'application'], choix: 4 } },
      { titre: ['Sur iPhone,', 'écran d\'accueil'], texte: 'Dans Safari, touchez Partager puis « Sur l\'écran d\'accueil » et « Ajouter ».', note: 'iPhone, dans Safari',
        ecran: { url: '/gestion' }, menu: { bas: true, lignes: ['Copier', 'Ajouter à la liste de lecture', 'Ajouter aux favoris', 'Sur l\'écran d\'accueil'], choix: 3 } },
      { titre: ['Une icône,', 'comme une application'], texte: 'Elle s\'ouvre en plein écran, sans barre de navigateur.', accueilTelephone: 'Awa Mode' },
      { titre: ['Activez', 'les notifications'], texte: 'Dans Mon compte : commandes et alertes arrivent même application fermée.',
        ecran: { url: '/gestion/compte', cadre: carte('Notifications sur cet appareil'), place: 'start' } },
    ],
  },
  {
    n: 3, titre: ['Les informations', 'de votre boutique'], accroche: 'Ce que vos clients voient partout.', suite: 'compléter les informations de la boutique',
    scenes: [
      { titre: ['Votre nom,', 'votre WhatsApp'], texte: 'Réglages › Ma boutique : nom, slogan, numéro WhatsApp des clients.',
        ecran: { url: '/gestion/parametres', cadre: carte('Coordonnées'), place: 'start' } },
      { titre: ['Sur la boutique,', 'les messages, les reçus'], texte: 'Vos clients savent tout de suite à qui ils achètent.', note: 'vue par votre client',
        ecran: { url: '/' } },
    ],
  },
  {
    n: 4, titre: ['Vos frais de', 'livraison'], accroche: 'Le bon prix, à chaque commande.', suite: 'régler vos frais de livraison',
    scenes: [
      { titre: ['Votre prix', 'habituel'], texte: 'Livraisons › Tarifs et zones : indiquez votre prix dans Abidjan.',
        ecran: { url: '/gestion/livraisons/tarifs', cadre: 'button:has(b:has-text("(partout)"))', place: 'center' } },
      { titre: ['Un prix', 'par commune'], texte: 'Touchez un prix pour le changer : Cocody, Yopougon, Abobo…',
        ecran: { url: '/gestion/livraisons/tarifs', cadre: 'section:has(> button:has-text("Cocody"))', place: 'center' } },
      { titre: ['Le bon prix,', 'tout seul'], texte: 'À chaque commande, les frais s\'ajoutent selon le quartier du client.',
        ecran: { url: '/gestion/commandes/nouvelle', cadre: carte('Qui livre'), place: 'start' } },
    ],
  },
  {
    n: 5, titre: ['Vos jours et', 'votre heure limite'], accroche: 'Chaque commande reçoit sa date de livraison.', suite: 'régler les jours et l\'heure limite de livraison',
    scenes: [
      { titre: ['Votre', 'heure limite'], texte: 'Avant : livrée le jour même. Après : le jour suivant. Fermez les jours sans livraison.',
        ecran: { url: '/gestion/livraisons/zones', cadre: carte('Jours et heures de livraison'), place: 'center' } },
      { titre: ['Chaque commande', 'a sa date'], texte: 'Vous voyez quoi livrer aujourd\'hui et demain.',
        ecran: { url: '/gestion/commandes', cadre: 'div:has(> button:text-is("Aujourd\'hui"))', place: 'center' } },
    ],
  },
  {
    n: 6, titre: ['Vos produits', 'et leur stock'], accroche: 'Une commande se saisit en quelques secondes.', suite: 'ajouter vos produits et leur stock',
    scenes: [
      { titre: ['Touchez', '« + Produit »'], texte: 'Produits › Mes produits : chaque article avec son prix et son stock.',
        ecran: { url: '/gestion/catalogue', cadre: 'a[href$="/catalogue/nouveau"]:visible', place: 'center' } },
      { titre: ['Le nom,', 'la description'], texte: 'Une photo, le nom de l\'article, quelques mots pour le décrire.',
        ecran: { url: '/gestion/catalogue/nouveau', cadre: carte('Nom du produit'), place: 'center' } },
      { titre: ['Le prix', 'et le stock'], texte: 'Tailles et couleurs : cochez « Plusieurs variantes ».',
        ecran: { url: '/gestion/catalogue/nouveau', cadre: carte('Prix et variantes'), place: 'start' } },
      { titre: ['Le stock baisse', 'tout seul'], texte: 'À chaque vente. Vous voyez ce qui va manquer.',
        ecran: { url: '/gestion/catalogue', cadre: ':text("en stock")', place: 'center' } },
    ],
  },
  {
    n: 7, titre: ['Des photos', 'qui font vendre'], accroche: 'Pour la boutique en ligne et les réseaux.', suite: 'mettre des photos sur vos produits',
    scenes: [
      { titre: ['Ajoutez', 'vos photos'], texte: 'Dans Mes produits, touchez « + Ajouter une photo » sur l\'article.',
        ecran: { url: '/gestion/catalogue', cadre: ':text("Ajouter une photo")', place: 'center' } },
      { titre: ['Appareil photo', 'ou galerie'], texte: 'Dans la fiche du produit. La première photo est la photo principale.',
        ecran: { url: '/gestion/catalogue/nouveau', cadre: carte('La première photo'), place: 'start' } },
      { titre: ['Une belle photo', 'fait vendre'], texte: 'Lumière du jour, fond uni, le produit en entier.', note: 'vue par votre client',
        ecran: { url: '/produits' } },
    ],
  },
  {
    n: 8, titre: ['Qui livre', 'vos colis ?'], accroche: 'Vos livreurs, ou une entreprise de livraison.', suite: 'choisir qui livre',
    scenes: [
      { titre: ['Ajoutez', 'vos livreurs'], texte: 'Livraisons › Mes livreurs › « + Livreur » : son nom et son numéro.',
        ecran: { url: '/gestion/livraisons/livreurs', cadre: 'button:has-text("Livreur")', place: 'center' } },
      { titre: ['Ses missions', 'sur son téléphone'], texte: 'Il accepte, appelle le client, encaisse : vous suivez tout.', note: 'téléphone du livreur',
        ecran: { zone: 'livreur', url: '/livreur' } },
      { titre: ['Ou une entreprise', 'de livraison'], texte: 'Livraisons › Entreprises de livraison : connectez la vôtre, les courses partent en un clic.',
        ecran: { url: '/gestion/livraisons/entreprises', cadre: 'button:has-text("Entreprise")', place: 'center',
          // Hors production, l'annuaire des entreprises ne répond pas : son message d'erreur n'est pas montré
          avant: (page) => page.locator('.card:has-text("Annuaire indisponible")').evaluateAll((list) => list.forEach((el) => el.remove())) } },
    ],
  },
  {
    n: 9, titre: ['Connectez', 'votre WhatsApp'], accroche: 'Vos clients sont informés, sans que vous y pensiez.', suite: 'connecter votre WhatsApp',
    scenes: [
      { titre: ['Votre numéro', 'WhatsApp Business'], texte: 'Dans WhatsApp, touchez « Connecter mon numéro » et suivez les étapes de Facebook.',
        ecran: { url: '/gestion/whatsapp', cadre: 'button:has-text("Connecter mon numéro")', place: 'center' } },
      { titre: ['Vos clients', 'sont informés'], texte: 'La confirmation, le suivi et le reçu partent tout seuls.', note: 'téléphone de votre client',
        discussion: { nom: 'Awa Mode', messages: [
          { texte: '✅ Bonjour Aminata, votre commande #1072 est confirmée : 16 500 F, livraison demain.', boutons: ['Confirmer', 'Annuler'] },
          { texte: '🛵 Votre colis est en route ! Le livreur Moussa vous appelle à son arrivée.' },
          { texte: 'Merci ! Voici votre reçu : 16 500 F payés. À bientôt chez Awa Mode.' },
        ] } },
      { titre: ['Leurs messages', 'dans l\'application'], texte: 'Toutes vos conversations au même endroit, pour répondre et créer la commande.',
        ecran: { url: '/gestion/whatsapp', cadre: 'a:has(:text-is("Conversations"))', place: 'center' } },
    ],
  },
  {
    n: 10, titre: ['Ouvrez votre', 'boutique en ligne'], accroche: 'Elle vend même quand vous dormez.', suite: 'ouvrir votre boutique en ligne',
    scenes: [
      { titre: ['Ouvrez-la', 'en un toucher'], texte: 'Boutique en ligne › Apparence : elle est en ligne, à votre adresse.',
        ecran: { url: '/gestion/boutique-en-ligne', cadre: carte('Votre boutique est'), place: 'start' } },
      { titre: ['Choisissez', 'son apparence'], texte: 'Thème, logo, couleurs, image d\'accueil.',
        ecran: { url: '/gestion/boutique-en-ligne', cadre: carte('Thème'), place: 'start' } },
      { titre: ['Partagez', 'le lien'], texte: 'Sur WhatsApp, Facebook, TikTok : vos clients commandent seuls.', note: 'vue par votre client',
        ecran: { url: '/produits' } },
    ],
  },
  {
    n: 11, titre: ['Votre', 'première commande'], accroche: 'Suivez-la jusqu\'au bout.', suite: 'saisir votre première commande',
    scenes: [
      { titre: ['Collez le message,', 'c\'est rempli'], texte: 'Le gros bouton +, puis « Coller le message du client ».',
        ecran: { url: '/gestion/commandes/nouvelle', cadre: ':is(a, button):has-text("Coller le message du client")', place: 'start' } },
      { titre: ['Confirmez', 'avec le client'], texte: 'Appelez ou écrivez-lui, puis touchez « Confirmée ».',
        ecran: { url: '/gestion/confirmation', cadre: 'button:has-text("Confirmée")', place: 'center' } },
      { titre: ['Confiez-la', 'au livreur'], texte: '« Faire livrer » : il la reçoit sur son téléphone. Vous suivez chaque étape.',
        ecran: { url: '/gestion/commandes', cadre: ':is(button, a):text-matches("^Faire livrer"):visible', place: 'center' } },
      { titre: ['Encaissez', 'au franc près'], texte: 'Caisse › Argent chez les livreurs : « Recevoir » quand il vous verse l\'argent.',
        ecran: { url: '/gestion/caisse/livreurs', cadre: 'button:has-text("Recevoir")', place: 'center' } },
    ],
  },
]

/**
 * Avant les photos : les produits de démonstration sans photo en reçoivent une (un dessin simple sur fond uni),
 * pour que le catalogue et la boutique en ligne ressemblent à une vraie boutique.
 */
async function preparer(pages) {
  const page = pages.boutique
  await page.goto(new URL('/gestion/catalogue', page.url()).href, { waitUntil: 'networkidle' })
  const added = await page.evaluate(async () => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' }
    const products = (await (await fetch('/api/v1/products?per_page=50', { headers, credentials: 'include' })).json()).data ?? []
    const palettes = [['#f6e7d8', '#c2410c', '#fdba74'], ['#e0f2fe', '#1d4ed8', '#93c5fd'], ['#fce7f3', '#be185d', '#f9a8d4'], ['#ecfccb', '#3f6212', '#bef264'], ['#fef9c3', '#a16207', '#fde047'], ['#ede9fe', '#6d28d9', '#c4b5fd']]
    let count = 0
    // Un produit reste sans photo : c'est lui qui montre « + Ajouter une photo »
    for (const [i, product] of products.filter((p) => !p.thumb).slice(1).entries()) {
      const [bg, main, light] = palettes[i % palettes.length]
      const canvas = Object.assign(document.createElement('canvas'), { width: 900, height: 900 })
      const c = canvas.getContext('2d')
      c.fillStyle = bg
      c.fillRect(0, 0, 900, 900)
      // Une robe sur cintre, à motifs
      c.strokeStyle = '#6b4f3a'
      c.lineWidth = 10
      c.beginPath(); c.arc(450, 150, 26, Math.PI, 2.2 * Math.PI); c.moveTo(450, 176); c.lineTo(330, 250); c.moveTo(450, 176); c.lineTo(570, 250); c.stroke()
      c.fillStyle = main
      c.beginPath(); c.moveTo(340, 240); c.lineTo(560, 240); c.lineTo(600, 420); c.lineTo(700, 760); c.lineTo(200, 760); c.lineTo(300, 420); c.closePath(); c.fill()
      c.fillStyle = light
      for (let y = 300; y < 740; y += 70) for (let x = 250 + ((y / 70) % 2) * 35; x < 660; x += 70) { c.beginPath(); c.arc(x, y, 16, 0, 2 * Math.PI); if (c.isPointInPath(x, y)) c.fill() }
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
      const form = new FormData()
      form.append('image', blob, 'photo.jpg')
      const res = await fetch(`/api/v1/products/${product.id}/images`, { method: 'POST', headers, body: form, credentials: 'include' })
      if (res.ok) count++
    }
    return count
  })
  if (added) console.log(`Photos de démonstration ajoutées à ${added} produit(s)`)
}

module.exports = { ETAPES, preparer }
