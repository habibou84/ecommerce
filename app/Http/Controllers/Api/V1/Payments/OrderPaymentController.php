<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PaymentDesk;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;

/**
 * Lien de paiement d'une commande (à envoyer par WhatsApp) et remboursements.
 */
class OrderPaymentController extends Controller
{
    public function link(Request $request, int $order, PaymentDesk $payments): JsonResponse
    {
        $data = $request->validate(['amount' => ['nullable', 'integer', 'min:5'], 'description' => ['nullable', 'string', 'max:120']]);
        $model = Order::with('store')->findOrFail($order);
        $store = $model->store;

        $link = $payments->createLink($model, $data['amount'] ?? null, $data['description'] ?? null, $request->user());
        $amount = $link->amount;
        $url = $store->url('/payer/'.$link->token);
        $text = 'Bonjour'.($model->customer_name ? ' '.Str::before($model->customer_name, ' ') : '').", voici le lien pour payer votre commande {$model->reference()} chez {$store->name} (".Money::format($amount).") : {$url}";

        // Envoi automatique par le WhatsApp de la boutique s'il est connecté et activé
        $sent = app(CustomerMessenger::class)->forOrder($model, 'payment_link', ['amount' => $amount, 'url' => $url]) !== null;

        return response()->json(['data' => [
            'url' => $url,
            'sent_by_whatsapp' => $sent,
            'amount' => $amount,
            'expires_at' => $link->expires_at->toIso8601String(),
            'whatsapp_url' => 'https://wa.me/'.ltrim((string) $model->customer_phone, '+').'?text='.rawurlencode($text),
        ]], 201);
    }

    public function refund(Request $request, int $order, PaymentDesk $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
            'manual' => ['sometimes', 'boolean'],
        ], [], ['amount' => 'montant']);
        $refund = $payments->refund(Order::findOrFail($order), $data['amount'], $data['reason'] ?? null, $request->user(), $request->boolean('manual'));

        return response()->json(['data' => [
            'amount' => $refund->amount,
            'method' => $refund->method,
            'message' => $refund->method === 'provider' ? 'Remboursé par le prestataire.' : 'Remboursement enregistré : rendez l\'argent au client (le prestataire ne permet pas de le faire automatiquement).',
        ]], 201);
    }
}
