<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\OutboundMessage;
use App\Models\WhatsappAccount;
use App\Services\Storefront\CartService;
use App\Services\WhatsApp\CustomerMessenger;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;

/**
 * WhatsApp de la boutique (API Cloud de Meta) : connexion du numéro, modèles et automatismes, messages envoyés ;
 * paniers abandonnés à relancer.
 */
class WhatsAppController extends Controller
{
    public function show(): JsonResponse
    {
        $account = WhatsappAccount::first();

        return response()->json(['data' => [
            'account' => $account ? [
                'phone_number_id' => $account->phone_number_id,
                'waba_id' => $account->waba_id,
                'display_phone' => $account->display_phone,
                'status' => $account->status,
                'last_error' => $account->last_error,
                'templates' => (object) ($account->templates ?? []),
                'automations' => (object) ($account->automations ?? []),
                'webhook_url' => Tenancy::apiUrl('/webhooks/whatsapp/'.$account->webhook_uuid),
                'verify_token' => $account->verify_token,
            ] : null,
            'events' => CustomerMessenger::EVENTS,
            'messages' => OutboundMessage::latest('id')->limit(30)->get()->map(fn (OutboundMessage $m) => [
                'id' => $m->id, 'event' => $m->event, 'to' => PhoneNumber::display($m->to_phone), 'status' => $m->status, 'error' => $m->error,
                'related_type' => $m->related_type, 'related_id' => $m->related_id, 'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $account = WhatsappAccount::first();
        $required = $account ? 'sometimes' : 'required';
        $data = $request->validate([
            'phone_number_id' => [$required, 'nullable', 'string', 'max:40'],
            'waba_id' => ['nullable', 'string', 'max:40'],
            'display_phone' => ['nullable', 'string', 'max:20'],
            'access_token' => [$account ? 'nullable' : 'required', 'string', 'max:1000'],
            'app_secret' => [$account ? 'nullable' : 'required', 'string', 'max:100'],
            'templates' => ['sometimes', 'array'],
            'templates.*' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'automations' => ['sometimes', 'array'],
            'automations.*' => ['boolean'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ], ['templates.*.regex' => 'Nom de modèle Meta : lettres minuscules, chiffres et _ uniquement.'], ['phone_number_id' => 'identifiant du numéro', 'access_token' => 'jeton d\'accès', 'app_secret' => 'clé secrète de l\'application']);

        foreach (['templates', 'automations'] as $key) {
            if (isset($data[$key])) {
                // Les réglages envoyés complètent ceux déjà enregistrés
                $data[$key] = array_intersect_key($data[$key], CustomerMessenger::EVENTS) + ($account?->{$key} ?? []);
            }
        }
        // Jeton et secret laissés vides : les valeurs enregistrées sont gardées
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        $account
            ? $account->update($data)
            : WhatsappAccount::create($data + ['store_id' => $this->currentStore()->id, 'status' => 'active', 'automations' => array_fill_keys(array_keys(CustomerMessenger::EVENTS), false)]);

        return $this->show();
    }

    public function destroy(): JsonResponse
    {
        WhatsappAccount::query()->delete();

        return $this->show();
    }

    /**
     * Paniers avec un numéro et des articles, sans commande depuis plus d'une heure (7 derniers jours).
     */
    public function abandonedCarts(CartService $carts): JsonResponse
    {
        $store = $this->currentStore();
        $list = Cart::where('status', 'active')->whereNotNull('phone')->where('last_activity_at', '<=', now()->subHour())
            ->where('last_activity_at', '>=', now()->subDays(7))->whereHas('items')->latest('last_activity_at')->limit(100)->get();

        return response()->json(['data' => $list->map(function (Cart $cart) use ($carts, $store) {
            $summary = $carts->summary($store, $cart);
            $link = $store->url('/panier/reprendre/'.$cart->token);
            $text = 'Bonjour'.($cart->name ? ' '.Str::before($cart->name, ' ') : '').", vous avez laissé des articles dans votre panier chez {$store->name} ("
                .Money::format($summary['subtotal']).'). Vous pouvez finaliser votre commande ici : '.$link;

            return [
                'id' => $cart->id,
                'name' => $cart->name,
                'phone' => PhoneNumber::display($cart->phone),
                'items' => collect($summary['lines'])->map(fn ($l) => $l['quantity'].' × '.$l['title'].($l['variant_title'] ? ' ('.$l['variant_title'].')' : ''))->values(),
                'subtotal' => $summary['subtotal'],
                'last_activity_at' => $cart->last_activity_at?->toIso8601String(),
                'recovery_sent_at' => $cart->recovery_sent_at?->toIso8601String(),
                'whatsapp_url' => 'https://wa.me/'.ltrim($cart->phone, '+').'?text='.rawurlencode($text),
            ];
        })]);
    }

    /**
     * Relance faite à la main (lien WhatsApp ouvert par le vendeur).
     */
    public function markRecovered(int $cart): JsonResponse
    {
        Cart::findOrFail($cart)->forceFill(['recovery_sent_at' => now()])->save();

        return response()->json(['message' => 'Relance notée.']);
    }
}
