<?php

namespace App\Services\Couriers;

use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rémunération des livreurs : gains enregistrés à chaque livraison (règle du livreur), primes, avances, puis relevé
 * qui regroupe les gains non payés jusqu'à une date et se marque payé.
 */
class CourierPayroll
{
    /**
     * Prime, ajustement (+/−) ou avance (montant retenu sur la prochaine paie).
     */
    public function adjust(Courier $courier, string $type, int $amount, ?string $note, User $actor): CourierEarning
    {
        if (! in_array($type, ['bonus', 'adjustment', 'advance'], true) || $amount === 0) {
            throw new BusinessRuleException('Montant ou type invalide.', 'amount');
        }

        return CourierEarning::create([
            'store_id' => $courier->store_id,
            'courier_id' => $courier->id,
            'type' => $type,
            // Une avance est déjà versée : elle vient en déduction
            'amount' => $type === 'advance' ? -abs($amount) : ($type === 'bonus' ? abs($amount) : $amount),
            'note' => $note,
            'created_by' => $actor->id,
        ]);
    }

    public function unpaid(Courier $courier, ?Carbon $until = null): int
    {
        return (int) CourierEarning::forStore($courier->store_id)->where('courier_id', $courier->id)->whereNull('payout_id')
            ->when($until, fn ($q) => $q->where('created_at', '<=', $until->copy()->endOfDay()))->sum('amount');
    }

    /**
     * Relevé des gains non payés jusqu'à la date (incluse).
     */
    public function createPayout(Courier $courier, Carbon $until, User $actor): CourierPayout
    {
        return DB::transaction(function () use ($courier, $until, $actor) {
            $earnings = CourierEarning::forStore($courier->store_id)->where('courier_id', $courier->id)->whereNull('payout_id')
                ->where('created_at', '<=', $until->copy()->endOfDay())->lockForUpdate()->get();

            if ($earnings->isEmpty()) {
                throw new BusinessRuleException('Rien à payer à ce livreur sur cette période.');
            }

            $payout = CourierPayout::create([
                'store_id' => $courier->store_id,
                'courier_id' => $courier->id,
                'period_start' => $earnings->min('created_at'),
                'period_end' => $until->toDateString(),
                'total' => (int) $earnings->sum('amount'),
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            CourierEarning::whereIn('id', $earnings->pluck('id'))->update(['payout_id' => $payout->id]);

            return $payout;
        });
    }

    public function markPaid(CourierPayout $payout, string $method, ?string $reference, User $actor): CourierPayout
    {
        if ($payout->status !== 'draft') {
            throw new BusinessRuleException('Ce relevé est déjà payé ou annulé.');
        }
        $payout->forceFill(['status' => 'paid', 'method' => $method, 'reference' => $reference, 'paid_at' => now(), 'paid_by' => $actor->id])->save();

        return $payout;
    }

    /**
     * Relevé annulé : ses gains redeviennent à payer.
     */
    public function cancel(CourierPayout $payout): void
    {
        if ($payout->status !== 'draft') {
            throw new BusinessRuleException('Un relevé payé ne peut pas être annulé.');
        }
        DB::transaction(function () use ($payout) {
            CourierEarning::where('payout_id', $payout->id)->update(['payout_id' => null]);
            $payout->forceFill(['status' => 'cancelled'])->save();
        });
    }
}
