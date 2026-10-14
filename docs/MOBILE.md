# Application mobile Jibiat Marchand (Android, puis iOS)

Une seule application pour toute l'équipe d'une boutique : le marchand et ses membres arrivent sur la gestion, un
livreur sur son espace livreur. C'est une coquille native (Capacitor, dossier `mobile/`) qui ouvre la gestion en
ligne : chaque mise à jour du site est aussitôt dans l'application, sans nouvelle version sur le Play Store.

## Fonctionnement

- Au lancement, l'application ouvre `https://<domaine>/connexion?app=1` : connexion par téléphone et mot de passe,
  puis choix de la boutique (directement s'il n'y en a qu'une).
- La dernière boutique ouverte se rouvre directement au lancement suivant. « Changer de boutique » (menu de la
  gestion, ou page de connexion de la boutique) revient au choix.
- Les pages de la plateforme et des boutiques (`*.jibiat-marchand.com`) restent dans l'application ; les liens
  WhatsApp, téléphone et cartes s'ouvrent dans leur application.
- Sans réseau, une page « Pas de connexion internet » propose de réessayer.
- Le site reconnaît l'application à son navigateur (`JibiatMarchandApp`, voir `resources/js/lib/app.js`).

## Construire

La construction se fait sur GitHub (workflow « Android », `.github/workflows/android.yml`) :

- à chaque changement du dossier `mobile/`, ou à la demande (Actions › Android › Run workflow, en choisissant le
  serveur : préproduction ou production) ;
- résultat dans la page de la construction, rubrique « Artifacts » :
  - `…-apk` : APK d'essai à installer directement sur un téléphone Android ;
  - `…-aab` : paquet signé pour le Play Store, seulement si la clé de signature est dans les secrets.

### Installer l'APK d'essai

1. Téléchargez l'artefact `…-apk` (fichier zip) sur le téléphone et ouvrez-le pour extraire `app-debug.apk`.
2. Ouvrez `app-debug.apk` ; Android demande d'autoriser l'installation depuis cette source (Fichiers ou Chrome) :
   acceptez.
3. L'application « Jibiat Marchand » apparaît avec les autres. Une nouvelle version s'installe par-dessus.

### Clé de signature (Play Store)

À créer une seule fois et à conserver précieusement (sans elle, plus aucune mise à jour possible) :

```
keytool -genkey -v -keystore jibiat-marchand.keystore -alias jibiat -keyalg RSA -keysize 2048 -validity 10000
base64 -w0 jibiat-marchand.keystore
```

Secrets du dépôt (Settings › Secrets and variables › Actions) : `ANDROID_KEYSTORE_BASE64` (le résultat de base64),
`ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS` (`jibiat`), `ANDROID_KEY_PASSWORD`.

## Sur un poste de développement

```
cd mobile
npm install
JM_DOMAIN=preprod.jibiat-marchand.com npx cap sync android
npx cap open android        # Android Studio
```

Icône et écran de démarrage : images sources dans `mobile/assets/`, puis
`npx @capacitor/assets generate --android --iconBackgroundColor '#047857' --splashBackgroundColor '#047857'`.

## Suite prévue

- Notifications push (nouvelle commande, nouveau message, livraison terminée) : projet Firebase à créer.
- Lecture de codes-barres (stock, vente au comptoir), partage natif des liens et des tickets.
- iOS : compte Apple Developer, construction sur une machine macOS de GitHub, TestFlight.
