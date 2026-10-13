<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentLink;
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

        abort_if($payments->availableMethods($store) === [], 422, 'Ajoutez d\'abord un compte de paiement (Réglages › Paiement en ligne).');
        abort_if(in_array($model->status->value, ['cancelled', 'returned'], true) || $model->amountDue() <= 0, 422, 'Il n\'y a rien à payer pour cette commande.');
        $amount = min($data['amount'] ?? $model->amountDue(), $model->amountDue());

        // Un seul lien ouvert par commande : le précédent est remplacé
        PaymentLink::where('order_id', $model->id)->where('status', 'open')->update(['status' => 'cancelled']);
        $link = PaymentLink::create([
            'store_id' => $store->id,
            'order_id' => $model->id,
            'token' => Str::random(24),
            'amount' => $amount,
            'description' => $data['description'] ?? null,
            'expires_at' => now()->addDays(7),
            'created_by' => $request->user()->id,
        ]);
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
