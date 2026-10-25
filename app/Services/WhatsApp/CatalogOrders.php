<?php

namespace App\Services\WhatsApp;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\QuotaExceededException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Orders\OrderDesk;
use Illuminate\Support\Collection;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;

/**
 * Panier envoyé par un client depuis le catalogue WhatsApp (connexion officielle de Meta ou WhatsApp relié) : il
 * devient une commande « à confirmer ». Chaque article du catalogue porte la référence du flux de la boutique (code
 * de la variante ou « V12 ») ; un article inconnu est repris avec son nom et son prix. Quartier, adresse et date
 * restent à demander au client.
 *
 * Panier renvoyé alors que la commande WhatsApp du client (moins de 24 h) est encore à confirmer : le même panier ne crée
 * rien de plus, un panier différent est ajouté à cette commande (une seule livraison).
 */
class CatalogOrders
{
    public const NOTE = 'Commande envoyée depuis le catalogue WhatsApp : quartier, adresse et date de livraison à confirmer avec le client.';

    public function __construct(private readonly OrderDesk $desk) {}

    /**
     * @param  list<array{ref?: ?string, name?: ?string, quantity?: mixed, price?: mixed}>  $items
     * @return array{order: ?Order, body: string}
     */
    public function place(Store $store, string $phone, ?string $name, array $items, ?int $total, ?string $text): array
    {
        $lines = $this->lines($store, $items);
        $summary = $lines->map(fn ($l) => "{$l['quantity']} × {$l['label']}".($l['price'] !== null ? ' — '.Money::format($l['price'] * $l['quantity']) : ''));
        $total ??= $lines->every(fn ($l) => $l['price'] !== null) && $lines->isNotEmpty() ? (int) $lines->sum(fn ($l) => $l['price'] * $l['quantity']) : null;
        $body = collect(['🛒 Commande depuis le catalogue WhatsApp', ...$summary, $total ? 'Total des articles : '.Money::format($total) : null, filled($text) ? 'Message : '.trim($text) : null])
            ->filter()->implode("\n");

        if ($lines->isEmpty()) {
            return ['order' => null, 'body' => $body."\nWhatsApp n'a pas transmis le détail du panier : ouvrez-le sur le téléphone, puis créez la commande depuis la conversation."];
        }

        $lineData = $lines->map(fn ($l) => $l['variant_id']
            ? ['variant_id' => $l['variant_id'], 'quantity' => $l['quantity']]
            : ['title' => $l['label'], 'quantity' => $l['quantity'], 'unit_price' => $l['price'] ?? 0])->values()->all();
        $open = $this->openOrder($store, $phone);
        if ($open !== null) {
            if ($this->sameCart($open, $lines)) {
                return ['order' => $open, 'body' => $body."\nMême panier que la commande {$open->reference()}, déjà reçue : pas de nouvelle commande."];
            }
            try {
                $order = $this->desk->addItems($open, $lineData, 'nouveau panier WhatsApp');

                return ['order' => $order, 'body' => $body."\nArticles ajoutés à la commande {$order->reference()}, toujours à confirmer : une seule livraison."];
            } catch (BusinessRuleException) {
                // ajout impossible (stock…) : nouvelle commande comme avant
            }
        }

        try {
            $order = $this->desk->create($store, [
                'source' => 'whatsapp',
                'customer_name' => $name,
                'customer_phone' => $phone,
                'delivery_method' => 'delivery',
                'customer_note' => filled($text) ? mb_substr(trim($text), 0, 1000) : null,
                'internal_note' => self::NOTE,
                'items' => $lineData,
            ], null, 'customer');
        } catch (BusinessRuleException|QuotaExceededException $e) {
            return ['order' => null, 'body' => $body."\nLa commande n'a pas pu être créée seule ({$e->getMessage()}) : créez-la depuis la conversation."];
        }

        return ['order' => $order, 'body' => $body."\nCommande {$order->reference()} créée, à confirmer : demandez au client son quartier et son adresse."];
    }

    /**
     * Commande WhatsApp du même client, passée depuis moins de 24 h et encore à confirmer.
     */
    private function openOrder(Store $store, string $phone): ?Order
    {
        $phone = PhoneNumber::normalize($phone) ?? $phone;

        return Order::forStore($store->id)->where('source', OrderSource::Whatsapp->value)->where('status', OrderStatus::PendingConfirmation->value)
            ->where('created_at', '>=', now()->subDay())->whereHas('customer', fn ($q) => $q->where('phone', $phone))
            ->latest('id')->first();
    }

    /**
     * Mêmes articles, mêmes quantités (le client a renvoyé son panier).
     *
     * @param  Collection<int, array{variant_id: ?int, label: string, quantity: int, price: ?int}>  $lines
     */
    private function sameCart(Order $order, Collection $lines): bool
    {
        $key = fn ($variantId, $title, $quantity) => ($variantId ? "v{$variantId}" : 't'.mb_strtolower(trim($title))).'×'.$quantity;
        $mine = $order->items()->get()->map(fn ($i) => $key($i->variant_id, $i->title, (int) $i->quantity))->sort()->values()->all();
        $sent = $lines->groupBy(fn ($l) => $l['variant_id'] ? "v{$l['variant_id']}" : 't'.mb_strtolower(trim($l['label'])))
            ->map(fn ($g, $k) => $k.'×'.$g->sum('quantity'))->sort()->values()->all();

        return $mine === $sent;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, array{variant_id: ?int, label: string, quantity: int, price: ?int}>
     */
    private function lines(Store $store, array $items): Collection
    {
        $items = collect($items)->filter(fn ($i) => is_array($i) && (int) ($i['quantity'] ?? 0) > 0)->values();
        $refs = $items->pluck('ref')->filter()->map(fn ($r) => trim((string) $r))->all();
        $ids = collect($refs)->map(fn ($r) => preg_match('/^V(\d+)$/i', $r, $m) ? (int) $m[1] : null)->filter()->all();
        $variants = $refs === [] ? collect() : ProductVariant::forStore($store->id)->with('product')
            ->where(fn ($q) => $q->whereIn('sku', $refs)->orWhereIn('id', $ids))->get();

        return $items->map(function (array $item) use ($variants) {
            $ref = trim((string) ($item['ref'] ?? ''));
            $variant = $ref === '' ? null : ($variants->first(fn ($v) => $v->sku !== null && $v->sku === $ref)
                ?? (preg_match('/^V(\d+)$/i', $ref, $m) ? $variants->firstWhere('id', (int) $m[1]) : null));
            $price = isset($item['price']) && is_numeric($item['price']) && $item['price'] > 0 ? (int) round((float) $item['price']) : null;

            return [
                'variant_id' => $variant?->product ? $variant->id : null,
                'label' => $variant?->product ? $variant->fullTitle() : (mb_substr(trim((string) ($item['name'] ?? '')), 0, 150) ?: 'Article du catalogue'.($ref !== '' ? " ({$ref})" : '')),
                'quantity' => min(999, (int) $item['quantity']),
                'price' => $variant?->product ? (int) $variant->price : $price,
            ];
        });
    }
}
