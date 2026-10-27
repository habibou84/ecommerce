<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Rules\StoreSlug;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Accounts\StoreSignup;
use App\Services\Billing\AccessKeys;
use App\Services\Shipping\CarrierDirectory;
use App\Support\Tenancy;
use App\Support\TrialOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Création de boutique sur jibiat-marchand.com.
 */
class SignupController extends Controller
{
    /**
     * Disponibilité d'une adresse, pendant la saisie.
     */
    public function checkSlug(Request $request): JsonResponse
    {
        $slug = strtolower(trim((string) $request->query('slug')));
        $validator = Validator::make(['slug' => $slug], ['slug' => [new StoreSlug]]);

        return response()->json([
            'slug' => $slug,
            'available' => $validator->passes(),
            'message' => $validator->errors()->first('slug') ?: null,
            'url' => Tenancy::storeUrl($slug),
        ]);
    }

    /**
     * Entreprise de livraison qui invite l'e-commerçant (lien « ?partenaire= ») et essai prolongé qu'elle lui offre.
     */
    public function partner(Request $request, CarrierDirectory $directory): JsonResponse
    {
        $company = $directory->find((string) $request->query('slug'));

        return response()->json(['data' => $company === null ? null : [
            'slug' => $company['slug'],
            'name' => $company['name'],
            'logo_url' => $company['logo_url'],
            'trial' => TrialOffer::duration(TrialOffer::partnerDays()).' de l\'offre '.TrialOffer::planName(),
        ]]);
    }

    public function start(Request $request, StoreSignup $signup, LoginHandoff $handoff, PhoneVerifier $verifier, CarrierDirectory $directory, AccessKeys $keys): JsonResponse
    {
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);

        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:80'],
            'slug' => ['required', new StoreSlug],
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', new PhoneRule],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'partner' => ['nullable', 'string', 'max:80'],
            'key' => ['nullable', 'string', 'max:20'],
        ], [], [
            'store_name' => 'nom de la boutique',
            'slug' => 'adresse',
            'name' => 'votre nom',
            'phone' => 'numéro de téléphone',
            'password' => 'mot de passe',
        ]);
        $data['phone'] = PhoneNumber::normalize($data['phone']);
        // Parrainage reconnu seulement pour une entreprise présente dans l'annuaire
        $data['partner'] = filled($data['partner'] ?? null) ? $directory->find($data['partner'])['slug'] ?? null : null;
        // Clé d'accès de la carte scannée : vérifiée maintenant, activée à la création de la boutique
        if (filled($data['key'] ?? null)) {
            $key = $keys->lookup($data['key']);
            if ($key === null || ! $key['available']) {
                throw ValidationException::withMessages(['key' => $key === null ? 'Cette clé n\'existe pas ou n\'est plus valable.' : 'Cette clé a déjà été utilisée.']);
            }
            $data['key'] = AccessKeys::normalize($data['key']);
        }

        $result = $signup->start($data, $request->ip());

        // Compte existant : boutique créée tout de suite
        if ($result['store'] !== null) {
            return response()->json(['redirect' => $handoff->url($result['user'], $result['store'], '/gestion?bienvenue=1')], 201);
        }

        return response()->json(VerificationController::pending($result['verification'], $result['token'], $result['demo_code'], $verifier), 202);
    }
}
