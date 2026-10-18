<?php

namespace App\Services\Stock;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\CashRegister;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;

/**
 * Bons de commande aux fournisseurs : préparation (avec les quantités conseillées d'après les ventes), envoi par
 * WhatsApp, réception en une ou plusieurs fois (chaque réception entre en stock et met à jour le prix d'achat
 * moyen) et paiement noté comme dépense « Achat de marchandises ».
 */
class PurchaseOrders
{
    /** Stock visé : de quoi tenir ce nombre de jours au rythme des ventes des 30 derniers jours */
    public const COVER_DAYS = 30;

    public function __construct(
        private readonly StockKeeper $stock,
        private readonly StockLevels $levels,
        private readonly CashRegister $register,
    ) {}

    /**
     * Articles à commander : ventes des 30 derniers jours rapportées au stock disponible, ou stock sous le seuil.
     *
     * @return list<array{variant_id: int, title: string, sold_30d: int, available: int, quantity: int, unit_cost: ?int}>
     */
    public function suggestions(Store $store): array
    {
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value])
            ->where('orders.created_at', '>=', now()->subDays(30))->whereNotNull('order_items.variant_id')
            ->groupBy('order_items.variant_id')->selectRaw('order_items.variant_id, SUM(order_items.quantity) AS sold');

        $rows = DB::query()->fromSub($this->levels->variantAvailability($store), 'a')
            ->leftJoinSub($sold, 's', 's.variant_id', '=', 'a.variant_id')
            ->where(fn ($q) => $q->where('s.sold', '>', 0)->orWhereColumn('a.available', '<=', 'a.threshold'))
            ->selectRaw('a.variant_id, a.available, a.threshold, COALESCE(s.sold, 0) AS sold')
            ->get();

        $variants = ProductVariant::with('product')->whereIn('id', $rows->pluck('variant_id'))->get()->keyBy('id');

        return $rows->map(function ($r) use ($variants) {
            $target = max((int) ceil($r->sold * self::COVER_DAYS / 30), (int) $r->threshold * 2);
            $variant = $variants->get($r->variant_id);

            return $variant ? [
                'variant_id' => (int) $r->variant_id,
                'title' => $variant->fullTitle(),
                'sold_30d' => (int) $r->sold,
                'available' => (int) $r->available,
                'quantity' => max(0, $target - (int) $r->available),
                'unit_cost' => $variant->cost_price,
            ] : null;
        })->filter(fn ($s) => $s && $s['quantity'] > 0)
            ->sortBy(fn ($s) => $s['sold_30d'] > 0 ? $s['available'] / $s['sold_30d'] : INF)->values()->take(50)->all();
    }

    /**
     * @param  array{supplier_id?: ?int, supplier_name?: ?string, supplier_phone?: ?string, location_id: int, expected_on?: ?string, note?: ?string, items: list<array{variant_id: int, quantity: int, unit_cost?: ?int}>}  $data
     */
    public function save(Store $store, ?PurchaseOrder $order, array $data, User $actor): PurchaseOrder
    {
        if ($order && ! in_array($order->status, ['draft', 'sent'], true)) {
            throw new BusinessRuleException('Ce bon de commande ne peut plus être modifié.');
        }
        $location = StockLocation::forStore($store->id)->where('is_active', true)->findOrFail($data['location_id']);
        $supplier = $this->supplier($store, $data);
        $variants = ProductVariant::forStore($store->id)->whereIn('id', collect($data['items'])->pluck('variant_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($store, $order, $data, $actor, $location, $supplier, $variants) {
            // Numéro suivant : la boutique est verrouillée le temps de le prendre
            Store::whereKey($store->id)->lockForUpdate()->first();
            $order ??= new PurchaseOrder([
                'store_id' => $store->id,
                'number' => (int) PurchaseOrder::forStore($store->id)->max('number') + 1,
                'created_by' => $actor->id,
            ]);
            $order->fill(['supplier_id' => $supplier?->id, 'location_id' => $location->id, 'expected_on' => $data['expected_on'] ?? null, 'note' => $data['note'] ?? null])->save();

            $order->items()->delete();
            foreach ($data['items'] as $line) {
                $variant = $variants->get($line['variant_id']) ?? throw new BusinessRuleException('Article introuvable.', 'items');
                $order->items()->create([
                    'variant_id' => $variant->id,
                    'quantity' => (int) $line['quantity'],
                    'unit_cost' => isset($line['unit_cost']) ? (int) $line['unit_cost'] : $variant->cost_price,
                ]);
            }
            $this->refreshTotal($order);

            return $order;
        });
    }

    /**
     * Bon envoyé : texte prêt pour le WhatsApp du fournisseur.
     *
     * @return array{order: PurchaseOrder, whatsapp_url: ?string, text: string}
     */
    public function send(Store $store, PurchaseOrder $order): array
    {
        if (in_array($order->status, ['received', 'cancelled'], true)) {
            throw new BusinessRuleException('Ce bon de commande est terminé.');
        }
        if ($order->status === 'draft') {
            $order->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        }
        $order->load('items.variant.product', 'supplier');
        $text = implode("\n", array_filter([
            'Bonjour'.($order->supplier ? ' '.$order->supplier->name : '').", voici la commande {$order->reference()} de {$store->name} :",
            ...$order->items->map(fn ($i) => "- {$i->quantity} × {$i->variant->fullTitle()}".($i->unit_cost !== null ? ' ('.Money::format($i->unit_cost).' pièce)' : ''))->all(),
            $order->total_cost ? 'Total : '.Money::format($order->total_cost) : null,
            $order->expected_on ? 'Livraison souhaitée le '.$order->expected_on->locale('fr')->isoFormat('dddd D MMMM') : null,
            $order->note,
            'Merci de confirmer.',
        ]));
        $phone = $order->supplier?->phone;

        return ['order' => $order, 'text' => $text, 'whatsapp_url' => $phone ? 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($text) : null];
    }

    /**
     * Réception (complète ou partielle) : entrée en stock et suivi des quantités reçues.
     *
     * @param  list<array{variant_id: int, quantity: int, unit_cost?: ?int}>  $lines
     */
    public function receive(PurchaseOrder $order, array $lines, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $lines, $actor) {
            $order = PurchaseOrder::forStore($order->store_id)->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->status, ['received', 'cancelled'], true)) {
                throw new BusinessRuleException('Ce bon de commande est terminé.');
            }
            $order->load('items.variant.product', 'location');
            $entries = [];
            foreach ($lines as $line) {
                $item = $order->items->firstWhere('variant_id', (int) $line['variant_id']);
                $quantity = (int) $line['quantity'];
                if ($item === null || $quantity <= 0) {
                    continue;
                }
                $cost = isset($line['unit_cost']) ? (int) $line['unit_cost'] : $item->unit_cost;
                $entries[] = ['variant' => $item->variant, 'quantity' => $quantity, 'unit_cost' => $cost];
                $item->forceFill(['received_quantity' => $item->received_quantity + $quantity, 'unit_cost' => $cost])->save();
            }
            if ($entries === []) {
                throw new BusinessRuleException('Indiquez les quantités reçues.', 'items');
            }

            $this->stock->receive($actor, $order->location, $entries, ['supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'reference' => $order->reference()]);

            $complete = $order->items->every(fn ($i) => $i->received_quantity >= $i->quantity);
            $order->forceFill(['status' => $complete ? 'received' : 'partial', 'received_at' => $complete ? now() : null, 'sent_at' => $order->sent_at ?? now()])->save();
            $this->refreshTotal($order);

            return $order;
        });
    }

    /**
     * Paiement au fournisseur : dépense « Achat de marchandises » (en espèces : sort du tiroir-caisse).
     */
    public function pay(Store $store, PurchaseOrder $order, int $amount, string $method, User $actor): PurchaseOrder
    {
        if ($order->status === 'cancelled') {
            throw new BusinessRuleException('Ce bon de commande est annulé.');
        }
        $this->register->record($store, [
            'type' => 'expense', 'category' => 'stock', 'amount' => $amount, 'method' => $method,
            'note' => 'Paiement '.$order->reference().($order->supplier ? ' · '.$order->supplier->name : ''), 'purchase_order_id' => $order->id,
        ], $actor);
        $order->forceFill(['paid_amount' => $order->paid_amount + $amount])->save();

        return $order;
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if (! in_array($order->status, ['draft', 'sent'], true)) {
            throw new BusinessRuleException('Un bon de commande déjà reçu (même en partie) ne peut pas être annulé.');
        }
        $order->forceFill(['status' => 'cancelled'])->save();

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PurchaseOrder $order, bool $withItems = true): array
    {
        $order->loadMissing('supplier', 'location', 'items.variant.product');

        return [
            'id' => $order->id,
            'reference' => $order->reference(),
            'status' => $order->status,
            'status_label' => PurchaseOrder::STATUSES[$order->status] ?? $order->status,
            'supplier' => $order->supplier ? ['id' => $order->supplier->id, 'name' => $order->supplier->name, 'phone' => $order->supplier->phone ? PhoneNumber::display($order->supplier->phone) : null] : null,
            'location' => ['id' => $order->location_id, 'name' => $order->location?->name],
            'expected_on' => $order->expected_on?->toDateString(),
            'note' => $order->note,
            'total_cost' => $order->total_cost,
            'paid_amount' => $order->paid_amount,
            'units' => (int) $order->items->sum('quantity'),
            'received_units' => (int) $order->items->sum('received_quantity'),
            'sent_at' => $order->sent_at?->toIso8601String(),
            'received_at' => $order->received_at?->toIso8601String(),
            'created_at' => $order->created_at->toIso8601String(),
            'items' => $withItems ? $order->items->map(fn ($i) => [
                'variant_id' => $i->variant_id,
                'title' => $i->variant?->fullTitle(),
                'quantity' => $i->quantity,
                'received_quantity' => $i->received_quantity,
                'unit_cost' => $i->unit_cost,
            ]) : null,
        ];
    }

    private function supplier(Store $store, array $data): ?Supplier
    {
        if (! empty($data['supplier_id'])) {
            return Supplier::forStore($store->id)->findOrFail($data['supplier_id']);
        }
        if (filled($data['supplier_name'] ?? null)) {
            $supplier = Supplier::forStore($store->id)->firstOrCreate(['store_id' => $store->id, 'name' => trim($data['supplier_name'])]);
            if (filled($data['supplier_phone'] ?? null) && blank($supplier->phone)) {
                $supplier->forceFill(['phone' => $data['supplier_phone']])->save();
            }

            return $supplier;
        }

        return null;
    }

    private function refreshTotal(PurchaseOrder $order): void
    {
        $items = $order->items()->get();
        $order->forceFill(['total_cost' => (int) $items->sum(fn ($i) => $i->quantity * ($i->unit_cost ?? 0))])->save();
    }

    /**
     * @return Collection<int, Supplier>
     */
    public function suppliers(Store $store): Collection
    {
        return Supplier::forStore($store->id)->withCount(['purchaseOrders' => fn ($q) => $q->where('status', '!=', 'cancelled')])->orderBy('name')->get();
    }
}
