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
use Illuminate\Validation\ValidationException;

/**
 * Console › Lives : lives payants oui/non, paliers par nombre de commandes, forfait 30 jours, commandes offertes au
 * départ, crédit minimum pour ouvrir un live, actions IA offertes ; ventes du mois ; commandes, crédit ou 30 jours
 * offerts à une boutique.
 */
class ConsoleLiveBillingController extends Controller
{
    public function __construct(private readonly LiveBilling $billing) {}

    public function show(Request $request): JsonResponse
    {
        $since = now()->startOfMonth();
        $paid = SubscriptionInvoice::withoutGlobalScopes()->whereIn('kind', LiveBilling::KINDS)->where('status', 'paid')->where('paid_at', '>=', $since)
            ->selectRaw('kind, count(*) as n, sum(amount) as total')->groupBy('kind')->get()->keyBy('kind');
        $billed = LiveSession::withoutGlobalScopes()->where('billing', 'tier')->where('billed_at', '>=', $since);

        return response()->json(['data' => $this->billing->settings() + [
            'tiers' => $this->billing->tiers(),
            // PayDunya prêt (clés sur le serveur) : sinon les lives ne peuvent pas être payés
            'provider_ready' => (bool) (app(BillingSettings::class)->available()[$this->billing->settings()['provider']] ?? false),
            'month' => [
                'recharges' => ['count' => (int) ($paid['live_credit']->n ?? 0), 'total' => (int) ($paid['live_credit']->total ?? 0)],
                'month' => ['count' => (int) ($paid['live_month']->n ?? 0), 'total' => (int) ($paid['live_month']->total ?? 0)],
                'lives' => (clone $billed)->count(),
                'billed' => (int) (clone $billed)->sum('billed_amount'),
                'orders' => (int) (clone $billed)->sum('billed_orders'),
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $money = ['sometimes', 'integer', 'min:0', 'max:1000000'];
        $data = $request->validate([
            'paid' => ['sometimes', 'boolean'],
            'free_upto' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'tier2_upto' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'tier2_price' => $money,
            'tier3_upto' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'tier3_price' => $money,
            'top_price' => $money,
            'month_price' => ['sometimes', 'integer', 'min:100', 'max:10000000'],
            'free_orders' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'min_credit' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'free_ai' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ], [], ['free_upto' => 'gratuit jusqu\'à', 'tier2_upto' => 'palier 2', 'tier3_upto' => 'palier 3', 'month_price' => 'prix des 30 jours',
            'free_orders' => 'commandes offertes', 'min_credit' => 'crédit minimum', 'free_ai' => 'actions IA offertes']);
        $next = [...$this->billing->settings(), ...$data];
        if (! ($next['free_upto'] < $next['tier2_upto'] && $next['tier2_upto'] < $next['tier3_upto'])) {
            throw ValidationException::withMessages(['tier2_upto' => 'Les paliers doivent se suivre : gratuit < palier 2 < palier 3.']);
        }
        $before = $this->billing->settings();
        $after = $this->billing->update($data);
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => 'live_billing_updated', 'data' => ['before' => $before, 'after' => $after], 'ip' => $request->ip()]);

        return $this->show($request);
    }

    /**
     * Offert à une boutique : des commandes en live (en plus de celles du départ), du crédit live, ou 30 jours de
     * lives illimités.
     */
    public function offer(Request $request, int $store): JsonResponse
    {
        $model = Store::findOrFail($store);
        $data = $request->validate([
            'orders' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'credit' => ['nullable', 'integer', 'min:100', 'max:1000000'],
            'month' => ['nullable', 'boolean'],
        ], [], ['orders' => 'commandes offertes', 'credit' => 'crédit offert']);
        if (empty($data['orders']) && empty($data['credit']) && empty($data['month'])) {
            throw ValidationException::withMessages(['orders' => 'Choisissez des commandes, du crédit ou 30 jours à offrir.']);
        }
        if (! empty($data['orders'])) {
            $model->forceFill(['live_orders_bonus' => (int) $model->live_orders_bonus + (int) $data['orders']])->save();
        }
        foreach (array_filter(['live_credit' => $data['credit'] ?? null, 'live_month' => ! empty($data['month']) ? 0 : null], fn ($v) => $v !== null) as $kind => $amount) {
            $invoice = SubscriptionInvoice::create(['store_id' => $model->id, 'kind' => $kind, 'billing_cycle' => $kind === 'live_month' ? 'monthly' : 'once',
                'amount' => 0, 'status' => 'offered', 'number' => Str::random(20), 'idempotency_key' => (string) Str::uuid(), 'provider' => 'offered', 'paid_at' => now()]);
            $invoice->update(['number' => 'JM-'.now()->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);
            // Crédit offert : ajouté au crédit, la facture reste à 0 F (rien n'a été encaissé)
            $kind === 'live_credit'
                ? $model->forceFill(['live_credit' => (int) $model->live_credit + (int) $amount])->save()
                : $this->billing->activate($model, $invoice);
            $invoice->update(['period_start' => now(), 'period_end' => $kind === 'live_month' ? $model->live_month_until : null]);
        }
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => $model->id, 'action' => 'lives_offered', 'data' => $data, 'ip' => $request->ip()]);

        return response()->json(['data' => $this->billing->overview($model)]);
    }
}
