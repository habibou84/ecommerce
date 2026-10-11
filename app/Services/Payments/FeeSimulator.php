<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PaymentRoute;
use App\Models\Store;

/**
 * Simulateur de frais (docs/PLAN.md §6.3) : à partir des ventes des 3 derniers mois et des taux saisis par le
 * marchand, coût mensuel de chaque prestataire pour chaque moyen de paiement, et combinaison la moins chère.
 */
class FeeSimulator
{
    public function __construct(private readonly PaymentDesk $payments) {}

    /**
     * @return array<string, mixed>
     */
    public function simulate(Store $store): array
    {
        $since = now()->subMonths(3);
        $orders = Order::forStore($store->id)->where('created_at', '>=', $since)->whereNotIn('status', ['cancelled'])->get(['total']);
        $monthlyVolume = (int) round($orders->sum('total') / 3);
        $monthlyCount = (int) ceil($orders->count() / 3);
        $averageTicket = $orders->count() ? (int) round($orders->avg('total')) : 0;

        // Répartition par moyen : paiements mobile money et cartes déjà reçus, sinon parts égales
        $methods = array_keys(PaymentDesk::METHODS);
        $observed = Payment::forStore($store->id)->where('status', 'succeeded')->where('created_at', '>=', $since)
            ->whereIn('method', $methods)->groupBy('method')->selectRaw('method, SUM(amount) AS total')->pluck('total', 'method');
        $share = $observed->sum() > 0
            ? collect($methods)->mapWithKeys(fn ($m) => [$m => (float) ($observed[$m] ?? 0) / $observed->sum()])
            : collect($methods)->mapWithKeys(fn ($m) => [$m => 1 / count($methods)]);

        $accounts = PaymentAccount::forStore($store->id)->where('status', 'active')->get();
        $current = PaymentRoute::forStore($store->id)->where('priority', 1)->pluck('payment_account_id', 'method');

        $rows = [];
        foreach ($methods as $method) {
            $volume = (int) round($monthlyVolume * $share[$method]);
            $count = (int) round($monthlyCount * $share[$method]);
            $options = $accounts->filter(fn (PaymentAccount $a) => in_array($method, $this->payments->gateway($a->provider)->methods(), true))
                ->map(fn (PaymentAccount $a) => [
                    'account_id' => $a->id,
                    'name' => $a->name(),
                    'fee_percent' => $a->fee_percent,
                    'monthly_cost' => (int) round($volume * ($a->fee_percent ?? 0) / 100) + $a->fee_fixed * $count,
                ])->sortBy('monthly_cost')->values();

            $rows[] = [
                'method' => $method,
                'label' => PaymentDesk::METHODS[$method],
                'share' => round($share[$method] * 100),
                'monthly_volume' => $volume,
                'options' => $options,
                'best_account_id' => $options->first()['account_id'] ?? null,
                'current_account_id' => $current[$method] ?? null,
                'current_cost' => $options->firstWhere('account_id', $current[$method] ?? null)['monthly_cost'] ?? null,
            ];
        }

        $best = collect($rows)->sum(fn ($r) => $r['options']->first()['monthly_cost'] ?? 0);
        $currentTotal = collect($rows)->sum(fn ($r) => $r['current_cost'] ?? 0);

        return [
            'monthly_volume' => $monthlyVolume,
            'monthly_orders' => $monthlyCount,
            'average_ticket' => $averageTicket,
            'based_on_payments' => $observed->sum() > 0,
            'methods' => $rows,
            'best_monthly_cost' => $best,
            'current_monthly_cost' => $currentTotal,
            'missing_rates' => $accounts->contains(fn ($a) => $a->fee_percent === null),
        ];
    }

    /**
     * Frais réels des paiements en ligne reçus, par compte (90 derniers jours).
     *
     * @return list<array<string, mixed>>
     */
    public function report(Store $store, int $days = 90): array
    {
        $rows = Payment::forStore($store->id)->where('status', 'succeeded')->whereNotNull('payment_account_id')
            ->where('paid_at', '>=', now()->subDays($days))
            ->groupBy('payment_account_id')
            ->selectRaw('payment_account_id, COUNT(*) AS count, SUM(amount) AS amount, SUM(COALESCE(fee, 0)) AS fees')
            ->get()->keyBy('payment_account_id');

        return PaymentAccount::forStore($store->id)->get()->map(fn (PaymentAccount $a) => [
            'account_id' => $a->id,
            'name' => $a->name(),
            'count' => (int) ($rows[$a->id]->count ?? 0),
            'amount' => (int) ($rows[$a->id]->amount ?? 0),
            'fees' => (int) ($rows[$a->id]->fees ?? 0),
            'net' => (int) (($rows[$a->id]->amount ?? 0) - ($rows[$a->id]->fees ?? 0)),
            'effective_rate' => ($rows[$a->id]->amount ?? 0) > 0 ? round($rows[$a->id]->fees / $rows[$a->id]->amount * 100, 2) : null,
        ])->values()->all();
    }
}
