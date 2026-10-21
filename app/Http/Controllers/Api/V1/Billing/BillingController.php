<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Billing;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abonnement de la boutique : offre, utilisation des limites, paiement sur le compte de la plateforme, factures.
 */
class BillingController extends Controller
{
    public function __construct(private readonly Billing $billing) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->billing->overview($this->currentStore())]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'code')->where('is_public', true)],
            'cycle' => ['required', Rule::in(['monthly', 'weekly', 'yearly'])],
            'method' => ['required', 'string'],
            'payer_phone' => [Rule::requiredIf($this->billing->manual()), 'nullable', new PhoneRule],
        ], [], ['plan' => 'offre', 'method' => 'moyen de paiement', 'payer_phone' => 'numéro Wave']);

        $invoice = $this->billing->checkout($this->currentStore(), Plan::where('code', $data['plan'])->firstOrFail(), $data['cycle'], $data['method'], $request->user(), $this->payer($data));

        return response()->json(['data' => $this->billing->presentInvoice($invoice), 'redirect' => $invoice->checkout_url], 201);
    }

    public function aiPack(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string'],
            'payer_phone' => [Rule::requiredIf($this->billing->manual()), 'nullable', new PhoneRule],
        ], [], ['method' => 'moyen de paiement', 'payer_phone' => 'numéro Wave']);

        $invoice = $this->billing->buyAiPack($this->currentStore(), $data['method'], $request->user(), $this->payer($data));

        return response()->json(['data' => $this->billing->presentInvoice($invoice), 'redirect' => $invoice->checkout_url], 201);
    }

    /**
     * Numéro Wave qui paie (lien marchand), au format lisible du pays.
     */
    private function payer(array $data): ?string
    {
        return filled($data['payer_phone'] ?? null) ? PhoneNumber::display(PhoneNumber::normalize($data['payer_phone'])) : null;
    }

    /**
     * Retour du prestataire : statut relu chez lui.
     */
    public function check(string $number): JsonResponse
    {
        $invoice = SubscriptionInvoice::where('number', $number)->firstOrFail();

        return response()->json(['data' => $this->billing->presentInvoice($this->billing->confirm($invoice)->loadMissing('plan'))]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->billing->cancelAtPeriodEnd($this->currentStore(), $request->boolean('cancel', true));

        return $this->show();
    }

    public function receipt(int $invoice): Response
    {
        $model = SubscriptionInvoice::with(['plan', 'store.owner'])->whereIn('status', ['paid', 'offered'])->findOrFail($invoice);

        return Pdf::loadView('pdf.subscription-invoice', ['invoice' => $model])->setPaper('a4')->download("facture-{$model->number}.pdf");
    }
}
