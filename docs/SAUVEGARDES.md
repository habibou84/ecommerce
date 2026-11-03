# Sauvegardes et entretien du serveur (Jibiat Marchand)

Tout est installé par `deploy/update.sh` à chaque mise à jour (et par les scripts d'installation) : il n'y a rien à
taper à la main sur le serveur, sauf les clés de la copie hors du serveur (plus bas). Le script est
`deploy/backup.sh` (le même que celui de Jibiat, `habibou84/deliverynew`) ; il tourne en root, chaque installation
(production `jibiat-production`, préproduction `jibiat-marchand`) a ses propres sauvegardes.

## Ce qui tourne tout seul

| Quand | Quoi |
|---|---|
| Chaque nuit, 1 h 45 (préproduction : 3 h 35) | Copie complète de la base (`pg_dump`) et des fichiers : `.env` (dont `APP_KEY`, indispensable pour relire les données chiffrées), photos du catalogue, logos, documents, sessions WhatsApp (`storage/app`). Chaque copie est relue aussitôt : un fichier vide ou illisible est signalé. Puis envoi chiffré hors du serveur, si c'est réglé. |
| Avant chaque mise à jour qui contient des migrations | Copie de la base `avant-mise-a-jour.dump`, remise en place automatiquement si la mise à jour échoue (docs/MISE-A-JOUR-AUTOMATIQUE.md). |
| Chaque nuit, 4 h 35 (préproduction : 4 h 50) | Entretien de PostgreSQL (`VACUUM ANALYZE`), fichiers temporaires de plus de 7 jours. |
| Chaque dimanche, 6 h 10 (préproduction : 6 h 30) | **Test de restauration** : la dernière sauvegarde est rechargée dans une base à part, les comptes, boutiques, commandes, produits, expéditions et livreurs sont comptés et comparés à la vraie base, puis la base de test est effacée. |
| Chaque nuit | Tâches en échec de plus de 30 jours effacées. Journaux tournés (14 jours). Correctifs de sécurité du système installés (dépôt « security » de Debian seulement, jamais de redémarrage du serveur). |

Tout se voit dans **Console › Santé** (sauvegarde de la nuit, copie hors du serveur, test de restauration, espace
disque). Une panne prévient les super administrateurs par le WhatsApp du support (au plus une fois toutes les
6 heures) ; l'espace disque à plus de 80 % et un test de restauration manquant préviennent une fois par jour.

## Dossier et conservation

`/var/backups/<installation>` (production : `/var/backups/jibiat-production`, réglage `PLATFORM_BACKUP_PATH` du `.env`) :

- `quotidien/` : `base-AAAA-MM-JJ.dump` et `fichiers-AAAA-MM-JJ.tar.gz`
- `hebdo/` : la copie du dimanche ; `mensuel/` : la copie du 1er du mois
- `etat-*.json` : ce que lit la console (aucune donnée sensible)
- journal : `/var/log/<installation>-backup.log`

| | Base | Fichiers |
|---|---|---|
| Copies de chaque jour | 14 jours | 7 jours |
| Copies du dimanche | 8 semaines | 4 semaines |
| Copies du 1er du mois | 12 mois | 3 mois |

La préproduction garde 3 jours, sans copies du dimanche ni du mois. Pour changer : `BACKUP_KEEP_DAILY`,
`BACKUP_KEEP_WEEKLY`, `BACKUP_KEEP_MONTHLY` (base) et `BACKUP_FILES_KEEP_DAILY`, `_WEEKLY`, `_MONTHLY` (fichiers)
dans le `.env`. Les dossiers ne sont lisibles que par root. Les anciennes copies tournantes (`base-1.dump` à
`base-7.dump`) sont effacées au bout de 14 jours.

## Copie hors du serveur (Cloudflare R2)

Même espace de stockage et mêmes réglages que Jibiat (les copies sont rangées dans le dossier de chaque
installation) : si R2 est déjà réglé pour Jibiat, reprendre les mêmes valeurs, avec une clé de chiffrement propre à
Jibiat Marchand.

Sans elle, un serveur perdu (panne du disque, piratage, résiliation) emporte aussi ses sauvegardes. Les 10 premiers
Go sont gratuits chaque mois chez Cloudflare R2.

1. **Cloudflare** › **R2** › activer R2 (Cloudflare demande une carte bancaire, même pour l'offre gratuite).
2. **Create bucket** : nom `jibiat-sauvegardes`, emplacement automatique.
3. **R2** › **Manage API tokens** › **Create API token** : droit **Object Read & Write**, limité à l'espace
   `jibiat-sauvegardes`. Cloudflare affiche une seule fois l'**Access Key ID**, le **Secret Access Key** et l'adresse
   (**endpoint**, `https://<identifiant>.r2.cloudflarestorage.com`).
4. Sur le serveur, créer la clé de chiffrement :
   ```bash
   openssl rand -base64 32
   ```
   **Gardez-en une copie en dehors du serveur** (gestionnaire de mots de passe, coffre) : sans elle, les copies
   distantes sont illisibles, et si le serveur disparaît, la clé du `.env` disparaît avec lui.
5. Ajouter à la fin du `.env` de la production (`nano /var/www/jibiat-production/.env`), sans jamais copier ces valeurs dans une
   conversation, un e-mail ou une capture :
   ```
   BACKUP_S3_ENDPOINT=https://<identifiant>.r2.cloudflarestorage.com
   BACKUP_S3_BUCKET=jibiat-sauvegardes
   BACKUP_S3_KEY=<Access Key ID>
   BACKUP_S3_SECRET=<Secret Access Key>
   BACKUP_ENCRYPTION_KEY=<la clé de l'étape 4>
   ```
6. Lancer une sauvegarde tout de suite pour vérifier : `bash /var/www/jibiat-production/deploy/backup.sh`. La fin doit dire
   « Copie hors du serveur : 2 fichier(s) chiffré(s) envoyé(s) », et **Console › Santé** passe au vert.

Chaque fichier est chiffré (AES-256) **avant** de partir : Cloudflare ne voit que des données illisibles. Les copies
distantes sont rangées dans `jibiat-sauvegardes/<installation>/quotidien|hebdo|mensuel/` et effacées avec la même
conservation que sur le serveur. Un autre fournisseur S3 (Backblaze B2…) marche aussi : `BACKUP_S3_PROVIDER=Other`
et son adresse dans `BACKUP_S3_ENDPOINT`.

## Restaurer

Toujours **d'abord dans une base à part**, pour vérifier, puis seulement remplacer la vraie base.

Une mise à jour qui échoue revient d'elle-même à la base d'avant (docs/MISE-A-JOUR-AUTOMATIQUE.md).

**Revenir à la sauvegarde d'une nuit** (production : base et utilisateur `jibiat_production`) :
```bash
cd /var/www/jibiat-production
sudo -u jibiat_production php artisan down
sudo -u postgres dropdb jibiat_production && sudo -u postgres createdb -O jibiat_production jibiat_production
sudo -u postgres pg_restore --no-owner --role=jibiat_production -d jibiat_production \
  < /var/backups/jibiat-production/quotidien/base-AAAA-MM-JJ.dump
sudo -u jibiat_production php artisan up
```
Pour un fichier perdu (une photo) : `tar -xzf fichiers-AAAA-MM-JJ.tar.gz storage/app/…` dans un dossier vide,
puis copier le fichier voulu.

**Le serveur a disparu** : installer un nouveau serveur (docs/PRODUCTION-LWS.md), puis récupérer et déchiffrer la
dernière copie distante (`rclone` installé, clés R2 et clé de chiffrement de l'étape 4) :
```bash
export RCLONE_CONFIG_R2_TYPE=s3 RCLONE_CONFIG_R2_PROVIDER=Cloudflare RCLONE_CONFIG_R2_REGION=auto \
  RCLONE_CONFIG_R2_ENDPOINT=<endpoint> RCLONE_CONFIG_R2_ACCESS_KEY_ID=<clé> RCLONE_CONFIG_R2_SECRET_ACCESS_KEY=<secret>
rclone ls r2:jibiat-sauvegardes/jibiat-production/quotidien
read -rs BACKUP_PASS && export BACKUP_PASS     # taper la clé de chiffrement (rien ne s'affiche)
for f in base-AAAA-MM-JJ.dump fichiers-AAAA-MM-JJ.tar.gz; do
  rclone cat "r2:jibiat-sauvegardes/jibiat-production/quotidien/$f.enc" \
    | openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASS > "$f"
done
```
Le fichier `fichiers-…tar.gz` contient le `.env` d'origine : reprendre son `APP_KEY` (sinon les données chiffrées
sont perdues), puis restaurer la base comme ci-dessus et remettre `storage/app` en place.

## Limites

- Une sauvegarde par nuit : en cas de catastrophe, on perd au pire les changements depuis 1 h 45 du matin. Pour
  descendre à quelques minutes, il faudrait l'archivage continu de PostgreSQL (WAL), plus lourd à mettre en place.
- Le test de restauration recharge la base entière une fois par semaine : il faut, ce jour-là, autant d'espace
  libre que la taille de la base.
- Le premier passage de `deploy/update.sh` après l'arrivée de cette page installe les tâches ; la première
  sauvegarde a lieu la nuit suivante.
