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

### Phase 0 : fondations (2,5 semaines) ✅ *réalisée*
- [x] Projet Laravel 12 + Vue 3 + Tailwind, CI (Pint, tests PostgreSQL en PHP 8.3 et 8.4, build front-end).
- [x] Paquet `habibou84/socle` créé dans `packages/socle` (téléphones, montants, SMS, signature des webhooks), avec ses tests.
- [x] `socle` sorti dans son propre dépôt public `habibou84/socle` ; Delivery App et Jibiat Marchand l'utilisent tous les deux (phase 4).
- [x] Client de l'API de Delivery App : `DeliveryAppDriver` (phase 4), dans l'application plutôt qu'en paquet séparé tant qu'un seul client l'utilise.
- [x] Multi-boutique : résolution par sous-domaine (`Tenancy`), filtre automatique `BelongsToStore`, sous-domaines réservés, anciennes adresses redirigées 90 jours, domaines personnalisés vérifiés (site seulement).
- [x] Session par cookie limitée au nom d'hôte de la boutique (Sanctum), lien de connexion à usage unique pour passer d'une adresse à l'autre.
- [x] Site vitrine `jibiat-marchand.com` + **création de boutique** (nom, adresse avec vérification de disponibilité, code SMS) et essai de 14 jours de l'offre Pro.
- [x] Comptes (un compte par numéro, plusieurs boutiques possibles), rôles par boutique (spatie en mode *teams*), invitation par lien WhatsApp, mot de passe oublié par SMS, retrouver ses boutiques depuis `jibiat-marchand.com/connexion`.
- [x] Back-office `/gestion` : connexion, accueil (premiers pas), équipe, paramètres (dont changement d'adresse), passage d'une boutique à l'autre.
- [x] Schéma DBML complet ([`database.dbml`](./database.dbml)).
- Vérification du numéro **par SMS uniquement** pour l'instant (mobiles ivoiriens) : la preuve gratuite par message WhatsApp entrant, utilisée par Delivery App, viendra avec le numéro WhatsApp Business de la plateforme (phase 6).

### Phase 1 : catalogue et stock (3 semaines) ✅ *réalisée*
- [x] Produits avec jusqu'à 3 options (taille, couleur…) ; variantes générées à partir des valeurs, avec prix, prix barré, coût, SKU, code-barres, poids, photo et stock propres ; combinaisons non vendues décochables ; saisie groupée du prix et du stock.
- [x] Photos : envoi depuis le téléphone, redressées et converties en trois tailles WebP (400, 800, 1 600 px), ordre, photo par variante.
- [x] Collections (rayons de la boutique, ordre des produits), étiquettes, duplication d'un produit, statut brouillon / actif / archivé.
- [x] Stock par variante et par emplacement (boutique, entrepôt, domicile) : journal immuable, ajustements (correction, casse), réceptions fournisseur avec coût moyen pondéré, transferts (envoi / réception / annulation), inventaires (comptage enregistré au fil de l'eau, puis validation).
- [x] Réservation, libération et sortie de stock prêtes pour les commandes (phase 2).
- [x] Alertes : stock bas (seuil du produit ou de la boutique) et épuisé, dans le catalogue et l'écran Stock ; valeur du stock au coût.
- [x] Import / export CSV (point-virgule et UTF-8 pour Excel, une colonne de stock par emplacement), avec vérification avant import et erreurs par ligne ; les exports Shopify sont acceptés. Les photos ne sont pas importées.
- [x] Reprise du catalogue depuis Delivery App (`GET /products` avec la clé API du marchand, non conservée ; seuls les domaines Delivery App sont interrogés). Le stock déposé à l'entrepôt de l'entreprise sera suivi par la connexion transporteur (phase 4).
- [x] Limite de produits selon l'offre.
- Les alertes de stock bas par notification (push, WhatsApp) arriveront avec les notifications (phase 2) ; les collections automatiques et les prix par quantité restent en V1 (phase 7).

### Phase 2 : commandes et clients (3 semaines) ✅ *réalisée*
- [x] Saisie rapide : le message du client est collé et analysé (téléphones, nom, quartier, adresse, repère, montant, produits avec quantité et variante) ; le client déjà connu retrouve son adresse habituelle.
- [x] Commandes multicanales (WhatsApp, Facebook, Instagram, TikTok, téléphone, boutique) avec statuts commande / paiement / expédition séparés : à confirmer → confirmée → en préparation → expédiée → livrée, annulée (avec motif) ou retournée (remise en stock au choix).
- [x] File de confirmation : appel ou WhatsApp en un geste, réponses (confirmée, injoignable, à rappeler, modification, faux numéro, refus), relances planifiées ; l'agent de confirmation peut corriger une commande encore à confirmer.
- [x] Fichier clients : un client par numéro, adresses, compteurs (commandes, livrées, refus, montant dépensé), taux de livraison, étiquettes, liste noire ; score de risque (refus, liste noire, doublons de moins de 24 h).
- [x] Stock : réservation à la commande, sortie à l'expédition, libération à l'annulation, retour en stock ; paiements saisis (acompte, mobile money, espèces encaissées à la livraison).
- [x] Référentiel des lieux (communes et quartiers d'Abidjan, grandes villes, variantes d'écriture) et zones de livraison de la boutique (frais, livraison offerte, délai) ; une boutique peut ajouter un quartier manquant.
- [x] Facture et étiquette colis 10 × 15 cm en PDF (QR code de suivi), messages WhatsApp préremplis selon le statut, page de suivi publique pour le client.
- [x] Notifications dans la gestion (nouvelle commande, stock bas ou épuisé) et chiffres de l'accueil.
- Restent pour la V1 : expédition partielle, échanges et retours clients (RMA), notifications push sur téléphone (avec l'application installable).

### Phase 3 : boutique en ligne (4 semaines) ✅ *réalisée*
- [x] Rendu serveur Blade sur `{boutique}.jibiat-marchand.com/` (et domaine personnalisé vérifié), sans session : le visiteur n'a qu'un cookie de panier ; les écritures passent par des appels JSON de nos pages (en-tête `X-Requested-With`, limites de débit).
- [x] 2 thèmes (Classique, Marché) décrits dans `config/themes.php` ; réglages (couleurs, police, bandeau, accueil, collection mise en avant, sections, bouton WhatsApp), logo et image d'accueil ; pages libres (mise en forme simple) et menus en-tête / pied de page, avec menus automatiques par défaut.
- [x] Accueil, catalogue avec recherche et tri, collections, fiche produit avec sélecteur de variantes (combinaisons épuisées barrées, photo de la variante, stock restant), panier, commande en une page : quartier recherché dans le référentiel, frais et délai de la zone calculés aussitôt (hors zone : frais à confirmer), retrait en boutique, paiement à la livraison. La commande arrive dans la file « À confirmer » ; page de remerciement et lien de suivi.
- [x] « Commander sur WhatsApp » préremplit le produit, la variante, la quantité et le lien ; coordonnées gardées avec le panier dès la saisie (relance en phase 6) ; origine de la visite (utm, fbclid, ttclid) notée sur la commande.
- [x] Ouverture et fermeture par le marchand (page « ouvre bientôt » tant que la boutique est fermée), aperçu par lien signé valable une heure, liste de ce qui manque (produits, zones, WhatsApp, logo).
- [x] SEO : titre, description, adresse canonique, Open Graph, données structurées (Store, Product), `sitemap.xml` et `robots.txt` par boutique.
- [x] Performance mesurée (Chromium, téléphone, réseau « 3G rapide ») : fiche produit affichée en ~0,55 s, 48 Ko transférés dont 8 Ko de JavaScript (3 Ko compressé), feuille de style propre à la boutique (~6 Ko compressée), photos WebP en `srcset`.
- Restent pour la V1 : codes promo, avis clients, paiement en ligne (phase 6), éditeur de thème par glisser-déposer, domaine personnalisé en libre-service.
- **Jalon MVP vendable (~ 12,5 semaines)** : un marchand peut vendre en ligne et sur les réseaux, gérer stock et commandes, et livrer « à la main ».

### Phase 4 : connecteur Delivery App (3 semaines) + ajouts côté Delivery App (2 semaines, en parallèle) ✅ *réalisée*
*Placée avant les livreurs propres : les entreprises qui utilisent déjà Delivery App sont le premier canal de distribution (§12, suggestion 1).*
- [x] Expéditions avec statut normalisé (§5.4), `CarrierDriver`, `DeliveryAppDriver`, `ManualDriver` (transporteur sans API : course envoyée par WhatsApp, statut saisi à la main).
- [x] Connexion par clé (vérifiée par `GET /me`, portées contrôlées), copie des zones et correspondance automatique avec les lieux de la boutique (corrigeable), devis comparés, création (`Idempotency-Key`, `X-Client-App`) et annulation de course, adresse webhook créée par l'API, réception signée et dédoublonnée, synchronisation de secours toutes les 10 minutes (`shipping:sync`).
- [x] Effets sur la commande : en préparation à la demande, sortie de stock au ramassage, terminée et encaissement « transporteur » à la livraison, remise en stock au retour, retour à « Confirmée » si la course est refusée ou annulée ; alertes (refus, échec, perte, retour).
- [x] Stock en entrepôt transporteur (emplacement relié, produits rapprochés par SKU, écarts affichés, course préparée par l'entrepôt avec ses identifiants produits) ; rapprochement des reversements (`payout.paid` → détail par course, écarts) ; numéro, code et lien de suivi du transporteur sur la page de suivi du client.
- [x] Côté Delivery App (branche `claude/socle-et-api-marchand`) : §5.7 points 1 à 5 et 7 (`/me`, `/webhooks`, `/payouts`, bloc `pickup`, preuves photo en liens signés, `X-Client-App`), portées `webhooks:write` et `payouts:read`, contrat OpenAPI à jour.
- [x] Test de bout en bout sur une instance Delivery App locale : connexion, zones, devis, course, ramassage, livraison et reversement rapproché sans écart, avec de vrais webhooks signés.
- [x] Bascule de Delivery App sur `socle` (dépôt public `habibou84/socle`, branche `main` en 1.0.x) : nombres, téléphones, SMS et signature des webhooks viennent du même code dans les deux applications.
- [x] Synchronisation sans décalage : événement `order.updated` (date changée, décision attendue, retour demandé, sans changement de statut) suivi et signalé à l'équipe, adresse webhook recréée une fois pour les connexions plus anciennes, date de livraison prévue et date au plus tôt du transporteur (`GET /delivery-day`) dans les devis, rattrapage des reversements depuis le dernier reçu (`paid_since`), zones relues chaque nuit (`shipping:zones`), lien de la photo de preuve redemandé quand il a expiré, alertes de l'entreprise de livraison coupées pour ces courses (`notify_merchant: false`) : l'équipe n'est prévenue qu'une fois, par Jibiat Marchand.
- Fait : annuaire des entreprises de livraison et flux « Connecter » en un clic (§5.7 point 6, contrat 1.2 « directory » et « connect-token »), dans Gestion › Livraisons › Entreprises de livraison.
- Restent pour la V1 : expédition partielle, test de contrat automatisé dans la CI de Delivery App.

### Phase 5 : livreurs propres (3 semaines) ✅ *réalisée*
- [x] Livreurs ajoutés par la gestion (nom, téléphone, véhicule, zones couvertes, plafond d'espèces, règle de paie) ; lien d'invitation à envoyer par WhatsApp (compte créé avec code SMS) ; un membre de l'équipe qui livre aussi est relié sans invitation ; disponibilité ; désactivation ; limite de l'offre (1 livreur en gratuit, 5 en Pro).
- [x] Assignation depuis la fiche commande (mes livreurs qui couvrent le quartier et disponibles en premier, puis les transporteurs), réassignation, annulation ; même flux d'expédition que les transporteurs (statuts, sortie de stock, commande livrée ou retournée).
- [x] Application livreur installable sur `/livreur` (manifeste, fonctionne hors ligne pour l'affichage) : missions du jour, appel / WhatsApp / carte, « colis récupéré », « en route », livré (code du client si la boutique l'exige, photo, espèces ou mobile money, montant), échec avec motif et report, colis rapporté ; argent à verser et paie à recevoir. Un livreur n'a accès qu'à son application.
- [x] Caisse (gérant ou caissier) : espèces détenues par livreur, alerte au-delà du plafond, versement avec écart (manque ou excédent) ; rémunération par livraison ou par zone, tentative échouée, primes, avances, relevés payés ou annulés.
- Restent pour la V1 : position du livreur sur la carte et suivi en direct par le client, tournées, notifications push sur le téléphone du livreur.
- **Jalon V1 livraison (~ 18,5 semaines)** : livreurs propres et transporteurs connectés dans le même flux.

### Phase 6 : paiements en ligne et WhatsApp (4 semaines) ✅ *réalisée*
- [x] `PaymentGateway` + pilotes **Wave** (sessions de paiement), **CinetPay** (v2) et **PayDunya** (factures) ; comptes du marchand (identifiants chiffrés, mode test / réel, frais), routage par moyen de paiement (Wave, Orange Money, MTN, Moov, carte) avec prestataires de secours essayés dans l'ordre (§6).
- [x] Paiement sur la boutique : désactivé, au choix du client, acompte exigé (% arrondi aux 5 F) ou tout en ligne ; paiement depuis la page de remerciement ; **liens de paiement** (7 jours, un seul ouvert par commande) envoyés par le WhatsApp de la boutique ou par lien wa.me ; un paiement réussi confirme la commande en attente.
- [x] Webhooks vérifiés (signature Wave, jeton CinetPay, clé PayDunya) et dédoublonnés ; une notification ne valide jamais un paiement seule : statut relu par l'API du prestataire, montant contrôlé. Remboursements par le prestataire quand il le permet, sinon notés comme rendus à la main.
- [x] Simulateur de frais (volume réel des 3 derniers mois par moyen, coût par prestataire, « choisir la combinaison la moins chère ») et rapport des frais réels par prestataire.
- [x] WhatsApp Cloud API au nom du marchand : modèles approuvés par événement (demande de confirmation avec boutons Confirmer / Annuler appliqués à la commande, expédition, livraison, lien de paiement, relance de panier), webhooks signés, statuts envoyé / reçu / lu ; paniers abandonnés relancés automatiquement après 1 h (`carts:recover`) ou à la main depuis la gestion.
- Avant la mise en production : valider chaque pilote sur le bac à sable du prestataire avec de vrais comptes (Wave Business, CinetPay, PayDunya) et faire approuver les modèles chez Meta.
- Restent pour la V1 : remboursement partiel Wave (l'API ne rembourse que le total), textes de messages modifiables par le marchand (`message_templates`), inscription WhatsApp intégrée (Embedded Signup de Meta) au lieu de la saisie des identifiants.

### Phase 7 : croissance (3 semaines) ✅ *réalisée*
- [x] Moteur de prix commun à la boutique et à la saisie des commandes : **ventes flash** (remise automatique datée, prix barré et compte à rebours), **prix par quantité** (« 3 pour 25 000 F », variantes confondues ; le plus avantageux des deux pour le client, sans cumul), **codes promo** (pourcentage, montant ou livraison offerte ; produits ou collections visés, achat minimum, limites globale et par client, dates), **livraison offerte dès X F** (barre « Plus que … » au panier). Utilisations comptées par commande, libérées à l'annulation ; ventes et remises par promotion.
- [x] Pixels Meta et TikTok, Google Analytics 4 (vue produit, ajout au panier, début de commande, achat) et **API Conversions de Meta** : l'achat est aussi envoyé par le serveur (données hachées, cookies `_fbp` / `_fbc`, `fbclid`), avec le même identifiant que le pixel pour le dédoublonnage.
- [x] **Pixel et catalogue Meta en un clic** (Marketing › Suivi) : connexion Facebook (`business_management`, `catalog_management`, `ads_management`, ou configuration `META_ADS_CONFIG_ID` avec jeton d'utilisateur système), choix du compte Business, pixel et catalogue existants ou créés, lien du catalogue ajouté chez Meta (toutes les heures), pixel relié au catalogue, accès gardé pour l'API Conversions (le jeton collé à la main reste prioritaire). Articles désignés pareil dans le pixel et le catalogue (`CatalogFeed::itemId`).
- [x] **Comparateur** à l'expédition (paie du livreur ou devis du transporteur, taux de réussite et délai moyen sur 90 jours, dans la zone si l'historique suffit) et **règles d'affectation** ordonnées (zones, fragile, montant, poids → mes livreurs, un transporteur, le moins cher, le plus rapide, à la main) ; mode désactivé, suggestion ou **automatique** dès la confirmation (échec noté au journal).
- [x] **Statistiques avancées** (offre Pro) : vue d'ensemble, marge nette des commandes livrées (produits − remises + frais facturés − coût des livraisons), par canal, produit / variante, zone, livreur, transporteur et promotion ; **rapport du jour** par WhatsApp (modèle Meta) ou email, à l'heure choisie par chaque membre (`reports:send`).
- [x] **Partage de produit** (suggestion 3) : lien court `/p/{code}` (visites comptées, origine notée) et image carrée 1080 × 1080 générée (photo, nom, prix, promotion, boutique).
- Restent pour la V1 : offres « 1 acheté = 1 offert » (`buy_x_get_y`), tableaux `store_daily_stats` précalculés quand les volumes l'exigeront, événements TikTok côté serveur, dépenses (publicité, emballage) dans la marge.

### Phase 8 : SaaS et lancement (3 semaines) ✅ *réalisée pour la partie logicielle*
- [x] **Abonnements** (§6.5) : offres Gratuit, Starter, Pro, Business (prix indicatifs 0 / 5 000 / 15 000 / 35 000 F par mois, deux mois offerts à l'année, modifiables dans la console) ; essai de 14 jours de l'offre Pro à la création ; paiement mensuel ou annuel sur le compte de la plateforme (mêmes pilotes Wave, CinetPay, PayDunya), validé par relecture chez le prestataire ; factures PDF ; rappels avant échéance, retard avec 7 jours de grâce, puis **retour à l'offre gratuite sans rien supprimer** (`billing:run`).
- [x] **Quotas** de l'offre : commandes par mois (la boutique en ligne affiche un message neutre au client, l'équipe est prévenue), produits, membres, livreurs, transporteurs, comptes de paiement ; fonctions réservées (domaine, WhatsApp automatique, statistiques avancées). Page Abonnement avec l'utilisation de chaque limite.
- [x] **Domaines personnalisés** (§4.4) : ajout (3 au plus, offre Pro), vérification DNS (TXT de propriété + CNAME ou A), domaine principal (adresse canonique), contrôle chaque nuit (`domains:check`) ; certificats à la demande par Caddy, autorisés par `/_caddy/ask` pour les seuls domaines vérifiés.
- [x] **Console** `admin.jibiat-marchand.com` : connexion par mot de passe **et code SMS**, tableau de bord (boutiques, actives, revenu mensuel récurrent, ventes, essais qui se terminent, créations par jour), boutiques (recherche, filtres, fiche, suspension / réactivation, essai prolongé, mois offerts), offres (prix, limites, fonctions), factures ; journal des actions.
- [x] **Sessions de support** : motif, durée limitée (60 min par défaut), rôle « support » sans abonnement, équipe ni comptes de paiement ; le propriétaire est prévenu, voit un bandeau et peut y mettre fin ; fin automatique (`support:expire`). Bouton **« Aide »** (WhatsApp du support) dans la gestion (suggestion 9).
- [x] **Pages légales** provisoires (`/cgu`, `/confidentialite`), acceptation à la création de boutique ; **supervision** : Sentry, sonde `/up` qui vérifie la base de données ; guide de mise en production, de déclaration ARTCI et de pilote (`docs/LANCEMENT.md`).
- À faire hors logiciel : relecture juridique des pages légales et identité de l'éditeur, déclaration ARTCI, comptes de paiement de la plateforme, pilote avec 10 marchands (`docs/LANCEMENT.md`).
- Reste : flux « Connecter » en un clic et annuaire (§5.7 point 6), qui dépend de Delivery App (branche `claude/socle-et-api-marchand` à terminer et déployer) ; export complet des données d'une boutique et fermeture en libre-service.

**Total : environ 6,5 mois** jusqu'au lancement commercial ; MVP vendable vers la **13e semaine**.

### Après le lancement (V2)
Caisse pour boutique physique, catalogue Meta / WhatsApp, prise de commande par conversation WhatsApp, fidélité, multi-devise et multilingue, éditeur de thème, autres connecteurs transporteurs, application native (Capacitor).

### Interface marchand (refonte)
- **Design unifié** : un seul jeu d'icônes au trait (`components/Icon.vue`, pastilles de réseau `NetworkMark.vue`) à la place des émojis ; petits textes agrandis et gris clair plus foncé (contraste) ; une seule action principale par écran, les autres discrètes, les gestes délicats (modifier, rembourser, retour, annuler) dans « Plus ». Contenu plus large sur ordinateur, onglets qui défilent avec un fondu et amènent l'onglet ouvert à l'écran (`lib/tabStrip.js`), heures du rapport en 24 h.
- **Téléphone** : barre du bas (Accueil, Commandes, gros bouton Nouvelle commande, Messages avec pastille, Plus qui ouvre le menu) ; filtres des commandes dans un panneau ; action principale de la commande fixée en bas ; stock en cartes avec « Ajuster » toujours visible ; filtres produits sur deux colonnes.
- **Accueil en tableau de bord** : ventes et commandes du jour comparées à hier à la même heure (flèche verte ou rouge), ventes des 7 derniers jours (barres, détail au survol ou au toucher), liste « À faire maintenant » (à confirmer, à faire livrer, messages non lus, épuisés), « Pour bien démarrer » repliable et masquable.
- **Formulaire de commande** : trois étapes (client et livraison, articles, paiement) ; canal en pastilles, client connu résumé, second numéro et notes repliés ; lignes d'articles avec stock en couleur, quantité − / +, total de ligne ; une seule entrée « Ajouter une remise » (code, montant, points) ; total et bouton fixés en bas sur téléphone, le bouton dit ce qui manque.
- **Qui livre, dès la saisie** : livreur de la boutique ou entreprise de livraison (`orders.planned_courier_id`, `planned_carrier_connection_id`). Pour le quartier choisi, `POST /orders/delivery-options` donne les livreurs qui le couvrent et, pour chaque entreprise, sa zone et son prix (devis de l'API, ou grille saisie par le marchand pour une entreprise sans API : `carrier_zones.fee`, écran « Zones et tarifs »). Les frais enregistrés sont ceux affichés ; l'expédition est créée dès la confirmation (`DeliveryAdvisor::autoAssign`, avant les règles automatiques).
- **Formulaire de commande, v2** : grands libellés et champs (`label-lg`, `input-lg`) ; second numéro retirable ; « Qui livre ? » dans une fenêtre avec recherche, filtres, récemment utilisés, prix par entreprise et « le moins cher » (`DelivererPicker`) ; quartier choisi après, limité aux zones de l'entreprise choisie et rangé par zone avec son prix (`GET /orders/delivery-options/places`, `DeliveryPlacePicker`) ; adresse précise et point de repère en un seul champ ; « Reprendre l'adresse » du client connu ; téléphone mis en forme pendant la frappe.

---

## 9. Modèle économique

| Offre | Pour qui | Contenu indicatif |
|---|---|---|
| **Gratuit** | Démarrer | 30 commandes / mois, 20 produits, sous-domaine, 1 livreur |
| **Starter** | Petit vendeur réseaux sociaux | Commandes illimitées, 3 membres, 5 livreurs, 1 transporteur connecté, paiement en ligne (1 compte) |
| **Pro** | Boutique établie | Domaine personnalisé, WhatsApp API, transporteurs et comptes de paiement illimités, statistiques avancées |
| **Business** | Plusieurs boutiques / entrepôts | Jusqu'à 3 boutiques sous un seul abonnement, API marchand (bientôt), support prioritaire |

Règles des offres :
- **Plusieurs boutiques** (« Mes boutiques », réservé au propriétaire) : créer une autre boutique depuis la gestion avec le même compte, voir les chiffres du jour de toutes ses boutiques et passer de l'une à l'autre. Avec l'offre Business, les boutiques rattachées (même propriétaire, 3 au plus avec la principale) ont l'offre de la boutique principale sans paiement séparé ; leur propre essai s'arrête et un abonnement payé en cours n'est plus renouvelé. Si l'abonnement Business s'arrête, chaque boutique reprend sa propre offre.
- **Une seule adresse pour plusieurs boutiques** (offre Business) : les boutiques créées depuis « Mes boutiques » (ou rattachées à l'abonnement commun) n'ont pas d'adresse propre. Elles sont hébergées à l'adresse de la boutique principale (`stores.host_store_id`, `stores.path`) :
  - *gestion* : même adresse et même session ; un sélecteur en haut de l'écran choisit la boutique (en-tête `X-Store-Id`, mémorisé sur l'appareil ; lien `?boutique=ID` depuis une notification) ; « Toutes les boutiques » ouvre les chiffres communs ; une personne qui ne travaille que pour une boutique hébergée se connecte à l'adresse et arrive sur elle ;
  - *boutique en ligne* : page `{adresse}/{nom}` (ex. `ballstyle.jibiat-marchand.com/kids`) servie comme une boutique à part (liens et redirections préfixés, panier propre) ; la boutique principale reste à la racine ; le nom de la page se change dans Réglages › Ma boutique ;
  - une boutique rattachée quitte son ancienne adresse, qui redirige vers sa page ; retirée de l'abonnement commun, elle retrouve sa propre adresse ; un nom de domaine s'ajoute à la boutique principale et sert aussi ses pages.
- **Équipe sur plusieurs boutiques** : l'invitation propose les autres boutiques de l'adresse (celles dont on gère l'équipe), avec le même rôle ou un rôle par boutique ; un seul lien, la personne rejoint toutes les boutiques cochées (`store_invitations.extra_stores`). La page Équipe montre les boutiques de chacun ; le bouton « Boutiques » ajoute, change le rôle ou retire une boutique sans nouvelle invitation (jamais le propriétaire, jamais le rôle de propriétaire). Un livreur ajouté peut travailler aussi pour d'autres boutiques (partagé tout de suite s'il a un compte, sinon en acceptant l'invitation).
- **Espace commun aux boutiques** (« Mes boutiques », propriétaire, abonnement Business) :
  - *Statistiques* de la période pour l'ensemble et boutique par boutique (taux recalculés sur l'ensemble, bénéfice), chiffre d'affaires livré jour par jour, produits les plus vendus toutes boutiques confondues.
  - *Caisse* : argent chez les livreurs, les entreprises de livraison et les clients à crédit, paie à verser, entrées et sorties de la période, par boutique et au total.
  - *Rapport du soir* : option « un seul rapport pour mes boutiques » (une ligne par boutique, puis le total).
  - *Livreurs partagés* : un livreur qui a activé son compte peut travailler pour plusieurs boutiques. Chaque boutique garde sa fiche (zones, règle de paie recopiée sans les montants par zone, plafond, missions, encaissements, gains) ; son application réunit ses missions, ses espèces et ses gains de toutes ces boutiques, et chaque étape d'une mission est enregistrée dans sa boutique. Un versement unique est réparti au prorata des espèces détenues pour chaque boutique (manquant compris, excédent sur la dernière) ; la paie se règle en une fois (un relevé payé par boutique).
- **Catalogue, stock et clients entre les boutiques de l'adresse** (selon les droits de chacun dans chaque boutique) :
  - *Copie d'un produit* (« Dans vos autres boutiques » sur la fiche) : photos copiées (indépendantes), options, variantes, prix, SKU encore libres, collections de même nom, étiquettes ; stock à zéro. La copie garde le produit et les variantes d'origine (`products.origin_product_id`, `product_variants.origin_variant_id`) : c'est le lien qui retrouve le même article d'une boutique à l'autre (à défaut, le même SKU).
  - *Transfert de stock* (Stock › Transferts › « Entre vos boutiques ») : envoi immédiat, sortie d'un côté et entrée de l'autre au coût d'achat d'origine, tracé dans les mouvements des deux boutiques et dans `store_transfers`.
  - *Fichier clients commun* : à la saisie d'une commande, un numéro déjà client d'une autre boutique remplit le nom et l'adresse et affiche son historique (commandes, livrées, refusées, liste noire) ; la fiche client montre ses achats dans les autres boutiques. Chaque boutique garde sa propre fiche client.
- **Avis clients** : après la livraison, le client reçoit un lien (`/avis/{jeton de suivi}`) pour noter chaque produit reçu (1 à 5 étoiles, commentaire) ; message WhatsApp automatique 24 h après la livraison (modèle `jm_demande_avis`) ou bouton « Demander un avis » sur la commande. Un avis renvoyé remplace le précédent. Rien n'apparaît avant que le marchand le publie (Marketing › Avis clients) ; la fiche produit affiche la note moyenne, les derniers avis (prénom et initiale) et la note pour Google.
- **Trois conseils du soir** (sans IA : gratuit, chaque jour, rien d'inventé) : des règles simples sur les vrais chiffres proposent chacune au plus un conseil avec une importance, les trois plus importants sont retenus — article qui va manquer (ventes de 7 jours rapportées au stock disponible), commandes à confirmer depuis plus d'un jour, zone ou livreur où beaucoup de colis reviennent (30 jours, au moins 5 livraisons terminées), argent des entreprises de livraison en retard, baisse ou hausse des ventes livrées, article qui dort depuis un mois. Ils s'ajoutent au rapport du soir d'une boutique (e-mail ; sur WhatsApp, un second message avec le modèle `jm_conseils_du_soir`, automatisation « Conseils du soir ») et s'affichent sur l'Accueil pour les membres qui voient les finances.
- **Vrai bénéfice** : une dépense de publicité indique le canal visé (Facebook, Instagram, TikTok, WhatsApp, site) ; les statistiques par canal donnent le coût de la publicité par commande livrée et la marge qui reste une fois la publicité payée, et le bénéfice réel se lit aussi par commande livrée. Une dépense payée autrement qu'en espèces peut être datée dans les douze derniers mois. Dépenses mensuelles automatiques (loyer, salaires, internet…, jamais en espèces) : notées le jour choisi par `expenses:recurring` (toutes les heures). Le rapport du soir donne le bénéfice depuis le 1er du mois aux membres qui voient les finances, et un conseil signale une publicité qui coûte plus qu'elle ne rapporte.
- **Relances WhatsApp** (Marketing › Relances WhatsApp) : message aux clients qui n'ont pas commandé depuis N jours, aux acheteurs d'un produit livré, ou à tous, avec le modèle marketing `jm_nouveautes` (nom du client, boutique, message sur une ligne, lien ; « Répondez STOP »). Ne sont visés que les clients qui ont accepté les offres (case à la commande sur la boutique, ou fiche client) — ou, si le marchand s'y engage, ceux déjà livrés — sans liste noire, sans réponse STOP (traitée à la réception), et pas relancés depuis 7 jours ; 5 000 au plus, un message toutes les 2 secondes. Le nombre de clients et le coût Meta estimé (`WHATSAPP_MARKETING_PRICE`, 15 F par défaut) s'affichent avant l'envoi ; ensuite : messages remis et lus, commandes et montants des clients relancés dans les 7 jours.
- **Paiement à la porte** (application du livreur) : si la boutique a un compte de paiement en ligne, le livreur ouvre « Paiement mobile money » au moment de livrer : lien de paiement de la commande (valable 24 h, remplace le lien ouvert) affiché en QR code à scanner, ou envoyé par WhatsApp ou SMS. L'écran attend la confirmation du prestataire (mise à jour toutes les 4 s) ; la commande payée, il n'y a plus rien à encaisser : la livraison se confirme sans espèces ni encaissement chez le livreur. La mission indique aussi « Déjà payé » quand le client a payé avant.
- **Retours et échanges** (commande livrée › « Retour / échange », liste dans Commandes › Retours) : articles rendus avec leur valeur (prix payé, remise répartie) ; un échange (autre taille…) crée une commande d'échange déjà confirmée pour les articles de remplacement, avec une remise égale à la valeur rendue — le client paie la différence et les frais choisis, et le livreur voit « reprenez au client » sur sa mission. À la réception des articles rendus : remise en stock (sauf articles abîmés) et remboursement de ce qui reste dû (droit « paiements »). Annuler un échange annule sa commande tant qu'elle n'est pas en route.
- **Fournisseurs et bons de commande** (Stock › Commandes fournisseurs) : fiche fournisseur (nom, WhatsApp, note) ; bon de commande BC-n avec les articles, quantités et prix d'achat, et un bouton « Ajouter les articles à recommander » (de quoi tenir 30 jours au rythme des ventes des 30 derniers jours, au moins deux fois le seuil de stock bas). Envoi au fournisseur par WhatsApp (texte prêt), réception en une ou plusieurs fois (chaque réception entre en stock et met à jour le coût moyen), paiement noté comme dépense « Achat de marchandises » (en espèces, il sort de la caisse).
- **Fidélité et parrainage** (Marketing › Fidélité ; tout se suit par le numéro du client) : points gagnés quand une commande est livrée (N points par 1 000 F d'articles, hors livraison), utilisés en réduction (valeur d'un point, minimum) sur une commande saisie par l'équipe ; rendus si la commande est annulée, retirés si elle est retournée ; ajustement à la main sur la fiche client. Parrainage : code du client (4 lettres de son nom + 4 caractères) affiché avec un lien de partage WhatsApp sur sa page de suivi ; à sa première commande (boutique — lien `?parrain=CODE` mémorisé sur le téléphone — ou saisie), le filleul a une réduction et le parrain gagne des points quand elle est livrée. Les réductions apparaissent sur la commande, la facture et les reçus, et sont déduites de la marge.
- **Catalogue Facebook, Instagram et WhatsApp** (Marketing › Suivi publicitaire) : lien secret `{boutique}/flux/catalogue/{jeton}.csv` au format de Meta (aussi lu par Google Merchant) — une ligne par variante en vente avec photo : identifiant (SKU, sinon V+n°), groupe de variantes, titre, description sans balises, disponibilité et quantité, prix (prix barré ou vente flash en `sale_price`), lien, photos, marque (sinon le nom de la boutique), taille et couleur d'après le nom des options. À coller dans Commerce Manager (source de données « URL », mise à jour toutes les heures) : prix et stock restent à jour sans ressaisie. Le lien peut être renouvelé (l'ancien cesse de marcher).
- **Lots et précommandes** (fiche produit) : un lot (« robe + sac ») est un produit sans variantes qui contient d'autres articles ; il n'a pas de stock à lui : le vendre réserve puis sort chaque article (contenu noté sur la ligne de commande), l'annuler les libère, le retourner les remet en stock ; disponible autant de fois que l'article le plus juste ; prix d'achat = somme de ses articles ; la boutique affiche son contenu et l'économie. Précommande : l'article se vend sans stock (« Précommander », date d'expédition prévue), la commande est marquée « Précommande » et, si un pourcentage est choisi, un acompte est demandé — payé en ligne à la commande quand la boutique a un compte de paiement.
- **Textes des messages modifiables** (Réglages › Textes des messages) : le marchand adapte les messages envoyés depuis son WhatsApp (demande de confirmation, commande confirmée, rappel de paiement, en route, remerciement, demande d'avis) et la demande de confirmation automatique dans la conversation, avec aperçu sur sa dernière commande. Mots remplacés : {prenom}, {boutique}, {commande}, {articles}, {total}, {reste}, {adresse}, {suivi}, {lien_avis} ; une ligne dont le mot est vide (ex. {reste} quand tout est payé) disparaît ; un mot inconnu est refusé. Seuls les textes modifiés sont gardés (`stores.message_texts`). Les modèles Meta, soumis à approbation, ne se modifient pas ici.
- **Vos données : export et fermeture** (Réglages › Informations de la boutique, propriétaire seulement) : export complet en arrière-plan — archive ZIP de fichiers CSV (« ; », UTF-8 avec BOM, pour Excel) : produits et stock, clients, commandes, articles, paiements, livraisons, dépenses et caisse, fournisseurs, avis ; notification quand c'est prêt, téléchargement pendant 7 jours (`exports:purge` chaque nuit), un export à la fois et trois par jour au plus. Fermeture : nom de la boutique à retaper, refusée tant qu'une commande est en route ; boutique et gestion fermées (statut « Fermée »), boutique en ligne désactivée, abonnement arrêté à la fin de la période payée, rapports arrêtés, dépenses mensuelles suspendues ; données conservées, réouverture par le support (console › réactiver).
- **Client prévenu quand le livreur part** : à l'étape « Je pars livrer », le client reçoit un message WhatsApp de la boutique (automatisation « Livreur en route » à activer) : nom et numéro du livreur, montant à payer, boutons « J'attends » / « Pas disponible » — dans la conversation (texte modifiable, gratuit) si le client a écrit depuis moins de 24 h, sinon modèle `jm_livreur_en_route`. « Pas disponible » prévient aussitôt le livreur (notification, alerte sur la mission et la liste) et l'équipe ; la réponse est notée sur la livraison et dans l'historique de la commande. Sans WhatsApp de la boutique, le livreur a un bouton « Prévenir le client que j'arrive » (message prérempli depuis son propre WhatsApp).
- **Application livreur sans réseau** : les missions en cours (avec tout leur détail : client, adresse, articles, montant), l'argent et le profil restent sur le téléphone, et l'application s'ouvre sans réseau. Les étapes faites hors connexion (colis récupéré, en route, livré avec photo et encaissement, pas livré) attendent dans une file sur le téléphone et partent toutes seules au retour du réseau, dans l'ordre, avec leur heure réelle (heure de livraison et d'encaissement justes ; jamais dans le futur ni plus de trois jours en arrière). Un envoi répété (réponse perdue) n'est pas refait. Le code du client est vérifié à l'envoi ; une étape refusée (code faux, mission retirée) s'affiche avec son motif et bloque les suivantes de la même mission. Le message « livreur en route » ne part pas s'il arriverait plus de quinze minutes trop tard ; le paiement à la porte attend le réseau. Les photos sont réduites avant envoi. À la sortie, tout ce que gardait le téléphone est effacé.
- **Tournée du jour** : l'accueil de l'application livreur montre les arrêts du jour numérotés et regroupés par commune (ordre automatique par commune et quartier ; avec la position du téléphone, les adresses ayant un point GPS sont rangées de proche en proche). Le livreur change l'ordre (↑ ↓, gardé sur le téléphone et envoyé à la boutique), ouvre l'itinéraire Google Maps des dix premiers arrêts, et déclare « J'ai récupéré les N colis » en une fois (marche aussi sans réseau). Les missions reportées à un autre jour sont à part ; une mission confiée à un autre livreur perd sa place. Réglages des livraisons (écran Mes livreurs) : code du client à la livraison, et position des livreurs pendant la tournée (désactivée par défaut ; dernière position seulement, envoyée toutes les deux minutes au plus quand le livreur est disponible et l'application ouverte ; le livreur en est informé ; effacée quand on désactive).
- **Versement déclaré par le livreur** : dans « Mon argent », le livreur déclare ce qu'il a versé à la boutique (espèces remises à la caisse, ou envoi Wave, Orange Money, MTN, Moov avec la référence obligatoire), avec la photo du reçu s'il veut ; s'il livre pour plusieurs boutiques, il choisit laquelle. Un seul versement en attente à la fois. L'équipe qui gère les livreurs est prévenue (notification et push) et confirme dans Caisse › Argent des livreurs en indiquant le montant réellement reçu : le versement est enregistré comme un versement reçu à la caisse (toutes les espèces détenues sont soldées, l'écart manque ou excédent est gardé), ou refusé avec un motif. Le livreur est prévenu de la réponse et voit l'historique de ses déclarations.
- **Missions en temps réel avec acceptation** : une mission confiée à un livreur arrive par notification et s'affiche en tête de son application (« Nouvelle mission », deux bips et une vibration ; l'application ouverte vérifie toutes les 20 secondes). Le livreur l'accepte, ou la refuse avant le ramassage avec un motif (trop loin, trop de colis…). Récupérer le colis vaut acceptation. La boutique voit sur la livraison si la mission est vue et acceptée. Refusée, la mission est annulée (la commande revient à « Confirmée »), l'équipe est prévenue avec le motif, et en affectation automatique elle part aussitôt vers un autre livreur — jamais vers celui qui l'a refusée (le comparateur ne le propose plus pour cette commande). Sans réponse au bout de quinze minutes, l'équipe reçoit un rappel et le livreur est relancé, une seule fois (commande `couriers:remind`, toutes les cinq minutes).
- **Application livreur, confort de conduite** : menu du bas avec pastilles (nombre de nouvelles missions sur « Ma tournée », point orange sur « Ma caisse » si le plafond d'espèces est dépassé ou si la boutique a répondu à un versement) ; action suivante en gros bouton fixé en bas de la livraison ; bouton d'appel du client sur chaque arrêt de la tournée ; code du client en quatre grandes cases (le curseur avance seul, vibration et cases vidées si le code est faux) ; « Ma caisse » : un gros chiffre « à verser » avec la jauge du plafond, « Ma journée » (livrées, échecs, encaissé, versé, paie du jour) à envoyer à la boutique par WhatsApp, onglets À verser / Mes versements / Ma paie ; Mon compte › Affichage : mode nuit et grands caractères (gardés sur le téléphone).
- **Réseaux sociaux — publier en un geste** (Marketing › Réseaux sociaux) : la boutique connecte sa page Facebook et le compte Instagram professionnel lié (une seule fenêtre Facebook, choix de la page), et son compte TikTok. Les fenêtres reviennent sur le domaine de la plateforme (`/reseaux/meta/retour`, `/reseaux/tiktok/retour`, seules adresses déclarées chez Meta et TikTok) avec un « state » chiffré qui désigne la boutique. Sur la fiche produit, « Publier sur les réseaux » : réseaux cochés, trois textes proposés par l'assistant IA (français simple ou nouchi léger, vrai prix, mots-clés), texte modifiable avec {lien}, publication immédiate (file d'attente) ou programmée (commande `social:publish`, chaque minute). L'application refait deux visuels avec la photo, le prix du moment et la boutique : carré 1080 × 1080 (Facebook, Instagram) et vertical 1080 × 1920 (TikTok, en photo). Un réseau en échec n'empêche pas les autres ; « Réessayer » ne republie que les réseaux en échec. TikTok : publication directe en photos (confidentialité permise par le compte ; domaine de la plateforme à vérifier chez TikTok pour les images).
- **Réseaux sociaux — ce que rapporte chaque réseau** : chaque publication a un lien court par réseau (`/p/{code}` → `utm_source={réseau}&utm_medium=social&utm_campaign=post-{id}`). Par réseau et par publication : clics, commandes et chiffre d'affaires sur 7, 30 ou 90 jours ; sont comptées aussi les commandes venues des publicités (fbclid → Facebook, ttclid → TikTok) et celles saisies avec le canal Facebook, Instagram ou TikTok.
- **Messages Messenger et Instagram dans la boîte Messages** : la page connectée est abonnée aux messages ; Meta envoie les messages de la page et d'Instagram à la même adresse que WhatsApp (`/webhooks/whatsapp`, objets `page` et `instagram`, signature de l'application). Un fil par client (nom récupéré chez Meta), réponse depuis la boîte par la page (24 h après le dernier message du client, comme WhatsApp), réponses proposées par l'IA, « Créer la commande » avec le canal Facebook ou Instagram (le numéro vient des messages du client).
- **Console › Assistant IA** : coût estimé par boutique et par mois (prix public de chaque modèle, `AI_USD_RATE` francs CFA pour un dollar), quota de l'offre, part du prix de l'abonnement.
- **Changement d'offre** : la valeur des jours payés non utilisés de l'offre précédente est convertie en jours supplémentaires sur la nouvelle offre (au prix du jour de la nouvelle offre).
- **Limite de commandes** : l'équipe est prévenue à 80 % de la limite, puis quand elle est atteinte (une fois par mois chaque fois).
- **Fonctions annoncées « bientôt »** (API marchand) : affichées comme telles dans les offres tant qu'elles ne sont pas disponibles.
- **Assistant IA** (Claude, d'Anthropic, par le SDK PHP officiel ; activé par `ANTHROPIC_API_KEY`, modèle `AI_MODEL`) : payé à part par recharges (`stores.ai_credits`, `platform.ai_pack`) : aucune offre n'en comprend (`limits.ai_actions_per_month` à 0, réglable dans la console ; une offre sans cette clé n'en a pas non plus), chaque action notée dans `ai_usages` (jetons ; un échec n'est pas compté). Le marchand relit et valide toujours ; seules les données utiles sont envoyées (photos, conversation, catalogue de la boutique, zones de livraison).
- **Accueil des nouveaux commerçants** (`MerchantOnboarding`, tâche `onboarding:run` toutes les 5 minutes) : par le WhatsApp du support, bienvenue (variantes carte Membre Premium et invitation d'une entreprise de livraison), premier produit (3 h, sans produit), vente en live (20 h, avec produits) dans les 24 h qui suivent le message de vérification ; fin de l'essai 3 jours avant (modèle Meta hors des 24 h, texte libre par QR code). Rien entre 21 h et 7 h, « STOP » / « START », textes et arrêt dans la console › WhatsApp.
- **Rattrapage des commerçants déjà inscrits** (`MerchantCatchUp`, lancé depuis la console › WhatsApp, envoyé par `onboarding:run`) : un message par commerçant inscrit depuis plus de 3 jours jamais accueilli, selon sa boutique (sans produit, sans commande, qui vend) ; modèles marketing créés chez Meta par l'application, au plus 100 par lot et 200 par jour, rien entre 21 h et 7 h, pause possible.
- **Clés d'accès** (console › Clés d'accès, `AccessKeys`) : lots de cartes à QR code imprimées en PDF (10 par A4). « Membre fondateur » : offre à vie, carte numérotée, en pause après 60 jours sans commande (prévenu 15 jours avant), rallumée à la commande suivante ; « durée » : l'offre pendant N mois (prévenu 7 jours avant la fin). Activation une seule fois, par la page `/cle/CODE` (inscription ou connexion) ou dans Réglages › Abonnement ; une carte fondateur peut remplacer une clé à durée, une seule clé à durée par boutique. L'offre donnée passe par `FreeAccess` (la plus élevée entre gratuit à vie, clé et gratuité générale) ; un abonnement payé plus élevé est gardé.
  - *Fiche produit depuis une photo* (nouveau produit) : nom, description, options visibles, étiquettes, collections existantes, référencement ; jamais de prix.
  - *Commande depuis une conversation* (saisie rapide) : texte mal écrit, plusieurs messages ou captures d'écran ; articles rapprochés du catalogue par leurs codes (un code inconnu est ignoré et signalé), quartier par la recherche de lieux, même résultat que l'analyse par règles.
  - *Question sur les chiffres* (écran Statistiques, offres avec statistiques avancées, droit « finances ») : l'assistant répond en quelques phrases à partir de deux outils en lecture sur la boutique — le rapport des ventes d'une période (celui de l'écran Statistiques) et l'état du stock ; il n'invente aucun chiffre. Chaque outil a au moins un paramètre obligatoire (le SDK PHP enverrait un objet vide comme une liste).
  - *Réponse WhatsApp proposée* (boîte Messages) : avec les vrais prix, la disponibilité, les frais et délais de livraison et l'état de la commande liée ; à vérifier signalé ; jamais envoyée sans le marchand.

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
   - ✅ un **programme partenaire** : l'entreprise reçoit 20 % de l'abonnement des marchands qu'elle fait inscrire, pendant 12 mois à partir du premier paiement (console › Parrainage ; elle suit tout dans Jibiat, Réglages › E-commerçants) ; elle est leur entreprise attitrée (en premier dans l'annuaire et dans « Qui livre ? ») et la plateforme ne leur propose pas d'autres entreprises ;
   - ✅ dans la mini-boutique de Jibiat (application marchand, Ma boutique), une invitation **« Découvrir Jibiat Marchand »** avec le lien de parrainage de l'entreprise ; la connexion se fait ensuite en un clic depuis l'annuaire, et le catalogue s'importe depuis l'entreprise.
2. ✅ **Création de boutique en 5 minutes depuis un téléphone** (phase 0). La plupart des marchands n'ont pas d'ordinateur. Le back-office `/gestion` doit être conçu d'abord pour le téléphone et installable en PWA, comme les applications de Delivery App.
3. ✅ **Partage de produit prêt pour les réseaux sociaux** (phase 7) : pour chaque produit, un lien court (`{boutique}.jibiat-marchand.com/p/abc`) et une **image carrée générée** (photo, prix, nom de la boutique), prête à publier en statut WhatsApp, sur Facebook ou TikTok. C'est la façon dont ces marchands vendent déjà.
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
