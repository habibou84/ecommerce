<?php

namespace App\Services\Stock;

use App\Enums\CountStatus;
use App\Enums\StockLocationType;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\InventoryCount;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tous les mouvements de stock passent par ici : chaque mouvement met à jour le niveau (ligne verrouillée)
 * et s'inscrit au journal immuable stock_movements. Repris de Delivery App, par variante et par emplacement.
 *
 * Disponible = en stock − réservé. Une commande réserve ses articles (phase 2), les sort du stock à
 * l'expédition et les libère si elle est annulée.
 */
class StockKeeper
{
    /**
     * Emplacement principal de la boutique, créé au premier besoin.
     */
    public function defaultLocation(Store $store): StockLocation
    {
        return StockLocation::forStore($store->id)->where('is_active', true)->orderBy('priority')->orderBy('id')->first()
            ?? StockLocation::create(['store_id' => $store->id, 'name' => 'Stock principal', 'type' => StockLocationType::Shop]);
    }

    /**
     * Entrée ou sortie manuelle (correction, casse, cadeau…).
     */
    public function adjust(?User $actor, ProductVariant $variant, StockLocation $location, int $delta, StockMovementType $type = StockMovementType::Adjustment, ?string $note = null): ?StockMovement
    {
        if ($delta === 0) {
            return null;
        }

        return $this->move($variant, $location, $type, $delta, 0, $actor, null, $note, function (StockLevel $level) use ($delta) {
            if ($delta < 0 && $level->available() < -$delta) {
                throw new BusinessRuleException("Seulement {$level->available()} disponible(s) : impossible d'en retirer ".(-$delta).'.', 'quantity');
            }
        });
    }

    /**
     * Quantité constatée (inventaire, import) : elle remplace la quantité en stock.
     */
    public function setOnHand(?User $actor, ProductVariant $variant, StockLocation $location, int $onHand, StockMovementType $type = StockMovementType::Count, ?string $note = null, ?Model $reference = null): ?StockMovement
    {
        if ($onHand < 0) {
            throw new BusinessRuleException('La quantité ne peut pas être négative.', 'quantity');
        }

        return DB::transaction(function () use ($actor, $variant, $location, $onHand, $type, $note, $reference) {
            $level = $this->lockedLevel($variant, $location);

            if ($onHand < $level->reserved) {
                throw new BusinessRuleException("{$level->reserved} article(s) « {$variant->fullTitle()} » sont réservés par des commandes en cours : la quantité ne peut pas être inférieure.", 'quantity');
            }

            $delta = $onHand - $level->on_hand;

            return $delta === 0 ? null : $this->move($variant, $location, $type, $delta, 0, $actor, $reference, $note);
        });
    }

    /**
     * Réception de marchandise : entrée en stock et mise à jour du coût moyen pondéré de chaque variante.
     *
     * @param  list<array{variant: ProductVariant, quantity: int, unit_cost: ?int}>  $lines
     */
    public function receive(?User $actor, StockLocation $location, array $lines, array $attributes = []): StockReceipt
    {
        if ($lines === []) {
            throw new BusinessRuleException('Ajoutez au moins un article.', 'items');
        }

        return DB::transaction(function () use ($actor, $location, $lines, $attributes) {
            $receipt = StockReceipt::create($attributes + [
                'store_id' => $location->store_id,
                'location_id' => $location->id,
                'received_at' => now(),
                'created_by' => $actor?->id,
                'total_cost' => collect($lines)->every(fn ($l) => $l['unit_cost'] !== null)
                    ? collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_cost']) : null,
            ]);

            foreach ($lines as $line) {
                $this->ensurePositive($line['quantity']);
                $variant = $line['variant'];

                if ($line['unit_cost'] !== null) {
                    $this->updateAverageCost($variant, $line['quantity'], $line['unit_cost']);
                }

                $receipt->items()->create(['variant_id' => $variant->id, 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost']]);
                $this->move($variant, $location, StockMovementType::Receipt, $line['quantity'], 0, $actor, $receipt, $attributes['reference'] ?? null, unitCost: $line['unit_cost']);
            }

            return $receipt;
        });
    }

    /**
     * Envoi d'un transfert : la marchandise quitte l'emplacement de départ.
     *
     * @param  list<array{variant: ProductVariant, quantity: int}>  $lines
     */
    public function sendTransfer(?User $actor, StockLocation $from, StockLocation $to, array $lines, ?string $reference = null): StockTransfer
    {
        if ($from->id === $to->id || $from->store_id !== $to->store_id) {
            throw new BusinessRuleException('Choisissez deux emplacements différents.', 'to_location_id');
        }
        if ($lines === []) {
            throw new BusinessRuleException('Ajoutez au moins un article.', 'items');
        }

        return DB::transaction(function () use ($actor, $from, $to, $lines, $reference) {
            $transfer = StockTransfer::create([
                'store_id' => $from->store_id,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => TransferStatus::InTransit,
                'reference' => $reference,
                'shipped_at' => now(),
                'created_by' => $actor?->id,
            ]);

            foreach ($lines as $line) {
                $this->ensurePositive($line['quantity']);
                $transfer->items()->create(['variant_id' => $line['variant']->id, 'quantity' => $line['quantity']]);
                $this->move($line['variant'], $from, StockMovementType::TransferOut, -$line['quantity'], 0, $actor, $transfer, "Vers {$to->name}",
                    check: function (StockLevel $level) use ($line) {
                        if ($level->available() < $line['quantity']) {
                            throw new BusinessRuleException("Stock insuffisant pour « {$line['variant']->fullTitle()} » : {$level->available()} disponible(s).", 'items');
                        }
                    });
            }

            return $transfer;
        });
    }

    /**
     * Réception d'un transfert. Quantités reçues par variante (par défaut : tout ce qui a été envoyé) ;
     * un manque reste visible sur le transfert.
     *
     * @param  array<int, int>  $received  variant_id => quantité reçue
     */
    public function receiveTransfer(?User $actor, StockTransfer $transfer, array $received = []): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transfer, $received) {
            $transfer = StockTransfer::forStore($transfer->store_id)->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TransferStatus::InTransit) {
                throw new BusinessRuleException('Ce transfert n\'est plus en route.');
            }

            foreach ($transfer->items()->with('variant.product')->get() as $item) {
                $quantity = min($item->quantity, max(0, (int) ($received[$item->variant_id] ?? $item->quantity)));
                $item->update(['received_quantity' => $quantity]);
                if ($quantity > 0) {
                    $this->move($item->variant, $transfer->to, StockMovementType::TransferIn, $quantity, 0, $actor, $transfer, "Depuis {$transfer->from->name}");
                }
            }

            $transfer->update(['status' => TransferStatus::Received, 'received_at' => now()]);

            return $transfer;
        });
    }

    /**
     * Annulation d'un transfert encore en route : la marchandise revient à son point de départ.
     */
    public function cancelTransfer(?User $actor, StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transfer) {
            $transfer = StockTransfer::forStore($transfer->store_id)->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TransferStatus::InTransit) {
                throw new BusinessRuleException('Ce transfert n\'est plus en route.');
            }

            foreach ($transfer->items()->with('variant.product')->get() as $item) {
                $this->move($item->variant, $transfer->from, StockMovementType::TransferIn, $item->quantity, 0, $actor, $transfer, 'Transfert annulé');
            }

            $transfer->update(['status' => TransferStatus::Cancelled]);

            return $transfer;
        });
    }

    /**
     * Ouvre un inventaire : photographie des quantités attendues de l'emplacement.
     */
    public function startCount(?User $actor, StockLocation $location, ?string $note = null): InventoryCount
    {
        if (InventoryCount::forStore($location->store_id)->where('location_id', $location->id)->where('status', CountStatus::InProgress->value)->exists()) {
            throw new BusinessRuleException('Un inventaire est déjà en cours pour cet emplacement.');
        }

        return DB::transaction(function () use ($actor, $location, $note) {
            $count = InventoryCount::create([
                'store_id' => $location->store_id,
                'location_id' => $location->id,
                'status' => CountStatus::InProgress,
                'note' => $note,
                'started_by' => $actor?->id,
            ]);

            $variants = ProductVariant::forStore($location->store_id)
                ->whereHas('product', fn ($q) => $q->where('track_inventory', true))
                ->leftJoin('stock_levels', fn ($j) => $j->on('stock_levels.variant_id', '=', 'product_variants.id')->where('stock_levels.location_id', $location->id))
                ->get(['product_variants.id', DB::raw('COALESCE(stock_levels.on_hand, 0) AS on_hand')]);

            foreach ($variants as $variant) {
                $count->items()->create(['variant_id' => $variant->id, 'expected' => (int) $variant->on_hand]);
            }

            return $count;
        });
    }

    /**
     * Clôt l'inventaire : chaque quantité comptée remplace la quantité en stock (les lignes non comptées ne changent pas).
     */
    public function completeCount(?User $actor, InventoryCount $count): InventoryCount
    {
        return DB::transaction(function () use ($actor, $count) {
            $count = InventoryCount::forStore($count->store_id)->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== CountStatus::InProgress) {
                throw new BusinessRuleException('Cet inventaire est déjà clos.');
            }

            foreach ($count->items()->whereNotNull('counted')->with('variant.product')->get() as $item) {
                $this->setOnHand($actor, $item->variant, $count->location, $item->counted, StockMovementType::Count, 'Inventaire', $count);
            }

            $count->update(['status' => CountStatus::Completed, 'completed_by' => $actor?->id, 'completed_at' => now()]);

            return $count;
        });
    }

    /**
     * Réserve des articles pour une commande (phase 2).
     */
    public function reserve(?User $actor, ProductVariant $variant, StockLocation $location, int $quantity, Model $reference): StockMovement
    {
        $this->ensurePositive($quantity);
        $allowBackorder = (bool) $variant->product?->allow_backorder || ! $variant->product?->track_inventory;

        return $this->move($variant, $location, StockMovementType::Reservation, 0, $quantity, $actor, $reference, null, function (StockLevel $level) use ($quantity, $variant, $allowBackorder) {
            if (! $allowBackorder && $level->available() < $quantity) {
                throw new BusinessRuleException("Stock insuffisant pour « {$variant->fullTitle()} » : {$level->available()} disponible(s).", 'items');
            }
        });
    }

    public function release(?User $actor, ProductVariant $variant, StockLocation $location, int $quantity, Model $reference, ?string $note = null): StockMovement
    {
        $this->ensurePositive($quantity);

        return $this->move($variant, $location, StockMovementType::Release, 0, -$quantity, $actor, $reference, $note);
    }

    /**
     * Articles réservés qui partent : ils sortent du stock.
     */
    public function ship(?User $actor, ProductVariant $variant, StockLocation $location, int $quantity, Model $reference): StockMovement
    {
        $this->ensurePositive($quantity);

        return $this->move($variant, $location, StockMovementType::Shipment, -$quantity, -$quantity, $actor, $reference);
    }

    /**
     * Articles revenus (colis refusé, retour client) remis en stock.
     */
    public function returnToStock(?User $actor, ProductVariant $variant, StockLocation $location, int $quantity, Model $reference, ?string $note = null): StockMovement
    {
        $this->ensurePositive($quantity);

        return $this->move($variant, $location, StockMovementType::Return, $quantity, 0, $actor, $reference, $note);
    }

    /**
     * Recalcule les niveaux d'une variante depuis le journal (contrôle ou réparation du cache).
     */
    public function rebuild(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant) {
            $totals = StockMovement::forStore($variant->store_id)->where('variant_id', $variant->id)->groupBy('location_id')
                ->selectRaw('location_id, SUM(on_hand_change) AS on_hand, SUM(reserved_change) AS reserved')->get();

            foreach ($totals as $row) {
                StockLevel::forStore($variant->store_id)->updateOrCreate(
                    ['variant_id' => $variant->id, 'location_id' => $row->location_id],
                    ['store_id' => $variant->store_id, 'on_hand' => (int) $row->on_hand, 'reserved' => max(0, (int) $row->reserved)],
                );
            }
        });
    }

    /**
     * @param  (callable(StockLevel): void)|null  $check  contrôle avant mouvement, sur le niveau verrouillé
     */
    private function move(
        ProductVariant $variant,
        StockLocation $location,
        StockMovementType $type,
        int $onHandChange,
        int $reservedChange,
        ?User $actor,
        ?Model $reference = null,
        ?string $note = null,
        ?callable $check = null,
        ?int $unitCost = null,
    ): StockMovement {
        if ($variant->store_id !== $location->store_id) {
            throw new BusinessRuleException('Cet article n\'appartient pas à cette boutique.', 'variant_id');
        }

        return DB::transaction(function () use ($variant, $location, $type, $onHandChange, $reservedChange, $actor, $reference, $note, $check, $unitCost) {
            $level = $this->lockedLevel($variant, $location);

            if ($check) {
                $check($level);
            }

            $availableBefore = $level->available();
            $level->on_hand += $onHandChange;
            $level->reserved = max(0, $level->reserved + $reservedChange);
            $level->save();

            if ($location->is_active) {
                app(StockAlerts::class)->afterMovement($variant, $level->available() - $availableBefore);
            }

            return StockMovement::create([
                'store_id' => $variant->store_id,
                'variant_id' => $variant->id,
                'location_id' => $location->id,
                'type' => $type,
                'on_hand_change' => $onHandChange,
                'reserved_change' => $reservedChange,
                'on_hand_after' => $level->on_hand,
                'reserved_after' => $level->reserved,
                'unit_cost' => $unitCost,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $actor?->id,
                'note' => $note,
            ]);
        });
    }

    private function lockedLevel(ProductVariant $variant, StockLocation $location): StockLevel
    {
        // Création éventuelle hors verrou (contrainte unique), puis verrouillage de la ligne
        StockLevel::forStore($variant->store_id)->firstOrCreate(
            ['variant_id' => $variant->id, 'location_id' => $location->id],
            ['store_id' => $variant->store_id, 'on_hand' => 0, 'reserved' => 0],
        );

        return StockLevel::forStore($variant->store_id)->where('variant_id', $variant->id)->where('location_id', $location->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Coût moyen pondéré : (stock × ancien coût + quantité reçue × coût reçu) / nouveau stock.
     */
    private function updateAverageCost(ProductVariant $variant, int $quantity, int $unitCost): void
    {
        $onHand = max(0, (int) StockLevel::forStore($variant->store_id)->where('variant_id', $variant->id)->sum('on_hand'));
        $current = $variant->cost_price;

        $average = $current === null || $onHand === 0
            ? $unitCost
            : (int) round(($onHand * $current + $quantity * $unitCost) / ($onHand + $quantity));

        $variant->forceFill(['cost_price' => $average])->saveQuietly();
    }

    private function ensurePositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new BusinessRuleException('La quantité doit être positive.', 'quantity');
        }
    }
}
