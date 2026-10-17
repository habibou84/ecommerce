<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tiroir-caisse de la boutique : ouverture avec un fond de caisse, dépenses, retraits et apports, puis clôture
 * avec comptage. Les espèces attendues sont le fond de caisse plus la colonne « espèces » de la caisse
 * (CashOverview) depuis l'ouverture : ventes au comptoir, remises des livreurs, reversements, paie, dépenses…
 */
class CashRegister
{
    public function __construct(private readonly CashOverview $overview) {}

    public function current(Store $store): ?CashSession
    {
        return CashSession::forStore($store->id)->whereNull('closed_at')->latest('id')->first();
    }

    public function expected(Store $store, CashSession $session): int
    {
        $to = $session->closed_at ? CarbonImmutable::parse($session->closed_at) : CarbonImmutable::now();

        return $session->opening_float + $this->overview->flows($store, CarbonImmutable::parse($session->opened_at), $to)['cash'];
    }

    public function open(Store $store, int $openingFloat, User $actor): CashSession
    {
        return DB::transaction(function () use ($store, $openingFloat, $actor) {
            Store::whereKey($store->id)->lockForUpdate()->first();
            if ($this->current($store) !== null) {
                throw new BusinessRuleException('La caisse est déjà ouverte.');
            }

            return CashSession::create([
                'store_id' => $store->id,
                'opening_float' => $openingFloat,
                'opened_at' => now(),
                'opened_by' => $actor->id,
            ]);
        });
    }

    /**
     * Une dépense payée autrement qu'en espèces peut être datée dans le passé (publicité de la semaine dernière…).
     *
     * @param  array{type: string, amount: int, method?: string, category?: ?string, channel?: ?string, occurred_on?: ?string, note?: ?string}  $data
     */
    public function record(Store $store, array $data, User $actor): CashMovement
    {
        $method = $data['method'] ?? 'cash';
        if ($data['type'] !== 'expense' && $method !== 'cash') {
            throw new BusinessRuleException('Un retrait ou un apport se fait en espèces.', 'method');
        }
        $occurredAt = now();
        if (filled($data['occurred_on'] ?? null)) {
            $day = CarbonImmutable::parse($data['occurred_on'], $store->timezone);
            if (! $day->isToday()) {
                if ($method === 'cash') {
                    throw new BusinessRuleException('Une dépense en espèces se note le jour même : elle sort du tiroir-caisse.', 'occurred_on');
                }
                if ($day->isFuture() || $day->lt(CarbonImmutable::now($store->timezone)->subYear()->startOfDay())) {
                    throw new BusinessRuleException('Choisissez une date dans les douze derniers mois.', 'occurred_on');
                }
                $occurredAt = $day->setTime(12, 0)->utc();
            }
        }
        $category = $data['type'] === 'expense' ? ($data['category'] ?? 'other') : null;
        $session = $this->current($store);
        if ($method === 'cash' && $session === null) {
            throw new BusinessRuleException('Ouvrez la caisse avant de sortir ou d\'ajouter des espèces.');
        }
        if ($data['type'] !== 'deposit' && $method === 'cash' && $data['amount'] > $this->expected($store, $session)) {
            throw new BusinessRuleException('Il n\'y a pas assez d\'espèces dans la caisse.', 'amount');
        }

        return CashMovement::create([
            'store_id' => $store->id,
            'cash_session_id' => $method === 'cash' ? $session?->id : null,
            'type' => $data['type'],
            'category' => $category,
            'channel' => $category === 'marketing' ? ($data['channel'] ?? null) : null,
            'amount' => $data['amount'],
            'method' => $method,
            'note' => $data['note'] ?? null,
            'occurred_at' => $occurredAt,
            'created_by' => $actor->id,
        ]);
    }

    public function close(Store $store, int $counted, ?string $note, User $actor): CashSession
    {
        return DB::transaction(function () use ($store, $counted, $note, $actor) {
            $session = CashSession::forStore($store->id)->whereNull('closed_at')->lockForUpdate()->latest('id')->first();
            if ($session === null) {
                throw new BusinessRuleException('La caisse n\'est pas ouverte.');
            }

            $session->closed_at = now();
            $expected = $this->expected($store, $session);
            $session->forceFill([
                'closed_by' => $actor->id,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'difference' => $counted - $expected,
                'note' => $note,
            ])->save();

            return $session;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Store $store, CashSession $session): array
    {
        $session->loadMissing('opener:id,name', 'closer:id,name');

        return [
            'id' => $session->id,
            'opening_float' => $session->opening_float,
            'opened_at' => $session->opened_at->toIso8601String(),
            'opened_by' => $session->opener?->name,
            'closed_at' => $session->closed_at?->toIso8601String(),
            'closed_by' => $session->closer?->name,
            'expected_cash' => $session->isOpen() ? $this->expected($store, $session) : $session->expected_cash,
            'counted_cash' => $session->counted_cash,
            'difference' => $session->difference,
            'note' => $session->note,
        ];
    }
}
