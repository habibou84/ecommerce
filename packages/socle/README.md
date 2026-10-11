# habibou84/socle

Briques techniques communes à **Jibiat** (`habibou84/deliverynew`) et **Jibiat Marchand** (`habibou84/ecommerce`),
sans logique métier (voir `docs/PLAN.md` §4.5) :

| Classe | Rôle |
|---|---|
| `Money` | Affichage des montants (`12 500 F`) |
| `PhoneNumber` | Normalisation E.164, affichage, mobiles ivoiriens |
| `Rules\PhoneNumber` | Règle de validation Laravel |
| `Sms\SmsGateway`, `Sms\LogSmsGateway`, `Sms\TwilioSmsGateway` | Envoi de SMS (pilote `log` en développement) |
| `Webhooks\WebhookSignature` | Signature et vérification HMAC des webhooks (`X-Webhook-Signature`) |

Pour l'instant, le paquet vit dans `packages/socle` du dépôt `ecommerce` (dépôt Composer de type `path`).
Il sera déplacé dans son propre dépôt `habibou84/socle` quand Delivery App basculera dessus.

Configuration (`config/services.php` de l'application) :

```php
'sms' => [
    'driver' => env('SMS_DRIVER', 'log'), // log | twilio | none
    'sender' => env('SMS_SENDER', 'Jibiat'),
    'twilio' => ['sid' => env('TWILIO_SID'), 'token' => env('TWILIO_TOKEN'), 'from' => env('TWILIO_FROM')],
],
```
