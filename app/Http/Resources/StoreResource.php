<?php

namespace App\Http\Resources;

use App\Models\Store;
use App\Services\Billing\AccessKeys;
use App\Services\Billing\FreeAccess;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Store
 */
class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Boutique couverte par l'abonnement d'une autre boutique du propriétaire : c'est cet abonnement qui compte
        $covering = app(PlanLimits::class)->coveringStore($this->resource);
        $subscription = $covering?->currentSubscription ?? $this->currentSubscription;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => $this->url(),
            // Boutique hébergée : sa page est {adresse de la boutique hôte}/{path}
            'path' => $this->path,
            'host_url' => $this->host_store_id !== null ? rtrim($this->host->url(), '/') : null,
            'tagline' => $this->tagline,
            'phone' => $this->phone,
            'whatsapp_phone' => $this->whatsapp_phone,
            'email' => $this->email,
            'address' => $this->address,
            'country' => $this->country,
            'currency' => $this->currency,
            'low_stock_threshold' => $this->low_stock_threshold,
            'storefront_enabled' => (bool) $this->storefront_enabled,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'free' => app(FreeAccess::class)->present($this->resource),
            'access_key' => app(AccessKeys::class)->present($this->resource),
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->name,
                'plan_code' => $subscription->plan->code,
                'status' => $subscription->status->value,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'covered_by' => $covering?->name,
            ] : null,
        ];
    }
}
