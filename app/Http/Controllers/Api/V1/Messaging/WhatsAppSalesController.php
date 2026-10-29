<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Enums\OrderSource;
use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Services\Ai\Assistant;
use App\Services\Marketing\CatalogFeed;
use App\Services\Marketing\MetaAds;
use App\Services\Messaging\AutoReplier;
use App\Services\WhatsApp\CatalogOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Réglages › WhatsApp › Vendre sur WhatsApp : réponses automatiques (désactivées par défaut, avec le risque pour un
 * numéro relié par QR code) et catalogue WhatsApp (lien du flux à donner à Meta, paniers reçus comme commandes).
 */
class WhatsAppSalesController extends Controller
{
    public function show(Assistant $assistant, CatalogFeed $feed): JsonResponse
    {
        $store = $this->currentStore();
        $connected = WhatsappLink::first()?->isConnected() ?? false;

        return response()->json(['data' => [
            'auto_replies' => AutoReplier::settings($store),
            // Numéro par lequel partiraient les réponses : relié (non officiel, risque de blocage) ou officiel
            'channel' => $connected ? 'linked' : (WhatsappAccount::first() ? 'official' : null),
            'assistant' => $assistant->status($store),
            'sent_last_7_days' => ConversationMessage::query()->where('type', 'auto')->where('sent_at', '>=', now()->subDays(7))->count(),
            'catalog' => [
                'feed_url' => $feed->url($store),
                'items' => count($feed->rows($store)),
                // Catalogue Meta branché en un clic (Marketing › Suivi), sinon null
                'meta' => ($account = app(MetaAds::class)->account($store)) ? ['business' => $account->name, 'catalog_id' => $store->marketingId('meta_catalog_id')] : null,
                'orders_last_30_days' => Order::query()->where('source', OrderSource::Whatsapp->value)
                    ->where('internal_note', CatalogOrders::NOTE)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]]);
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
