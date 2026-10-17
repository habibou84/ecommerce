<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Application Meta de la plateforme : WhatsApp des boutiques connecté en un clic (inscription intégrée)
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'whatsapp_config_id' => env('META_WHATSAPP_CONFIG_ID'), // Facebook Login for Business › Configurations
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        // Prix d'un message marketing WhatsApp facturé par Meta au marchand (F CFA, estimation affichée avant une relance)
        'marketing_message_price' => (int) env('WHATSAPP_MARKETING_PRICE', 15),
    ],

    // Notifications push de l'application (Firebase Cloud Messaging) : chemin du fichier JSON du compte de service
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    // Assistant IA (fiche produit depuis une photo, commande depuis une conversation, réponses WhatsApp)
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-5-5'),
        // Prix publics en dollars par million de jetons (entrée, sortie) pour estimer le coût dans la console
        'prices' => [
            'claude-opus-5-5' => [4.0, 20.0],
            'claude-sonnet-5-5' => [2.0, 10.0],
            'claude-haiku-5-5' => [0.10, 0.50],
        ],
        // Francs CFA pour un dollar (estimation des coûts ; à ajuster)
        'usd_rate' => (float) env('AI_USD_RATE', 600),
    ],

    // SMS (vérification des numéros) : log = écrit dans le journal, sans envoi (développement)
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log | orange | twilio | none
        // Orange Developer, offre « SMS Côte d'Ivoire » : identifiants de l'application, numéro d'envoi, nom validé
        'orange' => [
            'client_id' => env('ORANGE_SMS_CLIENT_ID'),
            'client_secret' => env('ORANGE_SMS_CLIENT_SECRET'),
            'sender_address' => env('ORANGE_SMS_SENDER_ADDRESS', '+2250000'),
            'sender_name' => env('ORANGE_SMS_SENDER_NAME', 'JIBIAT'),
        ],
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

];
