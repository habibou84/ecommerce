# Production sur le serveur LWS (à côté de la préproduction)

Pour commencer, la production tourne sur le même VPS LWS que la préproduction et Delivery App. C'est une
**installation séparée** : son dossier, son utilisateur système, sa base de données, son PHP-FPM, son hôte virtuel
Apache, sa file d'attente et ses tâches planifiées. La préproduction et les sites d'ISPConfig ne sont pas modifiés.

| | Préproduction | Production |
|---|---|---|
| Adresse | `preprod.jibiat-marchand.com`, `*.preprod.…` | `jibiat-marchand.com`, `*.jibiat-marchand.com` |
| Branche suivie | `main` | `production` |
| Nom de l'installation (`INSTANCE`) | `jibiat-marchand` | `jibiat-production` |
| Dossier | `/var/www/jibiat-marchand` | `/var/www/jibiat-production` |
| Utilisateur système et base | `jibiat`, `jibiat_marchand` | `jibiat_production`, `jibiat_production` |
| Accès | code d'accès (`PREPROD_ACCESS_CODE`), non indexée | ouverte à tous, indexée |
| SMS, paiements | codes affichés à l'écran, paiements de test | SMS réels, paiements réels |

## Cycle des mises à jour

```
travail ──(fusion)──► main ──► PRÉPRODUCTION : tests
                         └──(« mise en production »)──► production ──► PRODUCTION : marchands
```

1. Une modification fusionnée arrive sur `main` : `bash /var/www/jibiat-marchand/deploy/update.sh`, puis tests.
2. Tests concluants : « mise en production » = demande de fusion de `main` vers `production`.
3. Sur le serveur : `bash /var/www/jibiat-production/deploy/update.sh`.

Chaque `update.sh` met à jour l'installation du dossier où il se trouve.

## 1. Avant l'installation

- **Préproduction à jour** (elle contient le script d'installation qui gère plusieurs installations) :
  `bash /var/www/jibiat-marchand/deploy/update.sh`.
- **Branche `production`** créée sur GitHub à partir de `main`, une fois ce guide et les scripts fusionnés dans `main`.
- **SMS** : sans fournisseur SMS, personne ne peut créer de compte en production (les codes ne s'affichent pas
  à l'écran comme en préproduction). L'installation peut se faire avant, mais pas l'ouverture aux marchands.

## 2. DNS (Cloudflare)

Cloudflare › `jibiat-marchand.com` › **DNS** › **Records** :

| Type | Nom | Contenu | Proxy |
|---|---|---|---|
| A | `@` (jibiat-marchand.com) | IP du VPS LWS | **DNS only** (nuage gris) |
| A | `*` | IP du VPS LWS | **DNS only** (nuage gris) |

- S'il existe déjà un enregistrement `A`/`AAAA`/`CNAME` pour `@` ou `www` (page d'attente, ancien site), le
  remplacer. Les enregistrements `MX` (e-mails) ne sont pas concernés.
- Les enregistrements `preprod` et `*.preprod` restent tels quels : plus précis, ils gardent la préproduction.

Vérification : `nslookup jibiat-marchand.com` et `nslookup boutique.jibiat-marchand.com` donnent l'IP du VPS.

## 3. Installation

En SSH sur le VPS, puis `sudo -i` :

```bash
INSTANCE=jibiat-production \
DOMAIN=jibiat-marchand.com \
APP_ENV=production \
BRANCH=production \
DEMO=0 \
EMAIL=vous@exemple.ci \
DELIVERY_APP_DOMAINS=domaine.de.delivery.app \
bash /var/www/jibiat-marchand/deploy/install-apache.sh
```

- Le jeton Cloudflare est déjà sur le serveur (installation de la préproduction) : il est réutilisé. Il couvre
  toute la zone `jibiat-marchand.com`, donc aussi le certificat de la production.
- **Clé de déploiement** : au premier lancement, le script s'arrête et affiche une nouvelle clé `ssh-ed25519 …`
  (celle de l'utilisateur de la production). GitHub › dépôt **ecommerce** › **Settings** › **Deploy keys** ›
  **Add deploy key** : titre « Production LWS », sans « Allow write access ». Puis relancer la même commande.
- À la fin, le script interroge `https://jibiat-marchand.com/up` et `https://admin.jibiat-marchand.com/up`
  (attendu : `200`), puis la préproduction (toujours `200`).
- Une **sauvegarde de la base** est programmée chaque nuit (2 h 30) dans `/var/backups/jibiat-production`
  (7 copies, une par jour de la semaine).

## 4. Réglages de la production

`nano /var/www/jibiat-production/.env`, puis `cd /var/www/jibiat-production && sudo -u jibiat_production php artisan optimize`.

| Réglage | Valeur |
|---|---|
| `SMS_DRIVER` et les clés du fournisseur | fournisseur SMS réel |
| `BILLING_PROVIDER`, `BILLING_*` | comptes **de la plateforme** chez Wave, CinetPay ou PayDunya ; `BILLING_MODE=live` |
| `META_APP_ID`, `META_APP_SECRET`, `META_WHATSAPP_CONFIG_ID`, `META_WEBHOOK_VERIFY_TOKEN` | mêmes valeurs que la préproduction (même application Meta) |
| `FIREBASE_CREDENTIALS` | copier la clé : `cp /var/www/jibiat-marchand/storage/app/firebase.json /var/www/jibiat-production/storage/app/` puis `chown jibiat_production: …` |
| `SUPPORT_WHATSAPP` | numéro de l'équipe (bouton « Aide ») |
| `LEGAL_COMPANY`, `LEGAL_PRIVACY_EMAIL` | identité de l'éditeur, contact données personnelles |
| `SENTRY_LARAVEL_DSN` | supervision des erreurs |

Ne jamais mettre `PREPROD_ACCESS_CODE` en production (il y est de toute façon ignoré).

**Accès à la console** : créez votre compte en ouvrant une boutique sur `https://jibiat-marchand.com`, puis
`cd /var/www/jibiat-production && sudo -u jibiat_production php artisan platform:admin 07XXXXXXXX`.

## 5. Points d'attention

- **Webhook WhatsApp** : une application Meta n'a qu'une adresse de webhook. Au passage en production, la
  remplacer par `https://api.jibiat-marchand.com/webhooks/whatsapp` (même jeton de vérification) ; la
  préproduction ne recevra alors plus les messages entrants. Pour garder les deux, créer une seconde application
  Meta « Jibiat Marchand (test) » pour la préproduction.
- **Application Android** : GitHub › Actions › Android › Run workflow › serveur `jibiat-marchand.com` pour la
  version du Play Store.
- **Ordre des hôtes Apache** : `jibiat-marchand.conf` (préproduction) est lu avant `jibiat-production.conf` ;
  c'est ce qui envoie `*.preprod.jibiat-marchand.com` à la préproduction. Ne pas renommer ces fichiers.
- **Ressources** : trois applications partagent le VPS. Surveiller la mémoire (`free -h`) et la charge (`uptime`)
  pendant le pilote ; passer à un serveur dédié (`docs/LANCEMENT.md`) quand le trafic augmente.
- **Sauvegardes hors du serveur** : copier régulièrement `/var/backups/jibiat-production` ailleurs (stockage
  objet, autre machine) ; tester une restauration (`pg_restore -d base_de_test base-1.dump`).
- **En cas de problème** : journaux dans `/var/www/jibiat-production/storage/logs`,
  `/var/log/apache2/jibiat-production-error.log`, `supervisorctl status jibiat-production-queue`.
