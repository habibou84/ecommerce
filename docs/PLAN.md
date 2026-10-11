# Jibiat Marchand : plan produit et d'exécution

> Application e-commerce pour marchands — `jibiat-marchand.com`

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
- **[MVP]** Site sur `{boutique}.jibiat-marchand.com`, **thème** configurable (logo, couleurs, polices, bannières, sections de page d'accueil) ; 2 ou 3 thèmes au départ.
- **[MVP]** Pages : accueil, collection, produit (sélecteur de variantes avec photo et stock dynamiques), panier, commande, suivi, pages libres (À propos, CGV, contact).
- **[MVP]** **Commande en une page, optimisée mobile et 3G** : nom, téléphone, commune / quartier (liste), repère, position GPS facultative ; frais de livraison calculés immédiatement ; paiement à la livraison par défaut.
- **[MVP]** Bouton « Commander sur WhatsApp » (message pré-rempli avec panier) en complément du formulaire.
- **[MVP]** SEO : pages rendues côté serveur, balises Open Graph (aperçu propre quand le lien est partagé sur WhatsApp / Facebook), sitemap, URLs propres.
- **[V1]** **Domaine personnalisé** (`www.maboutique.ci`) avec certificat SSL automatique.
- **[V1]** Paiement en ligne (Wave, Orange Money, MTN MoMo, Moov, carte) avec **le ou les prestataires choisis par le marchand** (Wave, CinetPay, PayDunya) ; **acompte** possible (ex. 20 % pour les articles chers, le reste à la livraison). Détails au §6.
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
| Domaines | **Domaine distinct** de Delivery App ; certificat *wildcard* + **Caddy** (certificats à la demande) pour les domaines personnalisés | Voir §4.4 |
| Paiements | **Plusieurs prestataires** (Wave, CinetPay, PayDunya au départ) derrière une interface `PaymentGateway` ; le marchand choisit | Voir §6 |
| Code commun | Paquet Composer partagé + client PHP de l'API Delivery App | Voir §4.5 |

### 4.2 Schéma d'ensemble

```
 Client final ──────────► Boutique (Blade)       {slug}.jibiat-marchand.com/          ─┐
                                                  ou domaine personnalisé du marchand   │
 Marchand & équipe ─────► Back-office (Vue SPA)  {slug}.jibiat-marchand.com/gestion   ─┤
 Livreurs du marchand ──► Application (PWA)      {slug}.jibiat-marchand.com/livreur   ─┤
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
7. **Code commun** : paquet partagé pour les briques techniques, copie adaptée pour le code métier (voir §4.5).
8. **L'argent du marchand ne transite jamais par la plateforme** : il connecte ses propres comptes de paiement (voir §6).

### 4.4 Domaines et adresses
Domaine : **`jibiat-marchand.com`**, distinct de celui de Delivery App (`jibiat.com`). Même méthode que Delivery App : **un sous-domaine par marchand**, qui porte à la fois son site et sa gestion.

| Adresse | Contenu |
|---|---|
| `jibiat-marchand.com` (et `www.`) | Site vitrine du produit, tarifs, **création de boutique** (inscription en 5 minutes depuis un téléphone) |
| `{boutique}.jibiat-marchand.com/` | **Site e-commerce** du marchand (public) |
| `{boutique}.jibiat-marchand.com/gestion` | **Back-office** du marchand et de son équipe (connexion) |
| `{boutique}.jibiat-marchand.com/livreur` | Application PWA des livreurs du marchand |
| `{boutique}.jibiat-marchand.com/suivi/{code}` | Suivi de commande du client final |
| `www.maboutique.ci` (option, V1) | Domaine personnalisé : **site uniquement** ; `/gestion` et `/livreur` redirigent vers le sous-domaine |
| `admin.jibiat-marchand.com` | Console du super administrateur |
| `api.jibiat-marchand.com` | API publique des marchands (V1) et réception des webhooks (transporteurs, paiements) |

Règles :
- Résolution de la boutique par le nom d'hôte (sous-domaine ou domaine personnalisé), comme `Tenancy` dans Delivery App ; liste de sous-domaines réservés (`www`, `admin`, `api`, `app`, `mail`, `static`, `cdn`, `aide`…).
- Cookies de session **limités à l'hôte** (jamais `.jibiat-marchand.com`) : une connexion à une boutique n'est pas valable sur une autre.
- Un utilisateur qui appartient à plusieurs boutiques choisit la boutique après connexion sur `jibiat-marchand.com/connexion`, puis il est redirigé vers `{boutique}.jibiat-marchand.com/gestion`.
- Certificat *wildcard* `*.jibiat-marchand.com` (validation DNS) pour tous les sous-domaines ; certificats à la demande (Caddy, avec vérification que le domaine est bien déclaré par une boutique) pour les domaines personnalisés.
- Changement de sous-domaine possible avec redirection 301 de l'ancien pendant 90 jours (`redirects`).

### 4.5 Partage de code avec Delivery App
Décision : **un paquet Composer partagé pour les briques techniques stables, un client PHP pour l'API de Delivery App, et une copie adaptée pour le code métier.**

| Quoi | Comment | Pourquoi |
|---|---|---|
| `Money`, `PhoneNumber` (+ règle de validation), passerelles WhatsApp Meta / SMS / journal, canal Web Push, signature et vérification HMAC des webhooks, middleware d'idempotence, résolution du locataire par sous-domaine | Paquet **`habibou84/socle`** (dépôt privé, versionné, installé par Composer dans les deux applications) | Code sans logique métier, déjà testé, qu'on ne veut corriger qu'une fois |
| Client de l'API publique de Delivery App (devis, courses, zones, produits, webhooks, statuts) | Paquet **`habibou84/delivery-app-client`**, maintenu à côté de Delivery App ; ses tests tournent dans la CI de Delivery App (test de contrat) | Toute modification de l'API qui casserait l'application e-commerce est détectée **avant** sa mise en ligne |
| Commandes, stock, livreurs, caisse, application livreur PWA, analyse des messages WhatsApp | **Copie puis adaptation** | Les modèles divergent (variantes, expéditions multimodes) ; les forcer dans un paquet commun freinerait les deux produits |
| Composants Vue génériques (modale, pagination, notifications, champ téléphone, sélecteur de lieu) | Copie au départ ; paquet npm seulement si les deux copies évoluent ensemble | Peu de code, beaucoup de variations visuelles |

Coût : environ 4 jours en phase 0 pour créer `socle` et faire basculer Delivery App dessus (sans changement fonctionnel, couvert par ses tests existants).

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
2. Bouton **Connecter** → redirection vers `{entreprise}.jibiat.com/connect` (flux de type OAuth 2 avec PKCE) → le marchand se connecte ou **demande à devenir client** (validation par l'entreprise) → autorisation des portées → retour avec la clé.
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

## 6. Paiements en ligne

### 6.1 Principe : le marchand branche ses propres comptes
- Chaque marchand ouvre **son** compte chez le ou les prestataires de son choix et saisit ses identifiants d'API dans *Gestion › Paiements* (chiffrés en base). L'argent va **directement** du client au marchand.
- La plateforme ne détient donc jamais les fonds des marchands. Elle évite ainsi le statut d'établissement de paiement (agrément BCEAO), les litiges de reversement et la trésorerie à gérer.
- Le paiement à la livraison reste toujours disponible ; le paiement en ligne s'ajoute (total ou **acompte**).

### 6.2 Prestataires
Une interface unique, un pilote par prestataire :

```php
interface PaymentGateway
{
    public function methods(PaymentAccount $a): array;                 // Wave, Orange Money, MTN, Moov, carte…
    public function createCheckout(PaymentAccount $a, Payment $p): Checkout;   // URL de paiement
    public function verify(PaymentAccount $a, Payment $p): PaymentStatus;      // contrôle côté serveur
    public function parseWebhook(PaymentAccount $a, Request $r): ?PaymentEvent;
    public function refund(PaymentAccount $a, Payment $p, int $amount): Refund;
}
```

| Prestataire | Rôle | Phase |
|---|---|---|
| **Wave** (API Business, en direct) | Wave seul, frais en général plus bas qu'en passant par un agrégateur | V1 |
| **CinetPay** | Agrégateur : Orange Money, MTN, Moov, Wave, cartes ; bien implanté en Côte d'Ivoire et dans l'UEMOA | V1 |
| **PayDunya** | Agrégateur, simple à ouvrir ; fort au Sénégal | V1 |
| Hub2, FedaPay, Paystack… | Selon la demande des marchands et les pays visés | V2 |

> Les frais publiés circulent surtout via des blogs et se contredisent : en ordre de grandeur, environ 1 % pour Wave en direct
> et 2 à 3,5 % pour les agrégateurs, plus d'éventuels frais de retrait. **Ne rien coder en dur** : les taux sont saisis par
> le marchand (ils sont souvent négociés) et vérifiés auprès de chaque prestataire avant le lancement.

### 6.3 Le marchand choisit ce qui lui coûte le moins
- **Routage par moyen de paiement** : pour chaque moyen proposé au client, le marchand choisit le prestataire (ex. *Wave → Wave direct* ; *Orange Money, MTN, Moov, carte → CinetPay*). Si un prestataire est indisponible, le paiement bascule vers le suivant de la liste.
- **Simulateur** dans *Gestion › Paiements* : à partir des taux saisis et de ses ventes des 3 derniers mois, l'application affiche le coût mensuel de chaque combinaison et recommande la moins chère.
- **Rapport des frais réels** par prestataire (montant encaissé, frais, net reçu, délai de versement) dans les finances.

### 6.4 Fonctions de paiement
- **[V1]** Paiement total ou acompte au moment de la commande sur le site.
- **[V1] Lien de paiement** envoyé par WhatsApp pour une commande saisie à la main (vente sur Facebook, TikTok, WhatsApp) : c'est très utile aux vendeurs sur les réseaux sociaux.
- **[V1]** Remboursement total ou partiel depuis la commande (si le prestataire le permet, sinon remboursement manuel enregistré).
- **[V1]** Webhooks de paiement : vérification de signature **et** contrôle par `verify()` avant de valider une commande ; idempotence sur la référence de transaction.
- **[V2]** Paiement à la livraison par mobile money : le livreur affiche un QR code ou envoie un lien au client à la porte, et la commande passe à « payée » sans espèces à gérer.

### 6.5 Abonnements de la plateforme
Les abonnements des marchands sont encaissés sur le **compte de la plateforme**, avec le même pilote (CinetPay ou Wave), indépendamment des comptes des marchands.

---

## 7. Modèle de données (tables principales)

| Domaine | Tables |
|---|---|
| Comptes | `users`, `stores` (sous-domaine unique), `store_users` (rôle par boutique), `store_domains` (domaines personnalisés, état de vérification et du certificat), `subscriptions`, `plans` |
| Catalogue | `products`, `product_options` (nom, position), `product_option_values`, `product_variants` (SKU, prix, prix barré, coût, poids, code-barres, `option_values` JSONB), `product_images`, `variant_images`, `collections`, `collection_product`, `tags` |
| Stock | `stock_locations` (type : `store`, `warehouse`, `carrier_hub`), `stock_levels` (variante × emplacement : `on_hand`, `reserved`), `stock_movements`, `stock_transfers`, `inventory_counts` |
| Clients | `customers` (téléphone unique par boutique), `customer_addresses` (zone, repère, GPS), `customer_tags` |
| Ventes | `carts`, `cart_items`, `orders` (statut, statut de paiement, source, totaux), `order_items` (instantané de la variante), `order_events`, `order_confirmations` (tentatives d'appel), `discounts`, `discount_usages` |
| Paiements | `payment_accounts` (prestataire, identifiants chiffrés, taux saisis, actif), `payment_routes` (moyen de paiement → comptes par ordre de priorité), `payments` (méthode, prestataire, référence, statut, frais), `payment_links`, `refunds` |
| Livraison | `delivery_zones` (zones du marchand, frais, délai), `shipments` (mode, statut normalisé, montant à encaisser, frais), `shipment_items`, `shipment_events` |
| Livreurs propres | `couriers`, `courier_assignments`, `cash_collections`, `courier_remittances`, `courier_pay_rules`, `courier_payouts`, `courier_locations` |
| Transporteurs | `carriers` (catalogue des connecteurs), `carrier_connections` (URL, clé chiffrée, statut, secret webhook chiffré), `carrier_zone_mappings`, `carrier_product_mappings`, `carrier_webhook_receipts` (dédoublonnage), `carrier_payouts`, `carrier_payout_lines` |
| Finances | `ledger_entries`, `expenses` |
| Boutique en ligne | `themes`, `store_theme_settings` (JSONB), `pages`, `menus`, `redirects` |
| Messages | `message_templates`, `outbound_messages`, `push_subscriptions`, `notification_preferences` |
| Intégrations | `api_keys`, `webhook_subscriptions`, `webhook_deliveries`, `idempotency_keys` (mêmes structures que Delivery App) |

Schéma complet : [`database.dbml`](./database.dbml) (96 tables, importable tel quel sur https://dbdiagram.io ; le SQL généré a été chargé sans erreur dans PostgreSQL 16).

Choix faits en détaillant le schéma :
- **Référentiel commun des lieux** (`places` : ville › commune › quartier, avec variantes d'écriture). Les adresses des clients, les zones de livraison des boutiques et les correspondances avec les zones des transporteurs pointent toutes vers ces lieux. Une correspondance « lieu → zone du transporteur » faite une fois sert à toutes les commandes.
- **Variantes** : trois colonnes `option1..3_value_id` plutôt qu'un JSONB, pour garantir l'unicité de chaque combinaison et garder des requêtes simples.
- **Catégories = collections** : un seul concept pour le marchand.
- **Grand livre en partie double simplifiée** (`ledger_entries` débit / crédit, avec sous-comptes par livreur, transporteur et prestataire de paiement). Il donne directement l'argent détenu par chaque livreur et ce que doit chaque transporteur.
- **Rôles par boutique** avec spatie/laravel-permission en mode *teams* (`team_id` = boutique), car un même compte peut appartenir à plusieurs boutiques.

---

## 8. Plan d'exécution

Hypothèse d'équipe identique à Delivery App : 2 développeurs full-stack, 1 développeur front/mobile et 1 chef de projet/testeur à temps partiel. Durées indicatives.

### Phase 0 : fondations (2,5 semaines) ✅ *réalisée, sauf la bascule de Delivery App sur `socle`*
- [x] Projet Laravel 12 + Vue 3 + Tailwind, CI (Pint, tests PostgreSQL en PHP 8.3 et 8.4, build front-end).
- [x] Paquet `habibou84/socle` créé dans `packages/socle` (téléphones, montants, SMS, signature des webhooks), avec ses tests.
- [ ] Sortir `socle` dans son propre dépôt et **faire basculer Delivery App dessus** : nécessite un dépôt `habibou84/socle` et un accès en écriture à `deliverynew`.
- [ ] Squelette de `habibou84/delivery-app-client` : reporté à la phase 4, où il sert.
- [x] Multi-boutique : résolution par sous-domaine (`Tenancy`), filtre automatique `BelongsToStore`, sous-domaines réservés, anciennes adresses redirigées 90 jours, domaines personnalisés vérifiés (site seulement).
- [x] Session par cookie limitée au nom d'hôte de la boutique (Sanctum), lien de connexion à usage unique pour passer d'une adresse à l'autre.
- [x] Site vitrine `jibiat-marchand.com` + **création de boutique** (nom, adresse avec vérification de disponibilité, code SMS) et essai de 14 jours de l'offre Pro.
- [x] Comptes (un compte par numéro, plusieurs boutiques possibles), rôles par boutique (spatie en mode *teams*), invitation par lien WhatsApp, mot de passe oublié par SMS, retrouver ses boutiques depuis `jibiat-marchand.com/connexion`.
- [x] Back-office `/gestion` : connexion, accueil (premiers pas), équipe, paramètres (dont changement d'adresse), passage d'une boutique à l'autre.
- [x] Schéma DBML complet ([`database.dbml`](./database.dbml)).
- Vérification du numéro **par SMS uniquement** pour l'instant (mobiles ivoiriens) : la preuve gratuite par message WhatsApp entrant, utilisée par Delivery App, viendra avec le numéro WhatsApp Business de la plateforme (phase 6).

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
- [ ] Rendu serveur Blade sur `{boutique}.jibiat-marchand.com/`, 2 thèmes, réglages du thème, pages libres, menus.
- [ ] Fiche produit avec sélecteur de variantes, panier, commande en une page, zones et frais de livraison, paiement à la livraison, « Commander sur WhatsApp ».
- [ ] SEO, Open Graph, sitemap, performance (objectif : page produit < 1,5 s en 3G, < 100 Ko de JavaScript).
- **Jalon MVP vendable (~ 12,5 semaines)** : un marchand peut vendre en ligne et sur les réseaux, gérer stock et commandes, et livrer « à la main ».

### Phase 4 : connecteur Delivery App (3 semaines) + ajouts côté Delivery App (2 semaines, en parallèle)
*Placée avant les livreurs propres : les entreprises qui utilisent déjà Delivery App sont le premier canal de distribution (§12, suggestion 1).*
- [ ] Expéditions (statut normalisé), `CarrierDriver`, `DeliveryAppDriver`, `ManualDriver`.
- [ ] Écran de connexion par clé, correspondance des zones, devis, création et annulation de course, webhooks + synchronisation de secours.
- [ ] Stock en entrepôt transporteur ; rapprochement des reversements ; lien de suivi client.
- [ ] Côté Delivery App : §5.7 points 1 à 5 et 7 ; test de contrat de `delivery-app-client` dans sa CI.
- [ ] Tests de bout en bout sur une instance Delivery App de recette (création → livraison → reversement).

### Phase 5 : livreurs propres (3 semaines)
- [ ] Inscription des livreurs par invitation, zones, disponibilité.
- [ ] Assignation, application livreur PWA sur `/livreur` (missions, statuts, code de livraison ou photo, encaissements).
- [ ] Caisse : versements, écarts ; rémunération et relevés.
- **Jalon V1 livraison (~ 18,5 semaines)** : livreurs propres et transporteurs connectés dans le même flux.

### Phase 6 : paiements en ligne et WhatsApp (4 semaines)
- [ ] `PaymentGateway` + pilotes **Wave**, **CinetPay**, **PayDunya** ; comptes de paiement du marchand, routage par moyen de paiement, bascule de secours (§6).
- [ ] Paiement total ou acompte, **liens de paiement** par WhatsApp, remboursements, webhooks vérifiés.
- [ ] Simulateur de frais et rapport des frais réels par prestataire.
- [ ] WhatsApp Cloud API au nom du marchand, modèles, boutons de confirmation automatique ; relance des paniers abandonnés.

### Phase 7 : croissance (3 semaines)
- [ ] Codes promo, livraison gratuite, ventes flash, prix par quantité.
- [ ] Pixels Meta / TikTok, API Conversions, Analytics.
- [ ] Règles d'affectation automatiques et comparateur de transporteurs.
- [ ] Statistiques avancées (canal, produit, zone, livreur, transporteur), rapport quotidien.

### Phase 8 : SaaS et lancement (3 semaines)
- [ ] Abonnements, quotas, paiement sur le compte de la plateforme (§6.5), essai gratuit.
- [ ] Domaines personnalisés + SSL automatique (§4.4).
- [ ] Console super administrateur (`admin.jibiat-marchand.com`), sessions de support.
- [ ] Flux « Connecter » en un clic et annuaire (§5.7 point 6) si Delivery App est prêt.
- [ ] Déclaration à l'autorité de protection des données (ARTCI en Côte d'Ivoire), CGU / CGV, politique de confidentialité.
- [ ] Pilote avec 10 marchands (dont des marchands déjà clients d'une entreprise utilisant Delivery App), corrections, documentation, supervision (Sentry, disponibilité).

**Total : environ 6,5 mois** jusqu'au lancement commercial ; MVP vendable vers la **13e semaine**.

### Après le lancement (V2)
Caisse pour boutique physique, catalogue Meta / WhatsApp, prise de commande par conversation WhatsApp, fidélité, multi-devise et multilingue, éditeur de thème, autres connecteurs transporteurs, application native (Capacitor).

---

## 9. Modèle économique

| Offre | Pour qui | Contenu indicatif |
|---|---|---|
| **Gratuit** | Démarrer | 30 commandes / mois, 20 produits, sous-domaine, 1 livreur |
| **Starter** | Petit vendeur réseaux sociaux | Commandes illimitées, 3 membres, 5 livreurs, 1 transporteur connecté |
| **Pro** | Boutique établie | Domaine personnalisé, paiement en ligne, WhatsApp API, transporteurs illimités, statistiques avancées |
| **Business** | Plusieurs boutiques / entrepôts | Multi-boutique, API marchand, support prioritaire |

Revenus complémentaires possibles : commission sur paiement en ligne, apport d'affaires facturé aux entreprises de livraison (par course créée via l'application), thèmes premium.

---

## 10. Risques et réponses

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

## 11. Qualité et sécurité
- Tests : machine à états des commandes et expéditions, stock (réservation, sortie, retour), grand livre, correspondance des statuts transporteurs, signature des webhooks, idempotence.
- Secrets chiffrés (clés transporteurs, secrets webhook, jetons WhatsApp et paiement).
- Isolation stricte des boutiques (scope global + *Policies*), limitation de débit sur la commande publique et l'OTP, protection anti-robot sur le formulaire de commande.
- Sauvegardes quotidiennes chiffrées, supervision des files (Horizon) et des erreurs (Sentry).

---

## 12. Suggestions complémentaires

Les suggestions marquées ✅ sont déjà intégrées au plan ci-dessus ; les autres sont à décider.

1. ✅ **Utiliser les entreprises de livraison comme canal de distribution.** Elles ont déjà des dizaines de marchands. Le connecteur passe donc avant les livreurs propres (phase 4). À ajouter :
   - un **programme partenaire** : remise ou commission pour l'entreprise qui fait inscrire ses marchands ;
   - dans la mini-boutique `/b/{slug}` de Delivery App, un bouton **« Passer à la boutique complète »**, qui importe en un clic le catalogue et les clients et crée la connexion transporteur.
2. ✅ **Création de boutique en 5 minutes depuis un téléphone** (phase 0). La plupart des marchands n'ont pas d'ordinateur. Le back-office `/gestion` doit être conçu d'abord pour le téléphone et installable en PWA, comme les applications de Delivery App.
3. **Partage de produit prêt pour les réseaux sociaux** (V1, peu coûteux) : pour chaque produit, un lien court (`{boutique}.jibiat-marchand.com/p/abc`) et une **image carrée générée** (photo, prix, nom de la boutique), prête à publier en statut WhatsApp, sur Facebook ou TikTok. C'est la façon dont ces marchands vendent déjà.
4. **Fiche produit à partir d'une photo** (V1, option des offres payantes) : le marchand prend la photo, l'IA propose titre, description, catégorie et options (couleurs visibles). Il valide ou corrige. C'est un gain de temps énorme pour un catalogue de 200 articles.
5. ✅ **Code de livraison** remis au client par SMS / WhatsApp et saisi par le livreur (phase 5) : il empêche les fausses livraisons déclarées, surtout avec les livreurs propres.
6. **Prévoir le multi-pays dès le modèle de données** (coût faible maintenant, élevé plus tard) : pays et devise sur la boutique, format des numéros et zones par pays. Wave et PayDunya ouvrent naturellement le Sénégal.
7. **Réseau de confiance anti-refus** (V2, à valider juridiquement) : un score de refus partagé entre boutiques, calculé sur le numéro de téléphone **haché**, avec l'accord des marchands. Le score n'affiche qu'une note (jamais l'historique ni le nom de la boutique). C'est très utile en paiement à la livraison, mais c'est un traitement de données personnelles à déclarer.
8. **Mesurer l'activation dès le MVP** : part des boutiques qui reçoivent une première commande dans les 7 jours, puis taux de confirmation, taux de livraison et rétention à 30 jours. Ces chiffres guident les priorités de la phase 7 mieux que les intuitions.
9. **Support par WhatsApp intégré** au back-office (bouton « Aide » qui ouvre une conversation avec le support, avec l'identifiant de la boutique pré-rempli), puis des sessions de support encadrées comme dans Delivery App.
10. ✅ **Domaine** : `jibiat-marchand.com`. À faire avant la phase 0 :
   - DNS : enregistrement *wildcard* `*.jibiat-marchand.com` vers le serveur, et accès API au DNS (Cloudflare conseillé) pour le certificat *wildcard* ;
   - réserver aussi `jibiat-marchand.ci` si possible et les comptes réseaux sociaux « Jibiat Marchand » ;
   - les adresses `{boutique}.jibiat-marchand.com` sont longues à partager : les liens courts de la suggestion 3 et les domaines personnalisés compensent. Un domaine court dédié aux liens (ex. `jbt.ci/abc`) peut s'ajouter plus tard.
