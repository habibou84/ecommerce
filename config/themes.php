<?php

/*
 * Thèmes de la boutique en ligne. Chaque thème partage les mêmes gabarits Blade (resources/views/storefront) et
 * se distingue par sa mise en page (en-tête, cartes produits) et ses réglages par défaut. Les réglages sont
 * décrits ici une fois : l'écran « Apparence » de la gestion se construit à partir de ce schéma.
 */
$settings = [
    ['key' => 'primary_color', 'type' => 'color', 'label' => 'Couleur principale'],
    ['key' => 'background_color', 'type' => 'color', 'label' => 'Couleur de fond'],
    ['key' => 'font', 'type' => 'select', 'label' => 'Police', 'options' => ['sans' => 'Moderne', 'serif' => 'Élégante', 'rounded' => 'Arrondie']],
    ['key' => 'announcement', 'type' => 'text', 'label' => 'Bandeau d\'annonce', 'max' => 120, 'help' => 'Ex. « Livraison offerte dès 30 000 F à Abidjan ». Vide = pas de bandeau.'],
    ['key' => 'hero_title', 'type' => 'text', 'label' => 'Titre de l\'accueil', 'max' => 80],
    ['key' => 'hero_subtitle', 'type' => 'textarea', 'label' => 'Texte de l\'accueil', 'max' => 240],
    ['key' => 'hero_image', 'type' => 'image', 'label' => 'Image de l\'accueil'],
    ['key' => 'hero_button', 'type' => 'text', 'label' => 'Bouton de l\'accueil', 'max' => 30],
    ['key' => 'featured_collection_id', 'type' => 'collection', 'label' => 'Collection mise en avant'],
    ['key' => 'show_new_arrivals', 'type' => 'boolean', 'label' => 'Afficher les nouveautés'],
    ['key' => 'show_collections', 'type' => 'boolean', 'label' => 'Afficher les collections'],
    ['key' => 'show_whatsapp_button', 'type' => 'boolean', 'label' => 'Bouton « Commander sur WhatsApp »'],
    ['key' => 'footer_text', 'type' => 'textarea', 'label' => 'Texte du pied de page', 'max' => 300],
];

return [
    'default' => 'classique',

    'themes' => [
        'classique' => [
            'name' => 'Classique',
            'description' => 'Sobre et lumineux : vos photos au premier plan.',
            'version' => '1.0',
            'layout' => ['header' => 'centered', 'cards' => 'flat'],
            'settings' => $settings,
            'defaults' => [
                'primary_color' => '#047857', 'background_color' => '#ffffff', 'font' => 'sans', 'announcement' => null,
                'hero_title' => null, 'hero_subtitle' => null, 'hero_image' => null, 'hero_button' => 'Voir la boutique',
                'featured_collection_id' => null, 'show_new_arrivals' => true, 'show_collections' => true,
                'show_whatsapp_button' => true, 'footer_text' => null,
            ],
        ],
        'marche' => [
            'name' => 'Marché',
            'description' => 'Coloré et chaleureux : en-tête en couleur, cartes arrondies.',
            'version' => '1.0',
            'layout' => ['header' => 'banner', 'cards' => 'rounded'],
            'settings' => $settings,
            'defaults' => [
                'primary_color' => '#c2410c', 'background_color' => '#fffbf5', 'font' => 'serif', 'announcement' => null,
                'hero_title' => null, 'hero_subtitle' => null, 'hero_image' => null, 'hero_button' => 'Découvrir',
                'featured_collection_id' => null, 'show_new_arrivals' => true, 'show_collections' => true,
                'show_whatsapp_button' => true, 'footer_text' => null,
            ],
        ],
    ],
];
