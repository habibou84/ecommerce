<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Boost;
use App\Models\LiveSession;
use App\Models\LiveSessionBill;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\Credits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Console › Tarifs : commandes payantes oui/non (live, WhatsApp, boost), les 3 packs (commandes, prix, IA incluse),
 * l'illimité 30 jours, ce qui est offert au départ, le poids d'une commande de boost, le minimum pour ouvrir un live
 * et le seuil d'alerte ; ventes du mois ; recharge offerte à une boutique.
 */
class ConsoleCreditsController extends Controller
{
    public function __construct(private readonly Credits $credits) {}

    public function show(): JsonResponse
    {
        $since = now()->startOfMonth();
        $paid = SubscriptionInvoice::withoutGlobalScopes()->whereIn('kind', ['credits_pack', 'credits_month'])->where('status', 'paid')->where('paid_at', '>=', $since)
            ->selectRaw('kind, count(*) as n, sum(amount) as total')->groupBy('kind')->get()->keyBy('kind');
        $used = (int) LiveSession::withoutGlobalScopes()->where('billing', 'credits')->where('channel', 'live')->where('billed_at', '>=', $since)->sum('billed_amount')
            + (int) LiveSessionBill::withoutGlobalScopes()->where('day', '>=', $since->toDateString())->sum('amount')
            + (int) Boost::withoutGlobalScopes()->where('updated_at', '>=', $since)->sum('credits_used');
        $s = $this->credits->settings();

        return response()->json(['data' => $s + [
            'packs' => $this->credits->packs(),
            // PayDunya prêt (clés sur le serveur) : sinon les recharges ne peuvent pas être payées
            'provider_ready' => (bool) (app(BillingSettings::class)->available()[$s['provider']] ?? false),
            'month' => [
                'packs' => ['count' => (int) ($paid['credits_pack']->n ?? 0), 'total' => (int) ($paid['credits_pack']->total ?? 0)],
                'unlimited' => ['count' => (int) ($paid['credits_month']->n ?? 0), 'total' => (int) ($paid['credits_month']->total ?? 0)],
                'orders_used' => $used,
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $count = ['sometimes', 'integer', 'min:0', 'max:100000'];
        $money = ['sometimes', 'integer', 'min:0', 'max:10000000'];
        $rules = ['paid' => ['sometimes', 'boolean'], 'month_price' => ['sometimes', 'integer', 'min:100', 'max:10000000'], 'month_ai' => $count,
            'welcome_orders' => $count, 'welcome_ai' => $count, 'boost_weight' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'min_orders' => $count, 'alert_at' => $count];
        foreach ([1, 2, 3] as $i) {
            $rules += ["pack{$i}_orders" => ['sometimes', 'integer', 'min:1', 'max:100000'], "pack{$i}_price" => $money, "pack{$i}_ai" => $count];
        }
        $data = $request->validate($rules, [], ['month_price' => 'prix de l\'illimité', 'boost_weight' => 'commande de boost', 'min_orders' => 'minimum pour ouvrir un live']);
        $next = [...$this->credits->settings(), ...$data];
        if (! ($next['pack1_orders'] < $next['pack2_orders'] && $next['pack2_orders'] < $next['pack3_orders'])) {
            throw ValidationException::withMessages(['pack2_orders' => 'Les packs vont du plus petit au plus grand.']);
        }
        $before = $this->credits->settings();
        $after = $this->credits->update($data);
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => 'tarifs_updated', 'data' => ['before' => $before, 'after' => $after], 'ip' => $request->ip()]);

        return $this->show();
    }

    /**
     * Offert à une boutique : des commandes, des actions IA, ou 30 jours d'illimité (facture à 0 F).
     */
    public function offer(Request $request, int $store): JsonResponse
    {
        $model = Store::findOrFail($store);
        $data = $request->validate([
            'orders' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'ai' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'month' => ['nullable', 'boolean'],
        ], [], ['orders' => 'commandes offertes', 'ai' => 'actions IA offertes']);
        if (empty($data['orders']) && empty($data['ai']) && empty($data['month'])) {
            throw ValidationException::withMessages(['orders' => 'Choisissez des commandes, des actions IA ou 30 jours à offrir.']);
        }
        $offers = [];
        if (! empty($data['orders']) || ! empty($data['ai'])) {
            $offers[] = ['kind' => 'credits_pack', 'billing_cycle' => 'once', 'credit_orders' => (int) ($data['orders'] ?? 0), 'ai_actions' => (int) ($data['ai'] ?? 0)];
        }
        if (! empty($data['month'])) {
            $offers[] = ['kind' => 'credits_month', 'billing_cycle' => 'monthly', 'ai_actions' => 0];
        }
        foreach ($offers as $offer) {
            $invoice = SubscriptionInvoice::create($offer + ['store_id' => $model->id, 'amount' => 0, 'status' => 'offered', 'number' => Str::random(20),
                'idempotency_key' => (string) Str::uuid(), 'provider' => 'offered', 'paid_at' => now()]);
            $invoice->update(['number' => 'JM-'.now()->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);
            $this->credits->activate($model, $invoice);
            $invoice->update(['period_start' => now(), 'period_end' => $invoice->kind === 'credits_month' ? $model->live_month_until : null]);
        }
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => $model->id, 'action' => 'credits_offered', 'data' => $data, 'ip' => $request->ip()]);

        return response()->json(['data' => $this->credits->overview($model->fresh())]);
    }
}
