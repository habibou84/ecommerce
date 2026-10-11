# Plan produit et d'exécution : application e-commerce pour marchands

> Produit indépendant de toute entreprise de livraison. C'est la « Phase 9 » prévue dans le plan de
> l'application de livraison (`habibou84/deliverynew`, `docs/PLAN.md` §8.2), développée ici comme un produit à part entière.
> Il reprend la même stack technique et se connecte à Delivery App par son API publique (`/api/public/v1`) et ses webhooks.

---

## 1. Vision

| | Delivery App (existant) | Application e-commerce (ce dépôt) |
|---|---|---|
| Client payant | L'entreprise de livraison | **Le marchand** (abonnement SaaS) |
| Centre de gravité | La course (colis) | **La commande client** (vente) |
| Catalogue | Produits simples, stock chez le marchand ou au dépôt | Produits **à variantes**, collections, multi-emplacements |
| Vente | Mini-page de commande `/b/{slug}` | **Boutique en ligne complète** (domaine propre, thème, SEO, paiement) |
| Livraison | Livreurs de l'entreprise | **Livreurs du marchand** *et/ou* **entreprises de livraison connectées** |
| Lien entre les deux | — | Connecteur API : devis, création de course, suivi, encaissements |

Promesse au marchand : *« Vendez partout (site, WhatsApp, Facebook, TikTok, boutique physique), gérez tout au même endroit, livrez avec vos livreurs ou avec n'importe quelle société de livraison partenaire. »*

Effet réseau : chaque marchand qui connecte une entreprise de livraison lui apporte des courses par API. Les entreprises de livraison ont donc intérêt à recommander l'application à leurs marchands, et inversement.

---

## 2. Acteurs

| Acteur | Ce qu'il fait |
|---|---|
| **Propriétaire** de la boutique | Abonnement, paramètres, équipe, finances, connexions transporteurs |
| **Gestionnaire** | Catalogue, stock, commandes |
| **Agent de confirmation** | Appelle ou écrit aux clients pour confirmer les commandes avant expédition |
| **Préparateur / magasinier** | Prépare les colis, entrées et sorties de stock, inventaires |
| **Livreur du marchand** | Application mobile : ses livraisons, encaissements, preuves, versements |
| **Caissier** (option) | Vente en boutique physique (caisse simplifiée) et réception des versements des livreurs |
| **Client final** | Achète sur le site, suit sa commande, gère ses adresses, crée un compte facultatif |
| **Entreprise de livraison** (externe) | Reçoit les courses par API et renvoie les statuts par webhooks |
| **Super administrateur** (plateforme) | Boutiques, abonnements, support, modération, statistiques globales |

---

## 3. Fonctionnalités

Légende : **[MVP]** indispensable au lancement · **[V1]** dans les 6 mois · **[V2]** plus tard.

### 3.1 Catalogue
- **[MVP] Produits à variantes** : jusqu'à 3 options par produit (ex. *Taille*, *Couleur*, *Matière*). Les variantes sont générées automatiquement à partir des combinaisons, puis chacune reçoit ses propres valeurs :
  SKU, code-barres, prix, prix barré, coût d'achat, poids, photo, stock et statut actif/inactif.
- **[MVP]** Produit simple = produit avec une seule variante par défaut (un seul modèle de données, pas de cas particulier).
- **[MVP]** Photos multiples (redimensionnement et WebP automatiques), photo spécifique par variante (la couleur rouge affiche la photo rouge).
- **[MVP]** Collections (manuelles), catégories, étiquettes ; produits visibles ou masqués en ligne.
- **[MVP]** Import / export CSV (format proche de Shopify pour faciliter les migrations) ; **import depuis Delivery App** (`GET /products`) pour les marchands qui utilisaient la mini-boutique.
- **[V1]** Collections automatiques (règles : étiquette, prix, stock) ; produits liés / suggestions.
- **[V1]** Prix par quantité (ex. 1 = 10 000, 3 = 25 000) ; très utilisé en vente sur les réseaux sociaux.
- **[V2]** Lots et packs (bundle qui décrémente plusieurs variantes), produits numériques, précommandes.

### 3.2 Stock
- **[MVP]** Stock **par variante et par emplacement** : magasin, domicile, entrepôt du marchand… et **entrepôt d'une entreprise de livraison** (voir §5.5).
- **[MVP]** Journal immuable des mouvements (réception, ajustement, réservation, libération, expédition, retour), sur le modèle de `stock_movements` de Delivery App.
- **[MVP]** Réservation à la commande, sortie à l'expédition, remise en stock au retour (avec contrôle « article abîmé / revendable »).
- **[MVP]** Alertes de stock bas ; « en rupture » affiché sur le site, avec ou sans vente possible (paramètre par produit).
- **[V1]** Transferts entre emplacements, inventaires (comptage avec écart), bons de réception fournisseur, coût moyen pondéré.
- **[V2]** Fournisseurs, bons de commande fournisseur, prévision de rupture.

### 3.3 Commandes (multicanales)
- **[MVP]** Sources : site, saisie manuelle (WhatsApp, Facebook, Instagram, TikTok, téléphone), import CSV, caisse, API. La **source est enregistrée** pour mesurer quel canal vend.
- **[MVP]** Saisie rapide pensée pour le téléphone : coller le message du client → nom, numéro et quartier pré-remplis (même logique que l'analyse WhatsApp de Delivery App).
- **[MVP]** Trois statuts distincts, à ne jamais mélanger :
  - **commande** : `à confirmer → confirmée → en préparation → expédiée → terminée` (+ `annulée`) ;
  - **paiement** : `non payée → acompte → payée → remboursée (partiellement)` ;
  - **expédition** : par colis (voir §3.5).
- **[MVP] Confirmation des commandes** : file « à confirmer », boutons *Appeler* / *WhatsApp*, motifs (injoignable, faux numéro, a changé d'avis…), relances planifiées. En paiement à la livraison, c'est **le premier levier contre les retours**.
- **[MVP]** Modification avant expédition (articles, adresse, remise), annulation avec remise en stock, note interne, historique complet (journal immuable comme `order_events`).
- **[MVP]** Bon de commande / facture PDF, étiquette colis avec QR code.
- **[V1]** Expédition partielle (plusieurs colis pour une commande), échanges, retours clients (RMA), avoirs.
- **[V1]** Détection des doublons (même numéro, même article, moins de 24 h) et **score de risque client** (historique de refus).
- **[V2]** Commandes récurrentes / abonnements.

### 3.4 Clients (CRM)
- **[MVP]** Fiche client centrée sur **le numéro de téléphone** (l'email est rare) : adresses avec commune / quartier, point de repère et position GPS, historique d'achats, total dépensé, taux de livraison réussie.
- **[MVP]** Étiquettes (VIP, revendeur, à risque), liste noire (refus répétés).
- **[V1]** Segments (n'a pas acheté depuis 60 jours, a acheté la collection X…), export pour campagnes.
- **[V1]** Compte client sur le site (connexion par OTP SMS/WhatsApp, sans mot de passe), suivi de commande, adresses enregistrées.
- **[V2]** Programme de fidélité (points), parrainage.

### 3.5 Livraison et expéditions (cœur du différenciateur)
Une commande produit une ou plusieurs **expéditions**. Chaque expédition choisit un **mode** :

| Mode | Fonctionnement |
|---|---|
| **Livreur du marchand** | Assignation à un livreur inscrit dans la boutique, suivi dans son application |
| **Entreprise de livraison connectée** | Course créée par API chez le transporteur (Delivery App ou autre connecteur) ; statuts reçus par webhook |
| **Transporteur sans API** | Bordereau PDF et message WhatsApp pré-rempli au transporteur ; statut mis à jour à la main |
| **Retrait en boutique / point relais** | Code de retrait envoyé au client |

- **[MVP]** Zones de livraison propres au marchand (commune / quartier) avec frais affichés au client et délai annoncé.
- **[MVP]** Statut d'expédition **normalisé**, quel que soit le mode : `à préparer → prête → demandée → acceptée → en attente de ramassage → en transit → en cours de livraison → livrée | échec | reportée | en retour | retournée | annulée | perdue`.
- **[MVP]** **Livreurs propres** :
  - inscription par invitation (lien + OTP), profil, véhicule, zones couvertes, disponibilité ;
  - application mobile PWA (reprise de l'application livreur de Delivery App, simplifiée) : missions du jour, itinéraire, appel / WhatsApp client, encaissement, photo ou code de livraison, échec avec motif, report ;
  - **caisse** : montant encaissé par livreur, versement au marchand, écarts ;
  - **rémunération** : par livraison, par zone ou fixe ; relevé et paiement.
- **[V1] Comparateur et règles d'affectation automatiques** : pour chaque commande confirmée, proposer le meilleur mode selon des règles (zone couverte par mes livreurs → livreur propre ; sinon devis le moins cher ou le plus rapide parmi les transporteurs connectés ; colis fragile → transporteur X).
- **[V1]** Suivi en temps réel côté client (lien de suivi), position du livreur propre sur la carte.
- **[V1]** Tournées : regrouper les livraisons d'un livreur par zone et ordre de passage.
- **[V2]** Expéditions interurbaines (« zones d'expédition » de Delivery App : gares routières, compagnies de car).

### 3.6 Boutique en ligne intégrée
- **[MVP]** Site sur `{boutique}.domaine.com`, **thème** configurable (logo, couleurs, polices, bannières, sections de page d'accueil) ; 2 ou 3 thèmes au départ.
- **[MVP]** Pages : accueil, collection, produit (sélecteur de variantes avec photo et stock dynamiques), panier, commande, suivi, pages libres (À propos, CGV, contact).
- **[MVP]** **Commande en une page, optimisée mobile et 3G** : nom, téléphone, commune / quartier (liste), repère, position GPS facultative ; frais de livraison calculés immédiatement ; paiement à la livraison par défaut.
- **[MVP]** Bouton « Commander sur WhatsApp » (message pré-rempli avec panier) en complément du formulaire.
- **[MVP]** SEO : pages rendues côté serveur, balises Open Graph (aperçu propre quand le lien est partagé sur WhatsApp / Facebook), sitemap, URLs propres.
- **[V1]** **Domaine personnalisé** (`www.maboutique.ci`) avec certificat SSL automatique.
- **[V1]** Paiement en ligne (Wave, Orange Money, MTN MoMo, Moov, carte) via un agrégateur (CinetPay, PayDunya, Paystack) ; **acompte** possible (ex. 20 % pour les articles chers, le reste à la livraison).
- **[V1]** Codes promo, livraison gratuite dès X FCFA, ventes flash avec compte à rebours.
- **[V1]** Paniers abandonnés : relance WhatsApp / SMS automatique (le numéro est saisi en premier).
- **[V1]** Pixels Meta et TikTok, Google Analytics, API Conversions Meta (côté serveur).
- **[V2]** Flux catalogue Meta (catalogue Facebook / Instagram / WhatsApp), avis clients, multilingue (FR/EN), multi-devise (XOF, XAF, GNF…).
- **[V2]** Éditeur de thème par glisser-déposer, blog.

### 3.7 Notifications et WhatsApp
- **[MVP]** Notifications internes (application + push) : nouvelle commande, commande à confirmer, livrée, échec, stock bas.
- **[MVP]** Messages client (journalisés) : commande reçue, confirmée, expédiée avec lien de suivi, livrée. Mode « lien WhatsApp manuel » gratuit (le marchand clique, WhatsApp s'ouvre pré-rempli).
- **[V1]** WhatsApp Cloud API (modèles Meta) au nom du marchand, avec **boutons de confirmation** (« Je confirme » / « Annuler ») qui confirment la commande automatiquement.
- **[V2]** Prise de commande par conversation WhatsApp (reprise du module entrant de Delivery App).

### 3.8 Finances
- **[MVP]** Grand livre par boutique (les soldes sont calculés à partir des écritures, jamais stockés) : ventes, encaissements des livreurs propres, **sommes à recevoir des transporteurs**, frais de livraison, dépenses.
- **[MVP]** Rapprochement transporteurs : pour chaque expédition livrée par un transporteur, montant encaissé attendu, frais facturés et net à reverser ; pointage avec les reversements (`payout.paid`).
- **[V1]** Marge par commande et par produit (prix − coût d'achat − livraison − remises), dépenses (publicité, emballage), point journalier / mensuel.
- **[V2]** Export comptable, factures normalisées si la réglementation l'exige (FNE en Côte d'Ivoire), TVA.

### 3.9 Statistiques
- **[MVP]** Tableau de bord : chiffre d'affaires, commandes, panier moyen, taux de confirmation, **taux de livraison réussie**, retours, par jour / semaine / mois.
- **[V1]** Par canal de vente, par produit / variante, par zone, par livreur, par transporteur (délai moyen, taux de réussite, coût moyen) : **le classement des transporteurs** aide le marchand à choisir.
- **[V1]** Rapport automatique quotidien par WhatsApp / email.

### 3.10 Équipe, compte et plateforme
- **[MVP]** Rôles et permissions (propriétaire, gestionnaire, agent de confirmation, préparateur, livreur, caissier), journal d'activité.
- **[MVP]** Un compte peut posséder **plusieurs boutiques**.
- **[V1]** Abonnements SaaS (gratuit limité, Starter, Pro, Business) avec quotas (commandes / mois, produits, livreurs, membres d'équipe), paiement par mobile money, période d'essai.
- **[V1]** Console super administrateur : boutiques, abonnements, impersonation encadrée (sessions de support comme dans Delivery App), statistiques.
- **[V1]** API publique et webhooks **du marchand** (pour ses propres intégrations).
- **[V2]** Caisse (point de vente) pour boutique physique, mode hors ligne.
- **[V2]** Places de marché d'applications / extensions.

---

## 4. Architecture technique

### 4.1 Choix
Même stack que Delivery App, pour réutiliser le code et les compétences :

| Couche | Choix | Justification |
|---|---|---|
| Back-end | **Laravel 12**, Sanctum, spatie/laravel-permission | Identique à Delivery App |
| Base de données | **PostgreSQL 16** (+ JSONB pour options et attributs) | Base **séparée** de Delivery App |
| Files / cache | Redis + Horizon | Appels transporteurs, webhooks, messages, images |
| Temps réel | Laravel Reverb | Nouvelles commandes, position des livreurs |
| Back-office marchand | **Vue 3 + Pinia + Tailwind** (SPA) | Identique à Delivery App |
| Application livreur | PWA Vue 3 (puis Capacitor si besoin du GPS en arrière-plan) | Reprise de l'application livreur existante |
| **Boutique publique** | **Rendu serveur Blade** + petits composants Vue/Alpine (panier, sélecteur de variantes) | SEO, aperçus WhatsApp, pages très légères sur 3G ; pas de SPA pour le client final |
| Images | Stockage S3 compatible + transformation (Intervention Image, ou CDN Cloudflare / imgproxy) | WebP, tailles multiples |
| Domaines personnalisés | **Caddy** (certificats à la demande) ou Cloudflare for SaaS | SSL automatique pour `www.boutique.ci` |
| Paiements | Agrégateur (CinetPay / PayDunya / Paystack) derrière une interface `PaymentGateway` | Changer de fournisseur sans réécrire |

### 4.2 Schéma d'ensemble

```
 Client final ──► Boutique (Blade, {slug}.domaine / domaine perso) ─┐
 Marchand & équipe ──► Back-office (Vue SPA) ───────────────────────┤
 Livreurs du marchand ──► Application livreur (PWA) ────────────────┤
                                                                    ▼
                                         API Laravel (multi-boutique)
                                         PostgreSQL · Redis · Reverb · S3
                                                    │
       ┌──────────────────────┬──────────────────────┼─────────────────────┐
  Connecteurs transporteurs   Paiements (CinetPay…)  WhatsApp / SMS     Webhooks marchand
       │
       ├── Delivery App (entreprise A) ── /api/public/v1 + webhooks signés
       ├── Delivery App (entreprise B) ── même protocole, autre sous-domaine
       ├── Transporteur manuel (bordereau + WhatsApp)
       └── Autres transporteurs (connecteurs dédiés, V2)
```

### 4.3 Principes
1. **Multi-boutique** : `store_id` sur toutes les tables métier et *global scope* (même mécanisme que `BelongsToCompany` / `CompanyScope`).
2. **Un seul modèle produit** : toute vente porte sur une **variante** (`product_variant_id`) ; un produit simple a une variante par défaut.
3. **Instantanés** : les lignes de commande copient nom, options, SKU, prix et photo au moment de l'achat.
4. **Journaux immuables** : `order_events`, `stock_movements`, `ledger_entries`, `carrier_events`.
5. **Connecteurs transporteurs derrière une interface** (`CarrierDriver`) : le reste de l'application ne connaît que des expéditions normalisées.
6. **Montants en entiers** (FCFA, sans décimales) avec la devise sur la boutique, comme `Money` dans Delivery App.
7. **Code commun** : au départ, copier les modules éprouvés de Delivery App (`PhoneNumber`, `Money`, messagerie WhatsApp/SMS, push, PWA livreur, analyse de messages). Les extraire en paquet Composer partagé seulement quand les deux copies divergent trop peu pour justifier l'effort.

---

## 5. Intégration avec Delivery App

### 5.1 Ce qui existe déjà côté Delivery App (et suffit pour démarrer)
- Clé API par marchand (`lv_xxxxxxxx_…`), portées `orders:read`, `orders:write`, `stock:read`.
- `GET /zones`, `GET /hubs`, `POST /quotes`, `GET|POST /orders` (avec `Idempotency-Key`), `GET /orders/{tracking_code}`, `POST /orders/{tracking_code}/cancel`, `GET /reports/summary`, `GET /products`.
- Webhooks signés HMAC (`order.created`, `order.status_changed`, `order.incident`, `payout.paid`, `stock.low`), relances, en-tête `X-Webhook-Id` pour dédoublonner.
- Multi-entreprise : chaque entreprise a son adresse `{slug}.PLATFORM_DOMAIN`.

### 5.2 Parcours de connexion d'un marchand à une entreprise de livraison

**Étape 1 (MVP, fonctionne avec l'existant) : connexion par clé**
1. Le marchand a (ou demande) un compte marchand chez l'entreprise de livraison.
2. Il crée une clé API dans *Profil › Intégrations* de Delivery App et la colle dans l'application e-commerce, avec l'adresse de l'entreprise.
3. L'application teste la clé (`GET /zones`), récupère les zones, propose la **correspondance des zones** (rapprochement automatique par nom, corrections manuelles) et affiche l'URL de webhook à déclarer côté Delivery App.

**Étape 2 (V1, nécessite des ajouts côté Delivery App) : « Connecter » en un clic**
1. Annuaire des entreprises de livraison inscrites sur la plateforme (zones couvertes, tarifs indicatifs, note).
2. Bouton **Connecter** → redirection vers `{entreprise}.domaine/connect` (flux de type OAuth 2 avec PKCE) → le marchand se connecte ou **demande à devenir client** (validation par l'entreprise) → autorisation des portées → retour avec la clé.
3. L'application crée elle-même l'abonnement webhook par API ; le secret ne transite jamais par le marchand.

### 5.3 Création d'une course depuis une expédition
| Champ Delivery App (`NewOrder`) | Source dans l'application e-commerce |
|---|---|
| `merchant_reference` | Numéro de commande + suffixe d'expédition (`#1042-1`) |
| `recipient_name`, `recipient_phone`, `recipient_phone2` | Client / adresse de livraison |
| `delivery_zone_id` | Zone du marchand → zone du transporteur (table de correspondance) ; à défaut `delivery_zone` (nom) |
| `delivery_address`, `delivery_landmark` | Adresse de livraison |
| `delivery_scheduled_date`, `delivery_time_slot` | Créneau choisi par le client (V1) |
| `items[]` | Une ligne par variante : `label` = « T-shirt Basic — Rouge / M », `quantity`, `unit_price` ; `product_id` du transporteur **uniquement** si le stock est dans son entrepôt (§5.5) |
| `items_amount` | Reste à encaisser (0 si payé en ligne, montant hors acompte sinon) |
| `fee_payer` | Selon la règle de la boutique (livraison payée par le client ou offerte) |
| `is_fragile`, `weight_kg` | Somme des poids des variantes ; indicateur fragile au niveau produit |
| `pickup_hub_id` | Si l'expédition part d'un emplacement « entrepôt du transporteur » |
| En-tête `Idempotency-Key` | Identifiant unique de la tentative d'expédition (aucun doublon en cas de relance) |

### 5.4 Statuts : correspondance Delivery App → expédition normalisée
| Delivery App | Expédition |
|---|---|
| `pending` | demandée |
| `confirmed` | acceptée |
| `rejected` | **refusée** → alerte et proposition d'un autre mode |
| `pickup_assigned`, `pickup_in_progress` | en attente de ramassage |
| `picked_up`, `at_hub`, `delivery_assigned` | en transit (**sortie de stock** au `picked_up`) |
| `out_for_delivery` | en cours de livraison |
| `delivered` | livrée (écriture « à recevoir du transporteur ») |
| `delivery_failed` | échec (motif repris de `order.incident`) |
| `rescheduled` | reportée |
| `return_assigned`, `returning` | en retour |
| `returned` | retournée → contrôle et **remise en stock** |
| `cancelled` | annulée → libération du stock |
| `lost` | perdue → réclamation au transporteur |

Réception : un point d'entrée par connexion (`/webhooks/carriers/{connection_uuid}`), vérification de la signature et de la fraîcheur de l'horodatage, dédoublonnage sur `X-Webhook-Id`, traitement en file d'attente. **Filet de sécurité** : synchronisation périodique `GET /orders?updated_since=…` toutes les 10 minutes pour rattraper un webhook perdu.

### 5.5 Stock déposé chez une entreprise de livraison
- Un emplacement de type `carrier_hub` représente l'entrepôt d'un transporteur.
- Table de correspondance `variante ↔ product_id du transporteur`.
- La source de vérité est le transporteur : synchronisation par `GET /products` et webhook `stock.low`, écarts signalés au marchand.

### 5.6 Argent
- Pour chaque expédition livrée : montant encaissé par le transporteur, frais de livraison facturés, net attendu.
- `payout.paid` → rapprochement automatique avec les expéditions concernées, écarts listés.

### 5.7 Ajouts nécessaires côté Delivery App (chantier parallèle, environ 2 semaines)
1. **API de gestion des webhooks** : `GET|POST|DELETE /webhooks` (le secret est renvoyé une seule fois à la création).
2. **Détail des reversements** : `GET /payouts` et `GET /payouts/{id}` avec les courses incluses (indispensable au rapprochement).
3. **Adresse de ramassage par course** (le marchand peut avoir plusieurs magasins) : `pickup_address`, `pickup_zone_id`, `pickup_contact`.
4. **Preuve de livraison** dans la réponse `Order` (URL signée de la photo, horodatage, nom du réceptionnaire).
5. `GET /me` : identité du marchand, statut du compte, conditions (pour l'écran de connexion).
6. **Flux « Connecter »** (OAuth 2 + demande d'ouverture de compte marchand) et **annuaire public** des entreprises (opt-in).
7. Champ `source = ecommerce_app` (ou en-tête `X-Client-App`) pour mesurer le volume apporté par l'application.
8. Publication du contrat sous forme de **protocole ouvert** (version 1 figée, tests de conformité) pour que d'autres sociétés de livraison l'implémentent.

### 5.8 Interface des connecteurs
```php
interface CarrierDriver
{
    public function testConnection(CarrierConnection $c): ConnectionStatus;
    public function zones(CarrierConnection $c): array;
    public function quote(CarrierConnection $c, ShipmentDraft $d): Quote;
    public function createShipment(CarrierConnection $c, Shipment $s): CarrierReference;
    public function cancel(CarrierConnection $c, Shipment $s, ?string $reason): void;
    public function fetch(CarrierConnection $c, Shipment $s): CarrierStatus;   // synchronisation
    public function parseWebhook(CarrierConnection $c, Request $r): ?CarrierEvent;
}
```
Implémentations : `DeliveryAppDriver` (MVP), `ManualDriver` (MVP), autres (V2).

---

## 6. Modèle de données (tables principales)

| Domaine | Tables |
|---|---|
| Comptes | `users`, `stores`, `store_users` (rôle par boutique), `store_domains`, `subscriptions`, `plans` |
| Catalogue | `products`, `product_options` (nom, position), `product_option_values`, `product_variants` (SKU, prix, prix barré, coût, poids, code-barres, `option_values` JSONB), `product_images`, `variant_images`, `collections`, `collection_product`, `tags` |
| Stock | `stock_locations` (type : `store`, `warehouse`, `carrier_hub`), `stock_levels` (variante × emplacement : `on_hand`, `reserved`), `stock_movements`, `stock_transfers`, `inventory_counts` |
| Clients | `customers` (téléphone unique par boutique), `customer_addresses` (zone, repère, GPS), `customer_tags` |
| Ventes | `carts`, `cart_items`, `orders` (statut, statut de paiement, source, totaux), `order_items` (instantané de la variante), `order_events`, `order_confirmations` (tentatives d'appel), `discounts`, `discount_usages` |
| Paiements | `payments` (méthode, fournisseur, référence, statut), `refunds` |
| Livraison | `delivery_zones` (zones du marchand, frais, délai), `shipments` (mode, statut normalisé, montant à encaisser, frais), `shipment_items`, `shipment_events` |
| Livreurs propres | `couriers`, `courier_assignments`, `cash_collections`, `courier_remittances`, `courier_pay_rules`, `courier_payouts`, `courier_locations` |
| Transporteurs | `carriers` (catalogue des connecteurs), `carrier_connections` (URL, clé chiffrée, statut, secret webhook chiffré), `carrier_zone_mappings`, `carrier_product_mappings`, `carrier_webhook_receipts` (dédoublonnage), `carrier_payouts`, `carrier_payout_lines` |
| Finances | `ledger_entries`, `expenses` |
| Boutique en ligne | `themes`, `store_theme_settings` (JSONB), `pages`, `menus`, `redirects` |
| Messages | `message_templates`, `outbound_messages`, `push_subscriptions`, `notification_preferences` |
| Intégrations | `api_keys`, `webhook_subscriptions`, `webhook_deliveries`, `idempotency_keys` (mêmes structures que Delivery App) |

Le schéma DBML détaillé sera produit en phase 0 (`docs/database.dbml`), comme pour Delivery App.

---

## 7. Plan d'exécution

Hypothèse d'équipe identique à Delivery App : 2 développeurs full-stack, 1 développeur front/mobile et 1 chef de projet/testeur à temps partiel. Durées indicatives.

### Phase 0 : fondations (2 semaines)
- [ ] Projet Laravel 12 + Vue 3 + Tailwind, CI (tests, lint, build), environnement local identique à Delivery App.
- [ ] Multi-boutique (`store_id` + scope), sous-domaines `{boutique}.domaine`, comptes et rôles, invitation de membres, OTP téléphone.
- [ ] Reprise des modules communs : `Money`, `PhoneNumber`, push, notifications, journaux.
- [ ] Schéma DBML complet et validé.

### Phase 1 : catalogue et stock (3 semaines)
- [ ] Produits, options, génération et édition en masse des variantes, photos (par variante), collections.
- [ ] Emplacements, niveaux et mouvements de stock par variante ; alertes.
- [ ] Import / export CSV ; import depuis Delivery App.

### Phase 2 : commandes et clients (3 semaines)
- [ ] Saisie rapide (collage de message), commandes multicanales, statuts commande / paiement séparés.
- [ ] File de confirmation (appel, WhatsApp manuel, motifs, relances).
- [ ] CRM : fiche client, adresses, étiquettes, liste noire, score de livraison.
- [ ] Réservation et sortie de stock, PDF (facture, étiquette).

### Phase 3 : boutique en ligne (4 semaines)
- [ ] Rendu serveur Blade, 2 thèmes, réglages du thème, pages libres, menus.
- [ ] Fiche produit avec sélecteur de variantes, panier, commande en une page, zones et frais de livraison, paiement à la livraison, « Commander sur WhatsApp ».
- [ ] SEO, Open Graph, sitemap, performance (objectif : page produit < 1,5 s en 3G, < 100 Ko de JavaScript).
- **Jalon MVP vendable (~ 12 semaines)** : un marchand peut vendre en ligne et sur les réseaux, gérer stock et commandes, et livrer « à la main ».

### Phase 4 : livreurs propres (3 semaines)
- [ ] Inscription des livreurs par invitation, zones, disponibilité.
- [ ] Expéditions, assignation, application livreur PWA (missions, statuts, preuves, encaissements).
- [ ] Caisse : versements, écarts ; rémunération et relevés.
- [ ] Lien de suivi client.

### Phase 5 : connecteur Delivery App (3 semaines) + ajouts côté Delivery App (2 semaines, en parallèle)
- [ ] `CarrierDriver`, `DeliveryAppDriver`, `ManualDriver`.
- [ ] Écran de connexion par clé, correspondance des zones, devis, création et annulation de course, webhooks + synchronisation de secours.
- [ ] Stock en entrepôt transporteur ; rapprochement des reversements.
- [ ] Côté Delivery App : §5.7 points 1 à 5 et 7.
- [ ] Tests de bout en bout sur une instance Delivery App de recette (création → livraison → reversement).
- **Jalon V1 livraison (~ 18 semaines)** : livreurs propres et transporteurs connectés dans le même flux.

### Phase 6 : paiements en ligne et WhatsApp (3 semaines)
- [ ] Agrégateur de paiement (mobile money + carte), acomptes, remboursements, webhooks de paiement.
- [ ] WhatsApp Cloud API au nom du marchand, modèles, boutons de confirmation automatique.
- [ ] Relance des paniers abandonnés.

### Phase 7 : croissance (3 semaines)
- [ ] Codes promo, livraison gratuite, ventes flash, prix par quantité.
- [ ] Pixels Meta / TikTok, API Conversions, Analytics.
- [ ] Règles d'affectation automatiques et comparateur de transporteurs.
- [ ] Statistiques avancées (canal, produit, zone, livreur, transporteur), rapport quotidien.

### Phase 8 : SaaS et lancement (3 semaines)
- [ ] Abonnements, quotas, paiement mobile money, essai gratuit.
- [ ] Domaines personnalisés + SSL automatique.
- [ ] Console super administrateur, sessions de support.
- [ ] Flux « Connecter » en un clic et annuaire (§5.7 point 6) si Delivery App est prêt.
- [ ] Pilote avec 10 marchands (dont des marchands déjà clients d'une entreprise utilisant Delivery App), corrections, documentation, supervision (Sentry, disponibilité).

**Total : environ 6 mois** jusqu'au lancement commercial ; MVP vendable vers la **12e semaine**.

### Après le lancement (V2)
Caisse pour boutique physique, catalogue Meta / WhatsApp, prise de commande par conversation WhatsApp, fidélité, multi-devise et multilingue, éditeur de thème, autres connecteurs transporteurs, application native (Capacitor).

---

## 8. Modèle économique

| Offre | Pour qui | Contenu indicatif |
|---|---|---|
| **Gratuit** | Démarrer | 30 commandes / mois, 20 produits, sous-domaine, 1 livreur |
| **Starter** | Petit vendeur réseaux sociaux | Commandes illimitées, 3 membres, 5 livreurs, 1 transporteur connecté |
| **Pro** | Boutique établie | Domaine personnalisé, paiement en ligne, WhatsApp API, transporteurs illimités, statistiques avancées |
| **Business** | Plusieurs boutiques / entrepôts | Multi-boutique, API marchand, support prioritaire |

Revenus complémentaires possibles : commission sur paiement en ligne, apport d'affaires facturé aux entreprises de livraison (par course créée via l'application), thèmes premium.

---

## 9. Risques et réponses

| Risque | Réponse |
|---|---|
| Taux de refus élevé en paiement à la livraison | Confirmation obligatoire paramétrable, score client, acompte sur articles chers, liste noire |
| Transporteur qui change ou casse son API | Protocole versionné (`/v1`), tests de conformité, synchronisation de secours, mode manuel en repli |
| Zones différentes d'un transporteur à l'autre | Table de correspondance par connexion, rapprochement automatique par nom, alerte sur zone non reconnue |
| Écarts d'argent avec les transporteurs | Rapprochement ligne à ligne des reversements, écarts visibles et exportables |
| Connexion lente chez le client final | Boutique rendue côté serveur, images WebP, peu de JavaScript |
| Coût des messages WhatsApp | Mode lien manuel gratuit par défaut, API Meta réservée aux offres payantes |
| Complexité des variantes pour un vendeur novice | Produit simple par défaut, variantes ajoutées seulement si besoin, génération automatique |

---

## 10. Qualité et sécurité
- Tests : machine à états des commandes et expéditions, stock (réservation, sortie, retour), grand livre, correspondance des statuts transporteurs, signature des webhooks, idempotence.
- Secrets chiffrés (clés transporteurs, secrets webhook, jetons WhatsApp et paiement).
- Isolation stricte des boutiques (scope global + *Policies*), limitation de débit sur la commande publique et l'OTP, protection anti-robot sur le formulaire de commande.
- Sauvegardes quotidiennes chiffrées, supervision des files (Horizon) et des erreurs (Sentry).
