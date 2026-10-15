# Préproduction sur le serveur de Delivery App (LWS, ISPConfig)

Objectif : mettre en ligne `https://preprod.jibiat-marchand.com` (plateforme), `https://admin.preprod.jibiat-marchand.com` (console) et une boutique par sous-domaine (`https://demo.preprod.jibiat-marchand.com`), sur le VPS LWS qui héberge déjà Delivery App.

## Principe

- Chaque boutique a son sous-domaine : il faut un certificat **wildcard** (`*.preprod.jibiat-marchand.com`). Let's Encrypt ne le délivre qu'après une validation DNS. ISPConfig ne sait pas la faire : Jibiat Marchand est donc installé **à côté** d'ISPConfig, sans passer par le panneau.
- Ce que le script ajoute au serveur :
  - son hôte virtuel Apache (`/etc/apache2/sites-available/jibiat-marchand.conf`) ;
  - son groupe PHP-FPM ;
  - son utilisateur système `jibiat` ;
  - sa base PostgreSQL `jibiat_marchand` ;
  - sa file d'attente sous Supervisor ;
  - sa tâche planifiée (`/etc/cron.d/jibiat-marchand`).
- Les sites d'ISPConfig, dont Delivery App, ne sont pas modifiés.
- Le certificat est obtenu et renouvelé par certbot, grâce à un jeton API Cloudflare.
- Mode `staging` :
  - les codes SMS s'affichent à l'écran, puisqu'aucun fournisseur SMS n'est branché ;
  - les abonnements sont en mode test (`BILLING_MODE=test`).

---

## 1. Passer le DNS de jibiat-marchand.com chez Cloudflare

1. Créer un compte sur https://dash.cloudflare.com, puis **Add a site** › `jibiat-marchand.com` › offre **Free**.
2. Cloudflare importe les enregistrements existants. **Vérifier qu'il n'en manque aucun**, en les comparant à la zone DNS actuelle chez le registraire :
   - courrier : MX, SPF/TXT, DKIM ;
   - `www` et le site principal ;
   - vérifications Google ou Meta.
   Ajouter à la main ceux qui manquent.
3. Cloudflare indique deux serveurs de noms (`xxx.ns.cloudflare.com`). Chez le registraire, remplacer les serveurs de noms du domaine par ces deux-là.
4. Attendre l'e-mail « Cloudflare is now protecting jibiat-marchand.com » (de quelques minutes à 24 h).

## 2. Enregistrements de la préproduction

Dans Cloudflare › `jibiat-marchand.com` › **DNS** › **Records**, ajouter :

| Type | Nom | Contenu | Proxy |
|---|---|---|---|
| A | `preprod` | IP du VPS LWS | **DNS only** (nuage gris) |
| A | `*.preprod` | IP du VPS LWS | **DNS only** (nuage gris) |

Le nuage doit rester **gris** : le serveur présente lui-même son certificat, et le proxy gratuit de Cloudflare ne couvre pas les sous-domaines de second niveau (`demo.preprod…`).

Vérification, depuis n'importe quel ordinateur :

```bash
nslookup preprod.jibiat-marchand.com
nslookup demo.preprod.jibiat-marchand.com   # même IP
```

## 3. Jeton API Cloudflare (pour le certificat)

1. Cloudflare › icône du profil › **My Profile** › **API Tokens** › **Create Token**.
2. Modèle **Edit zone DNS** › **Use template**.
3. **Zone Resources** : Include › Specific zone › `jibiat-marchand.com`.
4. **Continue to summary** › **Create Token**.
5. Copier le jeton : il ne s'affiche qu'une fois.

Le script le range dans `/etc/letsencrypt/cloudflare-jibiat.ini` (lisible par root seulement). Certbot s'en sert pour les renouvellements.

## 4. Installation sur le serveur

Se connecter au VPS en SSH, puis passer root (`sudo -i`).

```bash
# Récupérer le dépôt (pour lancer le script ; l'application sera clonée dans /var/www/jibiat-marchand)
apt-get install -y git
git clone https://github.com/habibou84/ecommerce.git /root/jibiat-install
```

> Le dépôt est privé : si `git clone` demande un identifiant, utiliser un jeton GitHub personnel (lecture seule) comme mot de passe, ou copier le dossier `deploy/` sur le serveur (`scp -r deploy root@IP:/root/jibiat-install/`).

Lancer l'installation, en remplaçant l'adresse e-mail, le jeton et le domaine de Delivery App de préproduction :

```bash
DOMAIN=preprod.jibiat-marchand.com \
EMAIL=vous@exemple.ci \
CLOUDFLARE_API_TOKEN=le_jeton_cloudflare \
DELIVERY_APP_DOMAINS=livraison.exemple.ci \
bash /root/jibiat-install/deploy/install-apache.sh
```

**Premier lancement : clé de déploiement.**
1. Le script s'arrête et affiche une clé `ssh-ed25519 …`.
2. Dans GitHub, dépôt **ecommerce** › **Settings** › **Deploy keys** › **Add deploy key** :
   - titre : « Préproduction LWS » ;
   - collez la clé ;
   - **ne cochez pas** « Allow write access ».
3. Relancez exactement la même commande.

Durée : 5 à 10 minutes, surtout pour les paquets, Composer et la compilation de l'interface. Le script est **relançable** sans risque : la base, le `.env` et le certificat sont conservés.

À la fin, il interroge `https://…/up` sur la plateforme, la console et la boutique de démonstration : les trois doivent répondre `200`.

### Variables facultatives

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_ENV` | `staging` | `production` : codes SMS envoyés réellement (fournisseur requis) |
| `DEMO` | `1` | Boutique de démonstration `demo` (`0` : base vide) |
| `BRANCH` | `main` | Branche déployée |
| `APP_DIR` | `/var/www/jibiat-marchand` | Dossier de l'application |
| `DELIVERY_APP_DOMAINS` | — | Domaine(s) des serveurs Delivery App que les marchands peuvent connecter |

## 5. Premiers essais

1. **Boutique de démonstration** :
   - ouvrir `https://demo.preprod.jibiat-marchand.com` (boutique en ligne) ;
   - puis `/gestion`, avec le numéro `07 00 00 00 01` et le mot de passe `password`.
2. **Création de boutique** :
   - ouvrir `https://preprod.jibiat-marchand.com/creer-ma-boutique` ;
   - le code SMS s'affiche à l'écran, en mode `staging`.
3. **Console** :
   - après avoir créé votre compte (étape 2), donnez-vous l'accès :
     ```bash
     cd /var/www/jibiat-marchand && sudo -u jibiat php artisan platform:admin 07XXXXXXXX
     ```
   - puis ouvrir `https://admin.preprod.jibiat-marchand.com` (mot de passe, puis code affiché à l'écran).
4. **Delivery App** :
   - dans une boutique, ouvrir **Expéditions › Transporteurs** et connecter le serveur Delivery App de test ;
   - son domaine doit figurer dans `DELIVERY_APP_DOMAINS`.
5. **Commande complète** :
   - commander sur la boutique en ligne ;
   - confirmer la commande ;
   - l'expédier par Delivery App ;
   - suivre son statut.

> Les comptes de démonstration (mot de passe `password`) sont publics : changez leurs mots de passe ou supprimez la boutique `demo` avant de partager l'adresse.

## 6. Mises à jour

Après chaque fusion dans `main` :

```bash
sudo bash /var/www/jibiat-marchand/deploy/update.sh
```

Le script :
- met le site en maintenance ;
- récupère le code ;
- installe les dépendances et recompile l'interface ;
- applique les migrations, les rôles et les offres ;
- reconstruit les caches ;
- relance PHP-FPM et la file d'attente ;
- rouvre le site.

## 7. Sécurité de la préproduction

- **Moteurs de recherche** : hors production (`APP_ENV` différent de `production`), toutes les pages envoient
  l'en-tête `X-Robots-Tag: noindex, nofollow` et `robots.txt` interdit tout. Rien à régler (`SEARCH_INDEXING=true`
  forcerait l'indexation).
- **Réglages** : `APP_DEBUG=false`, clés de paiement **de test** uniquement, aucune vraie donnée client, mots de passe
  et clés différents de ceux de la production.
- **Accès** : la préproduction affiche les codes SMS à l'écran ; elle ne doit pas rester ouverte à tous. Avec
  `PREPROD_ACCESS_CODE` dans le `.env`, chaque visiteur doit saisir ce code avant toute page (plateforme, boutiques de
  test, gestion, application Android) ; il est mémorisé 30 jours sur l'appareil, pour toutes les boutiques de test.
  Restent ouverts : `api.preprod…` (notifications de Meta, de Delivery App et des paiements) et `/up`. Le réglage
  est ignoré quand `APP_ENV=production`.

  ```bash
  sudo -i
  nano /var/www/jibiat-marchand/.env        # ajouter : PREPROD_ACCESS_CODE=un-code-a-vous
  cd /var/www/jibiat-marchand && sudo -u jibiat php artisan optimize
  ```

  Changer le code oblige tout le monde à le saisir de nouveau. (Cloudflare Access n'est pas utilisable ici : le
  proxy gratuit de Cloudflare ne couvre pas les sous-domaines de second niveau comme `demo.preprod…`.)

## 8. Où regarder en cas de problème

| Quoi | Où |
|---|---|
| Erreurs de l'application | `/var/www/jibiat-marchand/storage/logs/laravel-AAAA-MM-JJ.log` |
| Apache | `/var/log/apache2/jibiat-marchand-error.log` |
| File d'attente | `supervisorctl status jibiat-marchand-queue`, `/var/www/jibiat-marchand/storage/logs/queue.log` |
| Certificat | `certbot certificates` ; essai de renouvellement : `certbot renew --dry-run` |
| Configuration | `/var/www/jibiat-marchand/.env` (puis `sudo -u jibiat php artisan optimize`) |

Points à vérifier si l'installation échoue :

- **`apache2ctl configtest` refuse la configuration** :
  - un module Apache manque : `a2enmod ssl proxy_fcgi` ;
  - ou un autre hôte virtuel déclare déjà `preprod.jibiat-marchand.com` dans ISPConfig : le supprimer du panneau.
- **Erreur 503 ou « Service Unavailable »** :
  - le groupe PHP-FPM ne tourne pas : `systemctl status php8.4-fpm` (adapter la version) ;
  - la commande `php` par défaut doit être de la même version que le `php-fpm` utilisé.
- **Échec du certificat** :
  - vérifier le jeton (zone `jibiat-marchand.com`, droit « DNS : Edit ») ;
  - vérifier que les serveurs de noms Cloudflare sont actifs (étape 1).
- **ISPConfig** : ne pas créer de site `preprod.jibiat-marchand.com` dans le panneau ; tout est géré par les fichiers ci-dessus.

## Pour la production

La même procédure fonctionne avec `DOMAIN=jibiat-marchand.com` et `APP_ENV=production`, une fois un fournisseur SMS et les comptes de paiement de la plateforme configurés. Pour la production, un serveur dédié est préférable, comme décrit dans `docs/LANCEMENT.md` (Caddy, domaines personnalisés des marchands).
