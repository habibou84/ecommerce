<?php

namespace App\Services\Customers;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\LoyaltyEntry;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fidélité et parrainage, suivis par le numéro de téléphone du client.
 *  - Points : gagnés quand une commande est livrée (articles, hors livraison), utilisés en réduction sur une
 *    commande saisie par l'équipe ; rendus si la commande est annulée, retirés si elle est retournée.
 *  - Parrainage : chaque client a un code ; un nouveau client qui le donne à sa première commande a une
 *    réduction, et le parrain gagne des points quand cette commande est livrée.
 */
class Loyalty
{
    public const DEFAULTS = [
        'enabled' => false,
        'points_per_1000' => 1, // points gagnés par tranche de 1 000 F d'articles livrés
        'point_value' => 50, // F de réduction par point
        'min_points' => 10, // points minimum pour les utiliser
        'referral_enabled' => false,
        'referral_discount' => 1000, // F de réduction pour le filleul
        'referral_points' => 20, // points pour le parrain
    ];

    /**
     * @return array<string, int|bool>
     */
    public function settings(Store $store): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($store->loyalty_settings ?? [], self::DEFAULTS));
    }

    /**
     * Réductions d'une nouvelle commande (avant le calcul du total) : points utilisés et parrainage.
     *
     * @param  array<string, mixed>  $data
     */
    public function prepare(Store $store, Order $order, ?Customer $customer, array $data): void
    {
        $settings = $this->settings($store);
        $points = (int) ($data['reward_points'] ?? 0);
        if ($points > 0) {
            if (! $settings['enabled'] || $customer === null) {
                throw new BusinessRuleException('Le programme de fidélité n\'est pas activé.', 'reward_points');
            }
            if ($points > $customer->loyalty_points) {
                throw new BusinessRuleException("Le client n'a que {$customer->loyalty_points} point(s).", 'reward_points');
            }
            if ($points < $settings['min_points']) {
                throw new BusinessRuleException("Au moins {$settings['min_points']} points pour les utiliser.", 'reward_points');
            }
            $order->forceFill(['points_used' => $points, 'points_discount' => $points * $settings['point_value']]);
        }

        $code = Str::upper(trim((string) ($data['referral_code'] ?? '')));
        if ($code !== '' && $settings['referral_enabled'] && $customer !== null) {
            $referrer = Customer::forStore($store->id)->where('referral_code', $code)->first();
            // Un code pour soi-même, ou un client qui a déjà commandé : pas de réduction de bienvenue
            if ($referrer !== null && $referrer->id !== $customer->id && (int) $customer->orders_count === 0 && $customer->referred_by_id === null) {
                $order->forceFill(['referral_discount' => $settings['referral_discount'], 'referred_by_customer_id' => $referrer->id]);
                $customer->forceFill(['referred_by_id' => $referrer->id])->save();
            }
        }
    }

    /**
     * Après l'enregistrement de la commande : points utilisés retirés du compte du client (montant final).
     */
    public function commit(Order $order, ?User $actor): void
    {
        if ($order->points_used > 0 && $order->customer_id) {
            $this->entry($order->store_id, $order->customer_id, -$order->points_used, 'redeemed', $order->id, null, $actor);
        }
    }

    /**
     * Journal des commandes : livrée → points gagnés (et parrain récompensé) ; annulée ou retournée → points rendus ou retirés.
     */
    public function onOrderEvent(OrderEvent $event): void
    {
        if ($event->type !== 'status_changed') {
            return;
        }
        $to = $event->data['to'] ?? null;
        $order = Order::forStore($event->store_id)->with('store')->find($event->order_id);
        if ($order === null || $order->customer_id === null) {
            return;
        }
        $settings = $this->settings($order->store);

        DB::transaction(function () use ($order, $to, $settings) {
            if ($to === OrderStatus::Completed->value) {
                $earned = $settings['enabled'] ? intdiv(max(0, $order->total - $order->shipping_fee), 1000) * (int) $settings['points_per_1000'] : 0;
                if ($earned > 0 && ! $this->has($order, 'earned')) {
                    $this->entry($order->store_id, $order->customer_id, $earned, 'earned', $order->id, null, null);
                }
                if ($order->referred_by_customer_id && $settings['referral_enabled'] && $settings['referral_points'] > 0 && ! $this->has($order, 'referral')) {
                    $this->entry($order->store_id, $order->referred_by_customer_id, (int) $settings['referral_points'], 'referral', $order->id,
                        'Filleul : '.($order->customer_name ?: $order->customer_phone), null);
                }
            }

            if (in_array($to, [OrderStatus::Cancelled->value, OrderStatus::Returned->value], true)) {
                if ($order->points_used > 0 && ! $this->has($order, 'refunded')) {
                    $this->entry($order->store_id, $order->customer_id, $order->points_used, 'refunded', $order->id, null, null);
                }
                $earned = (int) LoyaltyEntry::forStore($order->store_id)->where('order_id', $order->id)->where('type', 'earned')->sum('points');
                if ($earned > 0 && ! $this->has($order, 'reversed')) {
                    $this->entry($order->store_id, $order->customer_id, -$earned, 'reversed', $order->id, null, null);
                }
            }
        });
    }

    public function adjust(Customer $customer, int $points, ?string $note, User $actor): void
    {
        if ($points === 0 || $customer->loyalty_points + $points < 0) {
            throw new BusinessRuleException('Ajustement impossible : le solde ne peut pas devenir négatif.', 'points');
        }
        $this->entry($customer->store_id, $customer->id, $points, 'adjustment', null, $note, $actor);
    }

    /**
     * Code de parrainage du client (créé à la première demande).
     */
    public function referralCode(Customer $customer): string
    {
        if ($customer->referral_code) {
            return $customer->referral_code;
        }
        $prefix = Str::upper(Str::substr(Str::ascii(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $customer->name)) ?: 'AMI'), 0, 4)) ?: 'AMI';
        do {
            // Partie aléatoire sans caractères faciles à confondre (0/O, 1/I/L)
            $code = $prefix.strtr(Str::upper(Str::random(4)), ['0' => '7', 'O' => 'P', '1' => '8', 'I' => 'K', 'L' => 'M']);
        } while (Customer::forStore($customer->store_id)->where('referral_code', $code)->exists());
        $customer->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forCustomer(Customer $customer, bool $withHistory = false): ?array
    {
        $store = $customer->store ?? Store::find($customer->store_id);
        $settings = $this->settings($store);
        if (! $settings['enabled'] && ! $settings['referral_enabled']) {
            return null;
        }

        return [
            'points' => $customer->loyalty_points,
            'value' => $customer->loyalty_points * $settings['point_value'],
            'usable' => $settings['enabled'] && $customer->loyalty_points >= $settings['min_points'],
            'referral_code' => $settings['referral_enabled'] ? $this->referralCode($customer) : null,
            'referrals' => $settings['referral_enabled'] ? Customer::forStore($customer->store_id)->where('referred_by_id', $customer->id)->count() : null,
            'history' => $withHistory ? LoyaltyEntry::forStore($customer->store_id)->where('customer_id', $customer->id)->latest('id')->limit(30)->get()
                ->map(fn (LoyaltyEntry $e) => ['points' => $e->points, 'type' => $e->type, 'label' => LoyaltyEntry::TYPES[$e->type] ?? $e->type, 'note' => $e->note, 'order_id' => $e->order_id, 'created_at' => $e->created_at->toIso8601String()]) : null,
        ];
    }

    private function has(Order $order, string $type): bool
    {
        return LoyaltyEntry::forStore($order->store_id)->where('order_id', $order->id)->where('type', $type)->exists();
    }

    private function entry(int $storeId, int $customerId, int $points, string $type, ?int $orderId, ?string $note, ?User $actor): void
    {
        LoyaltyEntry::create(['store_id' => $storeId, 'customer_id' => $customerId, 'points' => $points, 'type' => $type, 'order_id' => $orderId, 'note' => $note, 'created_by' => $actor?->id]);
        Customer::forStore($storeId)->whereKey($customerId)->increment('loyalty_points', $points);
    }
}
