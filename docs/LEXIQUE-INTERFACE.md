# Lexique et règles d'écriture de l'interface

Ces règles valent pour tous les textes de Jibiat Marchand : écrans, boutons, messages, notifications.
Les suivre évite qu'une même chose porte plusieurs noms d'un écran à l'autre.

## Le ton

- **On vouvoie le marchand, son équipe et les livreurs.** « Choisissez vos articles », « Votre solde ».
- **On tutoie les clientes seulement dans ce qui leur est adressé directement** : l'écran affiché pendant
  le live (`LiveDisplay`, `LiveShowStage`, `LiveOverlay`), les textes de publicité proposés (`BoostWizard`),
  les phrases suggérées au marchand pour son live (« Merci à toi ! », champ `say` du copilote).
- Phrases courtes, à la voix active. Un bouton dit ce qu'il fait : « Confirmer », « Faire livrer ».
- Pas de « (s) » : on accorde (`commande{{ n > 1 ? 's' : '' }}`) ou on écrit « Aucun… ».

## Les mots retenus

| On écrit | On n'écrit plus | Où |
| --- | --- | --- |
| Argent chez les livreurs | Argent des livreurs, Argent à récupérer chez les livreurs | Menu Caisse, accueil |
| Argent chez les entreprises | À recevoir des entreprises | Menu Caisse |
| Ma caisse | Vue d'ensemble (caisse) | Menu Caisse |
| Toutes mes boutiques | Vue d'ensemble (plusieurs boutiques) | Menu, titre des pages « mes-boutiques » |
| Ma boutique | Informations de l'espace marchand, Informations | Réglages |
| Nouvelle commande | Commande (bouton +) | Accueil, liste des commandes |
| Coller un message | Coller WhatsApp | Accueil (le message peut venir de Facebook ou Instagram) |
| Toutes les dates | Tout (période) | Liste des commandes, pour ne pas confondre avec l'onglet « Toutes » |
| Préparer un live | Faire un live | Menu Live, accueil (le live se prépare ici et se fait sur TikTok ou Facebook) |
| Boost Facebook | 🚀 Boost Facebook | Menu : pas d'émoji dans le menu |

## Icônes

- Les icônes viennent de `ColorIcon` (en couleur) ou `Icon` (trait). Pas d'émoji comme icône : ils changent
  d'un téléphone à l'autre et les lecteurs d'écran les lisent (« cercle rouge »). Un émoji gardé pour
  décorer un texte est entouré d'un `<span aria-hidden="true">`.
- Les icônes en couleur (`colorIcons.js`) illustrent une rubrique ; elles ne disent jamais un état.

## Charte des couleurs

Toutes les couleurs sont déclarées dans `resources/css/app.css` (`@theme`). Pas de couleur écrite en dur
(`#…`, `bg-[#…]`) dans la gestion : on utilise les classes ci-dessous. Seul l'écran diffusé pendant le live
(LiveDisplay, LiveShowStage, LiveOverlay) a sa propre palette.

| Rôle | Classes | Pour |
| --- | --- | --- |
| Marque | `emerald-700` (actions), `emerald-800` (survol), `emerald-50`/`100` (fonds) | Boutons, liens, onglet actif |
| Live | `live`, `live-soft` | En petit seulement : onglet Live, son icône, point « en direct ». Les cartes du live sont en `bg-brand-grad` |
| Neutres | `slate-*` (gris teinté de vert), `field` (bord des champs) | Fonds, textes, bordures |
| Attention | `ocre-*`, ocre doré (`amber` et `orange` reprennent l'ocre) | À confirmer, non payée, à faire livrer, stock bas, échec à décider |
| Danger | `red-*` | Retard, perte, refus, erreur, suppression |
| En cours | `sky-*` | Confirmée, en préparation, course demandée |
| Mouvement | `violet-*` | En route, en livraison |
| Réussite | `emerald-*` | Livrée, payée, active |
| Neutre (fini) | `slate-*` | Annulée, brouillon, archivée, colis revenu |
| Externes | `whatsapp`, `facebook` | Uniquement leurs propres boutons |

- Badge d'état : fond doux + texte foncé de la même teinte (`bg-ocre-50 text-ocre-800`, `bg-sky-100 text-sky-800`…).
- Texte blanc seulement sur `emerald-700` ou plus foncé, `ocre-700`, `red-600`, `whatsapp`, `facebook`, `bg-brand-grad`.
- Couleurs des statuts : `lib/orders.js` (`STATUS_TONES`, `PAYMENT_TONES`) et `lib/shipping.js` (`SHIPMENT_TONES`).
- Deux choix côte à côte (ex. « Préparer un live » / « Commandes WhatsApp ») ont la même forme : le choix retenu
  prend `border-emerald-700 bg-emerald-50` et une coche.
- Étoiles de notation et badge premium : `yellow` (« or »).

## Polices et tailles de texte

Deux polices, déclarées dans `resources/css/app.css` : **Inter** pour le texte, **Plus Jakarta Sans** pour les
titres (`h1`, `h2`, `h3`, `font-display`) et les gros chiffres (`text-2xl` et plus).

Six tailles seulement, chacune avec un rôle. Pas de taille écrite à la main (`text-[15px]`) : le test
`tests/Feature/InterfaceTypographyTest.php` les refuse.

| Rôle | Taille | Classe | Pour |
| --- | --- | --- | --- |
| Mention | 13 px | `text-xs` | Dates, aides, badges, surtitres en capitales |
| Texte | 14 px | `text-sm` | Listes, tableaux, boutons, menus |
| Texte à lire | 16 px | `text-base` | Texte sur téléphone, champs de saisie, messages, explications |
| Titre de carte | 18 px | `text-lg` | Nom d'un client, d'un produit, titre d'une carte ou d'une section |
| Titre d'écran | 24 px | `text-2xl` | Un seul par écran, en haut ; chiffres des tuiles de statistiques |
| Gros chiffre | 30 px | `text-3xl` | Montant ou total à lire d'un coup d'œil |

- Hors échelle : les icônes et émojis (`text-xl`, `text-4xl`… restent permis pour eux seuls), l'écran diffusé
  pendant le live (LiveDisplay, LiveShowStage, LiveOverlay) et les écrans vus de loin, dont la taille suit l'écran
  (`text-[9vh]`, `text-[38vmin]`), la boutique en ligne, les PDF et les e-mails.
- `text-pastille` (11 px) : uniquement pour le chiffre d'une pastille posée sur une icône, faute de place.
