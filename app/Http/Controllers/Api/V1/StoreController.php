<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\SlugHistory;
use App\Rules\StoreSlug;
use App\Services\Accounts\LoginHandoff;
use App\Support\HostedStores;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Paramètres de la boutique de l'adresse.
 */
class StoreController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function show(): StoreResource
    {
        return StoreResource::make($this->tenancy->store()->load('currentSubscription.plan'));
    }

    public function update(Request $request): StoreResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', new PhoneRule],
            'whatsapp_phone' => ['nullable', new PhoneRule],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
        ], [], [
            'name' => 'nom', 'tagline' => 'slogan', 'phone' => 'téléphone', 'whatsapp_phone' => 'numéro WhatsApp',
            'email' => 'e-mail', 'address' => 'adresse',
        ]);

        foreach (['phone', 'whatsapp_phone'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = PhoneNumber::normalize($data[$field]);
            }
        }

        $store = $this->tenancy->store();
        $store->update($data);

        return StoreResource::make($store->load('currentSubscription.plan'));
    }

    /**
     * Changement d'adresse : l'ancienne redirige vers la nouvelle pendant 90 jours. La session étant liée au
     * nom d'hôte, la personne reçoit un lien de connexion vers la nouvelle adresse.
     */
    public function changeSlug(Request $request, LoginHandoff $handoff): JsonResponse
    {
        $store = $this->tenancy->store();
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);

        // Boutique hébergée : seul le nom de sa page change ({adresse}/{nom})
        if ($store->isHosted()) {
            if (($error = HostedStores::error($request->string('slug')->toString(), $store->host, $store->id)) !== null) {
                throw ValidationException::withMessages(['slug' => $error]);
            }
            $store->forceFill(['path' => $request->string('slug')->toString()])->save();

            return response()->json(['data' => StoreResource::make($store->load('currentSubscription.plan'))->resolve(), 'redirect' => null]);
        }

        $request->validate(['slug' => ['required', new StoreSlug($store->id)]], [], ['slug' => 'adresse']);

        $new = $request->string('slug')->toString();
        if ($new !== $store->slug) {
            DB::transaction(function () use ($store, $new) {
                // Retour à une ancienne adresse de la boutique : elle n'est plus une redirection
                SlugHistory::where('old_slug', $new)->where('store_id', $store->id)->delete();
                SlugHistory::updateOrCreate(
                    ['old_slug' => $store->slug],
                    ['store_id' => $store->id, 'expires_at' => now()->addDays(SlugHistory::KEEP_DAYS)],
                );
                $store->forceFill(['slug' => $new])->save();
            });
        }

        return response()->json([
            'data' => StoreResource::make($store->load('currentSubscription.plan'))->resolve(),
            'redirect' => $handoff->url($request->user(), $store, '/gestion/parametres'),
        ]);
    }
}
