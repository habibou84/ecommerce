# Vidéos « Les étapes essentielles pour commencer »

Les 11 vidéos de `public/videos/etapes` (page « Bien démarrer » du site, guide de démarrage et centre d'aide de
l'application) sont fabriquées par cet outil à partir des **vrais écrans** de l'application. Quand un écran change,
on corrige la scène et on relance : plus besoin de refaire la vidéo à la main.

- `etapes.cjs` : le contenu. Une vidéo par étape, une liste de scènes (titre, phrase, écran à montrer, ce qu'il faut
  entourer en vert). Les textes vouvoient, comme toute l'application.
- `fabriquer.cjs` : le moteur. Il ouvre la boutique de démonstration, photographie chaque écran, l'habille
  (fond vert, titre, téléphone, cadre) et assemble les images en vidéo MP4 verticale (1080 × 1920), avec la voix
  si elle existe.
- `voix/` : pour chaque vidéo, le texte lu (`etape-NN.txt`), la voix (`etape-NN.mp3`) et le début exact de chaque
  scène dans la voix (`etape-NN.json`).

## Préparer

```bash
service postgresql start; service redis-server start
php artisan migrate:fresh --seed        # boutique de démonstration « Awa Mode » (demo)
php artisan demo:fill demo --orders=60  # produits et commandes fictifs
php artisan storage:link                # photos des produits visibles
php artisan webpush:vapid               # copier les 3 lignes dans .env (bouton « Activer les notifications »)
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

Il faut aussi `ffmpeg` et Playwright avec Chromium (`npm i -g playwright`, puis `NODE_PATH=$(npm root -g)` si
Playwright est installé pour toute la machine).

## Fabriquer

```bash
node tools/videos/fabriquer.cjs            # les 11 vidéos
node tools/videos/fabriquer.cjs 4 6        # seulement les étapes 4 et 6
node tools/videos/fabriquer.cjs 4 --images # les images de l'étape 4, sans vidéo (pour vérifier un cadre)
```

Options : `--boutique=http://demo.localhost:8000`, `--site=http://localhost:8000`, `--telephone=0700000001`,
`--livreur=0700000003`, `--mot-de-passe=password`, `--travail=<dossier des images>`.

L'outil remplace `etape-NN.mp4` et `etape-NN.jpg`, et écrit `etapes.json` (durées et numéro de version).
`App\Support\StartSteps` lit ce fichier : les durées affichées et l'adresse des vidéos se mettent à jour seules, et
les navigateurs rechargent les nouvelles vidéos. Il reste à regarder les vidéos, puis à les enregistrer dans le dépôt.

## La voix

Chaque vidéo a une voix (voix « Martial » d'ElevenLabs, modèle `eleven_v4`), une seule lecture par vidéo. Chaque
scène dure le temps de sa phrase et change un instant avant que la voix la commence.

Quand le texte d'une vidéo change :

1. `node tools/videos/fabriquer.cjs 4 --texte-voix` réécrit `voix/etape-04.txt` (une scène par paragraphe). Une
   scène peut avoir un champ `dit` dans `etapes.cjs` quand le texte lu doit différer du texte affiché.
2. Faites lire ce texte par la même voix dans ElevenLabs et enregistrez le fichier en `voix/etape-04.mp3`.
3. Relevez le début de chaque paragraphe (transcription d'ElevenLabs, « speech to text », qui donne l'heure de
   chaque mot) dans `voix/etape-04.json` : `{"debuts": [0, 5.08, 10.6, ...]}`, un nombre par scène, en secondes.
   Sans ce fichier l'outil estime les débuts à partir des silences : c'est approximatif, à vérifier à l'écoute.
4. Relancez `node tools/videos/fabriquer.cjs 4`.

Sans fichier `.mp3`, la vidéo est fabriquée sans son.

## Corriger une scène

Dans `etapes.cjs`, `cadre` est un sélecteur Playwright (`button:has-text("Livreur")`, `.card:has-text("Thème")`…).
Si l'outil s'arrête sur « waiting for locator », l'écran a changé : ouvrez-le dans le navigateur, trouvez le nouveau
bouton, corrigez le sélecteur (et le texte de la scène si le chemin a changé), relancez l'étape avec `--images`.

Les écrans qui ne sont pas dans l'application (discussion WhatsApp, menu du navigateur, écran d'accueil du
téléphone) sont dessinés : `discussion`, `menu`, `accueilTelephone`.
