<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Store
 */
class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->currentSubscription;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => $this->url(),
            'tagline' => $this->tagline,
            'phone' => $this->phone,
            'whatsapp_phone' => $this->whatsapp_phone,
            'email' => $this->email,
            'address' => $this->address,
            'country' => $this->country,
            'currency' => $this->currency,
            'low_stock_threshold' => $this->low_stock_threshold,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->name,
                'plan_code' => $subscription->plan->code,
                'status' => $subscription->status->value,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ] : null,
        ];
    }
}
