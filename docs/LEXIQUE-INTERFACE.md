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
| Boost Facebook | 🚀 Boost Facebook | Menu : pas d'émoji dans le menu |

## Icônes et couleurs

- Les icônes viennent de `ColorIcon` (en couleur) ou `Icon` (trait). Pas d'émoji comme icône : ils changent
  d'un téléphone à l'autre et les lecteurs d'écran les lisent (« cercle rouge »). Un émoji gardé pour
  décorer un texte est entouré d'un `<span aria-hidden="true">`.
- Vert de la marque (`emerald-700`) pour les actions ; ocre pour ce qui est « à traiter » (statuts, compteurs) ;
  rouge pour le retard et les erreurs.
- Texte blanc seulement sur `emerald-700` ou plus foncé, `whatsapp` (#0b7a5c) ou le dégradé `bg-live-grad`.
- Contour des champs et boutons secondaires : `border-field`.
- Textes de 12 px au minimum.
