<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Services\Finance\CashRegister;
use App\Services\Finance\CounterSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Tiroir-caisse : session en cours, ouverture, dépenses / retraits / apports, clôture, ventes au comptoir.
 */
class CashRegisterController extends Controller
{
    public function __construct(private readonly CashRegister $register) {}

    public function show(): JsonResponse
    {
        $store = $this->currentStore();
        $session = $this->register->current($store);
        $last = CashSession::forStore($store->id)->whereNotNull('closed_at')->latest('closed_at')->first();

        return response()->json(['data' => [
            'session' => $session ? $this->register->present($store, $session) : null,
            'suggested_float' => $last?->counted_cash ?? 0,
            'movements' => CashMovement::forStore($store->id)->with('author:id,name')
                ->when($session, fn ($q) => $q->where('occurred_at', '>=', $session->opened_at), fn ($q) => $q->where('occurred_at', '>=', now()->subDay()))
                ->latest('occurred_at')->limit(50)->get()->map(fn (CashMovement $m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'category' => $m->category,
                    'category_label' => $m->category ? CashMovement::CATEGORIES[$m->category] ?? $m->category : null,
                    'amount' => $m->amount,
                    'method' => $m->method,
                    'note' => $m->note,
                    'occurred_at' => $m->occurred_at->toIso8601String(),
                    'author' => $m->author?->name,
                ]),
            'closings' => CashSession::forStore($store->id)->whereNotNull('closed_at')->latest('closed_at')->limit(10)->get()
                ->map(fn (CashSession $s) => $this->register->present($store, $s)),
            'categories' => collect(CashMovement::CATEGORIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_float' => ['required', 'integer', 'min:0', 'max:100000000']], [], ['opening_float' => 'fond de caisse']);
        $store = $this->currentStore();

        return response()->json(['data' => $this->register->present($store, $this->register->open($store, $data['opening_float'], $request->user()))], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['counted_cash' => 'espèces comptées']);
        $store = $this->currentStore();

        return response()->json(['data' => $this->register->present($store, $this->register->close($store, $data['counted_cash'], $data['note'] ?? null, $request->user()))]);
    }

    public function movement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(CashMovement::TYPES)],
            'category' => ['nullable', Rule::in(array_keys(CashMovement::CATEGORIES))],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'category' => 'catégorie', 'method' => 'moyen']);

        $movement = $this->register->record($this->currentStore(), $data, $request->user());

        return response()->json(['data' => ['id' => $movement->id, 'type' => $movement->type, 'amount' => $movement->amount]], 201);
    }

    public function sell(Request $request, CounterSales $sales): JsonResponse
    {
        if (is_string($request->input('customer_phone')) && PhoneNumber::isValid($request->input('customer_phone'))) {
            $request->merge(['customer_phone' => PhoneNumber::normalize($request->input('customer_phone'))]);
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'payments' => ['present', 'array', 'max:5'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', new PhoneRule],
            'discount_total' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['items' => 'articles', 'payments' => 'paiements', 'customer_phone' => 'téléphone']);

        $order = $sales->sell($this->currentStore(), $data, $request->user());

        return response()->json(['data' => [
            'id' => $order->id,
            'reference' => $order->reference(),
            'total' => $order->total,
            'amount_paid' => $order->amount_paid,
            'amount_due' => $order->amountDue(),
            'payment_status' => $order->payment_status->value,
        ]], 201);
    }
}
