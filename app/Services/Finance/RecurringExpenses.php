<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\RecurringExpense;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dépenses qui reviennent chaque mois : enregistrées comme dépenses de la boutique le jour choisi (jamais en
 * espèces : rien ne sort du tiroir-caisse sans quelqu'un pour le compter).
 */
class RecurringExpenses
{
    /**
     * @param  array{category: string, channel?: ?string, amount: int, method: string, note?: ?string, day_of_month: int, is_active?: bool}  $data
     */
    public function save(Store $store, ?RecurringExpense $expense, array $data, ?User $actor = null): RecurringExpense
    {
        if ($data['method'] === 'cash') {
            throw new BusinessRuleException('Une dépense automatique ne peut pas être en espèces : notez-la dans la caisse le jour où vous la payez.', 'method');
        }
        $data['channel'] = $data['category'] === 'marketing' ? ($data['channel'] ?? null) : null;

        $expense ??= new RecurringExpense(['store_id' => $store->id, 'created_by' => $actor?->id]);
        $isNew = ! $expense->exists;
        $expense->fill($data);
        if ($isNew) {
            // Le mois en cours ne compte que si le jour n'est pas encore passé : la première dépense est la prochaine échéance
            $today = CarbonImmutable::now($store->timezone);
            if ($today->day > $expense->day_of_month) {
                $expense->last_run_on = $today->setDay($expense->day_of_month)->toDateString();
            }
        }
        $expense->save();

        return $expense;
    }

    public function nextDate(Store $store, RecurringExpense $expense): string
    {
        $today = CarbonImmutable::now($store->timezone)->startOfDay();
        $due = $today->setDay($expense->day_of_month);
        if ($due->lt($today) || ($expense->last_run_on !== null && $expense->last_run_on->toDateString() >= $due->toDateString())) {
            $due = $today->startOfMonth()->addMonth()->setDay($expense->day_of_month);
        }

        return $due->toDateString();
    }

    /**
     * Enregistre les échéances arrivées (toutes boutiques) ; renvoie le nombre de dépenses créées.
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $count = 0;
        RecurringExpense::withoutGlobalScopes()->where('is_active', true)->with('store')->get()
            ->each(function (RecurringExpense $expense) use ($now, &$count) {
                $store = $expense->store;
                if ($store === null) {
                    return;
                }
                $today = ($now ?? CarbonImmutable::now())->setTimezone($store->timezone)->startOfDay();
                $due = $today->setDay($expense->day_of_month);
                if ($today->lt($due) || ($expense->last_run_on !== null && $expense->last_run_on->toDateString() >= $due->toDateString())) {
                    return;
                }

                DB::transaction(function () use ($expense, $store, $due) {
                    CashMovement::forceCreate([
                        'store_id' => $store->id,
                        'recurring_expense_id' => $expense->id,
                        'type' => 'expense',
                        'category' => $expense->category,
                        'channel' => $expense->channel,
                        'amount' => $expense->amount,
                        'method' => $expense->method,
                        'note' => $expense->note ?: 'Dépense mensuelle',
                        'occurred_at' => $due->setTime(12, 0)->utc(),
                        'created_by' => $expense->created_by,
                    ]);
                    $expense->forceFill(['last_run_on' => $due->toDateString()])->save();
                });
                $count++;
            });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Store $store, RecurringExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'category_label' => CashMovement::CATEGORIES[$expense->category] ?? $expense->category,
            'channel' => $expense->channel,
            'channel_label' => $expense->channel ? CashMovement::CHANNELS[$expense->channel] ?? $expense->channel : null,
            'amount' => $expense->amount,
            'method' => $expense->method,
            'note' => $expense->note,
            'day_of_month' => $expense->day_of_month,
            'is_active' => $expense->is_active,
            'next_date' => $expense->is_active ? $this->nextDate($store, $expense) : null,
        ];
    }
}
