<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Boost;
use App\Services\Marketing\BoostAds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Assistant Boost : ce qu'il faut pour lancer une pub, taille du public, lancement, suivi (« Mes boosts »), pause,
 * reprise, arrêt, prolongation.
 */
class BoostAdsController extends Controller
{
    public function __construct(private readonly BoostAds $ads) {}

    public function setup(): JsonResponse
    {
        return response()->json(['data' => $this->ads->setup($this->currentStore())]);
    }

    public function audience(Request $request): JsonResponse
    {
        $data = $request->validate($this->targetingRules() + ['ad_account_id' => ['required', 'string', 'max:40']]);
        $store = $this->currentStore();
        $targeting = $this->ads->targeting($store, $data['zones'] ?? [], $data['age_min'], $data['age_max'], $data['gender']);

        return response()->json(['data' => $this->ads->audience($store, $data['ad_account_id'], $targeting)]);
    }

    public function index(): JsonResponse
    {
        $boosts = Boost::query()->latest('id')->limit(30)->get();
        // Chiffres relus chez Meta au plus toutes les 15 minutes
        $boosts->filter(fn (Boost $b) => in_array($b->status, Boost::LIVE, true) && (! $b->synced_at || $b->synced_at->lt(now()->subMinutes(15))))
            ->each(fn (Boost $b) => $this->ads->sync($b));

        return response()->json(['data' => $boosts->map(fn (Boost $b) => $this->ads->present($b))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->targetingRules() + [
            'kind' => ['required', Rule::in(['product', 'products', 'catalog'])],
            'product_ids' => ['exclude_if:kind,catalog', 'required', 'array', 'min:1', 'max:10'],
            'product_ids.*' => ['integer', 'distinct'],
            'collection_id' => ['nullable', 'integer'],
            'daily_budget' => ['required', 'integer', 'min:500', 'max:1000000'],
            'days' => ['required', 'integer', 'min:1', 'max:60'],
            'message' => ['required', 'string', 'max:600'],
            'ad_account_id' => ['required', 'string', 'max:40'],
            'page_id' => ['required', 'string', 'max:40'],
        ], [], ['product_ids' => 'produits', 'daily_budget' => 'budget par jour', 'days' => 'durée', 'message' => 'texte de la pub', 'page_id' => 'page Facebook', 'ad_account_id' => 'compte publicitaire']);
        if ($data['kind'] === 'product') {
            $data['product_ids'] = array_slice($data['product_ids'], 0, 1);
        }
        if ($data['kind'] === 'products' && count($data['product_ids']) < 2) {
            return response()->json(['message' => 'Choisis au moins 2 produits pour un carrousel.', 'errors' => ['product_ids' => ['Choisis au moins 2 produits.']]], 422);
        }
        $boost = $this->ads->launch($this->currentStore(), $request->user(), $data);

        return response()->json(['data' => $this->ads->present($boost)], 201);
    }

    public function action(Request $request, Boost $boost): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'stop', 'extend'])], 'days' => ['required_if:action,extend', 'integer', 'min:1', 'max:30']]);
        $boost = $data['action'] === 'extend' ? $this->ads->extend($boost, (int) $data['days']) : $this->ads->setStatus($boost, $data['action']);

        return response()->json(['data' => $this->ads->present($boost)]);
    }

    /** Supprime un boost qui n'a pas été créé chez Meta (rien n'est perdu : ni dépense, ni commande) */
    public function destroy(Boost $boost): JsonResponse
    {
        if (! $boost->isDeletable()) {
            throw new BusinessRuleException('Seul un boost qui n\'a pas été créé chez Meta, sans dépense ni commande, peut être supprimé.');
        }
        $boost->delete();

        return response()->json(['data' => ['id' => $boost->id]]);
    }

    /** Efface d'un coup tous les boosts « pas créés » de la boutique */
    public function clearFailed(): JsonResponse
    {
        $deleted = Boost::query()->where('status', 'failed')->where('spend', 0)->where('orders', 0)->where('credits_used', 0)->delete();

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    /** @return array<string, mixed> */
    private function targetingRules(): array
    {
        return [
            'zones' => ['present', 'array', 'max:30'],
            'zones.*' => ['string', Rule::in(array_keys(BoostAds::LOCATIONS))],
            'age_min' => ['required', 'integer', 'min:18', 'max:65'],
            'age_max' => ['required', 'integer', 'gte:age_min', 'max:65'],
            'gender' => ['required', Rule::in(['all', 'women', 'men'])],
        ];
    }
}
