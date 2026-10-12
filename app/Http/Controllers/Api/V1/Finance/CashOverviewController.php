<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\CashOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Écran « Caisse » : où est l'argent de la boutique et ce qui est entré ou sorti sur la période.
 */
class CashOverviewController extends Controller
{
    public function show(Request $request, CashOverview $overview): JsonResponse
    {
        $period = $request->validate(['period' => ['nullable', Rule::in(CashOverview::PERIODS)]])['period'] ?? 'today';
        $store = $this->currentStore();
        ['from' => $from, 'to' => $to] = $overview->range($store, $period);

        return response()->json(['data' => [
            'period' => $period,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'position' => $overview->position($store),
            'flows' => $overview->flows($store, $from, $to),
        ]]);
    }
}
