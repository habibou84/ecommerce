<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\User;
use App\Rules\StoreSlug;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\StoreGroup;
use App\Services\Couriers\SharedCouriers;
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

    /**
     * Livreurs des boutiques de l'abonnement commun, par personne.
     */
    public function couriers(Request $request, SharedCouriers $shared): JsonResponse
    {
        $stores = $this->group($request);

        return response()->json(['data' => ['people' => $shared->people($stores), 'stores' => $stores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values()]]);
    }

    /**
     * Le livreur travaille aussi pour une autre boutique.
     */
    public function shareCourier(Request $request, SharedCouriers $shared, int $courier, int $store): JsonResponse
    {
        $stores = $this->group($request);
        $target = $stores->firstWhere('id', $store) ?? abort(404);
        $shared->share($this->groupCourier($stores, $courier), $target);

        return $this->couriers($request, $shared);
    }

    /**
     * Le livreur ne travaille plus pour cette boutique.
     */
    public function unshareCourier(Request $request, SharedCouriers $shared, int $courier, int $store): JsonResponse
    {
        $stores = $this->group($request);
        $person = $this->groupCourier($stores, $courier);
        $row = Courier::withoutGlobalScope(StoreScope::class)->with('store')->where('store_id', $store)->whereIn('store_id', $stores->pluck('id'))
            ->where(fn ($q) => $person->user_id ? $q->where('user_id', $person->user_id) : $q->where('phone', $person->phone))->firstOrFail();
        $shared->unshare($row);

        return $this->couriers($request, $shared);
    }

    /**
     * Un seul versement d'espèces pour toutes ses boutiques, réparti entre elles.
     */
    public function remitCourier(Request $request, SharedCouriers $shared, int $courier): JsonResponse
    {
        $stores = $this->group($request);
        $data = $request->validate([
            'received_amount' => ['required', 'integer', 'min:0'],
            'method' => ['required', Rule::in(['cash', 'wave', 'orange_money', 'mtn_momo', 'moov_money'])],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['received_amount' => 'montant reçu']);

        $remittances = $shared->remit($shared->linked($this->groupCourier($stores, $courier)), $data['received_amount'], $data['method'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => $remittances->map(fn ($r) => [
            'store_id' => $r->store_id, 'expected_amount' => $r->expected_amount, 'received_amount' => $r->received_amount, 'difference' => $r->difference,
        ])->values()], 201);
    }

    /**
     * Paie de toutes ses boutiques réglée en une fois.
     */
    public function payCourier(Request $request, SharedCouriers $shared, int $courier): JsonResponse
    {
        $stores = $this->group($request);
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'wave', 'orange_money', 'mtn_momo', 'moov_money', 'bank'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $payouts = $shared->payAll($shared->linked($this->groupCourier($stores, $courier)), $data['method'], $data['reference'] ?? null, $request->user());

        return response()->json(['data' => $payouts->map(fn ($p) => ['store_id' => $p->store_id, 'total' => $p->total])->values()], 201);
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

    /**
     * Fiche d'un livreur d'une des boutiques de l'abonnement commun.
     *
     * @param  Collection<int, Store>  $stores
     */
    private function groupCourier(Collection $stores, int $id): Courier
    {
        return Courier::withoutGlobalScope(StoreScope::class)->with('store')->whereIn('store_id', $stores->pluck('id'))->findOrFail($id);
    }

    private function owner(Request $request): User
    {
        abort_unless($this->currentStore()->owner_id === $request->user()->id, 403, 'Réservé au propriétaire de la boutique.');

        return $request->user();
    }
}
