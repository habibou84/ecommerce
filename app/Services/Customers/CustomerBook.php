<?php

namespace App\Services\Customers;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fichier clients : un client par numéro de téléphone et par boutique, ses adresses, ses compteurs et son
 * niveau de risque (refus à la livraison, liste noire, commandes en double).
 */
class CustomerBook
{
    /**
     * Client du numéro (créé au besoin). Le nom n'écrase pas celui déjà connu.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreate(Store $store, string $phone, ?string $name = null, array $attributes = []): Customer
    {
        $customer = Customer::forStore($store->id)->withTrashed()->where('phone', $phone)->first();

        if ($customer === null) {
            return Customer::create(['store_id' => $store->id, 'phone' => $phone, 'name' => $name] + $attributes);
        }

        if ($customer->trashed()) {
            $customer->restore();
        }
        if (blank($customer->name) && filled($name)) {
            $customer->update(['name' => $name]);
        }

        return $customer;
    }

    /**
     * Adresse de livraison mémorisée (la dernière utilisée devient l'adresse par défaut).
     */
    public function rememberAddress(Customer $customer, Order $order): ?CustomerAddress
    {
        if ($order->delivery_method !== 'delivery' || ($order->shipping_place_id === null && blank($order->shipping_address))) {
            return null;
        }

        return DB::transaction(function () use ($customer, $order) {
            $address = $customer->addresses()->where('place_id', $order->shipping_place_id)
                ->where('address_line', $order->shipping_address)->first()
                ?? new CustomerAddress(['store_id' => $customer->store_id, 'customer_id' => $customer->id]);

            $customer->addresses()->update(['is_default' => false]);
            $address->fill([
                'recipient_name' => $order->shipping_name,
                'phone' => $order->shipping_phone,
                'place_id' => $order->shipping_place_id,
                'address_line' => $order->shipping_address,
                'landmark' => $order->shipping_landmark,
                'latitude' => $order->shipping_latitude,
                'longitude' => $order->shipping_longitude,
                'is_default' => true,
            ])->save();

            return $address;
        });
    }

    /**
     * Compteurs recalculés depuis les commandes (après chaque changement de statut).
     */
    public function refreshStats(Customer $customer): void
    {
        $orders = Order::forStore($customer->store_id)->where('customer_id', $customer->id);

        $refusals = array_map(fn (CancelReason $r) => $r->value, array_filter(CancelReason::cases(), fn (CancelReason $r) => $r->isCustomerFault()));

        $customer->forceFill([
            'orders_count' => (clone $orders)->count(),
            'delivered_count' => (clone $orders)->where('status', OrderStatus::Completed->value)->count(),
            'refused_count' => (clone $orders)->where(fn ($q) => $q->where('status', OrderStatus::Returned->value)
                ->orWhere(fn ($q) => $q->where('status', OrderStatus::Cancelled->value)->whereIn('cancel_reason', $refusals)))->count(),
            'total_spent' => (int) (clone $orders)->where('status', OrderStatus::Completed->value)->sum('total'),
            'last_order_at' => (clone $orders)->max('created_at'),
        ])->save();
    }

    /**
     * Niveau de risque d'une nouvelle commande, de 0 à 100 :
     * liste noire = 100 ; sinon part des refus (jusqu'à 60), refus répétés (+20), commande en double en cours (+30).
     */
    public function riskScore(Customer $customer, ?int $ignoreOrderId = null): int
    {
        if ($customer->isBlacklisted()) {
            return 100;
        }

        $score = 0;
        $decided = $customer->delivered_count + $customer->refused_count;
        if ($decided > 0) {
            $score += (int) round(60 * $customer->refused_count / $decided);
        }
        if ($customer->refused_count >= 2) {
            $score += 20;
        }
        if ($this->openDuplicates($customer, $ignoreOrderId)->isNotEmpty()) {
            $score += 30;
        }

        return min(100, $score);
    }

    /**
     * Autres commandes en cours du même client passées depuis moins de 24 h (doublons probables).
     *
     * @return Collection<int, Order>
     */
    public function openDuplicates(Customer $customer, ?int $ignoreOrderId = null)
    {
        return Order::forStore($customer->store_id)->where('customer_id', $customer->id)
            ->whereIn('status', [OrderStatus::PendingConfirmation->value, OrderStatus::Confirmed->value, OrderStatus::Processing->value])
            ->where('created_at', '>=', now()->subDay())
            ->when($ignoreOrderId, fn ($q) => $q->whereKeyNot($ignoreOrderId))
            ->get(['id', 'number', 'total', 'created_at']);
    }

    public function blacklist(Customer $customer, string $reason): void
    {
        $customer->forceFill(['blacklisted_at' => now(), 'blacklist_reason' => $reason])->save();
    }

    public function unblacklist(Customer $customer): void
    {
        $customer->forceFill(['blacklisted_at' => null, 'blacklist_reason' => null])->save();
    }
}
