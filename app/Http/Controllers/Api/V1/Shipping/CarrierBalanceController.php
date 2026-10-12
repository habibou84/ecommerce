<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Services\Shipping\CarrierBalances;
use App\Services\Shipping\PayoutReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ce que les transporteurs doivent à la boutique : solde par transporteur, détail des courses à reverser,
 * relevé CSV et saisie d'un reversement reçu d'un transporteur sans API.
 */
class CarrierBalanceController extends Controller
{
    public function __construct(private readonly CarrierBalances $balances) {}

    public function index(): JsonResponse
    {
        $carriers = $this->balances->summary($this->currentStore());

        return response()->json([
            'data' => $carriers,
            'total' => array_sum(array_column($carriers, 'amount')),
            'overdue_days' => $this->balances->overdueDays(),
        ]);
    }

    public function show(int $carrier): JsonResponse
    {
        $connection = CarrierConnection::findOrFail($carrier);

        return response()->json(['data' => $this->balances->pending($this->currentStore(), $connection)->values()]);
    }

    public function export(int $carrier): Response
    {
        $connection = CarrierConnection::findOrFail($carrier);
        $name = 'a-recevoir-'.Str::slug($connection->name).'-'.now()->format('Y-m-d').'.csv';

        return response($this->balances->csv($this->currentStore(), $connection), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function storePayout(Request $request, int $carrier, PayoutReconciler $reconciler): JsonResponse
    {
        $connection = CarrierConnection::findOrFail($carrier);
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['integer'],
            'amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ], [], ['shipment_ids' => 'courses', 'amount' => 'montant reçu', 'method' => 'moyen', 'transaction_ref' => 'référence', 'paid_at' => 'date']);

        $payout = $reconciler->recordManual($connection, array_map('intval', $data['shipment_ids']), $data);

        return response()->json(['data' => [
            'id' => $payout->id,
            'reference' => $payout->remote_id,
            'amount' => $payout->amount,
            'status' => $payout->reconciliation_status,
            'difference' => $payout->difference,
        ]], 201);
    }
}
