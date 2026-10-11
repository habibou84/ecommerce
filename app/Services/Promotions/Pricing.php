<?php

namespace App\Services\Promotions;

use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountUsage;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\VolumePrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;

/**
 * Prix de vente : ventes flash (remises automatiques), prix par quantité et codes promo.
 *
 * Une vente flash et un prix par quantité ne se cumulent pas : le client a le plus avantageux des deux.
 * Un code promo s'applique ensuite au panier (sur les articles visés), une seule fois par commande.
 */
class Pricing
{
    /**
     * Ventes flash en cours, collections et paliers de quantité des produits donnés.
     *
     * @param  list<int>  $productIds
     */
    public function context(int $storeId, array $productIds): PricingContext
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        $flash = $productIds === [] ? collect() : Discount::forStore($storeId)->whereNull('code')->running()
            ->whereIn('type', ['percentage', 'fixed_amount'])->get();

        return new PricingContext(
            $flash,
            $this->collectionsOf($productIds),
            $productIds === [] ? [] : VolumePrice::forStore($storeId)->whereIn('product_id', $productIds)->orderBy('min_quantity')->get()->groupBy('product_id')->all(),
        );
    }

    /**
     * Prix d'une variante seule (fiches et grilles) : vente flash éventuelle.
     *
     * @return array{price: int, compare_at: ?int, discount: ?Discount}
     */
    public function unit(PricingContext $context, ProductVariant $variant): array
    {
        $flash = $this->bestFlash($context, $variant);
        $price = $flash ? $flash->reducedPrice($variant->price) : $variant->price;

        return [
            'price' => $price,
            'compare_at' => $this->compareAt($variant, $price, $flash !== null),
            'discount' => $flash,
        ];
    }

    /**
     * Prix des articles d'un panier ou d'une commande.
     *
     * @param  list<array{variant: ProductVariant, quantity: int}>  $lines  variantes avec leur produit
     * @return list<array{unit_price: int, total: int, compare_at: ?int, discount_id: ?int, volume: bool}>
     */
    public function lines(int $storeId, array $lines): array
    {
        $context = $this->context($storeId, array_map(fn ($l) => $l['variant']->product_id, $lines));
        $result = [];
        foreach ($lines as $index => $line) {
            $unit = $this->unit($context, $line['variant']);
            $result[$index] = [
                'unit_price' => $unit['price'],
                'total' => $unit['price'] * $line['quantity'],
                'compare_at' => $unit['compare_at'],
                'discount_id' => $unit['discount']?->id,
                'volume' => false,
            ];
        }

        // Prix par quantité : toutes les variantes du produit comptent ; appliqué s'il est plus avantageux
        foreach (collect($lines)->groupBy(fn ($l) => $l['variant']->product_id, true) as $productId => $group) {
            $quantity = $group->sum('quantity');
            $tier = $this->tier($context, (int) $productId, $quantity);
            if ($tier === null) {
                continue;
            }

            $volumeTotal = (int) round($quantity * $tier->price / $tier->min_quantity);
            if ($volumeTotal >= $group->keys()->sum(fn ($i) => $result[$i]['total'])) {
                continue;
            }

            $left = $volumeTotal;
            $keys = $group->keys()->all();
            foreach ($keys as $n => $i) {
                $q = $lines[$i]['quantity'];
                $total = $n === count($keys) - 1 ? $left : (int) round($volumeTotal * $q / $quantity);
                $left -= $total;
                $result[$i] = [
                    'unit_price' => (int) round($total / $q),
                    'total' => $total,
                    'compare_at' => $lines[$i]['variant']->price,
                    'discount_id' => null,
                    'volume' => true,
                ];
            }
        }

        return $result;
    }

    /**
     * Code promo pour des articles déjà au prix de vente.
     *
     * @param  list<array{product_id: ?int, total: int}>  $lines
     * @return array{discount: ?Discount, code: ?string, amount: int, free_shipping: bool, error: ?string, allocations: list<int>, label: ?string}
     */
    public function code(int $storeId, ?string $code, array $lines, ?string $phone, ?int $exceptOrderId = null): array
    {
        $code = self::normalize($code);
        $none = ['discount' => null, 'code' => $code, 'amount' => 0, 'free_shipping' => false, 'error' => null, 'allocations' => array_fill(0, count($lines), 0), 'label' => null];
        if ($code === null) {
            return $none;
        }

        $discount = Discount::forStore($storeId)->where('code', $code)->where('is_active', true)->first();
        $error = match (true) {
            $discount === null => 'Ce code promo n\'existe pas.',
            ! $discount->isRunning() => $discount->starts_at?->isFuture() ? 'Ce code promo n\'est pas encore valable.' : 'Ce code promo n\'est plus valable.',
            $discount->usage_limit !== null && $this->usageCount($discount, $exceptOrderId) >= $discount->usage_limit => 'Ce code promo a atteint sa limite d\'utilisation.',
            $discount->per_customer_limit !== null && $this->customerUsageCount($discount, $phone, $exceptOrderId) >= $discount->per_customer_limit => 'Vous avez déjà utilisé ce code promo.',
            default => null,
        };
        if ($error !== null) {
            return ['error' => $error] + $none;
        }

        $collections = $discount->applies_to === 'collections' ? $this->collectionsOf(array_column($lines, 'product_id')) : [];
        $eligible = array_map(fn ($l) => $l['product_id'] !== null && $discount->targets($l['product_id'], $collections[$l['product_id']] ?? []) ? $l['total'] : 0, $lines);
        $eligibleTotal = array_sum($eligible);
        $subtotal = array_sum(array_column($lines, 'total'));

        if ($discount->min_subtotal !== null && $subtotal < $discount->min_subtotal) {
            return ['error' => 'Ce code promo s\'applique dès '.Money::format($discount->min_subtotal).' d\'achat.'] + $none;
        }
        if ($eligibleTotal <= 0) {
            return ['error' => 'Ce code promo ne s\'applique à aucun article de votre panier.'] + $none;
        }

        $amount = match ($discount->type) {
            'percentage' => intdiv($eligibleTotal * min(100, $discount->value), 100),
            'fixed_amount' => min($discount->value, $eligibleTotal),
            default => 0,
        };

        return [
            'discount' => $discount,
            'code' => $code,
            'amount' => $amount,
            'free_shipping' => $discount->type === 'free_shipping',
            'error' => null,
            'allocations' => $this->allocate($amount, $eligible),
            'label' => $discount->summary(),
        ];
    }

    /**
     * Utilisations d'une commande : code promo et ventes flash, recomptées après chaque changement.
     */
    public function recordUsages(Order $order): void
    {
        $order->loadMissing('items');
        $amounts = [];
        foreach ($order->items->whereNotNull('discount_id') as $item) {
            $amounts[$item->discount_id] = ($amounts[$item->discount_id] ?? 0) + max(0, ((int) $item->compare_at_price - $item->unit_price) * $item->quantity);
        }
        if ($order->discount_code) {
            $codeId = Discount::forStore($order->store_id)->where('code', $order->discount_code)->value('id');
            if ($codeId) {
                $amounts[$codeId] = ($amounts[$codeId] ?? 0) + $order->discount_total;
            }
        }

        $previous = DiscountUsage::forStore($order->store_id)->where('order_id', $order->id)->pluck('discount_id')->all();
        DiscountUsage::forStore($order->store_id)->where('order_id', $order->id)->whereNotIn('discount_id', array_keys($amounts))->delete();
        foreach ($amounts as $discountId => $amount) {
            DiscountUsage::forStore($order->store_id)->updateOrCreate(
                ['discount_id' => $discountId, 'order_id' => $order->id],
                ['store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'amount' => $amount],
            );
        }
        $this->refreshCounts($order->store_id, array_unique([...$previous, ...array_keys($amounts)]));
    }

    /**
     * Commande annulée : ses utilisations sont libérées.
     */
    public function releaseUsages(Order $order): void
    {
        $ids = DiscountUsage::forStore($order->store_id)->where('order_id', $order->id)->pluck('discount_id')->all();
        DiscountUsage::forStore($order->store_id)->where('order_id', $order->id)->delete();
        $this->refreshCounts($order->store_id, $ids);
    }

    public static function normalize(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }

    /**
     * @param  list<int>  $ids
     */
    private function refreshCounts(int $storeId, array $ids): void
    {
        foreach ($ids as $id) {
            Discount::forStore($storeId)->whereKey($id)->update(['used_count' => DiscountUsage::forStore($storeId)->where('discount_id', $id)->count()]);
        }
    }

    private function usageCount(Discount $discount, ?int $exceptOrderId): int
    {
        return DiscountUsage::forStore($discount->store_id)->where('discount_id', $discount->id)
            ->when($exceptOrderId, fn ($q) => $q->where('order_id', '!=', $exceptOrderId))->count();
    }

    private function customerUsageCount(Discount $discount, ?string $phone, ?int $exceptOrderId): int
    {
        $customerId = $phone ? Customer::forStore($discount->store_id)->where('phone', PhoneNumber::normalize($phone) ?? $phone)->value('id') : null;
        if ($customerId === null) {
            return 0;
        }

        return DiscountUsage::forStore($discount->store_id)->where('discount_id', $discount->id)->where('customer_id', $customerId)
            ->when($exceptOrderId, fn ($q) => $q->where('order_id', '!=', $exceptOrderId))->count();
    }

    private function bestFlash(PricingContext $context, ProductVariant $variant): ?Discount
    {
        $best = null;
        foreach ($context->flash as $discount) {
            if (! $discount->targets($variant->product_id, $context->collections[$variant->product_id] ?? [])) {
                continue;
            }
            if ($best === null || $discount->reducedPrice($variant->price) < $best->reducedPrice($variant->price)) {
                $best = $discount;
            }
        }

        return $best !== null && $best->reducedPrice($variant->price) < $variant->price ? $best : null;
    }

    /**
     * Palier le plus avantageux atteint pour une quantité.
     */
    private function tier(PricingContext $context, int $productId, int $quantity): ?VolumePrice
    {
        /** @var Collection<int, VolumePrice> $tiers */
        $tiers = collect($context->tiers[$productId] ?? [])->filter(fn (VolumePrice $t) => $t->min_quantity <= $quantity && $t->min_quantity > 0);

        return $tiers->sortBy(fn (VolumePrice $t) => $t->price / $t->min_quantity)->first();
    }

    private function compareAt(ProductVariant $variant, int $price, bool $reduced): ?int
    {
        if ($reduced) {
            return $variant->price;
        }

        return $variant->compare_at_price && $variant->compare_at_price > $price ? $variant->compare_at_price : null;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, list<int>>
     */
    private function collectionsOf(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            return [];
        }

        return DB::table('collection_products')->whereIn('product_id', $productIds)->get(['product_id', 'collection_id'])
            ->groupBy('product_id')->map(fn ($rows) => $rows->pluck('collection_id')->map(fn ($id) => (int) $id)->all())->all();
    }

    /**
     * Répartit un montant au prorata des lignes (la dernière ligne concernée prend l'arrondi).
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $result = array_fill(0, count($weights), 0);
        if ($amount <= 0 || $total <= 0) {
            return $result;
        }

        $last = array_key_last(array_filter($weights));
        $left = $amount;
        foreach ($weights as $i => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $share = $i === $last ? $left : intdiv($amount * $weight, $total);
            $result[$i] = $share;
            $left -= $share;
        }

        return $result;
    }
}
