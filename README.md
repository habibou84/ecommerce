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
| Code commun avec Delivery App | `packages/socle` (paquet Composer `habibou84/socle`) |

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
| Boutique en ligne de démonstration | http://demo.localhost:8000 (ouverte : catalogue, panier, commande paiement à la livraison) |

Les SMS ne partent pas en local (`SMS_DRIVER=log`) : le code de vérification s'affiche sur la page et dans
`storage/logs/laravel.log`. En production, `SMS_DRIVER=twilio` (le code n'est jamais affiché quand `APP_ENV=production`).

## Tests

```bash
php artisan test     # PostgreSQL (base jibiat_marchand_test), y compris les tests du paquet socle
vendor/bin/pint      # style du code
```

## Production : DNS et certificats

- Enregistrements DNS `jibiat-marchand.com` et `*.jibiat-marchand.com` vers le serveur.
- Certificat *wildcard* `*.jibiat-marchand.com` (validation DNS : hébergeur DNS avec API, Cloudflare conseillé).
- `.env` : `APP_URL=https://jibiat-marchand.com`, `PLATFORM_DOMAIN=jibiat-marchand.com`, `SESSION_SECURE_COOKIE=true`,
  `SESSION_DOMAIN` **vide** (cookie limité à chaque sous-domaine).

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
| `app/Services/Storefront`, `routes/storefront.php` | Boutique en ligne : thèmes (`config/themes.php`), catalogue public, panier par cookie, commande en une page ; routes sans session |
| `app/Services/Accounts` | Création de boutique, vérification des numéros par SMS, invitations, mot de passe oublié, rôles par boutique, lien de connexion |
| `app/Enums/Role.php`, `Permission.php` | Rôles (propriétaire, gérant, agent de confirmation, préparateur, caissier, livreur) et leurs permissions |
| `packages/socle` | Code commun avec Delivery App : téléphones, montants, SMS, signature des webhooks |
| `resources/js` | Application Vue : création de boutique, connexion, gestion (`/gestion`) |
| `resources/views` | Pages Blade : présentation, boutique en ligne (`storefront/`, `layouts/storefront`), erreurs, PDF |
| `resources/js/storefront.js`, `resources/css/storefront.css` | JavaScript (sans dépendance) et styles de la boutique en ligne, séparés de la gestion |
