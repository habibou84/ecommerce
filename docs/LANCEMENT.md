# Mise en production et lancement

Guide pratique pour mettre Jibiat Marchand en ligne, puis mener le pilote avec 10 marchands (docs/PLAN.md, phase 8).

Préproduction sur le serveur existant de Delivery App (Apache, ISPConfig) : voir `docs/DEPLOIEMENT-PREPROD.md` et les scripts du dossier `deploy/`.
Production sur ce même serveur pour commencer : `docs/PRODUCTION-LWS.md`.

---

## 1. Serveur

Un serveur Linux (Ubuntu 24.04) suffit pour le pilote et les premières centaines de boutiques : 4 vCPU, 8 Go de mémoire, 80 Go de disque SSD.

| Composant | Version | Rôle |
|---|---|---|
| PHP-FPM | 8.3 (extensions `pgsql`, `gd` avec FreeType et WebP, `intl`, `mbstring`, `bcmath`, `zip`) | Application |
| PostgreSQL | 16 | Base de données |
| Caddy | 2.8+ | HTTPS automatique, domaines personnalisés |
| Redis | 7 (recommandé) | Sessions et cache (bases 4 et 5), mis en place par les scripts de déploiement |
| Node.js | 20 | Compilation du front (`npm ci && npm run build`) au déploiement seulement |

### Variables d'environnement à renseigner

Voir `.env.example`. Les indispensables en production :

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://jibiat-marchand.com`, `PLATFORM_DOMAIN=jibiat-marchand.com`.
- Base de données (`DB_*`), `QUEUE_CONNECTION=database` (ou `redis`).
- SMS (vérification des numéros) : fournisseur et clés du paquet `socle`.
- Abonnements : `BILLING_PROVIDER` et les clés du compte **de la plateforme** chez Wave, CinetPay ou PayDunya (`BILLING_*`). Adresse de notification à déclarer chez le prestataire : `https://api.jibiat-marchand.com/webhooks/billing`.
- Domaines personnalisés : `CUSTOM_DOMAIN_CNAME=domaines.jibiat-marchand.com` et `CUSTOM_DOMAIN_IPS` (adresse IP publique du serveur, pour les domaines nus).
- Supervision : `SENTRY_LARAVEL_DSN`.
- Support : `SUPPORT_WHATSAPP` (numéro de l'équipe pour le bouton « Aide »).
- Pages légales : `LEGAL_COMPANY` (raison sociale, RCCM, adresse) et `LEGAL_PRIVACY_EMAIL`.

### DNS (Cloudflare conseillé)

| Enregistrement | Valeur |
|---|---|
| `jibiat-marchand.com` A | IP du serveur |
| `*.jibiat-marchand.com` A | IP du serveur (toutes les boutiques, `admin`, `api`) |
| `domaines.jibiat-marchand.com` A | IP du serveur (cible CNAME des domaines personnalisés) |

Créer un jeton API Cloudflare limité à « Zone DNS : modifier » pour la zone : Caddy l'utilise pour le certificat *wildcard* (validation DNS).

### Caddy

Caddy doit être compilé avec le module DNS de Cloudflare (`xcaddy build --with github.com/caddy-dns/cloudflare`).

```caddyfile
{
    email admin@jibiat-marchand.com
    # Domaines personnalisés : certificat demandé au premier visiteur, seulement si l'application l'autorise
    on_demand_tls {
        ask http://127.0.0.1:8080/_caddy/ask
    }
}

# Application (PHP-FPM) servie en interne sur le port 8080, pour la route « ask »
http://127.0.0.1:8080 {
    root * /var/www/jibiat-marchand/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
}

# Plateforme et toutes les boutiques : certificat wildcard par DNS
jibiat-marchand.com, *.jibiat-marchand.com {
    tls {
        dns cloudflare {env.CLOUDFLARE_API_TOKEN}
    }
    root * /var/www/jibiat-marchand/public
    encode zstd gzip
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
}

# Domaines personnalisés des boutiques (www.maboutique.ci)
https:// {
    tls {
        on_demand
    }
    root * /var/www/jibiat-marchand/public
    encode zstd gzip
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
}
```

La route `/_caddy/ask` n'accepte que les appels de la machine elle-même (127.0.0.1). Elle répond 200 pour un domaine vérifié d'une boutique ouverte, sinon 404 : aucun certificat n'est émis pour un domaine inconnu.

### Tâches de fond

```
# crontab de l'utilisateur www-data
* * * * * cd /var/www/jibiat-marchand && php artisan schedule:run >> /dev/null 2>&1
```

Le planificateur lance : synchronisation des expéditions (10 min), sessions de support échues (5 min), rapports du jour (15 min), relance des paniers (1 h), abonnements (`billing:run`, chaque jour à 7 h 10), contrôle DNS des domaines (chaque jour à 3 h 20).

File d'attente (messages WhatsApp, API Conversions, affectation automatique), avec systemd ou supervisor :

```
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

### Déploiement

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # après tout changement de rôles
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

Premier déploiement : `php artisan db:seed --force` (rôles, offres, lieux, thèmes), puis créer le compte de l'équipe (en ouvrant une boutique de test) et lui donner la console : `php artisan platform:admin 07XXXXXXXX`. La console est sur `https://admin.jibiat-marchand.com` (mot de passe puis code SMS).

### Sauvegardes

- `pg_dump` chaque nuit, chiffré, copié hors du serveur (stockage objet), conservé 30 jours ; test de restauration une fois par mois.
- Photos du catalogue : disque `public` (ou stockage objet compatible S3 via `filesystems.catalog_disk`) sauvegardé de la même façon.

---

## 2. Supervision

- **Erreurs** : Sentry (`SENTRY_LARAVEL_DSN`). Alertes par email et WhatsApp sur toute nouvelle erreur.
- **Disponibilité** : sonde externe (UptimeRobot, Better Stack…) toutes les minutes sur `https://jibiat-marchand.com/up` ; la route vérifie aussi que la base de données répond.
- **Files d'attente** : surveiller la table `failed_jobs` (alerte si elle grossit).
- **Abonnements** : la console affiche le revenu mensuel récurrent, les essais qui se terminent et les factures.

---

## 3. Protection des données (ARTCI)

Jibiat Marchand traite des données personnelles (marchands, clients des boutiques, livreurs) : la loi n° 2013-450 impose une formalité préalable auprès de l'ARTCI.

1. Désigner un responsable de la protection des données (personne de l'équipe) et une adresse de contact (`LEGAL_PRIVACY_EMAIL`).
2. Tenir le registre des traitements (base : la politique de confidentialité `/confidentialite`) : finalités, catégories de données, destinataires, durées, mesures de sécurité.
3. Déposer la **déclaration** auprès de l'ARTCI (formulaire sur www.artci.ci) ; demander une **autorisation** pour les transferts hors de Côte d'Ivoire (hébergement, Sentry, Meta).
4. Faire relire par un juriste les **conditions d'utilisation et de vente** (`/cgu`) et la **politique de confidentialité** (`/confidentialite`), compléter l'identité de l'éditeur (`LEGAL_COMPANY`), puis mettre à jour `LegalController::VERSION`.
5. Contrat de sous-traitance avec chaque marchand : il est couvert par les CGU (article 8) ; à valider par le juriste.

---

## 4. Pilote avec 10 marchands

Objectif : 10 boutiques actives pendant 4 semaines avant l'ouverture commerciale, dont au moins 4 déjà clientes d'une entreprise de livraison qui utilise Delivery App.

| Semaine | Actions |
|---|---|
| 0 | Recruter les marchands (entreprises de livraison partenaires, réseaux sociaux). Prolonger leur essai depuis la console (`Prolonger l'essai`, 60 jours). Groupe WhatsApp du pilote. |
| 1 | Installation accompagnée (session de support si besoin) : boutique, catalogue (import CSV ou depuis Delivery App), zones, livreurs ou transporteur. Première commande test. |
| 2-3 | Usage réel. Point hebdomadaire de 15 minutes par marchand. Suivre dans la console : commandes, taux de confirmation et de livraison. |
| 4 | Bilan : ce qui manque, ce qui gêne, prix acceptables. Corrections avant l'ouverture. |

Indicateurs suivis (docs/PLAN.md, suggestion 8) : boutiques avec une première commande en 7 jours, taux de confirmation, taux de livraison réussie, rétention à 30 jours, demandes de support.

---

## 5. Avant d'ouvrir les inscriptions

- [ ] Prestataires de paiement : comptes de la plateforme validés (paiement réel de 100 F, remboursement), adresse de notification déclarée.
- [ ] Modèles WhatsApp approuvés chez Meta pour les messages automatiques et le rapport du jour.
- [ ] SMS : expéditeur « JIBIAT » validé chez l'opérateur, crédit suffisant.
- [ ] Sentry et sonde de disponibilité actifs, alertes testées.
- [ ] Sauvegarde et restauration testées.
- [ ] Pages légales relues et complétées, déclaration ARTCI déposée.
- [ ] Prix des offres confirmés dans la console (`Offres`).
