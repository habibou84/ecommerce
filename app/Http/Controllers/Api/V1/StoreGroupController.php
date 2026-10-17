<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Rules\StoreSlug;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\StoreGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function owner(Request $request): User
    {
        abort_unless($this->currentStore()->owner_id === $request->user()->id, 403, 'Réservé au propriétaire de la boutique.');

        return $request->user();
    }
}
