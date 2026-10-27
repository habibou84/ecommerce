<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Billing\AccessKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clé d'accès d'une carte à QR code : page publique de la clé (jibiat-marchand.com/cle/CODE) et activation depuis
 * Réglages › Abonnement.
 */
class AccessKeyController extends Controller
{
    public function __construct(private readonly AccessKeys $keys) {}

    public function show(string $code): JsonResponse
    {
        $key = $this->keys->lookup($code);
        abort_if($key === null, 404, 'Cette clé n\'existe pas ou n\'est plus valable. Vérifiez le code écrit sur la carte.');

        return response()->json(['data' => $key]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'code de la clé']);
        $store = $this->currentStore();
        $this->keys->redeem($data['code'], $store);

        return response()->json(['data' => $this->keys->present($store)]);
    }
}
