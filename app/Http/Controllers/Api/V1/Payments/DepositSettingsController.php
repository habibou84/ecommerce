<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Acompte automatique de la boutique : jamais, au-dessus d'un montant, ou toujours (pourcentage du total) ; et ce
 * qui se passe tant qu'il n'est pas payé (la commande attend avant le livreur, ou juste un rappel). Marche aussi
 * sans paiement en ligne (lien Wave de la boutique, reçu envoyé sur WhatsApp).
 */
class DepositSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => [
            'deposit_mode' => $store->deposit_mode ?? 'never',
            'deposit_min_total' => $store->deposit_min_total,
            'deposit_rule_percent' => $store->deposit_rule_percent,
            'deposit_blocks' => (bool) ($store->deposit_blocks ?? true),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'deposit_mode' => ['required', Rule::in(['never', 'above', 'always'])],
            'deposit_min_total' => ['nullable', 'integer', 'min:1', 'max:100000000', 'required_if:deposit_mode,above'],
            'deposit_rule_percent' => ['nullable', 'integer', 'between:5,100', 'required_unless:deposit_mode,never'],
            'deposit_blocks' => ['required', 'boolean'],
        ], [], ['deposit_min_total' => 'montant', 'deposit_rule_percent' => 'pourcentage de l\'acompte']);
        $this->currentStore()->forceFill($data)->save();

        return $this->show();
    }
}
