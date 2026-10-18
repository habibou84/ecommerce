<?php

namespace App\Services\Storefront;

use App\Enums\PaymentKind;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Services\Payments\PaymentDesk;

/**
 * Paiement en ligne côté client : ce que la boutique propose (au choix, acompte, obligatoire) et ouverture du
 * paiement d'une commande.
 */
class OnlinePayment
{
    public function __construct(private readonly PaymentDesk $payments) {}

    /**
     * @return array{mode: string, methods: array<string, string>, deposit_percent: ?int, deposit: ?int}
     */
    public function options(Store $store, ?int $total = null): array
    {
        $methods = $store->online_payment === 'off' ? [] : $this->payments->availableMethods($store);
        $mode = $methods === [] ? 'off' : $store->online_payment;
        $percent = $mode === 'deposit' ? max(1, min(100, (int) ($store->deposit_percent ?? 30))) : null;

        return [
            'mode' => $mode,
            'methods' => $methods,
            'deposit_percent' => $percent,
            'deposit' => $percent && $total ? self::deposit($total, $percent) : null,
        ];
    }

    /**
     * Acompte arrondi aux 5 F supérieurs (montants acceptés par les prestataires).
     */
    public static function deposit(int $total, int $percent): int
    {
        return (int) (ceil($total * $percent / 100 / 5) * 5);
    }

    /**
     * Paiement du reste dû (ou de l'acompte exigé) : renvoie le paiement avec l'adresse de paiement du prestataire.
     */
    public function payOrder(Order $order, string $method, bool $deposit = false): Payment
    {
        $order->loadMissing('store');
        if ($deposit) {
            // Acompte déjà fixé (précommande), sinon celui de la boutique
            $amount = min($order->amountDue(), $order->deposit_required ?: self::deposit($order->total, (int) ($order->store->deposit_percent ?? 30)));
            $order->forceFill(['deposit_required' => $amount])->save();
        }

        return $this->payments->startCheckout($order, $amount ?? $order->amountDue(), $method, $deposit ? PaymentKind::Deposit : PaymentKind::Online);
    }
}
