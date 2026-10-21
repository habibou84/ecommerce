<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Rules\StoreSlug;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Accounts\StoreSignup;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    public function start(Request $request, StoreSignup $signup, LoginHandoff $handoff, PhoneVerifier $verifier): JsonResponse
    {
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);

        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:80'],
            'slug' => ['required', new StoreSlug],
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', new PhoneRule],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [], [
            'store_name' => 'nom de l\'espace marchand',
            'slug' => 'adresse',
            'name' => 'votre nom',
            'phone' => 'numéro de téléphone',
            'password' => 'mot de passe',
        ]);
        $data['phone'] = PhoneNumber::normalize($data['phone']);

        $result = $signup->start($data, $request->ip());

        // Compte existant : boutique créée tout de suite
        if ($result['store'] !== null) {
            return response()->json(['redirect' => $handoff->url($result['user'], $result['store'], '/gestion?bienvenue=1')], 201);
        }

        return response()->json(VerificationController::pending($result['verification'], $result['token'], $result['demo_code'], $verifier), 202);
    }
}
