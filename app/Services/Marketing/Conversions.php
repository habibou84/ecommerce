<?php

namespace App\Services\Marketing;

use App\Models\Order;
use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * API Conversions de Meta : l'achat est aussi envoyé par le serveur, pour être compté même quand le navigateur
 * bloque le pixel. Même identifiant d'événement que le pixel (page de remerciement) : Meta ne le compte qu'une fois.
 */
class Conversions
{
    public const GRAPH = 'https://graph.facebook.com/'.WhatsAppCloud::VERSION;

    public static function eventId(Order $order): string
    {
        return 'order-'.$order->id;
    }

    /**
     * @param  array{ip?: ?string, user_agent?: ?string, fbp?: ?string, fbc?: ?string, url?: ?string}  $context  visite du client
     */
    public function sendPurchase(Order $order, array $context = []): bool
    {
        $store = $order->store;
        $pixel = $store->marketingId('meta_pixel_id');
        if ($pixel === null || blank($store->meta_capi_token) || $order->conversion_sent_at !== null) {
            return false;
        }

        $order->loadMissing('items');
        $hash = fn (?string $value) => filled($value) ? [hash('sha256', $value)] : null;
        $names = preg_split('/\s+/', Str::lower(trim((string) $order->customer_name))) ?: [];
        $fbclid = $order->utm['fbclid'] ?? null;

        $event = [
            'event_name' => 'Purchase',
            'event_time' => $order->created_at->getTimestamp(),
            'event_id' => self::eventId($order),
            'action_source' => 'website',
            'event_source_url' => $context['url'] ?? $store->url('/commande'),
            'user_data' => array_filter([
                'ph' => $hash(preg_replace('/\D/', '', (string) $order->customer_phone)),
                'fn' => $hash($names[0] ?? null),
                'ln' => $hash(count($names) > 1 ? end($names) : null),
                'country' => $hash(Str::lower($store->country)),
                'external_id' => $hash($order->customer_id ? (string) $order->customer_id : null),
                'client_ip_address' => $context['ip'] ?? null,
                'client_user_agent' => $context['user_agent'] ?? null,
                'fbp' => $context['fbp'] ?? null,
                'fbc' => $context['fbc'] ?? ($fbclid ? 'fb.1.'.$order->created_at->getTimestampMs().'.'.$fbclid : null),
            ]),
            'custom_data' => [
                'currency' => $store->currency,
                'value' => $order->total,
                'order_id' => $order->reference(),
                'content_type' => 'product',
                'content_ids' => $order->items->pluck('product_id')->filter()->map(fn ($id) => (string) $id)->values()->all(),
                'contents' => $order->items->filter(fn ($i) => $i->product_id)->map(fn ($i) => ['id' => (string) $i->product_id, 'quantity' => $i->quantity, 'item_price' => $i->unit_price])->values()->all(),
                'num_items' => (int) $order->items->sum('quantity'),
            ],
        ];

        try {
            $response = Http::timeout(10)->asJson()->post(self::GRAPH."/{$pixel}/events?access_token=".urlencode($store->meta_capi_token), ['data' => [$event]]);
        } catch (Throwable $e) {
            Log::warning('API Conversions injoignable', ['order' => $order->id, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('API Conversions : envoi refusé', ['order' => $order->id, 'status' => $response->status(), 'error' => $response->json('error.message')]);

            return false;
        }

        $order->forceFill(['conversion_sent_at' => now()])->save();

        return true;
    }
}
