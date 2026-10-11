<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoreDomain;
use App\Services\Billing\PlanLimits;
use App\Services\Domains\CustomDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Domaines personnalisés de la boutique (offre Pro) : site public sur www.maboutique.ci.
 */
class DomainController extends Controller
{
    public function __construct(private readonly CustomDomains $domains) {}

    public function index(PlanLimits $limits): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json([
            'data' => StoreDomain::where('store_id', $store->id)->orderByDesc('is_primary')->orderBy('id')->get()->map(fn ($d) => $this->present($d)),
            'available' => $limits->hasFeature($store, 'custom_domain'),
            'subdomain' => rtrim($store->url(), '/'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['hostname' => ['required', 'string', 'max:253']], [], ['hostname' => 'domaine']);
        $domain = $this->domains->add($this->currentStore(), $request->string('hostname'));

        return response()->json(['data' => $this->present($domain)], 201);
    }

    public function verify(int $domain): JsonResponse
    {
        $model = $this->find($domain);
        $this->domains->verify($model);

        return response()->json(['data' => $this->present($model->fresh())]);
    }

    public function primary(int $domain): JsonResponse
    {
        $this->domains->makePrimary($this->find($domain));

        return $this->index(app(PlanLimits::class));
    }

    public function destroy(int $domain): JsonResponse
    {
        $this->domains->remove($this->find($domain));

        return response()->json(['message' => 'Domaine retiré.']);
    }

    private function find(int $id): StoreDomain
    {
        return StoreDomain::where('store_id', $this->currentStore()->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StoreDomain $d): array
    {
        return [
            'id' => $d->id,
            'hostname' => $d->hostname,
            'url' => 'https://'.$d->hostname,
            'is_primary' => $d->is_primary,
            'verified' => $d->verified_at !== null && $d->ssl_status === 'active',
            'verified_at' => $d->verified_at?->toIso8601String(),
            'last_checked_at' => $d->last_checked_at?->toIso8601String(),
            'last_error' => $d->last_error,
            'records' => $this->domains->instructions($d),
        ];
    }
}
