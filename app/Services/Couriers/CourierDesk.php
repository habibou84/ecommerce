<?php

namespace App\Services\Couriers;

use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Shipping\Drivers\RemoteShipment;
use App\Services\Shipping\ShipmentDesk;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Ce que fait le livreur dans son application : colis récupéré, en route, livré (code du client ou photo,
 * encaissement), échec (motif, report), colis rapporté à la boutique.
 */
class CourierDesk
{
    public const MOBILE_METHODS = ['wave', 'orange_money', 'mtn_momo', 'moov_money'];

    /** Étapes possibles depuis chaque statut */
    private const NEXT = [
        'awaiting_pickup' => ['in_transit'],
        'in_transit' => ['out_for_delivery', 'delivered', 'failed'],
        'out_for_delivery' => ['delivered', 'failed'],
        'failed' => ['out_for_delivery', 'returning'],
        'rescheduled' => ['out_for_delivery', 'returning'],
    ];

    public function __construct(private readonly ShipmentDesk $shipments) {}

    /**
     * @return list<string>
     */
    public static function nextSteps(Shipment $shipment): array
    {
        return self::NEXT[$shipment->status->value] ?? [];
    }

    /**
     * @param  array{code?: ?string, payment_method?: ?string, collected_amount?: ?int, reason?: ?string, rescheduled_to?: ?string, note?: ?string, occurred_at?: ?string}  $data
     */
    public function update(Shipment $shipment, Courier $courier, string $step, array $data, ?UploadedFile $photo = null): Shipment
    {
        if ($shipment->courier_id !== $courier->id || ! $shipment->isOwnCourier()) {
            throw new BusinessRuleException('Cette mission ne vous est pas confiée.');
        }
        if (! in_array($step, self::nextSteps($shipment), true)) {
            throw new BusinessRuleException('Étape impossible pour cette livraison.', 'status');
        }

        $store = $shipment->store;
        $photoPath = $photo?->store("proofs/{$store->id}", 'local');
        $remote = match ($step) {
            'delivered' => $this->delivered($shipment, $data, $photoPath),
            'failed' => $this->failed($shipment, $data, $photoPath),
            default => new RemoteShipment(trackingCode: '', status: ShipmentStatus::from($step), rawStatus: $step, occurredAt: $data['occurred_at'] ?? null),
        };

        $this->shipments->apply($shipment, $remote, 'courier', $data['note'] ?? ($step === 'failed' ? $data['reason'] : null), $courier->user);
        $shipment = $shipment->fresh(['order', 'courier']);

        // Le livreur part : le client est prévenu (nom et numéro du livreur), un échec d'envoi ne bloque pas l'étape.
        // Étape faite sans réseau et envoyée bien plus tard : le message arriverait trop tard, il ne part pas.
        $late = isset($data['occurred_at']) && Carbon::parse($data['occurred_at'])->lt(now()->subMinutes(15));
        if ($step === 'out_for_delivery' && ! $late) {
            try {
                app(CustomerMessenger::class)->courierOnTheWay($shipment);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $shipment;
    }

    private function delivered(Shipment $shipment, array $data, ?string $photoPath): RemoteShipment
    {
        if ($shipment->delivery_code !== null && trim((string) ($data['code'] ?? '')) !== $shipment->delivery_code) {
            throw new BusinessRuleException('Code de livraison incorrect : demandez-le au client (il est sur sa page de suivi).', 'code');
        }

        $method = $data['payment_method'] ?? 'cash';
        if ($method !== 'cash' && ! in_array($method, self::MOBILE_METHODS, true)) {
            throw new BusinessRuleException('Mode de paiement inconnu.', 'payment_method');
        }
        $collected = (int) ($data['collected_amount'] ?? $shipment->cod_amount);
        if ($collected < 0 || $collected > $shipment->cod_amount) {
            throw new BusinessRuleException('Le montant encaissé ne peut pas dépasser '.$shipment->cod_amount.' F.', 'collected_amount');
        }

        return new RemoteShipment(
            trackingCode: '',
            status: ShipmentStatus::Delivered,
            rawStatus: 'delivered',
            collected: $collected,
            paymentMethod: PaymentMethod::from($method)->value,
            proofPhotoPath: $photoPath,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }

    private function failed(Shipment $shipment, array $data, ?string $photoPath): RemoteShipment
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new BusinessRuleException('Indiquez le motif.', 'reason');
        }
        $date = $data['rescheduled_to'] ?? null;
        // Jour de l'étape (une étape faite sans réseau la veille garde son jour)
        $day = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at'])->toDateString() : today()->toDateString();
        if ($date !== null && $date < $day) {
            throw new BusinessRuleException('La nouvelle date doit être aujourd\'hui ou plus tard.', 'rescheduled_to');
        }

        return new RemoteShipment(
            trackingCode: '',
            status: $date ? ShipmentStatus::Rescheduled : ShipmentStatus::Failed,
            rawStatus: $date ? 'rescheduled' : 'failed',
            incident: $reason,
            attempts: $shipment->attempts_count + 1,
            proofPhotoPath: $photoPath,
            rescheduledTo: $date,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }
}
