<?php

namespace App\Services\Billing;

use App\Exceptions\QuotaExceededException;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\StoreUser;
use App\Notifications\BillingNotice;
use App\Services\Accounts\TeamNotifier;
use Carbon\CarbonImmutable;

/**
 * Limites de l'offre de la boutique (docs/PLAN.md §9). Sans abonnement en cours : offre gratuite.
 */
class PlanLimits
{
    public const LABELS = [
        'orders_per_month' => 'commandes par mois',
        'products' => 'produits',
        'members' => 'membres d\'équipe',
        'couriers' => 'livreurs',
        'carrier_connections' => 'entreprises de livraison connectées',
        'payment_accounts' => 'comptes de paiement en ligne',
        'stores' => 'boutiques sous cet abonnement',
    ];

    public const FEATURES = [
        'custom_domain' => 'Domaine personnalisé',
        'online_payment' => 'Paiement en ligne',
        'whatsapp_api' => 'WhatsApp automatique',
        'ai_product' => 'Fiche produit depuis une photo',
        'advanced_stats' => 'Statistiques avancées',
        'merchant_api' => 'API marchand',
        'multi_store' => 'Plusieurs boutiques',
    ];

    /**
     * Fonctions annoncées mais pas encore disponibles : affichées « bientôt », jamais présentées comme acquises.
     */
    public const COMING_SOON = ['ai_product', 'merchant_api'];

    /**
     * Part de la limite de commandes à partir de laquelle l'équipe est prévenue.
     */
    private const ORDERS_WARNING = 0.8;

    public function plan(Store $store): ?Plan
    {
        return $this->coveringStore($store)?->currentSubscription->plan
            ?? $store->currentSubscription?->plan ?? Plan::where('code', 'free')->first();
    }

    /**
     * Boutique du même propriétaire dont l'abonnement (offre avec plusieurs boutiques) couvre celle-ci, dans la
     * limite de boutiques de cette offre (les plus anciennes rattachées d'abord).
     */
    public function coveringStore(Store $store): ?Store
    {
        if ($store->billing_store_id === null) {
            return null;
        }

        $main = $store->billingStore;
        $plan = $main?->currentSubscription?->plan;
        if ($main === null || $main->owner_id !== $store->owner_id || ! $plan?->hasFeature('multi_store')) {
            return null;
        }

        $max = $plan->limit('stores');
        if ($max !== null && Store::where('billing_store_id', $main->id)->where('id', '<', $store->id)->count() + 1 >= $max) {
            return null;
        }

        return $main;
    }

    public function hasFeature(Store $store, string $feature): bool
    {
        return $this->plan($store)?->hasFeature($feature) ?? false;
    }

    /**
     * Refuse l'ajout si la boutique a atteint la limite de son offre.
     */
    public function ensureCanAdd(Store $store, string $limit, ?int $current = null, int $adding = 1): void
    {
        $max = $this->plan($store)?->limit($limit);
        $current ??= $this->used($store, $limit);

        if ($max !== null && $current + $adding > $max) {
            $label = self::LABELS[$limit] ?? $limit;

            throw new QuotaExceededException("Votre offre {$this->plan($store)->name} est limitée à {$max} {$label}. Passez à une offre supérieure pour en ajouter.");
        }
    }

    public function ensureFeature(Store $store, string $feature): void
    {
        if (! $this->hasFeature($store, $feature)) {
            $plan = Plan::where('is_public', true)->orderBy('sort')->get()->first(fn (Plan $p) => $p->hasFeature($feature));

            throw new QuotaExceededException((self::FEATURES[$feature] ?? $feature).' : fonction comprise '.($plan ? "à partir de l'offre {$plan->name}" : 'dans les offres supérieures').'. Changez d\'offre dans Réglages › Abonnement.');
        }
    }

    /**
     * Commandes du mois : l'équipe est prévenue à l'approche de la limite ; au-delà, la commande est refusée et
     * l'équipe prévenue (une fois par mois chaque fois).
     */
    public function ensureOrderQuota(Store $store): void
    {
        $max = $this->plan($store)?->limit('orders_per_month');
        if ($max === null) {
            return;
        }

        $month = CarbonImmutable::now($store->timezone)->format('Y-m');
        $notices = $store->billing_notices ?? [];
        $count = $this->used($store, 'orders_per_month') + 1;

        if ($count <= $max) {
            if ($count >= (int) ceil($max * self::ORDERS_WARNING) && ($notices['orders_quota_warning'] ?? null) !== $month) {
                $store->forceFill(['billing_notices' => ['orders_quota_warning' => $month] + $notices])->save();
                app(TeamNotifier::class)->notify($store, 'orders.view', new BillingNotice($store->id, 'Limite de commandes bientôt atteinte',
                    "{$count} commandes sur {$max} ce mois-ci avec l'offre {$this->plan($store)->name}. Au-delà, les nouvelles commandes seront refusées jusqu'au mois prochain : passez à l'offre Starter pour des commandes illimitées."));
            }

            return;
        }

        if (($notices['orders_quota'] ?? null) !== $month) {
            $store->forceFill(['billing_notices' => ['orders_quota' => $month] + $notices])->save();
            app(TeamNotifier::class)->notify($store, 'orders.view', new BillingNotice($store->id, 'Limite de commandes atteinte',
                "Votre offre {$this->plan($store)->name} est limitée à {$max} commandes par mois : les nouvelles commandes sont refusées jusqu'au mois prochain. Passez à l'offre Starter pour des commandes illimitées."));
        }

        throw new QuotaExceededException("Votre offre {$this->plan($store)->name} est limitée à {$max} commandes par mois. Passez à une offre supérieure pour continuer à recevoir des commandes.");
    }

    /**
     * Utilisation de chaque limite de l'offre.
     *
     * @return array<string, array{label: string, used: int, limit: ?int}>
     */
    public function usage(Store $store): array
    {
        $plan = $this->plan($store);

        return collect(self::LABELS)->map(fn ($label, $key) => ['label' => $label, 'used' => $this->used($store, $key), 'limit' => $plan?->limit($key)])->all();
    }

    public function used(Store $store, string $limit): int
    {
        return match ($limit) {
            'orders_per_month' => Order::forStore($store->id)->where('created_at', '>=', CarbonImmutable::now($store->timezone)->startOfMonth()->utc())->count(),
            'products' => Product::forStore($store->id)->count(),
            // Équipe : membres actifs (hors livreurs, comptés à part) et invitations en attente
            'members' => StoreUser::where('store_id', $store->id)->where('status', 'active')
                ->whereNotIn('user_id', Courier::forStore($store->id)->whereNotNull('user_id')->select('user_id'))->count()
                + StoreInvitation::where('store_id', $store->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->where('role', '!=', 'courier')->count(),
            'couriers' => Courier::forStore($store->id)->where('status', '!=', 'inactive')->count(),
            'carrier_connections' => CarrierConnection::forStore($store->id)->where('status', '!=', 'disabled')->count(),
            'payment_accounts' => PaymentAccount::forStore($store->id)->count(),
            // La boutique elle-même et celles que son abonnement couvre
            'stores' => 1 + Store::where('billing_store_id', $store->id)->count(),
            default => 0,
        };
    }
}
