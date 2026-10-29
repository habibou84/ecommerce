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
        // WhatsApp par une autre application Meta déjà validée (celle de Jibiat) ; vide : l'application ci-dessus
        'whatsapp_app_id' => env('META_WHATSAPP_APP_ID'),
        'whatsapp_app_secret' => env('META_WHATSAPP_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        // Connexion de la page Facebook et du compte Instagram (Facebook Login for Business › Configurations ;
        // sans configuration, les autorisations sont demandées une à une)
        'social_config_id' => env('META_SOCIAL_CONFIG_ID'),
        // Pixel et catalogue branchés en un clic (Facebook Login for Business › Configurations, jeton d'utilisateur
        // système : accès sans expiration ; sans configuration, autorisations demandées une à une, accès de 60 jours)
        'ads_config_id' => env('META_ADS_CONFIG_ID'),
        // Prix d'un message marketing WhatsApp facturé par Meta au marchand (F CFA, estimation affichée avant une relance)
        'marketing_message_price' => (int) env('WHATSAPP_MARKETING_PRICE', 15),
    ],

    // Notifications push de l'application (Firebase Cloud Messaging) : chemin du fichier JSON du compte de service
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    // Notifications push du navigateur et de l'application installée depuis le navigateur (Web Push, VAPID) :
    // clés créées par la mise à jour du serveur (« php artisan webpush:vapid »)
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    // Application Google (client OAuth « Application Web ») pour connecter le Gmail qui envoie les e-mails de la
    // plateforme ; peut aussi être saisie dans la console (Paiements › Envoi des e-mails)
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    // WhatsApp relié par QR code (service whatsapp-bridge sur le serveur, 127.0.0.1) : jeton et port créés par la
    // mise à jour du serveur
    'whatsapp_bridge' => [
        'url' => env('WHATSAPP_BRIDGE_URL', 'http://127.0.0.1:'.env('WHATSAPP_BRIDGE_PORT', 3210)),
        'token' => env('WHATSAPP_BRIDGE_TOKEN'),
    ],

    // Assistant IA (fiche produit depuis une photo, commande depuis une conversation, réponses WhatsApp)
    // Publication sur TikTok (Login Kit + Content Posting API)
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],

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

    // Cartes Membre Premium demandées par les entreprises de Jibiat : secret partagé avec le serveur de Jibiat
    'jibiat' => [
        'premium_secret' => env('JIBIAT_PREMIUM_SECRET'),
    ],

];
