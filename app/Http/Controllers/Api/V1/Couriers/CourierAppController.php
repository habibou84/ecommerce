<?php

namespace App\Http\Controllers\Api\V1\Couriers;

use App\Http\Controllers\Controller;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\Shipment;
use App\Services\Couriers\CourierDesk;
use App\Services\Couriers\CourierPayroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;

/**
 * API de l'application livreur (/livreur) : ses missions, leurs étapes, son argent.
 */
class CourierAppController extends Controller
{
    private const ACTIVE = ['awaiting_pickup', 'in_transit', 'out_for_delivery', 'failed', 'rescheduled', 'returning'];

    public function me(Request $request, CourierPayroll $payroll): JsonResponse
    {
        $courier = $this->courier($request);
        $today = Shipment::where('courier_id', $courier->id)->whereDate('delivered_at', today());

        return response()->json(['data' => [
            'id' => $courier->id,
            'name' => $courier->name,
            'is_available' => $courier->is_available,
            'store' => ['name' => $courier->store->name, 'phone' => $courier->store->phone],
            'cash_held' => $courier->cashHeld(),
            'cash_limit' => $courier->cash_limit,
            'unpaid' => $payroll->unpaid($courier),
            'today' => ['delivered' => (clone $today)->count(), 'collected' => (int) CashCollection::where('courier_id', $courier->id)->whereDate('collected_at', today())->sum('amount')],
        ]]);
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['is_available' => ['required', 'boolean']]);
        $this->courier($request)->forceFill(['is_available' => $data['is_available']])->save();

        return response()->json(['data' => ['is_available' => $data['is_available']]]);
    }

    /**
     * Missions en cours, puis celles terminées aujourd'hui.
     */
    public function missions(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $missions = Shipment::with('order')->where('courier_id', $courier->id)
            ->where(fn ($q) => $q->whereIn('status', self::ACTIVE)->orWhereDate('updated_at', today()))
            ->orderByRaw("CASE status WHEN 'out_for_delivery' THEN 0 WHEN 'in_transit' THEN 1 WHEN 'awaiting_pickup' THEN 2 WHEN 'rescheduled' THEN 3 WHEN 'failed' THEN 4 WHEN 'returning' THEN 5 ELSE 6 END")
            ->orderBy('requested_at')->get();

        return response()->json(['data' => $missions->map(fn (Shipment $s) => $this->summary($s))]);
    }

    public function show(Request $request, int $shipment): JsonResponse
    {
        $model = $this->mission($request, $shipment)->load(['order.items', 'origin']);
        $order = $model->order;

        return response()->json(['data' => $this->summary($model) + [
            'customer_phone' => $order->shipping_phone ?? $order->customer_phone,
            'customer_phone_display' => PhoneNumber::display($order->shipping_phone ?? $order->customer_phone),
            'customer_phone2' => $order->shipping_phone2,
            'address' => $order->shipping_address,
            'landmark' => $order->shipping_landmark,
            'customer_note' => $order->customer_note,
            'note' => $model->note,
            'pickup' => $model->origin ? ['name' => $model->origin->name, 'address' => $model->origin->address] : null,
            'items' => $order->items->map(fn ($i) => ['label' => $i->label(), 'quantity' => $i->quantity]),
            'requires_code' => $model->delivery_code !== null,
            'failure_reason' => $model->failure_reason,
            'rescheduled_to' => $model->rescheduled_to?->toDateString(),
            'next_steps' => CourierDesk::nextSteps($model),
        ]]);
    }

    public function step(Request $request, int $shipment, CourierDesk $desk): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_transit', 'out_for_delivery', 'delivered', 'failed', 'returning'])],
            'code' => ['nullable', 'string', 'max:10'],
            'payment_method' => ['nullable', Rule::in(['cash', ...CourierDesk::MOBILE_METHODS])],
            'collected_amount' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'rescheduled_to' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
        ], [], ['code' => 'code', 'reason' => 'motif', 'photo' => 'photo']);

        $courier = $this->courier($request);
        $model = $desk->update($this->mission($request, $shipment), $courier, $data['status'], $data, $request->file('photo'));

        return response()->json(['data' => $this->summary($model)]);
    }

    /**
     * Ses encaissements non versés et ses derniers versements.
     */
    public function cash(Request $request): JsonResponse
    {
        $courier = $this->courier($request);

        return response()->json(['data' => [
            'held' => $courier->cashHeld(),
            'collections' => CashCollection::with('shipment.order.store')->where('courier_id', $courier->id)->whereNull('remittance_id')
                ->where('method', 'cash')->orderByDesc('collected_at')->get()
                ->map(fn ($c) => ['reference' => $c->shipment->order->reference(), 'amount' => $c->amount, 'collected_at' => $c->collected_at->toIso8601String()]),
            'remittances' => CourierRemittance::where('courier_id', $courier->id)->latest('received_at')->limit(10)->get()
                ->map(fn ($r) => ['received_amount' => $r->received_amount, 'difference' => $r->difference, 'received_at' => $r->received_at->toIso8601String()]),
        ]]);
    }

    public function earnings(Request $request, CourierPayroll $payroll): JsonResponse
    {
        $courier = $this->courier($request);

        return response()->json(['data' => [
            'unpaid' => $payroll->unpaid($courier),
            'lines' => CourierEarning::with('shipment.order.store')->where('courier_id', $courier->id)->whereNull('payout_id')->latest('id')->limit(100)->get()
                ->map(fn ($e) => ['type_label' => CourierEarning::TYPES[$e->type] ?? $e->type, 'amount' => $e->amount, 'reference' => $e->shipment?->reference(), 'created_at' => $e->created_at->toIso8601String()]),
        ]]);
    }

    private function courier(Request $request): Courier
    {
        return $request->attributes->get('courier');
    }

    private function mission(Request $request, int $id): Shipment
    {
        return Shipment::with('order.store')->where('courier_id', $this->courier($request)->id)->findOrFail($id);
    }

    private function summary(Shipment $s): array
    {
        $order = $s->order;

        return [
            'id' => $s->id,
            'reference' => $s->reference(),
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'customer_name' => $order->shipping_name ?? $order->customer_name,
            'place' => $order->shipping_place_label,
            'cod_amount' => $s->cod_amount,
            'collected_amount' => $s->collected_amount,
            'attempts_count' => $s->attempts_count,
            'scheduled_date' => $order->scheduled_date?->toDateString(),
        ];
    }
}
