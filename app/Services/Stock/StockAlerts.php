<?php

namespace App\Services\Stock;

use App\Enums\Permission;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Notifications\LowStock;
use App\Services\Accounts\TeamNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Alerte quand le disponible d'une variante (tous emplacements actifs) passe sous son seuil : une seule fois
 * au franchissement, pas à chaque vente suivante.
 */
class StockAlerts
{
    private static bool $muted = false;

    public function __construct(private readonly TeamNotifier $notifier) {}

    /**
     * Mouvements sans alerte (suppression d'une variante, d'un produit).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function muted(callable $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    public function afterMovement(ProductVariant $variant, int $availableChange): void
    {
        if ($availableChange >= 0 || self::$muted) {
            return;
        }

        $product = $variant->product;
        if ($product === null || ! $product->track_inventory || $product->allow_backorder) {
            return;
        }

        $available = (int) StockLevel::forStore($variant->store_id)->where('variant_id', $variant->id)
            ->whereHas('location', fn ($q) => $q->where('is_active', true))
            ->sum(DB::raw('on_hand - reserved'));
        $threshold = $product->low_stock_threshold ?? $product->store?->low_stock_threshold ?? 3;
        $before = $available - $availableChange;

        $crossedLow = $before > $threshold && $available <= $threshold;
        $crossedOut = $before > 0 && $available <= 0;

        if ($crossedLow || $crossedOut) {
            DB::afterCommit(fn () => $this->notifier->notify($product->store, Permission::StockManage->value, new LowStock($variant, $available)));
        }
    }
}
