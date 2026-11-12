<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Billing;
use App\Services\Billing\Credits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mon solde : commandes et actions IA, packs ou illimité 30 jours par PayDunya, retour du paiement.
 */
class CreditsController extends Controller
{
    public function __construct(private readonly Credits $credits) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->credits->overview($this->currentStore())]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['pack', 'month'])],
            'pack' => ['required_if:kind,pack', 'nullable', 'integer', 'between:1,3'],
        ], [], ['kind' => 'choix', 'pack' => 'pack']);
        $invoice = $this->credits->checkout($this->currentStore(), $data['kind'], $request->user(), isset($data['pack']) ? (int) $data['pack'] : null);

        return response()->json(['data' => ['number' => $invoice->number, 'checkout_url' => $invoice->checkout_url]], 201);
    }

    /**
     * Retour de PayDunya : statut relu chez le prestataire (jamais sur la seule foi du retour).
     */
    public function confirm(string $number, Billing $billing): JsonResponse
    {
        $invoice = SubscriptionInvoice::forStore($this->currentStore()->id)->whereIn('kind', Credits::KINDS)->where('number', $number)->firstOrFail();
        $invoice = $billing->confirm($invoice);

        return response()->json(['data' => ['status' => $invoice->status] + $this->credits->overview($this->currentStore())]);
    }
}
