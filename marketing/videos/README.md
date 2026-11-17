# Vidéos publicitaires de Jibiat Marchand

Série de vidéos verticales (1080 × 1920, 30 images/s) : chacune met en avant une fonctionnalité, puis cite les
autres et se termine par l'inscription (site **jibiat-marchand.com** ou **« Inscription » sur WhatsApp au
+225 07 03 68 68 76**, le numéro `support_whatsapp` de `config/platform.php`).

| Vidéo | Montage | Écrans simulés | Durée |
|---|---|---|---|
| 1. Récap WhatsApp (le marchand écrit « récap ») | `sources/pub8` | `sources/video8` | 66 s |
| 2. Commande seule par « modèles » | `sources/pub7` | `sources/video7` | 62 s |
| 3. Vos livreurs (zones, affectation automatique, caisse) | `sources/pub9` | `sources/video9` | 76 s |
| 4. Vente en live | `sources/pub10` | `sources/video10` | 71 s |

## Identité sonore (à mettre dans **chaque** vidéo)

Tout est synthétisé par `son/identite.py` (aucun son extérieur, aucun droit à payer) :

Couleur **« liquide »** : sons ronds et graves (gouttes, bulles, « bloup » d'envoi de message), **jamais d'aigus**.

- **Musique de fond** `son/musique-boucle.wav` : boucle de 8 mesures, 104 BPM, ré majeur (Dmaj9 – A – Bm7 – Gmaj7),
  piano électrique qui ondule, basse « log drum » qui glisse (amapiano), percussions en gouttes d'eau, motif de la
  marque joué en gouttes ; filtrée au-dessus de 4,5 kHz. Baissée automatiquement sous la voix.
- **Logo sonore** `son/logo-sonore.wav` : quatre gouttes montantes ré – fa# – la – ré sur un accord chaud.
- **Effets** `son/effets/` (tous des gouttes ou des bulles, sous 1 kHz environ) : `pop` (bloup d'envoi, apparition),
  `tap` (toucher), `key` (frappe), `whoosh` / `whoosh_soft` (clapotis de transition), `ding` (double bloup de
  notification), `cash` / `coin` (gouttes en cascade), `success` (trois gouttes qui montent), `vibrate` (vibration
  grave), `stamp` (choc sourd), `error` (goutte qui descend), `shutter` (photo), `shimmer` (bulles qui remontent),
  `tick` (chiffres), `ring` (sonnerie grave).
- Aperçu à écouter : `son/apercu-musique-et-logo.mp3`.

```bash
python3 son/identite.py generer son/            # régénère musique, logo et effets (toujours identiques)
python3 son/identite.py mixer VOIX.mp3 son/evenements/4-vente-en-live.json MIX.wav DUREE TEMPS_DU_LOGO
ffmpeg -i video-sans-son.mp4 -i MIX.wav -map 0:v -map 1:a -c:v copy -af loudnorm=I=-14:TP=-1.5:LRA=11 \
  -c:a aac -b:a 192k -ar 48000 -shortest -movflags +faststart video-finale.mp4
```

Réglages du mixage (dans `mix`) : voix à 0,92 ; musique à 0,20 dans les silences, baissée de 60 % sous la voix ;
effets à 0,3 × leur gain ; volume final normalisé à −14 LUFS (niveau des réseaux sociaux). Le fichier d'événements
liste `[secondes, "effet", gain]`.

## Fabriquer une nouvelle vidéo de la série

1. **Vérifier la fonctionnalité dans le code** (`docs/PLAN.md`, écrans Vue, services) : textes et libellés réels.
2. **Script** : accroche (0 – 5 s), problème, démonstration en étapes numérotées, bénéfice, « Et ce n'est pas
   tout : … », fin avec le site et l'inscription WhatsApp.
3. **Voix off** ElevenLabs, voix « Elisa – Dynamic French Commercial » (`rPVufKNutioPFV4by3r0`), modèle `eleven_v4` ;
   écrire « jibiat tiret marchand point com » et le numéro en toutes lettres. Puis transcription mot à mot
   (`eleven_scribe_v1`) pour caler chaque animation sur le mot prononcé.
4. **Écrans simulés** (`sources/videoN/*.html`) : pages 540 × 960 avec une fonction `render(t)` qui dessine l'état
   à l'instant `t` (aucune animation CSS : rendu image par image, identique à chaque fois).
5. **Montage** (`sources/pubN/index.html`) : fond vert foncé de la marque, titres, téléphone avec l'écran simulé en
   `iframe`, sous-titres, fin commune ; tables `SCENES`, `SUBS`, `STEPS` et correspondances de temps.
6. **Rendu** : servir le dossier `sources/` (`python3 -m http.server 8765`), puis
   `node capture3.js http://127.0.0.1:8765/pubN/index.html#capture frames 0 full` et assembler les images avec
   ffmpeg (`-framerate 30 -i frames/f%05d.jpg`), puis mixer le son comme ci-dessus.

Emoji : les écrans les affichent en images (le navigateur sans écran ne dessine pas la police couleur). Avant un
rendu : `npm pack emoji-datasource-google`, puis copier `package/img/google/64` vers `sources/video7/emoji64`
(non versionné, 21 Mo).

Les visuels des articles sont des dessins provisoires : les remplacer par de vraies photos (`PHOTOS` dans les écrans).
