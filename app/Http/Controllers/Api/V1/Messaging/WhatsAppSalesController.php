<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\LiveSession;
use App\Models\Order;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Services\Ai\Assistant;
use App\Services\Marketing\CatalogFeed;
use App\Services\Marketing\MetaAds;
use App\Services\Messaging\AutoReplier;
use App\Services\Platform\Features;
use App\Services\WhatsApp\CatalogOrders;
use App\Services\WhatsApp\RecapOrders;
use App\Services\WhatsApp\WhatsAppCommerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Menu WhatsApp : ce qui attend (messages non lus, récaps sans réponse, paniers à confirmer), assistant des ventes
 * (récap, catalogue WhatsApp, modèles, commandes avec codes, commandes WhatsApp récentes) et assistant des réponses
 * (réponses automatiques, désactivées par défaut, avec le risque pour un numéro relié par QR code).
 */
class WhatsAppSalesController extends Controller
{
    public function show(Assistant $assistant, CatalogFeed $feed): JsonResponse
    {
        $store = $this->currentStore();
        $connected = WhatsappLink::first()?->isConnected() ?? false;
        $account = WhatsappAccount::first();
        $waiting = Order::query()->where('credit_channel', 'recap')->where('status', OrderStatus::PendingConfirmation->value)->count();

        return response()->json(['data' => [
            'auto_replies' => AutoReplier::settings($store),
            // Numéro par lequel partiraient les réponses : relié (non officiel, risque de blocage) ou officiel
            'channel' => $connected ? 'linked' : ($account ? 'official' : null),
            'assistant' => $assistant->status($store),
            // Modèles montrés aux clients qui écrivent « modèles » (allumé par défaut)
            'models' => [
                'enabled' => (bool) $store->whatsapp_models,
                'orders_last_30_days' => Order::query()->where('source', OrderSource::Whatsapp->value)
                    ->where('internal_note', 'WhatsApp : commandé depuis les modèles')->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'sent_last_7_days' => ConversationMessage::query()->where('type', 'auto')->where('sent_at', '>=', now()->subDays(7))->count(),
            // « récap » écrit par le marchand depuis son téléphone
            'recap' => [
                'enabled' => (bool) $store->whatsapp_recap,
                'remind_hours' => RecapOrders::REMIND_HOURS,
                'expire_hours' => RecapOrders::EXPIRE_HOURS,
                'waiting' => $waiting,
                'confirmed_last_30_days' => Order::query()->where('credit_channel', 'recap')->whereNotNull('confirmed_at')->where('confirmed_at', '>=', now()->subDays(30))->count(),
            ],
            // Ce qui attend, pour l'accueil WhatsApp et l'onglet WhatsApp du téléphone
            'todo' => [
                'unread' => (int) Conversation::query()->where('channel', 'whatsapp')->sum('unread_count'),
                'recaps' => $waiting,
                'carts' => Order::query()->where('credit_channel', 'catalog')->where('status', OrderStatus::PendingConfirmation->value)->count(),
            ],
            // Dernier message écrit depuis le téléphone de la boutique : montre que les échos de Meta arrivent
            'phone_message_at' => ConversationMessage::query()->where('direction', 'out')->where('type', 'text')->whereNull('user_id')->max('sent_at'),
            'account' => $account ? ['display_phone' => $account->display_phone, 'status' => $account->status] : null,
            // Commandes avec codes sans live (fonction de la console) : ouvertes, en préparation, aucune ({}), ou
            // fonction éteinte (null)
            'codes' => ! app(Features::class)->enabled('whatsapp_codes', $store) ? null : LiveSession::query()->where('channel', LiveSession::CHANNEL_WHATSAPP)->whereIn('status', ['live', 'draft'])
                ->latest('id')->first(['id', 'status']) ?? (object) [],
            'orders' => Order::query()->whereNotNull('credit_channel')->latest('id')->limit(8)
                ->get(['id', 'number', 'store_id', 'customer_name', 'customer_phone', 'credit_channel', 'status', 'total', 'created_at'])
                ->map(fn (Order $o) => ['id' => $o->id, 'reference' => $o->reference(), 'customer' => $o->customer_name ?: $o->customer_phone,
                    'channel' => $o->credit_channel, 'status' => $o->status->value, 'status_label' => $o->status->label(), 'total' => (int) $o->total, 'created_at' => $o->created_at?->toIso8601String()])->all(),
            // Catalogue WhatsApp : seulement quand la console l'a allumé (il attend la validation de Meta)
            'catalog' => ! app(Features::class)->enabled('whatsapp_catalog', $store) ? null : [
                'feed_url' => $feed->url($store),
                'items' => count($feed->rows($store)),
                // Catalogue Meta branché en un clic (Marketing › Suivi), sinon null
                'meta' => ($business = app(MetaAds::class)->account($store)) ? ['business' => $business->name, 'catalog_id' => $store->marketingId('meta_catalog_id')] : null,
                'orders_last_30_days' => Order::query()->where('source', OrderSource::Whatsapp->value)
                    ->where('internal_note', CatalogOrders::NOTE)->where('created_at', '>=', now()->subDays(30))->count(),
                // Côté numéro : catalogue rattaché au compte WhatsApp, visible, panier (null : pas de numéro officiel,
                // pas encore de catalogue chez Meta, ou Meta ne répond pas)
                'number' => $account && $store->marketingId('meta_catalog_id') ? app(WhatsAppCommerce::class)->state($account) : null,
                // Après un panier : commune demandée, puis récap avec « Je confirme »
                'cart_flow' => (bool) $store->whatsapp_cart_flow,
            ],
        ]]);
    }

    /** « Montrer mes modèles aux clients » : allumé ou éteint */
    public function models(Request $request, Assistant $assistant, CatalogFeed $feed): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->currentStore()->forceFill(['whatsapp_models' => $data['enabled']])->save();

        return $this->show($assistant, $feed);
    }

    /** « récap » écrit par le marchand : allumé ou éteint */
    public function recap(Request $request, Assistant $assistant, CatalogFeed $feed): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->currentStore()->forceFill(['whatsapp_recap' => $data['enabled']])->save();

        return $this->show($assistant, $feed);
    }

    /** Après un panier du catalogue : commune puis récap avec « Je confirme », ou rien (l'équipe s'en charge) */
    public function cartFlow(Request $request, Assistant $assistant, CatalogFeed $feed): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->currentStore()->forceFill(['whatsapp_cart_flow' => $data['enabled']])->save();

        return $this->show($assistant, $feed);
    }

    /** Catalogue montré sur le numéro (icône boutique) avec le panier, ou caché */
    public function catalogVisible(Request $request, Assistant $assistant, CatalogFeed $feed, WhatsAppCommerce $commerce): JsonResponse
    {
        $data = $request->validate(['visible' => ['required', 'boolean']]);
        $account = WhatsappAccount::first() ?? throw new BusinessRuleException('Connectez d\'abord votre numéro WhatsApp officiel.', 'visible');
        try {
            $commerce->show($account, $data['visible']);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage(), 'visible');
        }

        return $this->show($assistant, $feed);
    }

    public function update(Request $request, Assistant $assistant, CatalogFeed $feed): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'when' => ['required', Rule::in(['always', 'away'])],
            'from' => ['required', 'date_format:H:i'],
            'to' => ['required', 'date_format:H:i', 'different:from'],
            'per_hour' => ['required', 'integer', 'min:1', 'max:60'],
        ], ['to.different' => 'Choisissez deux heures différentes.'], ['from' => 'heure de début', 'to' => 'heure de fin', 'per_hour' => 'nombre de réponses par heure']);

        $this->currentStore()->forceFill(['auto_replies' => $data])->save();

        return $this->show($assistant, $feed);
    }
}
