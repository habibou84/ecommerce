<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentAccount;
use Illuminate\Http\Request;

/**
 * Pilote d'un prestataire de paiement (docs/PLAN.md §6.2).
 */
interface PaymentGateway
{
    /**
     * Moyens de paiement que ce prestataire sait encaisser.
     *
     * @return list<string>
     */
    public function methods(): array;

    /**
     * Identifiants à saisir par le marchand : clé => libellé.
     *
     * @return array<string, string>
     */
    public function credentialFields(): array;

    /**
     * @param  array{return_url: string, cancel_url: string, notify_url: string, description: string, customer_name: ?string, customer_phone: ?string, store_name: string}  $context
     */
    public function createCheckout(PaymentAccount $account, Payment $payment, array $context): Checkout;

    public function verify(PaymentAccount $account, Payment $payment): GatewayResult;

    /**
     * Notification du prestataire : signature contrôlée, puis identifiant de l'événement et du paiement concerné.
     * Null si la notification n'est pas authentique.
     *
     * @return array{event_id: string, payment_key: ?string, provider_reference: ?string}|null
     */
    public function parseWebhook(PaymentAccount $account, Request $request): ?array;

    /**
     * Remboursement par l'API (false : non pris en charge pour ce montant, remboursement manuel).
     */
    public function refund(PaymentAccount $account, Payment $payment, int $amount): bool;
}
