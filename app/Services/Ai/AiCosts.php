<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\Store;
use App\Services\Billing\PlanLimits;
use Carbon\CarbonImmutable;

/**
 * Coût estimé de l'assistant IA pour la console : actions et jetons du mois, par boutique et par modèle,
 * au prix public des modèles (config services.anthropic.prices) converti en francs CFA.
 */
class AiCosts
{
    public function __construct(private readonly PlanLimits $limits) {}

    /**
     * @return array<string, mixed>
     */
    public function month(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', 'Africa/Abidjan')->startOfMonth();
        $rows = AiUsage::withoutGlobalScopes()
            ->whereBetween('created_at', [$start->utc(), $start->endOfMonth()->utc()])
            ->groupBy('store_id', 'model')
            ->select('store_id', 'model')
            ->selectRaw('SUM(CASE WHEN succeeded THEN 1 ELSE 0 END) AS actions, SUM(CASE WHEN succeeded THEN 0 ELSE 1 END) AS failures')
            ->selectRaw('SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens')
            ->get();

        $stores = Store::whereIn('id', $rows->pluck('store_id')->unique())->get()->keyBy('id');
        $perStore = $rows->groupBy('store_id')->map(function ($lines, $storeId) use ($stores) {
            $store = $stores->get($storeId);
            $plan = $store ? $this->limits->plan($store) : null;
            $usd = $lines->sum(fn ($l) => $this->usd($l->model, (int) $l->input_tokens, (int) $l->output_tokens));

            return [
                'store' => $store ? ['id' => $store->id, 'name' => $store->name] : ['id' => (int) $storeId, 'name' => '(supprimée)'],
                'plan' => $plan?->name,
                'plan_price' => $plan?->monthly_price,
                'quota' => $plan?->limits['ai_actions_per_month'] ?? null,
                'actions' => (int) $lines->sum('actions'),
                'failures' => (int) $lines->sum('failures'),
                'input_tokens' => (int) $lines->sum('input_tokens'),
                'output_tokens' => (int) $lines->sum('output_tokens'),
                'models' => $lines->pluck('model')->unique()->values()->all(),
                'cost_usd' => round($usd, 2),
                'cost' => $this->xof($usd),
            ];
        })->sortByDesc('cost_usd')->values();

        $byModel = $rows->groupBy('model')->map(fn ($lines, $model) => [
            'model' => $model,
            'actions' => (int) $lines->sum('actions'),
            'cost' => $this->xof($lines->sum(fn ($l) => $this->usd($model, (int) $l->input_tokens, (int) $l->output_tokens))),
            'priced' => isset(config('services.anthropic.prices')[$model]),
        ])->values();

        $totalUsd = $perStore->sum('cost_usd');

        return [
            'month' => $month,
            'months' => collect(range(0, 5))->map(fn ($i) => CarbonImmutable::now('Africa/Abidjan')->startOfMonth()->subMonths($i)->format('Y-m'))->all(),
            'usd_rate' => (float) config('services.anthropic.usd_rate'),
            'total' => [
                'actions' => (int) $perStore->sum('actions'),
                'failures' => (int) $perStore->sum('failures'),
                'stores' => $perStore->count(),
                'cost_usd' => round($totalUsd, 2),
                'cost' => $this->xof($totalUsd),
            ],
            'models' => $byModel,
            'stores' => $perStore,
        ];
    }

    private function usd(string $model, int $input, int $output): float
    {
        // Modèle sans prix connu : au prix du plus cher, pour ne jamais sous-estimer
        [$in, $out] = config('services.anthropic.prices')[$model] ?? collect(config('services.anthropic.prices'))->sortByDesc(fn ($p) => $p[1])->first();

        return ($input * $in + $output * $out) / 1_000_000;
    }

    private function xof(float $usd): int
    {
        return (int) round($usd * (float) config('services.anthropic.usd_rate'));
    }
}
