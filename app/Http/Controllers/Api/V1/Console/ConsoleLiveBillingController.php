<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\LiveSession;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\BillingSettings;
use App\Services\Live\LiveBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console › Lives : prix du pass 24 h et des 30 jours, lives offerts au départ, actions IA offertes, lives payants
 * oui/non ; ventes du mois ; lives ou 30 jours offerts à une boutique.
 */
class ConsoleLiveBillingController extends Controller
{
    public function __construct(private readonly LiveBilling $billing) {}

    public function show(Request $request): JsonResponse
    {
        $since = now()->startOfMonth();
        $paid = SubscriptionInvoice::withoutGlobalScopes()->whereIn('kind', LiveBilling::KINDS)->where('status', 'paid')->where('paid_at', '>=', $since)
            ->selectRaw('kind, count(*) as n, sum(amount) as total')->groupBy('kind')->get()->keyBy('kind');

        return response()->json(['data' => $this->billing->settings() + [
            // PayDunya prêt (clés sur le serveur) : sinon les lives ne peuvent pas être payés
            'provider_ready' => (bool) (app(BillingSettings::class)->available()[$this->billing->settings()['provider']] ?? false),
            'month' => [
                'day' => ['count' => (int) ($paid['live_day']->n ?? 0), 'total' => (int) ($paid['live_day']->total ?? 0)],
                'month' => ['count' => (int) ($paid['live_month']->n ?? 0), 'total' => (int) ($paid['live_month']->total ?? 0)],
                'offered' => LiveSession::withoutGlobalScopes()->where('billing', 'offered')->where('started_at', '>=', $since)->count(),
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paid' => ['sometimes', 'boolean'],
            'day_price' => ['sometimes', 'integer', 'min:100', 'max:1000000'],
            'month_price' => ['sometimes', 'integer', 'min:100', 'max:10000000'],
            'free_lives' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'free_ai' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ], [], ['day_price' => 'prix des 24 h', 'month_price' => 'prix des 30 jours', 'free_lives' => 'lives offerts', 'free_ai' => 'actions IA offertes']);
        $before = $this->billing->settings();
        $after = $this->billing->update($data);
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => 'live_billing_updated', 'data' => ['before' => $before, 'after' => $after], 'ip' => $request->ip()]);

        return $this->show($request);
    }

    /**
     * Lives offerts à une boutique (en plus de ceux du départ), ou 30 jours de lives illimités.
     */
    public function offer(Request $request, int $store): JsonResponse
    {
        $model = Store::findOrFail($store);
        $data = $request->validate([
            'lives' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_without:month'],
            'month' => ['nullable', 'boolean'],
        ], [], ['lives' => 'lives offerts']);
        if (! empty($data['lives'])) {
            $model->forceFill(['live_free_bonus' => (int) $model->live_free_bonus + (int) $data['lives']])->save();
        }
        if (! empty($data['month'])) {
            $invoice = SubscriptionInvoice::create(['store_id' => $model->id, 'kind' => 'live_month', 'billing_cycle' => 'monthly', 'amount' => 0,
                'status' => 'offered', 'number' => Str::random(20), 'idempotency_key' => (string) Str::uuid(), 'provider' => 'offered', 'paid_at' => now()]);
            $invoice->update(['number' => 'JM-'.now()->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);
            $this->billing->activate($model, $invoice);
            $invoice->update(['period_start' => now(), 'period_end' => $model->live_month_until]);
        }
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => $model->id, 'action' => 'lives_offered', 'data' => $data, 'ip' => $request->ip()]);

        return response()->json(['data' => $this->billing->overview($model)]);
    }
}
