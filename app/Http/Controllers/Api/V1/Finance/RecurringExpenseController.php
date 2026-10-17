<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\RecurringExpense;
use App\Services\Finance\RecurringExpenses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dépenses mensuelles automatiques (loyer, salaires, abonnements…).
 */
class RecurringExpenseController extends Controller
{
    public function __construct(private readonly RecurringExpenses $expenses) {}

    public function index(): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => RecurringExpense::forStore($store->id)->orderBy('day_of_month')->orderBy('id')->get()
            ->map(fn (RecurringExpense $e) => $this->expenses->present($store, $e))]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        $expense = $this->expenses->save($store, null, $this->validated($request), $request->user());

        return response()->json(['data' => $this->expenses->present($store, $expense)], 201);
    }

    public function update(Request $request, int $expense): JsonResponse
    {
        $store = $this->currentStore();
        $model = RecurringExpense::forStore($store->id)->findOrFail($expense);

        return response()->json(['data' => $this->expenses->present($store, $this->expenses->save($store, $model, $this->validated($request)))]);
    }

    public function destroy(int $expense): JsonResponse
    {
        RecurringExpense::forStore($this->currentStore()->id)->findOrFail($expense)->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(array_keys(CashMovement::CATEGORIES))],
            'channel' => ['nullable', Rule::in(array_keys(CashMovement::CHANNELS))],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['category' => 'catégorie', 'amount' => 'montant', 'method' => 'moyen', 'day_of_month' => 'jour du mois', 'channel' => 'canal']);
    }
}
