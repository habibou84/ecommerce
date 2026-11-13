# Meta : pixel, catalogue et Boost

Configuration chez Meta pour « Connecter mon Facebook » (Boost, Marketing › Suivi) : choix du compte Business, du pixel
et du catalogue, flux du catalogue relu par Meta toutes les heures, pixel relié au catalogue, accès gardé pour l'API
Conversions. Code : `app/Services/Marketing/MetaAds.php` et `CatalogFeed.php`.

L'application Meta est celle de la plateforme (`META_APP_ID`) : voir `docs/META-WHATSAPP.md` § 2 pour sa création.
Les noms de menus Meta changent souvent : ils peuvent différer un peu à l'écran.

## 1. Application Meta

1. developers.facebook.com : application rattachée au portefeuille d'entreprise de Jibiat Marchand.
2. Paramètres › Général : domaine `jibiat-marchand.com`, politique de confidentialité, conditions.
3. Noter l'identifiant de l'application et la clé secrète (`META_APP_ID`, `META_APP_SECRET`).

## 2. Facebook Login for Business

1. Ajouter le produit **Facebook Login for Business**.
2. Paramètres › URI de redirection OAuth valides :
   - `https://jibiat-marchand.com/reseaux/meta/retour`
   - `https://preprod.jibiat-marchand.com/reseaux/meta/retour`
3. Domaines autorisés du SDK JavaScript : `jibiat-marchand.com` et `preprod.jibiat-marchand.com` (pas de joker `*.`).

## 3. Configuration « Publicité »

Configurations › Créer une configuration :

1. Nom : « Publicité boutiques ». Variante avec choix des autorisations (pas l'inscription intégrée WhatsApp).
2. Type de jeton : **jeton d'utilisateur système** (accès sans expiration ; sans configuration, 60 jours).
3. Actifs demandés (cochés par le marchand dans la fenêtre Facebook) : compte Business, catalogues, comptes
   publicitaires, pages.
4. Autorisations : `business_management`, `catalog_management`, `ads_management`, `pages_show_list`,
   `pages_read_engagement`, `pages_manage_ads` (`MetaAds::SCOPES`).
5. Noter l'identifiant de configuration (`META_ADS_CONFIG_ID`).

## 4. Variables du serveur

```
META_APP_ID=…
META_APP_SECRET=…
META_ADS_CONFIG_ID=…
```

Puis `deploy/update.sh` (ou `php artisan optimize`), en préproduction puis en production.

## 5. Tester avec son propre compte

Avant la validation de Meta, seuls les administrateurs et testeurs de l'application peuvent se connecter.

1. Rôles de l'application : vous ajouter (ou ajouter le testeur).
2. Avoir un compte Business Meta avec un compte publicitaire (et, si possible, un catalogue existant).
3. Dans l'application (préproduction) : Boost › **Connecter mon Facebook**. Dans la fenêtre Facebook, **cocher le
   compte Business, le catalogue et le compte publicitaire**.
4. Choisir le compte Business, le pixel et le catalogue (ou « Créer un nouveau »), puis valider.
5. Vérifier chez Meta (Gestionnaire de commerce › Catalogue › Sources de données) que le flux
   `https://<boutique>/flux/catalogue/<jeton>.csv` est présent (relu toutes les heures) et que le pixel est dans
   Sources d'événements.
6. Dans Boost, vérifier le pixel (événements reçus par Meta sur 7 jours).

## 6. Validation par Meta (tous les marchands)

Vérification de l'application › Autorisations et fonctionnalités : demander l'**accès avancé** à
`business_management`, `catalog_management`, `ads_management` et `pages_manage_ads`. Meta demande une vidéo de
démonstration, la politique de confidentialité et la vérification de l'entreprise (Business Verification).

## 7. Dépannage

- **Liste des catalogues vide** : l'écran dit pourquoi, d'après les autorisations réellement reçues. Soit une
  autorisation manque dans la configuration (§ 3), soit le marchand n'a rien coché dans la fenêtre Facebook.
- **« Unsupported post request » à la création du catalogue** : Meta refuse la création au nom de ce compte Business.
  Créer le catalogue dans le Gestionnaire de commerce, puis le choisir dans la liste. Aucun pixel n'est créé pour rien.
- **Lien du catalogue non ajouté** ou **pixel non relié** : avertissement affiché ; le faire à la main dans le
  Gestionnaire de catalogue (Sources de données, Sources d'événements).
- **Flux non lu par Meta** : le lien du flux doit être public (jeton renouvelable par
  `marketing/catalog-feed/renew`, fonctionnalité `meta_catalog` requise).
