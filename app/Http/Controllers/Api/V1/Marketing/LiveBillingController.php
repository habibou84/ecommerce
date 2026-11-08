<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Billing;
use App\Services\Live\LiveBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lives payants côté boutique : paliers, crédit live, commandes offertes restantes, forfait en cours, recharge ou
 * 30 jours par PayDunya et retour du paiement.
 */
class LiveBillingController extends Controller
{
    public function __construct(private readonly LiveBilling $billing) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->billing->overview($this->currentStore())]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:live_credit,live_month'],
            'amount' => ['nullable', 'required_if:kind,live_credit', 'integer', 'min:100', 'max:1000000'],
        ], [], ['kind' => 'choix', 'amount' => 'montant']);
        $invoice = $this->billing->checkout($this->currentStore(), $data['kind'], $request->user(), isset($data['amount']) ? (int) $data['amount'] : null);

        return response()->json(['data' => ['number' => $invoice->number, 'checkout_url' => $invoice->checkout_url]], 201);
    }

    /**
     * Retour de PayDunya : statut relu chez le prestataire (jamais sur la seule foi du retour).
     */
    public function confirm(string $number, Billing $billing): JsonResponse
    {
        $invoice = SubscriptionInvoice::forStore($this->currentStore()->id)->whereIn('kind', LiveBilling::KINDS)->where('number', $number)->firstOrFail();
        $invoice = $billing->confirm($invoice);

        return response()->json(['data' => ['status' => $invoice->status] + $this->billing->overview($this->currentStore())]);
    }
}
