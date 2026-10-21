<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionInvoice;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Jibiat\Socle\Money;

/**
 * Validation d'un paiement par lien marchand Wave : lien signé reçu par e-mail ou SMS, puis code du validateur.
 */
class BillingValidationController extends Controller
{
    public function __construct(private readonly Billing $billing, private readonly BillingSettings $settings) {}

    public function show(int $invoice): View
    {
        $model = $this->invoice($invoice);

        return view('billing.validate', $this->details($model) + ['error' => null]);
    }

    public function decide(Request $request, int $invoice): View
    {
        $model = $this->invoice($invoice);
        $data = $request->validate(['code' => ['required', 'string', 'max:40'], 'decision' => ['required', 'in:received,missing']]);

        // 5 essais par quart d'heure : le code ne se devine pas
        $key = 'billing-validate:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return view('billing.validate', $this->details($model) + ['error' => 'Trop d\'essais. Réessayez dans quelques minutes.']);
        }
        if (! $this->settings->checkPin((string) $data['code'])) {
            RateLimiter::hit($key, 900);

            return view('billing.validate', $this->details($model) + ['error' => 'Code incorrect.']);
        }
        RateLimiter::clear($key);

        $model = $this->billing->decide($model, $data['decision'] === 'received');

        return view('billing.validate', $this->details($model->load(['plan', 'store'])) + ['error' => null]);
    }

    private function invoice(int $id): SubscriptionInvoice
    {
        return SubscriptionInvoice::withoutGlobalScopes()->with(['plan', 'store'])->where('provider', 'wave_link')->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function details(SubscriptionInvoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'amount' => Money::format($invoice->amount),
            'payer' => $invoice->raw['payer_phone'] ?? null,
            'payerName' => $invoice->raw['payer_name'] ?? null,
            'label' => $invoice->raw['description'] ?? $invoice->number,
        ];
    }
}
