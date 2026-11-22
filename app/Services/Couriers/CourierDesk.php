<?php

namespace App\Services\Couriers;

use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierAssignment;
use App\Models\Shipment;
use App\Services\Orders\Expeditions;
use App\Services\Shipping\Drivers\RemoteShipment;
use App\Services\Shipping\ShipmentDesk;
use App\Services\WhatsApp\CustomerMessenger;
use App\Support\Files;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ce que fait le livreur dans son application : colis récupéré, en route, livré (code du client ou photo,
 * encaissement), échec (motif, report), colis rapporté à la boutique.
 */
class CourierDesk
{
    public const MOBILE_METHODS = ['wave', 'orange_money', 'mtn_momo', 'moov_money'];

    /**
     * Étapes possibles depuis chaque statut. Après un échec sans nouvelle date, le marchand décide (relivrer ou
     * retour) : le livreur n'a rien à faire. Le retour est demandé par le marchand, puis le colis est reçu à la boutique.
     */
    private const NEXT = [
        'awaiting_pickup' => ['in_transit'],
        'in_transit' => ['out_for_delivery', 'delivered', 'failed'],
        'out_for_delivery' => ['delivered', 'failed'],
        'rescheduled' => ['out_for_delivery', 'delivered', 'failed'],
    ];

    public function __construct(private readonly ShipmentDesk $shipments) {}

    /**
     * @return list<string>
     */
    public static function nextSteps(Shipment $shipment, ?Carbon $day = null): array
    {
        return CourierDays::allowedSteps($shipment, self::NEXT[$shipment->status->value] ?? [], $day);
    }

    /**
     * @param  array{code?: ?string, payment_method?: ?string, collected_amount?: ?int, reason?: ?string, rescheduled_to?: ?string, note?: ?string, occurred_at?: ?string}  $data
     */
    public function update(Shipment $shipment, Courier $courier, string $step, array $data, ?UploadedFile $photo = null): Shipment
    {
        if ($shipment->courier_id !== $courier->id || ! $shipment->isOwnCourier()) {
            throw new BusinessRuleException('Cette course ne vous est pas confiée.');
        }
        // Jour de l'étape (faite sans réseau, elle garde son jour) : date passée ou course d'un autre jour refusées
        $day = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : today();
        if (in_array($step, self::NEXT[$shipment->status->value] ?? [], true) && ! in_array($step, self::nextSteps($shipment, $day), true)) {
            throw new BusinessRuleException(CourierDays::refusal($shipment, $day) ?? 'Étape impossible pour cette livraison.', 'status');
        }
        if (! in_array($step, self::nextSteps($shipment, $day), true)) {
            throw new BusinessRuleException('Étape impossible pour cette livraison.', 'status');
        }

        $store = $shipment->store;
        // Expédition : « livrée » veut dire déposée à la gare, avec la compagnie et ses frais
        $expedition = $step === 'delivered' && $shipment->order->isExpedition();
        if ($expedition && (blank($data['expedition_company'] ?? null) || ! isset($data['expedition_fee']))) {
            throw new BusinessRuleException('Indiquez la compagnie de transport et les frais d\'expédition payés.', 'expedition_company');
        }
        $photoPath = $photo?->store("proofs/{$store->id}", Files::privateDisk());
        $remote = match (true) {
            $expedition => new RemoteShipment(trackingCode: '', status: ShipmentStatus::Delivered, rawStatus: 'delivered', collected: 0, proofPhotoPath: $photoPath, occurredAt: $data['occurred_at'] ?? null),
            $step === 'delivered' => $this->delivered($shipment, $data, $photoPath),
            $step === 'failed' => $this->failed($shipment, $data, $photoPath),
            default => new RemoteShipment(trackingCode: '', status: ShipmentStatus::from($step), rawStatus: $step, occurredAt: $data['occurred_at'] ?? null),
        };

        $this->shipments->apply($shipment, $remote, 'courier', $data['note'] ?? ($step === 'failed' ? $data['reason'] : null), $courier->user);
        if ($expedition) {
            app(Expeditions::class)->record($shipment->order->fresh(), [
                'company' => (string) $data['expedition_company'], 'fee' => (int) $data['expedition_fee'],
                'ticket' => $data['expedition_ticket'] ?? null, 'advanced' => (bool) ($data['expedition_advanced'] ?? false),
            ], $photoPath, $courier->user, $courier);
        }
        // Reportée avec le client : la commande prend la nouvelle date (la boutique ne la voit pas « en retard »)
        if ($step === 'failed' && ! empty($data['rescheduled_to'])) {
            $shipment->order->forceFill(['scheduled_date' => $data['rescheduled_to']])->save();
        }
        // Colis récupéré : la mission est acceptée de fait
        if ($step === 'in_transit') {
            CourierAssignment::forStore($shipment->store_id)->where('shipment_id', $shipment->id)->where('courier_id', $courier->id)
                ->where('status', 'assigned')->whereNull('accepted_at')->update(['accepted_at' => now(), 'seen_at' => DB::raw('COALESCE(seen_at, CURRENT_TIMESTAMP)')]);
        }
        $shipment = $shipment->fresh(['order', 'courier']);

        // Le livreur part : le client est prévenu (nom et numéro du livreur), un échec d'envoi ne bloque pas l'étape.
        // Étape faite sans réseau et envoyée bien plus tard : le message arriverait trop tard, il ne part pas.
        $late = isset($data['occurred_at']) && Carbon::parse($data['occurred_at'])->lt(now()->subMinutes(15));
        if ($step === 'out_for_delivery' && ! $late && ! $shipment->order->isExpedition()) {
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
            // Sans nouvelle date convenue avec le client : le marchand décide (relivrer ou faire revenir le colis)
            needsDecision: $date === null,
            occurredAt: $data['occurred_at'] ?? null,
        );
    }
}
