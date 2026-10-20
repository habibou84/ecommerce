# Jibiat Marchand (e-commerce) — consignes pour Claude

Jibiat Marchand est l'application des e-commerçants (boutiques, commandes, stock, expéditions). Elle confie les
livraisons aux entreprises **Jibiat** (`habibou84/deliverynew`) par leur API publique `/api/public/v1` et reçoit leurs
webhooks signés. Le code commun est dans `habibou84/socle`.

## Langue
Toujours répondre en français. Textes de l'interface, messages, commits et PR en français.

## Branches et déploiement
- `main` = préproduction, `production` = production. Le déploiement est automatique à la fusion (migrations comprises).
- On ouvre une PR seulement à la demande (« ouvre la PR et fusionne », « mets en production »).
- Mise en production : PR `main` → `production`. Signaler les PR d'autres sessions qui partent avec.
- Ne pas faire travailler deux sessions sur le même écran ou le même service en même temps
  (ex. le formulaire de commande) : finir et fusionner l'une avant l'autre.

## Tests et style
```bash
php artisan test     # PostgreSQL : base jibiat_marchand_test, utilisateur jibiat / secret (comme la CI)
vendor/bin/pint      # style (la CI lance pint --test)
npm run build        # build front-end
```
Si PostgreSQL ou Redis sont arrêtés : `service postgresql start; service redis-server start`.

## Livraison par Jibiat
- Pilote : `app/Services/Shipping/Drivers/DeliveryAppDriver.php` ; lecture d'une course :
  `RemoteShipment::fromDeliveryApp` ; statuts : `ShipmentStatus::fromDeliveryApp` ; webhooks : `CarrierEvents` ;
  actions sur la course (modifier, décider, retour, note) : `ShipmentDesk`.
- Contrat avec Jibiat dans `habibou84/socle` (`contract/v1`) : `tests/Feature/Shipping/ShippingTest.php` simule Jibiat
  avec `Contract::example()` et vérifie que chaque statut, course et webhook du contrat est compris. Utiliser ces
  exemples plutôt que des réponses écrites à la main.
- Un nouveau champ ou statut de Jibiat doit d'abord être compris ici (un statut inconnu ne doit rien casser).
- Un changement de socle se fait dans son dépôt, puis `composer update habibou84/socle` ici et dans Jibiat.
- Une fonctionnalité qui touche les deux applications : socle d'abord, puis Jibiat, puis Jibiat Marchand.
