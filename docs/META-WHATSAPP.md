# Connexion WhatsApp en un clic : démarches chez Meta

Le bouton « Connecter WhatsApp avec Facebook » (Réglages › WhatsApp) utilise l'**inscription intégrée** de Meta
(Embedded Signup). Il passe par **une seule application Meta, celle de Jibiat Marchand**, à créer une fois pour
toute la plateforme. Tant que les variables `META_*` du `.env` sont vides, le bouton n'apparaît pas et seul le
formulaire manuel est proposé.

Comptez une à quelques semaines au total : les délais dépendent de Meta (vérification de l'entreprise, validation
de l'application). En attendant, le bouton fonctionne déjà pour les comptes ajoutés comme testeurs de l'application.

---

## 1. Portefeuille Meta Business de Jibiat Marchand

1. https://business.facebook.com/overview › créez (ou ouvrez) le portefeuille **de votre société**, pas celui
   d'une boutique.
2. Paramètres de l'entreprise › **Centre de sécurité** › « Commencer la vérification » : raison sociale, adresse,
   site web (jibiat-marchand.com), registre de commerce ou document officiel. Meta répond en général en quelques
   jours.

## 2. Application Meta « Jibiat Marchand »

1. https://developers.facebook.com/ › « Commencer » si vous n'êtes pas encore inscrit comme développeur.
2. https://developers.facebook.com/apps/ › **Créer une application** :
   - nom : `Jibiat Marchand`, e-mail de contact ;
   - cas d'utilisation : **« Contacter les clients via WhatsApp »** ;
   - portefeuille : celui de l'étape 1.
3. **Paramètres de l'application › Général** :
   - domaines de l'application : `jibiat-marchand.com` ;
   - URL de la politique de confidentialité : `https://jibiat-marchand.com/confidentialite` ;
   - URL des conditions : `https://jibiat-marchand.com/cgu` ;
   - suppression des données : « URL des instructions », `https://jibiat-marchand.com/confidentialite` (section 6) ;
   - icône (1024 × 1024) et catégorie « Entreprise ».
   Notez l'**identifiant de l'application** et la **clé secrète** (« Afficher »).
4. Ajoutez le produit **Facebook Login for Business** :
   - Paramètres › URI de redirection OAuth valides : `https://jibiat-marchand.com/` et
     `https://preprod.jibiat-marchand.com/` ;
   - domaines autorisés pour le SDK JavaScript : `jibiat-marchand.com` et `preprod.jibiat-marchand.com` seulement
     (Meta refuse les jokers `*.` : la fenêtre Facebook de toutes les boutiques s'ouvre depuis la plateforme, page
     `/whatsapp/facebook`, puis revient sur la gestion de la boutique) ;
   - **Configurations › Créer une configuration** : nom « WhatsApp boutiques », variante **« Inscription intégrée
     WhatsApp »** (WhatsApp Embedded Signup), produits : WhatsApp Cloud API, droits
     `whatsapp_business_management` et `whatsapp_business_messaging`. Notez l'**identifiant de configuration**.
5. **WhatsApp › Configuration › Webhook** :
   - URL de rappel : `https://api.jibiat-marchand.com/webhooks/whatsapp`
     (préproduction : `https://api.preprod.jibiat-marchand.com/webhooks/whatsapp`) ;
   - jeton de vérification : une longue chaîne de votre choix (la même que `META_WEBHOOK_VERIFY_TOKEN`) ;
   - abonnez les champs **`messages`** et **`message_template_status_update`**.

## 3. Variables du serveur

Dans le `.env` (préproduction puis production), puis `deploy/update.sh` :

```
META_APP_ID=identifiant de l'application
META_APP_SECRET=clé secrète de l'application
META_WHATSAPP_CONFIG_ID=identifiant de la configuration (étape 2.4)
META_WEBHOOK_VERIFY_TOKEN=le jeton choisi à l'étape 2.5
```

Le bouton apparaît alors dans Réglages › WhatsApp. **Avant la validation de Meta**, seules les personnes ajoutées
dans l'application (Rôles de l'application › Testeurs ou Administrateurs) peuvent l'utiliser : essayez avec votre
propre compte et un numéro de test.

## 3 bis. Variante : passer par l'application Meta de Jibiat (déjà validée)

L'application Meta de Jibiat (livraison, même portefeuille d'entreprise) est déjà validée pour WhatsApp. Jibiat
Marchand peut s'en servir pour le bouton « Connecter WhatsApp », sans attendre la validation de sa propre application
(qui reste utilisée pour Facebook, Instagram et la publicité).

1. Dans l'application Meta **de Jibiat** :
   - Paramètres › Général › **Domaines de l'application** : ajouter `jibiat-marchand.com` ;
   - Facebook Login for Business › Paramètres : ajouter `https://jibiat-marchand.com` (et `https://www.…`) aux
     **domaines autorisés du SDK JavaScript** et aux **URI de redirection OAuth valides** ;
   - Facebook Login for Business › Configurations : garder la configuration WhatsApp de Jibiat, ou en créer une
     deuxième (« WhatsApp Jibiat Marchand ») et noter son identifiant ;
   - la politique de confidentialité déclarée doit couvrir aussi Jibiat Marchand (même entreprise).
2. Dans le `.env` de Jibiat Marchand (les `META_APP_ID` / `META_APP_SECRET` restent ceux de Jibiat Marchand) :

```
META_WHATSAPP_APP_ID=identifiant de l'application Meta de Jibiat
META_WHATSAPP_APP_SECRET=clé secrète de l'application Meta de Jibiat
META_WHATSAPP_CONFIG_ID=identifiant de la configuration WhatsApp choisie ci-dessus
```

   puis `php artisan optimize`. Le bouton utilise alors l'application de Jibiat.
3. **Notifications** : l'adresse de notifications de l'application de Jibiat reste celle de Jibiat. À la connexion
   d'une boutique, Jibiat Marchand demande à Meta d'envoyer les notifications de **ce compte WhatsApp** à
   `https://api.jibiat-marchand.com/webhooks/whatsapp` (avec `META_WEBHOOK_VERIFY_TOKEN`) : rien à régler chez Meta,
   rien à changer chez Jibiat. Une boutique connectée avant ce changement doit se reconnecter une fois.
4. Dans la fenêtre Facebook, les commerçants voient le nom de l'application : « Jibiat ».

## 4. Fournisseur technique et validation de l'application

1. Dans l'application : **WhatsApp › Démarrage rapide** › « Devenir fournisseur technique » (Tech Provider) et
   suivez les étapes (elles demandent l'entreprise vérifiée de l'étape 1).
2. **Validation de l'application › Demandes** : demandez l'**accès avancé** à `whatsapp_business_management` et
   `whatsapp_business_messaging` (et `public_profile`).
   - Description à coller (à adapter) :
     > Jibiat Marchand est un logiciel de gestion de boutique pour les commerçants de Côte d'Ivoire. Le
     > commerçant connecte le numéro WhatsApp Business de sa boutique via l'inscription intégrée. Nous utilisons
     > whatsapp_business_management pour abonner notre application à son compte, enregistrer son numéro et créer
     > ses modèles de messages (confirmation de commande, suivi de livraison), et whatsapp_business_messaging pour
     > envoyer ces messages à ses clients et afficher leurs réponses dans sa boîte « Messages ».
   - Vidéo (2 à 3 minutes, écran enregistré) : connexion à la gestion d'une boutique de démonstration › Réglages ›
     WhatsApp › « Connecter WhatsApp avec Facebook » › parcours complet dans la fenêtre › retour « WhatsApp
     connecté » et modèles créés › une commande envoie le message de confirmation › le client répond › le message
     apparaît dans Ventes › Messages et on lui répond.
3. Passez l'application en mode **« En ligne »** (Live) une fois la validation obtenue.

## 5. À savoir

- **Facturation** : chaque boutique paie ses propres messages à Meta (moyen de paiement ajouté dans la fenêtre
  d'inscription ou ensuite dans son WhatsApp Manager).
- **Coexistence** : la case « Mon numéro est déjà dans l'application WhatsApp Business » garde l'application du
  téléphone en service, si Meta la propose pour ce numéro et ce pays.
- **Modèles** : créés automatiquement (`jm_confirmation_commande`, `jm_commande_en_route`, `jm_commande_livree`,
  `jm_lien_paiement`, `jm_panier_en_attente`, `jm_rapport_du_jour`). Leur état (en vérification, approuvé, refusé)
  s'affiche à côté de chaque message automatique.
- **Code PIN** : l'application choisit le code de vérification en deux étapes du numéro et le garde chiffré.
- **Liaison par QR code** (service whatsapp-bridge, non officielle) : coupée par défaut pour les nouvelles boutiques.
  Le super administrateur l'autorise boutique par boutique dans la console (fiche boutique › « WhatsApp par QR
  code »). Couper déconnecte le téléphone relié. Sans autorisation, la boutique ne voit que « Connecter WhatsApp avec
  Facebook ». Les boutiques déjà reliées avant ce réglage restent autorisées.
