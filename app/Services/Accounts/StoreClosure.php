<?php

namespace App\Services\Accounts;

use App\Enums\OrderStatus;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\ScheduledReport;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fermeture d'une boutique par son propriétaire : boutique en ligne et gestion fermées, abonnement arrêté à la
 * fin de la période payée, rapports arrêtés. Les données restent conservées (export possible avant) ; une
 * réouverture se demande au support.
 */
class StoreClosure
{
    public function close(Store $store, User $user, string $confirmation, ?string $reason): Store
    {
        if ($store->owner_id !== $user->id) {
            throw new BusinessRuleException('Seul le propriétaire peut fermer l\'espace marchand.');
        }
        if (Str::lower(trim($confirmation)) !== Str::lower(trim($store->name))) {
            throw new BusinessRuleException('Tapez exactement le nom de votre espace marchand pour confirmer.', 'confirmation');
        }
        $inDelivery = Order::forStore($store->id)->where('status', OrderStatus::Shipped->value)->count();
        if ($inDelivery > 0) {
            throw new BusinessRuleException("{$inDelivery} commande(s) sont en cours de livraison : terminez-les avant de fermer l'espace marchand.");
        }

        return DB::transaction(function () use ($store, $reason) {
            $store->forceFill([
                'status' => StoreStatus::Closed,
                'closed_at' => now(),
                'closed_reason' => $reason ? Str::limit($reason, 250, '') : null,
                'storefront_enabled' => false,
            ])->save();
            $subscription = $store->currentSubscription()->first();
            if ($subscription && $subscription->status !== SubscriptionStatus::Trialing) {
                $subscription->update(['cancel_at_period_end' => true]);
            }
            ScheduledReport::withoutGlobalScopes()->where('store_id', $store->id)->update(['is_active' => false]);

            return $store;
        });
    }
}
