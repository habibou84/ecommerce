<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Billing;
use App\Services\Live\LiveBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lives payants côté boutique : prix, lives offerts restants, pass ou forfait en cours, achat par PayDunya et
 * retour du paiement.
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
        $data = $request->validate(['kind' => ['required', 'in:live_day,live_month']], [], ['kind' => 'durée']);
        $invoice = $this->billing->checkout($this->currentStore(), $data['kind'], $request->user());

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
