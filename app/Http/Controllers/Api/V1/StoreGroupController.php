<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Rules\StoreSlug;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\StoreGroup;
use App\Services\Finance\CashOverview;
use App\Services\Reports\GroupStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * « Mes boutiques » : boutiques du propriétaire, création d'une autre boutique et abonnement commun.
 * Réservé au propriétaire de la boutique de l'adresse.
 */
class StoreGroupController extends Controller
{
    public function __construct(private readonly StoreGroup $group) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->group->overview($this->owner($request), $this->currentStore())]);
    }

    /**
     * Statistiques de la période pour toutes les boutiques de l'abonnement commun.
     */
    public function stats(Request $request, GroupStats $stats): JsonResponse
    {
        $stores = $this->group($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $tz = $this->currentStore()->timezone;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz)->startOfDay() : $to->subDays(29)->startOfDay();
        if ($from->diffInDays($to) > 366) {
            return response()->json(['message' => 'Choisissez une période d\'un an au plus.'], 422);
        }

        return response()->json(['data' => $stats->report($stores, $from->utc(), $to->utc(), $tz)]);
    }

    /**
     * Caisse commune : argent dehors et mouvements de la période, par boutique et au total.
     */
    public function cash(Request $request, GroupStats $stats): JsonResponse
    {
        $stores = $this->group($request);
        $period = $request->validate(['period' => ['nullable', Rule::in(CashOverview::PERIODS)]])['period'] ?? 'today';

        return response()->json(['data' => $stats->cash($stores, $period) + ['period' => $period]]);
    }

    public function store(Request $request, LoginHandoff $handoff): JsonResponse
    {
        $owner = $this->owner($request);
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', new StoreSlug],
        ], [], ['name' => 'nom de la boutique', 'slug' => 'adresse']);

        $store = $this->group->create($owner, $this->currentStore(), $data['name'], $data['slug']);

        return response()->json(['redirect' => $handoff->url($owner, $store, '/gestion?bienvenue=1')], 201);
    }

    public function attach(Request $request, int $store): JsonResponse
    {
        $owner = $this->owner($request);
        $this->group->attach($owner, $this->currentStore(), Store::findOrFail($store));

        return $this->index($request);
    }

    public function detach(Request $request, int $store): JsonResponse
    {
        $owner = $this->owner($request);
        $this->group->detach($owner, Store::findOrFail($store));

        return $this->index($request);
    }

    /**
     * Boutiques de l'abonnement commun (réservé au propriétaire).
     *
     * @return Collection<int, Store>
     */
    private function group(Request $request): Collection
    {
        $this->owner($request);

        return $this->group->stores($this->currentStore())
            ?? abort(response()->json(['message' => 'Les chiffres communs à plusieurs boutiques sont compris dans l\'offre Business.', 'upgrade' => true], 403));
    }

    private function owner(Request $request): User
    {
        abort_unless($this->currentStore()->owner_id === $request->user()->id, 403, 'Réservé au propriétaire de la boutique.');

        return $request->user();
    }
}
