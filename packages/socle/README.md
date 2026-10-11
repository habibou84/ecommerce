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

Installation (dépôt public, versions étiquetées `vX.Y.Z`) :

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/habibou84/socle" }],
"require": { "habibou84/socle": "^1.0" }
```

Toute modification passe par ce dépôt : nouvelle étiquette, puis `composer update habibou84/socle` dans chaque
application. La signature des webhooks fait partie du contrat entre les deux applications : ne la changez qu'avec
une nouvelle version majeure.

Configuration (`config/services.php` de l'application) :

```php
'sms' => [
    'driver' => env('SMS_DRIVER', 'log'), // log | twilio | none
    'sender' => env('SMS_SENDER', 'Jibiat'),
    'twilio' => ['sid' => env('TWILIO_SID'), 'token' => env('TWILIO_TOKEN'), 'from' => env('TWILIO_FROM')],
],
```
