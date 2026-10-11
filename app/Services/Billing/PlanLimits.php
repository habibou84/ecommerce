<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Plan;
use App\Models\Store;

/**
 * Limites de l'offre de la boutique (docs/PLAN.md §9). Sans abonnement en cours : offre gratuite.
 */
class PlanLimits
{
    public function plan(Store $store): ?Plan
    {
        return $store->currentSubscription?->plan ?? Plan::where('code', 'free')->first();
    }

    /**
     * Refuse l'ajout si la boutique a atteint la limite de son offre.
     */
    public function ensureCanAdd(Store $store, string $limit, int $current, int $adding = 1): void
    {
        $max = $this->plan($store)?->limit($limit);

        if ($max !== null && $current + $adding > $max) {
            $label = match ($limit) {
                'products' => 'produits',
                'members' => 'membres d\'équipe',
                'couriers' => 'livreurs',
                default => $limit,
            };

            throw new BusinessRuleException("Votre offre {$this->plan($store)->name} est limitée à {$max} {$label}. Passez à une offre supérieure pour en ajouter.");
        }
    }
}
