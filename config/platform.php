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

    // Durée de l'essai gratuit à la création d'une boutique (toutes les fonctions de l'offre d'essai)
    'trial_days' => (int) env('PLATFORM_TRIAL_DAYS', 14),
    'trial_plan' => env('PLATFORM_TRIAL_PLAN', 'pro'),
];
