<?php

namespace App\Services\Shipping;

use App\Enums\ShipmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CarrierConnection;
use App\Models\CarrierPayout;
use App\Models\CarrierPayoutLine;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /**
     * Reversement d'un transporteur sans API (payé en espèces, Wave…), saisi par la boutique : les courses cochées
     * sont soldées et l'écart entre le montant reçu et le net attendu est noté.
     *
     * @param  list<int>  $shipmentIds
     * @param  array{amount: int, method?: ?string, transaction_ref?: ?string, paid_at?: ?string}  $data
     */
    public function recordManual(CarrierConnection $connection, array $shipmentIds, array $data): CarrierPayout
    {
        if ($connection->isApi()) {
            throw new BusinessRuleException('L\'argent reversé par '.$connection->name.' arrive automatiquement.', 'shipment_ids');
        }

        return DB::transaction(function () use ($connection, $shipmentIds, $data) {
            $shipments = Shipment::forStore($connection->store_id)->where('carrier_connection_id', $connection->id)
                ->where('status', ShipmentStatus::Delivered->value)->whereDoesntHave('payoutLines')
                ->whereIn('id', $shipmentIds)->lockForUpdate()->get();
            if ($shipments->isEmpty() || $shipments->count() !== count(array_unique($shipmentIds))) {
                throw new BusinessRuleException('Choisissez des courses livrées qui n\'ont pas encore été reversées.', 'shipment_ids');
            }

            $nets = $shipments->mapWithKeys(fn (Shipment $s) => [$s->id => (int) ($s->collected_amount ?? $s->cod_amount) - (int) $s->carrier_fee]);
            $difference = $data['amount'] - $nets->sum();

            $payout = CarrierPayout::create([
                'store_id' => $connection->store_id,
                'connection_id' => $connection->id,
                'remote_id' => 'MAN-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'amount' => $data['amount'],
                'total_collected' => (int) $shipments->sum(fn (Shipment $s) => (int) ($s->collected_amount ?? $s->cod_amount)),
                'total_fees' => (int) $shipments->sum('carrier_fee'),
                'method' => $data['method'] ?? null,
                'transaction_ref' => $data['transaction_ref'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
                'reconciliation_status' => $difference === 0 ? 'matched' : 'discrepancy',
                'difference' => $difference,
            ]);

            foreach ($shipments as $s) {
                CarrierPayoutLine::create([
                    'carrier_payout_id' => $payout->id,
                    'shipment_id' => $s->id,
                    'remote_tracking_code' => $s->carrier_tracking_code ?? $s->reference(),
                    'collected' => (int) ($s->collected_amount ?? $s->cod_amount),
                    'fees' => (int) $s->carrier_fee,
                    'net' => $nets[$s->id],
                    'expected_net' => $nets[$s->id],
                    'difference' => 0,
                ]);
            }

            return $payout;
        });
    }
}
