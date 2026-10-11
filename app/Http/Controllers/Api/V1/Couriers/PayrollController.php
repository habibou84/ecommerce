<?php

namespace App\Http\Controllers\Api\V1\Couriers;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Services\Couriers\CourierPayroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Rémunération des livreurs : gains, primes et avances, relevés de paie.
 */
class PayrollController extends Controller
{
    public function __construct(private readonly CourierPayroll $payroll) {}

    public function earnings(int $courier): JsonResponse
    {
        $model = Courier::findOrFail($courier);

        return response()->json([
            'data' => CourierEarning::with('shipment.order.store')->where('courier_id', $model->id)->whereNull('payout_id')->latest('id')->get()
                ->map(fn (CourierEarning $e) => $this->presentEarning($e)),
            'unpaid' => $this->payroll->unpaid($model),
            'payouts' => CourierPayout::where('courier_id', $model->id)->latest('id')->limit(30)->get()->map(fn ($p) => $this->presentPayout($p)),
        ]);
    }

    public function adjust(Request $request, int $courier): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['bonus', 'adjustment', 'advance'])],
            'amount' => ['required', 'integer', 'between:-10000000,10000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $earning = $this->payroll->adjust(Courier::findOrFail($courier), $data['type'], $data['amount'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->presentEarning($earning)], 201);
    }

    public function createPayout(Request $request, int $courier): JsonResponse
    {
        $data = $request->validate(['until' => ['nullable', 'date', 'before_or_equal:today']]);
        $payout = $this->payroll->createPayout(Courier::findOrFail($courier), $data['until'] ?? null ? Carbon::parse($data['until']) : today(), $request->user());

        return response()->json(['data' => $this->presentPayout($payout)], 201);
    }

    public function pay(Request $request, int $payout): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'wave', 'orange_money', 'mtn_momo', 'moov_money', 'bank'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->presentPayout($this->payroll->markPaid(CourierPayout::findOrFail($payout), $data['method'], $data['reference'] ?? null, $request->user()))]);
    }

    public function cancel(int $payout): JsonResponse
    {
        $this->payroll->cancel(CourierPayout::findOrFail($payout));

        return response()->json(['message' => 'Relevé annulé.']);
    }

    private function presentEarning(CourierEarning $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => CourierEarning::TYPES[$e->type] ?? $e->type,
            'amount' => $e->amount,
            'reference' => $e->shipment?->reference(),
            'order_id' => $e->shipment?->order_id,
            'note' => $e->note,
            'created_at' => $e->created_at->toIso8601String(),
        ];
    }

    private function presentPayout(CourierPayout $p): array
    {
        return [
            'id' => $p->id,
            'period_start' => $p->period_start?->toDateString(),
            'period_end' => $p->period_end->toDateString(),
            'total' => $p->total,
            'status' => $p->status,
            'method' => $p->method,
            'reference' => $p->reference,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'lines_count' => $p->earnings()->count(),
        ];
    }
}
