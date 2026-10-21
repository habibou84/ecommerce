# Publier Jibiat Marchand sur le Play Store

Tout ce qu'il faut pour remplir la Google Play Console. Les images sont dans `docs/play-store/`.

## 1. Ce que vous faites (une seule fois)

1. **Compte développeur** : https://play.google.com/console › « Créer un compte » (25 dollars, une fois).
   - Compte **personnel** : Google impose d'abord un **test fermé avec au moins 12 testeurs pendant 14 jours**
     d'affilée avant de pouvoir publier pour tout le monde.
   - Compte **organisation** (entreprise) : pas de test obligatoire, mais il faut un numéro **D-U-N-S** (gratuit,
     quelques jours à quelques semaines) et des documents de l'entreprise.
2. **Clé de signature** (voir `docs/MOBILE.md` › Clé de signature) : créez-la sur votre ordinateur, gardez le
   fichier et les mots de passe en lieu sûr, mettez les quatre secrets dans GitHub. Ne les envoyez à personne.
3. **Construire le paquet** : GitHub › Actions › Android › « Run workflow », branche `production`, serveur
   `jibiat-marchand.com`. Dans la page de la construction, rubrique Artifacts : `…-aab` (pour le Play Store) et
   `…-apk` (pour essayer sur un téléphone).
4. **Compte de démonstration pour Google** : sur la production, une petite boutique d'essai avec quelques produits
   et commandes. Son numéro et son mot de passe se saisissent **directement dans la Play Console** (Contenu de
   l'application › Accès à l'application), jamais ailleurs.

## 2. Fiche de l'application

- **Nom** : Jibiat Marchand
- **Catégorie** : Entreprise (Business)
- **Site** : https://jibiat-marchand.com
- **Email de contact** : l'adresse du support (elle est publique sur la fiche)
- **Règles de confidentialité** : https://jibiat-marchand.com/confidentialite
- **Suppression du compte** : https://jibiat-marchand.com/suppression-compte
- **Icône** : `docs/play-store/icone-512.png` ; **image de présentation** : `docs/play-store/banniere-1024x500.png`
- **Captures (téléphone)** : `docs/play-store/1-accueil.png` à `6-livraisons.png` (1080 × 1920)

### Description courte (80 caractères au plus)

> Commandes WhatsApp, stock, livraisons et caisse de votre boutique, au même endroit.

### Description complète

> Jibiat Marchand est l'application des boutiques qui vendent sur WhatsApp, Facebook, Instagram, TikTok et en ligne.
> Toute votre activité au même endroit, sur votre téléphone.
>
> COMMANDES
> • Collez le message du client : le numéro, le nom, le quartier et les produits se remplissent tout seuls.
> • Commandes classées par date de livraison, à confirmer, à livrer, à encaisser.
> • Alertes quand une commande attend : pas de livreur, colis pas ramassé, livraison en échec.
>
> STOCK ET PRODUITS
> • Produits avec tailles et couleurs, photos, stock par magasin ou dépôt.
> • Le stock baisse tout seul à chaque vente ; alerte quand un produit va manquer.
>
> LIVRAISONS
> • Confiez vos colis à vos propres livreurs ou à une entreprise de livraison partenaire.
> • Suivi en direct, preuve de livraison, argent encaissé par chaque livreur.
> • Espace livreur dans la même application.
>
> CAISSE ET PAIEMENTS
> • Caisse du jour, vente au comptoir, dépenses, vrai bénéfice.
> • Paiements Wave, Orange Money, MTN, Moov ; argent des livreurs vérifié.
>
> BOUTIQUE EN LIGNE
> • Votre site de vente prêt en quelques minutes, qui prend des commandes même la nuit.
>
> ÉQUIPE
> • Ajoutez vos vendeurs, caissiers et livreurs ; chacun voit seulement ce qui le concerne.
> • Notifications sur le téléphone pour les nouvelles commandes et les livraisons.
>
> Essai gratuit, sans engagement. Pensé pour la Côte d'Ivoire et l'Afrique de l'Ouest.

## 3. Contenu de l'application (questionnaires)

- **Accès à l'application** : « Tout ou partie de l'application est soumis à des restrictions » › instructions :
  « Connectez-vous avec le numéro et le mot de passe ci-dessous, puis choisissez la boutique ». Saisissez le
  compte de démonstration.
- **Annonces** : l'application ne contient pas d'annonces.
- **Classification du contenu** : catégorie « Utilitaire, productivité, communication ou autre » ; réponses « Non »
  partout (pas de violence, pas de contenu sexuel, pas de jeux d'argent). Les utilisateurs peuvent échanger des
  messages avec leurs clients (boîte Messages WhatsApp) : répondre « Oui » à « interaction entre utilisateurs ».
- **Public cible** : 18 ans et plus. L'application ne s'adresse pas aux enfants.
- **Application d'actualités** : non. **Application de santé** : non. **Fonctionnalités financières** : aucune
  (pas de prêt, pas de portefeuille ; les paiements passent par Wave et les autres prestataires).
- **Identifiant publicitaire** : non utilisé.

### Sécurité des données

Réponses générales :
- Les données sont **chiffrées pendant le transfert** (https) : oui.
- Les utilisateurs peuvent **demander la suppression** de leurs données : oui (dans l'application, Mon compte ›
  Supprimer mon compte, et la page de suppression).
- Données **partagées** avec des tiers : non (les prestataires techniques, livraison et paiement choisis par la
  boutique sont des sous-traitants, ce que Google ne compte pas comme un partage).

Données collectées (toutes « nécessaires au fonctionnement », aucune pour la publicité) :

| Type Google | Pourquoi |
| --- | --- |
| Informations personnelles › Nom, Numéro de téléphone, Adresse email (facultative) | Compte, connexion, équipe |
| Informations personnelles › Adresse | Adresses de livraison des clients des boutiques |
| Informations financières › Historique des achats, Autres informations financières | Commandes, caisse, encaissements des livreurs |
| Messages › Autres messages | Boîte Messages WhatsApp de la boutique |
| Photos et vidéos › Photos | Photos des produits, preuves de livraison |
| Activité dans l'application › Interactions | Journal des actions (sécurité) |
| Infos et performances de l'appli › Journaux de plantage, Diagnostics | Supervision des erreurs |
| Identifiants de l'appareil ou autres | Jeton de notification (Firebase) |

**Autorisations demandées par l'application** : Internet, appareil photo (photo d'un produit, preuve de livraison),
notifications. Pas de localisation, pas de contacts, pas de SMS.

## 4. Test fermé (compte personnel)

1. Play Console › Tester › **Tests fermés** › créer une piste, ajouter une liste de testeurs (adresses Gmail de
   12 personnes au moins : marchands, livreurs, proches).
2. Envoyer le paquet `.aab`, puis « Examiner et déployer ».
3. Envoyer aux testeurs le lien d'inscription au test ; chacun installe l'application depuis le Play Store et
   l'ouvre **au moins une fois par jour ou presque pendant 14 jours**.
4. Après 14 jours : Play Console › Tableau de bord › « Demander l'accès à la production » (quelques questions sur
   le test), puis examen par Google (quelques jours).

## 5. Nouvelles versions

Presque tout change sans nouvelle version : l'application ouvre la gestion en ligne. Une nouvelle version n'est
nécessaire que pour un changement du dossier `mobile/` (icône, autorisations, fonctions natives comme le lecteur
de codes-barres). Le numéro de version augmente tout seul à chaque construction.
