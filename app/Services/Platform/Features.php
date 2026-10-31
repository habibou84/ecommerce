<?php

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\PlatformSetting;
use App\Models\Store;

/**
 * Fonctions que la console allume ou éteint pour toute la plateforme : masquée (invisible, le serveur refuse),
 * test (seulement pour les boutiques choisies, par exemple pour la vérification de Meta) ou active (pour tous, dans
 * la limite de l'offre). Masquer ne supprime rien : les connexions des boutiques sont mises en pause.
 */
class Features
{
    public const STATES = ['hidden', 'test', 'on'];

    /** @var array<string, array{group: string, label: string, description: string, default: string}> */
    public const FEATURES = [
        // Meta et réseaux sociaux : masqués tant que Meta (ou TikTok) n'a pas validé l'application
        'social_meta' => ['group' => 'Meta et réseaux sociaux', 'label' => 'Facebook et Instagram', 'description' => 'Connexion de la page, publication des produits, mesures par publication.', 'default' => 'hidden'],
        'meta_messages' => ['group' => 'Meta et réseaux sociaux', 'label' => 'Messenger et Instagram dans Messages', 'description' => 'Les messages Facebook et Instagram des clients dans la boîte Messages.', 'default' => 'hidden'],
        'meta_catalog' => ['group' => 'Meta et réseaux sociaux', 'label' => 'Catalogue Meta', 'description' => 'Flux du catalogue pour Facebook et Instagram.', 'default' => 'hidden'],
        'meta_connect' => ['group' => 'Meta et réseaux sociaux', 'label' => 'Pixel et catalogue Meta en un clic', 'description' => 'Branchement automatique par la connexion Facebook (la saisie manuelle du pixel reste possible).', 'default' => 'hidden'],
        'whatsapp_catalog' => ['group' => 'Meta et réseaux sociaux', 'label' => 'Catalogue WhatsApp', 'description' => 'Catalogue relié à Meta et paniers WhatsApp reçus comme commandes.', 'default' => 'hidden'],
        'social_tiktok' => ['group' => 'Meta et réseaux sociaux', 'label' => 'TikTok', 'description' => 'Connexion du compte TikTok et publication des produits.', 'default' => 'hidden'],
        // WhatsApp
        'whatsapp_embedded' => ['group' => 'WhatsApp', 'label' => 'Numéro officiel en un clic', 'description' => 'Connexion du numéro WhatsApp officiel de la boutique par Meta.', 'default' => 'on'],
        'whatsapp_qr' => ['group' => 'WhatsApp', 'label' => 'WhatsApp par QR code', 'description' => 'Relier le WhatsApp de la boutique comme WhatsApp Web, pour les boutiques autorisées une à une (fiche de la boutique).', 'default' => 'on'],
        'whatsapp_campaigns' => ['group' => 'WhatsApp', 'label' => 'Campagnes WhatsApp', 'description' => 'Relance des anciens clients.', 'default' => 'on'],
        // Ventes
        'live' => ['group' => 'Ventes', 'label' => 'Vente en live', 'description' => 'Préparer et animer un live, réservations par code.', 'default' => 'on'],
        'live_screens' => ['group' => 'Ventes', 'label' => 'Affichage des articles dans le live', 'description' => 'Écran du live (télé, LIVE Studio, PRISM), mode présentation et cartes.', 'default' => 'on'],
        'online_payment' => ['group' => 'Ventes', 'label' => 'Paiement en ligne', 'description' => 'Comptes Wave, CinetPay, PayDunya des boutiques et liens de paiement.', 'default' => 'on'],
        'custom_domain' => ['group' => 'Ventes', 'label' => 'Nom de domaine personnalisé', 'description' => 'La boutique en ligne à l\'adresse de la boutique.', 'default' => 'on'],
        // Outils
        'ai' => ['group' => 'Outils', 'label' => 'Assistant IA', 'description' => 'Fiche depuis une photo, commande depuis une conversation, réponses, questions sur les chiffres.', 'default' => 'on'],
        'loyalty' => ['group' => 'Outils', 'label' => 'Fidélité et parrainage clients', 'description' => 'Points, récompenses et parrainage entre clients.', 'default' => 'on'],
        'reviews' => ['group' => 'Outils', 'label' => 'Avis clients', 'description' => 'Avis demandés après la livraison et publiés sur la boutique.', 'default' => 'on'],
        'suppliers' => ['group' => 'Outils', 'label' => 'Fournisseurs et bons de commande', 'description' => 'Achats aux fournisseurs et réception du stock.', 'default' => 'on'],
        // Plateforme
        'signup' => ['group' => 'Plateforme', 'label' => 'Inscription de nouvelles boutiques', 'description' => 'Création d\'un espace marchand depuis le site.', 'default' => 'on'],
    ];

    /** @var array<string, string>|null */
    private ?array $states = null;

    /** @var list<int>|null */
    private ?array $testStores = null;

    public function state(string $key): string
    {
        $this->states ??= PlatformSetting::group('feature');

        return in_array($this->states[$key] ?? null, self::STATES, true) ? $this->states[$key] : (self::FEATURES[$key]['default'] ?? 'on');
    }

    /**
     * Boutiques qui voient les fonctions en test.
     *
     * @return list<int>
     */
    public function testStores(): array
    {
        return $this->testStores ??= array_values(array_map('intval', json_decode((string) PlatformSetting::find('features.test_stores')?->value, true) ?: []));
    }

    public function enabled(string $key, ?Store $store = null): bool
    {
        return match ($this->state($key)) {
            'on' => true,
            'test' => $store !== null && in_array($store->id, $this->testStores(), true),
            default => false,
        };
    }

    public function ensure(string $key, ?Store $store): void
    {
        if (! $this->enabled($key, $store)) {
            throw new BusinessRuleException('Cette fonction n\'est pas encore disponible.');
        }
    }

    /**
     * Ce que voit une boutique : fonction => visible.
     *
     * @return array<string, bool>
     */
    public function forStore(?Store $store): array
    {
        return collect(self::FEATURES)->mapWithKeys(fn ($f, $key) => [$key => $this->enabled($key, $store)])->all();
    }

    public function set(string $key, string $state): void
    {
        if (! isset(self::FEATURES[$key]) || ! in_array($state, self::STATES, true)) {
            throw new BusinessRuleException('Fonction ou état inconnu.');
        }
        PlatformSetting::put("feature.{$key}", $state);
        $this->states = null;
    }

    /**
     * @param  list<int>  $ids
     */
    public function setTestStores(array $ids): void
    {
        $ids = Store::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        PlatformSetting::put('features.test_stores', json_encode($ids));
        $this->testStores = null;
    }
}
