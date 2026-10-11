<?php

namespace App\Services\Couriers;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierRemittance;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Caisse des livreurs : espèces encaissées et pas encore versées, versements à la boutique et écarts.
 */
class CashDesk
{
    /**
     * Situation de chaque livreur : espèces détenues, colis en main, plafond.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summary(Store $store): Collection
    {
        $held = CashCollection::forStore($store->id)->whereNull('remittance_id')->where('method', 'cash')
            ->groupBy('courier_id')->selectRaw('courier_id, SUM(amount) AS total, COUNT(*) AS count, MIN(collected_at) AS oldest')
            ->get()->keyBy('courier_id');

        return Courier::forStore($store->id)->where('status', '!=', 'invited')->orderBy('name')->get()
            ->map(fn (Courier $c) => [
                'courier_id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'held' => (int) ($held[$c->id]->total ?? 0),
                'collections_count' => (int) ($held[$c->id]->count ?? 0),
                'oldest_at' => isset($held[$c->id]) ? $held[$c->id]->oldest : null,
                'cash_limit' => $c->cash_limit,
                'over_limit' => $c->cash_limit !== null && (int) ($held[$c->id]->total ?? 0) > $c->cash_limit,
            ])->values();
    }

    /**
     * Le livreur verse ses espèces : tous ses encaissements en cours (ou ceux choisis) sont soldés ; l'écart
     * (manquant ou excédent) est gardé.
     *
     * @param  list<int>|null  $collectionIds
     */
    public function remit(Courier $courier, int $received, string $method, ?string $note, User $receiver, ?array $collectionIds = null): CourierRemittance
    {
        return DB::transaction(function () use ($courier, $received, $method, $note, $receiver, $collectionIds) {
            $collections = CashCollection::forStore($courier->store_id)->where('courier_id', $courier->id)
                ->whereNull('remittance_id')->where('method', 'cash')
                ->when($collectionIds !== null, fn ($q) => $q->whereIn('id', $collectionIds))
                ->lockForUpdate()->get();

            if ($collections->isEmpty()) {
                throw new BusinessRuleException('Ce livreur n\'a aucun encaissement à verser.');
            }
            if ($received < 0) {
                throw new BusinessRuleException('Montant invalide.', 'received_amount');
            }

            $expected = (int) $collections->sum('amount');
            $remittance = CourierRemittance::create([
                'store_id' => $courier->store_id,
                'courier_id' => $courier->id,
                'expected_amount' => $expected,
                'received_amount' => $received,
                'difference' => $received - $expected,
                'method' => $method,
                'received_by' => $receiver->id,
                'note' => $note,
                'received_at' => now(),
            ]);
            CashCollection::whereIn('id', $collections->pluck('id'))->update(['remittance_id' => $remittance->id]);

            return $remittance;
        });
    }

    /**
     * Livreurs au-delà de leur plafond d'espèces (alerte).
     */
    public function overLimit(Courier $courier): bool
    {
        return $courier->cash_limit !== null && $courier->cashHeld() > $courier->cash_limit;
    }

    public static function permission(): string
    {
        return Permission::CouriersManage->value.'|'.Permission::FinanceView->value;
    }
}
