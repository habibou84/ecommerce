<?php

namespace App\Http\Controllers\Api\Partners;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Services\Billing\PremiumCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Cartes Membre Premium demandées par les entreprises de Jibiat (contrat socle 1.6) : offrir une carte à un marchand,
 * lire les cartes de l'entreprise, en annuler une. Appels signés (VerifyPartnerSignature).
 */
class PremiumCardController extends Controller
{
    private const SLUG = 'regex:/^[a-z0-9][a-z0-9-]{0,79}$/';

    public function store(Request $request, PremiumCards $cards): JsonResponse
    {
        $payload = $request->json()->all();
        $errors = Contract::validate('premium-card-request', $payload);
        if ($errors !== []) {
            throw ValidationException::withMessages(['request' => $errors]);
        }
        $request->validate([
            'company.slug' => ['required', self::SLUG],
            'company.name' => ['required', 'string', 'max:120'],
            'company.phone' => ['nullable', 'string', 'max:32'],
            'merchant.reference' => ['required', 'string', 'max:64'],
            'merchant.name' => ['required', 'string', 'max:120'],
            'merchant.phone' => ['required', new PhoneRule],
        ]);
        [$key, $created] = $cards->offer($payload);

        return response()->json(['data' => $cards->present($key)], $created ? 201 : 200);
    }

    public function index(Request $request, PremiumCards $cards): JsonResponse
    {
        $slug = $this->company($request);

        return response()->json(['data' => $cards->list($slug)->map(fn (AccessKey $key) => $cards->present($key))->values()]);
    }

    public function destroy(Request $request, PremiumCards $cards, string $code): JsonResponse
    {
        $key = $cards->revoke($this->company($request), $code);
        abort_if($key === null, 404, 'Cette carte n\'existe pas pour cette entreprise.');

        return response()->json(['data' => $cards->present($key->refresh()->load('batch.plan', 'store'))]);
    }

    private function company(Request $request): string
    {
        return $request->validate(['company' => ['required', self::SLUG]])['company'];
    }
}
