# Jibiat Marchand (`jibiat-marchand.com`)

Plateforme SaaS pour e-commerçants : catalogue à variantes, stock, commandes multicanales, boutique en ligne,
livreurs propres et connexion aux entreprises de livraison qui utilisent Delivery App (`habibou84/deliverynew`).

- **Fonctionnalités, architecture et plan d'exécution** : [`docs/PLAN.md`](docs/PLAN.md)
- **Schéma de base de données** : [`docs/database.dbml`](docs/database.dbml) (à importer sur https://dbdiagram.io)

## Stack

| Couche | Technologie |
|---|---|
| API | Laravel 12, Sanctum (session par cookie), spatie/laravel-permission (rôles par boutique) |
| Base de données | PostgreSQL 16 |
| Front-end | Vue 3, Pinia, Vue Router, Tailwind CSS 4 (Vite) ; pages publiques en Blade |
| Code commun avec Delivery App | paquet Composer public [`habibou84/socle`](https://github.com/habibou84/socle) |

## Adresses

Chaque boutique a son sous-domaine, qui porte son site et sa gestion :

| Adresse | Contenu |
|---|---|
| `jibiat-marchand.com` | Présentation, **création de boutique** (`/creer-ma-boutique`), retrouver ses boutiques (`/connexion`) |
| `{boutique}.jibiat-marchand.com/` | Boutique en ligne (page « ouvre bientôt » tant que le marchand ne l'a pas ouverte) |
| `{boutique}.jibiat-marchand.com/gestion` | Gestion de la boutique |
| `admin.jibiat-marchand.com` | Console du super administrateur (phase 8) |
| `api.jibiat-marchand.com` | API publique et webhooks entrants (à venir) |

La session est liée au nom d'hôte : une connexion à une boutique ne vaut rien sur une autre. Pour passer d'une adresse
à l'autre en restant connecté (création de boutique, choix d'une autre boutique, changement d'adresse), le serveur
remet un lien de connexion à usage unique, valable deux minutes (`/auth/relais`).

## Installation locale

Prérequis : PHP 8.2+ (extension `pdo_pgsql`), Composer, Node 22, PostgreSQL 16.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Bases de données (adapter à votre installation PostgreSQL)
createuser -P jibiat                       # mot de passe : secret (cf. .env)
createdb -O jibiat jibiat_marchand
createdb -O jibiat jibiat_marchand_test    # pour les tests

php artisan migrate --seed                 # rôles, offres et boutique de démonstration
php artisan storage:link                   # photos du catalogue
npm run build                              # ou « npm run dev » pendant le développement
php artisan serve
```

En local, `PLATFORM_DOMAIN=localhost` : les navigateurs envoient `*.localhost` vers `127.0.0.1` sans configuration DNS.

| Page | Adresse |
|---|---|
| Présentation et création de boutique | http://localhost:8000 |
| Gestion de la boutique de démonstration | http://demo.localhost:8000/gestion : `07 00 00 00 01` / `password` (propriétaire) ou `07 00 00 00 02` / `password` (gérant). Produits, zone de livraison et deux commandes déjà créés. |
| Application livreur de démonstration | http://demo.localhost:8000/livreur : `07 00 00 00 03` / `password` (Moussa, livreur de la boutique) |
| Boutique en ligne de démonstration | http://demo.localhost:8000 (ouverte : catalogue, panier, commande paiement à la livraison) |

Vérification des numéros (création de boutique, invitation, mot de passe oublié, connexion par WhatsApp, console) :
**WhatsApp d'abord**. Dès que le numéro du support est relié dans la console (Console › WhatsApp, par QR code comme
WhatsApp Web ou avec Facebook), la page ouvre WhatsApp avec le message « Code de vérification : 123456 » ; le
serveur reçoit le message, vérifie l'expéditeur, répond, et la page (qui interroge `GET /verifications/{jeton}`
toutes les 3 s) termine l'action. Le SMS n'est proposé que si aucun numéro n'est relié, ou si le réglage de
plateforme `sms.fallback` vaut `on`. « Me connecter par WhatsApp » (boutique, plateforme) ouvre la session sans mot de
passe (`POST auth/whatsapp`, `POST account/whatsapp`).
Les SMS ne partent pas en local (`SMS_DRIVER=log`) : le code de vérification s'affiche sur la page et dans
`storage/logs/laravel.log`. En production, `SMS_DRIVER=twilio` (le code n'est jamais affiché quand `APP_ENV=production`).

### Avec une instance locale de Delivery App

Pour essayer les expéditions de bout en bout, lancez `deliverynew` sur le port 8001 (`PLATFORM_DOMAIN=localhost`,
`PLATFORM_SCHEME=http`, `WEBHOOKS_ALLOW_HTTP=true`, `WEBHOOKS_ALLOW_PRIVATE_TARGETS=true`, plusieurs processus :
`PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8001`), et ici `DELIVERY_APP_DOMAINS=jibiat.com,localhost`,
`DELIVERY_APP_SCHEME=http`, également avec `PHP_CLI_SERVER_WORKERS=4` (les deux applications s'appellent
mutuellement). Créez une clé API pour un marchand de Delivery App, puis dans la gestion : *Expéditions ›
Transporteurs › + Transporteur*, adresse `{entreprise}.localhost:8001`.

## Tests

```bash
php artisan test     # PostgreSQL (base jibiat_marchand_test) ; les tests de socle sont dans son dépôt
vendor/bin/pint      # style du code
```

## Production : DNS et certificats

- Enregistrements DNS `jibiat-marchand.com` et `*.jibiat-marchand.com` vers le serveur.
- Certificat *wildcard* `*.jibiat-marchand.com` (validation DNS : hébergeur DNS avec API, Cloudflare conseillé).
- `.env` : `APP_URL=https://jibiat-marchand.com`, `PLATFORM_DOMAIN=jibiat-marchand.com`, `SESSION_SECURE_COOKIE=true`,
  `SESSION_DOMAIN` **vide** (cookie limité à chaque sous-domaine).
- `api.jibiat-marchand.com` reçoit les webhooks des entreprises de livraison (`/webhooks/carriers/{uuid}`).
- Tâches planifiées : `* * * * * php artisan schedule:run` (synchronisation des expéditions toutes les 10 minutes, relance des paniers abandonnés chaque heure, rapports du jour toutes les 15 minutes, abonnements chaque jour, contrôle des domaines chaque nuit, fin des sessions de support) ; `php artisan queue:work` pour les messages WhatsApp ; `node whatsapp-bridge/server.js` (après `npm ci` dans ce dossier) pour le WhatsApp relié par QR code.

## Organisation du code

| Dossier | Contenu |
|---|---|
| `app/Support/Tenancy.php` | Boutique désignée par l'adresse (zones : plateforme, boutique, domaine personnalisé, console, API) |
| `app/Http/Middleware` | `ResolveStore` (boutique de l'adresse, redirections), `EnsureZone`, `EnsureStoreMember` |
| `app/Models/Concerns/BelongsToStore.php` | Filtre automatique par boutique, à mettre sur tous les modèles métier |
| `app/Services/Catalog` | Produits et variantes (`CatalogWriter`), photos (`ImageStore`), import/export CSV, reprise depuis Delivery App |
| `app/Services/Stock` | Tous les mouvements de stock (`StockKeeper`, journal immuable) et lectures agrégées (`StockLevels`) |
| `app/Services/Orders` | Commandes (`OrderDesk` : création, statuts, stock, paiements, confirmation), analyse des messages, PDF, messages WhatsApp |
| `app/Services/Customers`, `app/Services/Places` | Fichier clients et score de risque ; lieux et zones de livraison |
| `app/Services/Shipping` | Expéditions et transporteurs : connecteurs (`Drivers/`), `ShipmentDesk` (course, statuts → commande), webhooks et synchronisation (`CarrierEvents`), reversements (`PayoutReconciler`), solde à recevoir de chaque transporteur (`CarrierBalances`), stock chez le transporteur |
| `app/Services/Couriers` | Livreurs de la boutique : ajout et invitation (`CourierRoster`), actions du livreur (`CourierDesk`), encaissements et gains (`CourierMissions`), caisse (`CashDesk`), paie (`CourierPayroll`) |
| `app/Services/Payments` | Paiement en ligne sur les comptes du marchand : pilotes `Gateways/` (Wave, CinetPay, PayDunya), `PaymentDesk` (routage et secours, vérification, webhooks, remboursements), `FeeSimulator` |
| `app/Services/Finance` | Caisse de la boutique (`CashOverview`) : où est l'argent (livreurs, transporteurs, clients à crédit, à payer) et entrées / sorties par canal, avec la part en espèces ; tiroir-caisse (`CashRegister` : ouverture, dépenses, retraits, clôture avec écart) et vente au comptoir en une fois (`CounterSales`) |
| `app/Services/Billing` | Offres et quotas (`PlanLimits`), abonnements payés sur le compte de la plateforme (`Billing`) |
| `app/Services/Domains` | Domaines personnalisés : vérification DNS, autorisation des certificats Caddy (`CustomDomains`) |
| `app/Services/Console`, `resources/js/views/console` | Console du super administrateur (`admin.`) et sessions de support (`SupportSessions`) ; accès donné par `php artisan platform:admin {téléphone}` |
| `docs/LANCEMENT.md` | Mise en production (Caddy, tâches, sauvegardes), supervision, déclaration ARTCI, pilote |
| `docs/DEPLOIEMENT-PREPROD.md` | Préproduction sur le serveur de Delivery App (LWS, ISPConfig) : DNS Cloudflare, script `deploy/install-apache.sh`, mises à jour |
| `app/Services/Promotions` | Prix de vente : ventes flash, prix par quantité, codes promo (`Pricing`), utilisé par le panier et par la saisie des commandes |
| `app/Services/Marketing` | API Conversions de Meta (`Conversions`), lien court et image de partage d'un produit (`ShareImages`, polices dans `resources/fonts`) |
| `app/Services/Reports` | Statistiques avancées (`StoreStats`) et rapport du jour (`DailyReport`) |
| `app/Services/Shipping/DeliveryAdvisor.php` | Comparateur livreurs / transporteurs et règles d'affectation (suggestion ou automatique) |
| `app/Services/WhatsApp` | WhatsApp Cloud API au nom du marchand : envoi des modèles (`WhatsAppCloud`), messages automatiques et réponses des clients (`CustomerMessenger`) ; boîte Messages (`Inbox`) ; WhatsApp relié par QR code (`WhatsAppBridge`) ; paniers du catalogue WhatsApp transformés en commandes (`CatalogOrders`) |
| `app/Services/Messaging` | Réponses proposées par l'assistant (`ReplySuggester`) et réponses automatiques avec leurs garde-fous, désactivées par défaut (`AutoReplier`, job `SendAutoReply`) |
| `whatsapp-bridge/` | Service Node (Baileys) du WhatsApp relié par QR code, une session par boutique et une pour le numéro du support (`platform`, console : messages et codes de vérification), sous Supervisor (`<instance>-whatsapp`), repris de Jibiat ; installé et relancé par les scripts de `deploy/` |
| `resources/js/views/courier`, `public/livreur-sw.js` | Application livreur `/livreur` (installable) |
| `app/Services/Storefront`, `routes/storefront.php` | Boutique en ligne : thèmes (`config/themes.php`), catalogue public, panier par cookie, commande en une page ; routes sans session |
| `app/Services/Accounts` | Création de boutique, vérification des numéros par WhatsApp puis SMS, invitations, mot de passe oublié, connexion par WhatsApp, rôles par boutique, lien de connexion |
| `app/Enums/Role.php`, `Permission.php` | Rôles (propriétaire, gérant, agent de confirmation, préparateur, caissier, livreur) et leurs permissions |
| `vendor/habibou84/socle` | Code commun avec Delivery App (dépôt `habibou84/socle`) : téléphones, montants, SMS, signature des webhooks, contrat de l'API de Jibiat (`Contract`, exemples utilisés par `ShippingTest`) ; une modification se fait dans ce dépôt, puis `composer update habibou84/socle` dans les deux applications |
| `resources/js` | Application Vue : création de boutique, connexion, gestion (`/gestion`) |
| `resources/views` | Pages Blade : présentation, boutique en ligne (`storefront/`, `layouts/storefront`), erreurs, PDF |
| `resources/js/storefront.js`, `resources/css/storefront.css` | JavaScript (sans dépendance) et styles de la boutique en ligne, séparés de la gestion |
