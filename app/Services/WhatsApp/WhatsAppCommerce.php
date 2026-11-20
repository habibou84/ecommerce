<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Catalogue WhatsApp côté numéro : le catalogue de Meta doit être rattaché au compte WhatsApp Business (un seul par
 * compte, dans le gestionnaire WhatsApp › Catalogue), puis rendu visible avec le panier sur le numéro (réglages
 * commerce du numéro, par l'API). Relier le catalogue à Meta (Marketing › Suivi) ne suffit pas.
 */
class WhatsAppCommerce
{
    /** État relu chez Meta au plus toutes les 10 minutes */
    private const CACHE_MINUTES = 10;

    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /**
     * Catalogue rattaché au compte WhatsApp (identifiant et nom), visible et panier ; null si Meta ne répond pas.
     *
     * @return array{catalog: ?array{id: string, name: ?string}, visible: bool, cart: bool}|null
     */
    public function state(WhatsappAccount $account, bool $fresh = false): ?array
    {
        $key = 'wa-commerce:'.$account->id;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($account) {
            try {
                $catalogs = $account->waba_id ? ($this->cloud->graph('GET', $account->waba_id.'/product_catalogs', $account->access_token)['data'] ?? []) : [];
                $settings = $this->cloud->graph('GET', $account->phone_number_id.'/whatsapp_commerce_settings', $account->access_token)['data'][0] ?? [];
            } catch (RuntimeException $e) {
                Log::info('Catalogue WhatsApp : état illisible chez Meta', ['account' => $account->id, 'error' => $e->getMessage()]);

                return null;
            }
            $catalog = $catalogs[0] ?? null;

            return [
                'catalog' => $catalog ? ['id' => (string) ($catalog['id'] ?? ''), 'name' => $catalog['name'] ?? null] : null,
                'visible' => (bool) ($settings['is_catalog_visible'] ?? false),
                'cart' => (bool) ($settings['is_cart_enabled'] ?? false),
            ];
        });
    }

    /**
     * Catalogue montré (icône boutique, boutons du catalogue) et panier activé sur le numéro, ou les deux éteints.
     */
    public function show(WhatsappAccount $account, bool $visible): ?array
    {
        $this->cloud->graph('POST', $account->phone_number_id.'/whatsapp_commerce_settings', $account->access_token, [
            'is_catalog_visible' => $visible ? 'true' : 'false',
            'is_cart_enabled' => $visible ? 'true' : 'false',
        ]);

        return $this->state($account, true);
    }
}
