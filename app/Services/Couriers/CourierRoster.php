<?php

namespace App\Services\Couriers;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\DeliveryZone;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\StoreRoles;
use App\Services\Billing\PlanLimits;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\PhoneNumber;

/**
 * Livreurs de la boutique : ajout (lien d'invitation à envoyer par WhatsApp), zones, disponibilité, désactivation.
 * Une personne déjà membre de l'équipe (gérant qui livre aussi) est reliée tout de suite, sans invitation.
 */
class CourierRoster
{
    public function __construct(
        private readonly Invitations $invitations,
        private readonly PlanLimits $limits,
        private readonly StoreRoles $roles,
    ) {}

    /**
     * @param  array{name: string, phone: string, vehicle_type?: string, pay_rule_id?: ?int, cash_limit?: ?int, zone_ids?: list<int>, note?: ?string}  $data
     * @return array{0: Courier, 1: ?string} livreur et lien d'invitation (null s'il a déjà un compte dans la boutique)
     */
    public function add(Store $store, User $inviter, array $data): array
    {
        $phone = PhoneNumber::normalize($data['phone']) ?? throw new BusinessRuleException('Numéro de téléphone invalide.', 'phone');
        if (Courier::forStore($store->id)->where('phone', $phone)->exists()) {
            throw new BusinessRuleException('Ce livreur est déjà enregistré.', 'phone');
        }
        $this->limits->ensureCanAdd($store, 'couriers', Courier::forStore($store->id)->where('status', '!=', 'inactive')->count());

        $member = User::where('phone', $phone)->first();
        $isMember = $member !== null && $member->activeMembershipIn($store) !== null;

        return DB::transaction(function () use ($store, $inviter, $data, $phone, $member, $isMember) {
            $courier = Courier::create([
                'store_id' => $store->id,
                'name' => $data['name'],
                'phone' => $phone,
                'vehicle_type' => $data['vehicle_type'] ?? 'moto',
                'pay_rule_id' => $data['pay_rule_id'] ?? null,
                'cash_limit' => $data['cash_limit'] ?? null,
                'note' => $data['note'] ?? null,
                'user_id' => $isMember ? $member->id : null,
                'status' => $isMember ? 'active' : 'invited',
            ]);
            $this->syncZones($courier, $data['zone_ids'] ?? []);

            $link = $isMember ? null : $this->invitations->invite($store, $inviter, $phone, Role::Courier, $data['name'])[1];

            return [$courier, $link];
        });
    }

    /**
     * Nouveau lien (le précédent cesse de fonctionner).
     */
    public function reinvite(Courier $courier, User $inviter): string
    {
        if ($courier->status !== 'invited') {
            throw new BusinessRuleException('Ce livreur a déjà son compte.');
        }

        return $this->invitations->invite($courier->store, $inviter, $courier->phone, Role::Courier, $courier->name)[1];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Courier $courier, array $data): Courier
    {
        DB::transaction(function () use ($courier, $data) {
            $courier->fill(collect($data)->only(['name', 'vehicle_type', 'pay_rule_id', 'cash_limit', 'is_available', 'note'])->all())->save();
            if (array_key_exists('zone_ids', $data)) {
                $this->syncZones($courier, $data['zone_ids'] ?? []);
            }
        });

        return $courier;
    }

    /**
     * Le livreur ne reçoit plus de missions et perd l'accès à l'application (s'il n'est que livreur).
     */
    public function deactivate(Courier $courier): void
    {
        if ($courier->shipments()->whereIn('status', ['awaiting_pickup', 'in_transit', 'out_for_delivery', 'failed', 'rescheduled', 'returning'])->exists()) {
            throw new BusinessRuleException('Ce livreur a encore des missions en cours : réassignez-les d\'abord.');
        }

        DB::transaction(function () use ($courier) {
            $courier->forceFill(['status' => 'inactive', 'is_available' => false])->save();
            // Un membre qui n'est que livreur perd l'accès ; un gérant qui livrait garde le sien
            if ($courier->user && $this->roles->roleOf($courier->user, $courier->store) === Role::Courier) {
                $courier->store->members()->where('user_id', $courier->user_id)->update(['status' => UserStatus::Suspended]);
            }
        });
    }

    /**
     * @param  list<int>  $zoneIds
     */
    private function syncZones(Courier $courier, array $zoneIds): void
    {
        $valid = DeliveryZone::forStore($courier->store_id)->whereIn('id', $zoneIds)->pluck('id')->all();
        $courier->zones()->sync($valid);
    }

    /**
     * Fiche livreur de la personne connectée dans cette boutique (application /livreur).
     */
    public static function of(User $user, Store $store): ?Courier
    {
        return Courier::forStore($store->id)->where('user_id', $user->id)->where('status', 'active')->first();
    }
}
