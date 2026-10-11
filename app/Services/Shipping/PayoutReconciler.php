<?php

namespace App\Services\Shipping;

use App\Models\CarrierConnection;
use App\Models\CarrierPayout;
use App\Models\CarrierPayoutLine;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * Rapprochement d'un reversement du transporteur avec les expéditions : pour chaque course, montant encaissé et
 * frais retenus, comparés à ce que la boutique attendait (encaissé − tarif du transporteur).
 */
class PayoutReconciler
{
    public function __construct(private readonly CarrierConnections $connections) {}

    public function import(CarrierConnection $connection, string $reference): CarrierPayout
    {
        $data = $this->connections->driver($connection)->payout($connection, $reference);

        return DB::transaction(function () use ($connection, $data) {
            $payout = CarrierPayout::forStore($connection->store_id)->updateOrCreate(
                ['connection_id' => $connection->id, 'remote_id' => $data['remote_id']],
                [
                    'store_id' => $connection->store_id,
                    'amount' => $data['amount'],
                    'total_collected' => $data['total_collected'],
                    'total_fees' => $data['total_fees'],
                    'method' => $data['method'],
                    'transaction_ref' => $data['transaction_ref'],
                    'paid_at' => $data['paid_at'],
                    'raw' => $data['raw'],
                ],
            );
            $payout->lines()->delete();

            $shipments = Shipment::forStore($connection->store_id)->where('carrier_connection_id', $connection->id)
                ->whereIn('carrier_tracking_code', array_column($data['lines'], 'tracking_code'))->get()->keyBy('carrier_tracking_code');

            $difference = 0;
            foreach ($data['lines'] as $line) {
                $shipment = $shipments->get($line['tracking_code']);
                $net = $line['collected'] - $line['fees'];
                $expected = $shipment ? (int) ($shipment->collected_amount ?? $shipment->cod_amount) - (int) $shipment->carrier_fee : null;
                $lineDifference = $expected === null ? $net : $net - $expected;
                $difference += $lineDifference;

                CarrierPayoutLine::create([
                    'carrier_payout_id' => $payout->id,
                    'shipment_id' => $shipment?->id,
                    'remote_tracking_code' => $line['tracking_code'],
                    'collected' => $line['collected'],
                    'fees' => $line['fees'],
                    'net' => $net,
                    'expected_net' => $expected,
                    'difference' => $lineDifference,
                ]);
            }

            $payout->forceFill([
                'difference' => $difference,
                'reconciliation_status' => $difference === 0 && $shipments->count() === count($data['lines']) ? 'matched' : 'discrepancy',
            ])->save();

            return $payout;
        });
    }
}
