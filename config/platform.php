<?php

/*
 * Plateforme multi-boutiques. Chaque boutique a son sous-domaine, {slug}.PLATFORM_DOMAIN, qui porte
 * son site (/), sa gestion (/gestion) et l'application de ses livreurs (/livreur).
 *
 * En production : PLATFORM_DOMAIN=jibiat-marchand.com. En local, sans configuration DNS :
 * APP_URL=http://localhost:8000 et PLATFORM_DOMAIN=localhost (les navigateurs envoient
 * *.localhost vers 127.0.0.1) → http://maboutique.localhost:8000.
 * Le protocole et le port des liens générés sont ceux d'APP_URL.
 */
return [
    'domain' => strtolower((string) env('PLATFORM_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')),

    // Moteurs de recherche : seule la production est indexée (préproduction : en-tête noindex et robots.txt fermé)
    'indexable' => (bool) env('SEARCH_INDEXING', env('APP_ENV') === 'production'),

    // Préproduction fermée au public : code demandé avant toute page (vide = ouvert ; jamais actif en production)
    'preprod_access_code' => (string) env('PREPROD_ACCESS_CODE', ''),

    // Console du super administrateur : admin.PLATFORM_DOMAIN
    'admin_subdomain' => env('PLATFORM_ADMIN_SUBDOMAIN', 'admin'),

    // API publique des marchands et réception des webhooks (transporteurs, paiements) : api.PLATFORM_DOMAIN
    'api_subdomain' => env('PLATFORM_API_SUBDOMAIN', 'api'),

    // Sous-domaines qui ne peuvent pas devenir l'adresse d'une boutique
    'reserved_slugs' => [
        'www', 'admin', 'api', 'app', 'apps', 'mail', 'email', 'smtp', 'imap', 'pop', 'ftp', 'ws', 'wss', 'socket',
        'static', 'assets', 'cdn', 'img', 'images', 'media', 'files', 'docs', 'doc', 'status', 'support', 'aide',
        'help', 'blog', 'news', 'dev', 'test', 'staging', 'demo-admin', 'console', 'gestion', 'livreur', 'livreurs',
        'boutique', 'boutiques', 'shop', 'store', 'jibiat', 'jibiat-marchand', 'marchand', 'marchands', 'connexion',
        'login', 'inscription', 'signup', 'compte', 'account', 'paiement', 'pay', 'payment', 'checkout', 'billing',
        'security', 'securite', 'root', 'null', 'undefined',
    ],

    // Domaines des entreprises de livraison (Delivery App) que la boutique peut interroger : import du catalogue,
    // puis connexion transporteur (phase 4). Aucune requête n'est faite vers un autre domaine.
    'delivery_app_domains' => array_filter(array_map('trim', explode(',', (string) env('DELIVERY_APP_DOMAINS', 'jibiat.com')))),
    'delivery_app_scheme' => env('DELIVERY_APP_SCHEME', 'https'),
    // Annuaire des entreprises de livraison de Jibiat (contrat « directory » de habibou84/socle) et connexion en un clic
    'delivery_app_directory_url' => env('DELIVERY_APP_DIRECTORY_URL', 'https://jibiat.com/api/public/directory'),

    // Reversement des entreprises de livraison : une course livrée non reversée depuis ce nombre de jours est signalée en retard
    'carrier_payout_overdue_days' => (int) env('CARRIER_PAYOUT_OVERDUE_DAYS', 7),

    // Durée de l'essai gratuit à la création d'une boutique (toutes les fonctions de l'offre d'essai)
    'trial_days' => (int) env('PLATFORM_TRIAL_DAYS', 14),
    'trial_plan' => env('PLATFORM_TRIAL_PLAN', 'pro'),

    // Abonnements des boutiques, encaissés sur le compte de la plateforme (docs/PLAN.md §6.5)
    'billing' => [
        'provider' => env('BILLING_PROVIDER', 'wave'), // wave | cinetpay | paydunya
        'mode' => env('BILLING_MODE', 'live'), // test | live
        // Identifiants du compte de la plateforme chez le prestataire (noms des champs du pilote)
        'credentials' => array_filter([
            'api_key' => env('BILLING_WAVE_API_KEY'),
            'webhook_secret' => env('BILLING_WAVE_WEBHOOK_SECRET'),
            'apikey' => env('BILLING_CINETPAY_APIKEY'),
            'site_id' => env('BILLING_CINETPAY_SITE_ID'),
            'secret_key' => env('BILLING_CINETPAY_SECRET_KEY'),
            'master_key' => env('BILLING_PAYDUNYA_MASTER_KEY'),
            'private_key' => env('BILLING_PAYDUNYA_PRIVATE_KEY'),
            'token' => env('BILLING_PAYDUNYA_TOKEN'),
        ]),
        // Jours de grâce après l'échéance avant le retour à l'offre gratuite ; rappel avant l'échéance
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),
        'reminder_days' => (int) env('BILLING_REMINDER_DAYS', 3),
    ],

    // Domaines personnalisés : le marchand fait pointer son domaine (CNAME) vers cette adresse, ou (domaine nu)
    // vers ces adresses IP ; les certificats sont demandés à la volée par Caddy (route /_caddy/ask)
    'custom_domains' => [
        'cname_target' => env('CUSTOM_DOMAIN_CNAME', 'domaines.'.strtolower((string) env('PLATFORM_DOMAIN', 'localhost'))),
        'ips' => array_filter(array_map('trim', explode(',', (string) env('CUSTOM_DOMAIN_IPS', '')))),
    ],

    // Support : WhatsApp de l'équipe Jibiat Marchand (bouton « Aide » de la gestion)
    'support_whatsapp' => env('SUPPORT_WHATSAPP', '+2250700000000'),
    'support_session_minutes' => (int) env('SUPPORT_SESSION_MINUTES', 60),
];
