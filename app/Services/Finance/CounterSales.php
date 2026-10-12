<?php

namespace App\Services\Finance;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Orders\OrderDesk;
use Illuminate\Support\Facades\DB;

/**
 * Vente au comptoir en une fois : commande « En boutique » retirée sur place, stock sorti, paiements notés.
 * Un reste à payer devient une vente à crédit (carte « Clients : reste à payer » de la caisse).
 */
class CounterSales
{
    public function __construct(
        private readonly OrderDesk $orders,
        private readonly CashRegister $register,
    ) {}

    /**
     * @param  array{items: list<array{variant_id: int, quantity: int}>, payments: list<array{method: string, amount: int}>, customer_name?: ?string, customer_phone?: ?string, discount_total?: ?int, note?: ?string}  $data
     */
    public function sell(Store $store, array $data, User $actor): Order
    {
        $payments = array_values(array_filter($data['payments'] ?? [], fn ($p) => (int) $p['amount'] > 0));
        $cash = array_sum(array_map(fn ($p) => $p['method'] === 'cash' ? (int) $p['amount'] : 0, $payments));
        if ($cash > 0 && $this->register->current($store) === null) {
            throw new BusinessRuleException('Ouvrez la caisse avant d\'encaisser des espèces.', 'payments');
        }

        return DB::transaction(function () use ($store, $data, $actor, $payments) {
            $order = $this->orders->create($store, [
                'source' => 'in_store',
                'delivery_method' => 'pickup',
                'confirmed' => true,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'discount_total' => $data['discount_total'] ?? 0,
                'internal_note' => $data['note'] ?? null,
                'items' => $data['items'],
            ], $actor);

            $paid = array_sum(array_map(fn ($p) => (int) $p['amount'], $payments));
            if ($paid > $order->total) {
                throw new BusinessRuleException('Le montant encaissé dépasse le total ('.$order->total.' F) : notez seulement le prix, la monnaie rendue est affichée à part.', 'payments');
            }
            if ($paid < $order->total && $order->customer_id === null) {
                throw new BusinessRuleException('Vente à crédit : indiquez le téléphone du client pour suivre ce qu\'il doit.', 'customer_phone');
            }

            $order = $this->orders->transition($order, OrderStatus::Completed, $actor, ['note' => 'Vente au comptoir']);
            foreach ($payments as $payment) {
                $this->orders->recordPayment($order, ['amount' => (int) $payment['amount'], 'method' => $payment['method'], 'kind' => PaymentKind::Manual], $actor);
            }

            return $order->fresh(['items', 'payments']);
        });
    }
}
