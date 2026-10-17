<?php

namespace App\Services\Accounts;

use App\Enums\OrderStatus;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Rules\StoreSlug;
use App\Services\Billing\PlanLimits;
use App\Support\HostedStores;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Boutiques d'un même propriétaire : vue d'ensemble, création d'une nouvelle boutique depuis la gestion, et
 * rattachement à l'abonnement d'une boutique dont l'offre couvre plusieurs boutiques (offre Business).
 */
class StoreGroup
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly StoreSignup $signup,
    ) {}

    /**
     * Boutique dont l'abonnement peut couvrir les autres boutiques du propriétaire : celle de l'adresse si son
     * propre abonnement le permet, sinon celle qui la couvre déjà.
     */
    public function main(Store $store): ?Store
    {
        if ($store->billing_store_id === null) {
            return $store->currentSubscription?->plan?->hasFeature('multi_store') ? $store : null;
        }

        return $this->limits->coveringStore($store);
    }

    /**
     * Boutiques réunies sous l'abonnement commun (la principale d'abord), ou null sans abonnement commun.
     *
     * @return Collection<int, Store>|null
     */
    public function stores(Store $current): ?Collection
    {
        $main = $this->main($current);
        if ($main === null) {
            return null;
        }

        $covered = Store::where('billing_store_id', $main->id)->orderBy('id')->get()
            ->filter(fn (Store $s) => $this->limits->coveringStore($s)?->id === $main->id);

        return collect([$main])->concat($covered)->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $owner, Store $current): array
    {
        $main = $this->main($current);
        $stores = Store::where('owner_id', $owner->id)->with('currentSubscription.plan')->orderBy('id')->get();
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $rows = $stores->map(function (Store $s) use ($current, $main, $today, $month) {
            $covering = $this->limits->coveringStore($s);

            return [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'url' => $s->url(),
                'is_current' => $s->id === $current->id,
                'is_hosted' => $s->host_store_id !== null,
                'path' => $s->path,
                'is_open' => $s->isOpen(),
                'status_label' => $s->status->label(),
                'plan' => $this->limits->plan($s)?->name,
                'is_main' => $main !== null && $s->id === $main->id,
                'covered' => $covering !== null,
                // Rattachée mais au-delà du nombre de boutiques de l'offre : elle garde sa propre offre
                'attached' => $s->billing_store_id !== null,
                'today_count' => Order::forStore($s->id)->where('created_at', '>=', $today)->count(),
                'today_amount' => (int) Order::forStore($s->id)->where('created_at', '>=', $today)->where('status', '!=', OrderStatus::Cancelled->value)->sum('total'),
                'to_confirm' => Order::forStore($s->id)->where('status', OrderStatus::PendingConfirmation->value)->count(),
                'month_revenue' => (int) Order::forStore($s->id)->where('status', OrderStatus::Completed->value)->where('completed_at', '>=', $month)->sum('total'),
            ];
        });

        $plan = $main?->currentSubscription?->plan;

        return [
            'stores' => $rows->values(),
            'totals' => [
                'today_count' => $rows->sum('today_count'),
                'today_amount' => $rows->sum('today_amount'),
                'to_confirm' => $rows->sum('to_confirm'),
                'month_revenue' => $rows->sum('month_revenue'),
            ],
            // Adresse sous laquelle la prochaine boutique sera créée (null : elle aura sa propre adresse)
            'next_address' => ($next = $this->hostsNext($current)) ? rtrim($next->url(), '/') : null,
            'group' => $main ? [
                'main_id' => $main->id,
                'main_name' => $main->name,
                'address' => rtrim($main->url(), '/'),
                'plan' => $plan->name,
                'limit' => $plan->limit('stores'),
                'used' => $this->limits->used($main, 'stores'),
            ] : null,
        ];
    }

    /**
     * Le prochain ajout sera hébergé à l'adresse de la boutique principale (abonnement commun avec de la place).
     */
    public function hostsNext(Store $current): ?Store
    {
        $main = $this->main($current);

        return $main !== null && $this->hasRoom($main) ? $main : null;
    }

    /**
     * Nouvelle boutique du propriétaire. Avec un abonnement commun qui a de la place, elle est hébergée à
     * l'adresse de la boutique principale (page « /{path} », sans essai) ; sinon elle a sa propre adresse et
     * son propre essai.
     */
    public function create(User $owner, Store $current, string $name, ?string $slug, ?string $path = null): Store
    {
        $main = $this->hostsNext($current);
        if ($main === null) {
            if ($slug === null) {
                throw new BusinessRuleException('Choisissez l\'adresse de la nouvelle boutique.', 'slug');
            }

            return $this->signup->createStore($owner, $name, $slug);
        }

        $path ??= HostedStores::freePath($name, $main);
        if (($error = HostedStores::error($path, $main)) !== null) {
            throw new BusinessRuleException($error, 'path');
        }

        return DB::transaction(function () use ($owner, $name, $main, $path) {
            $store = $this->signup->createStore($owner, $name, $this->internalSlug($main, $path), trial: false);
            $store->forceFill(['billing_store_id' => $main->id, 'host_store_id' => $main->id, 'path' => $path, 'status' => StoreStatus::Active])->save();

            return $store;
        });
    }

    /**
     * Rattache une boutique du propriétaire à l'abonnement de la boutique principale.
     */
    public function attach(User $owner, Store $current, Store $store): void
    {
        $main = $this->main($current);
        if ($main === null) {
            throw new BusinessRuleException('Votre offre ne couvre qu\'une boutique. L\'offre Business regroupe plusieurs boutiques sous un même abonnement.');
        }
        $this->ensureOwned($owner, $store);
        if ($store->id === $main->id || ($store->billing_store_id === $main->id && $store->host_store_id === $main->id)) {
            return;
        }
        if (Store::where('billing_store_id', $store->id)->orWhere('host_store_id', $store->id)->exists()) {
            throw new BusinessRuleException("La boutique {$store->name} couvre déjà d'autres boutiques avec son abonnement.");
        }
        if (! $this->hasRoom($main)) {
            $limit = $main->currentSubscription->plan->limit('stores');

            throw new BusinessRuleException("Votre offre {$main->currentSubscription->plan->name} couvre {$limit} boutiques au plus.");
        }

        DB::transaction(function () use ($store, $main) {
            // Elle passe à l'adresse de la boutique principale ; son ancienne adresse y redirige
            $path = $store->host_store_id === $main->id ? $store->path : HostedStores::freePath(Str::after($store->slug, $main->slug.'-'), $main);
            $store->forceFill(['billing_store_id' => $main->id, 'host_store_id' => $main->id, 'path' => $path])->save();
            if (in_array($store->status, [StoreStatus::Trial, StoreStatus::PastDue], true)) {
                $store->forceFill(['status' => StoreStatus::Active])->save();
            }

            // Son propre abonnement n'a plus d'objet : l'essai s'arrête, un abonnement payé va jusqu'à son échéance
            // sans être renouvelé
            $own = $store->currentSubscription()->first();
            if ($own?->status === SubscriptionStatus::Trialing || $own?->status === SubscriptionStatus::PastDue) {
                $own->update(['status' => SubscriptionStatus::Expired]);
            } elseif ($own?->status === SubscriptionStatus::Active) {
                $own->update(['cancel_at_period_end' => true]);
            }
        });
    }

    /**
     * La boutique quitte l'abonnement commun : elle retrouve sa propre adresse et sa propre offre (gratuite
     * sans abonnement).
     */
    public function detach(User $owner, Store $store): void
    {
        $this->ensureOwned($owner, $store);
        $store->forceFill(['billing_store_id' => null, 'host_store_id' => null, 'path' => null])->save();
    }

    /**
     * Sous-domaine interne d'une boutique hébergée (jamais affiché : son ancienne adresse redirige vers sa page).
     */
    private function internalSlug(Store $main, string $path): string
    {
        $base = Str::limit("{$main->slug}-{$path}", 34, '');
        $base = rtrim($base, '-');
        $slug = $base;
        while (Validator::make(['slug' => $slug], ['slug' => [new StoreSlug]])->fails()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    private function hasRoom(Store $main): bool
    {
        $limit = $main->currentSubscription?->plan?->limit('stores');

        return $limit === null || $this->limits->used($main, 'stores') < $limit;
    }

    private function ensureOwned(User $owner, Store $store): void
    {
        if ($store->owner_id !== $owner->id) {
            throw new BusinessRuleException('Seules vos propres boutiques peuvent partager votre abonnement.');
        }
    }
}
