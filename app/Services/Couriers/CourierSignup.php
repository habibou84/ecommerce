<?php

namespace App\Services\Couriers;

use App\Enums\UserStatus;
use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Accounts\PhoneVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Inscription d'un livreur au lien commun (jibiat-marchand.com/livreur) : numéro vérifié, compte et profil créés,
 * sans boutique. Les boutiques le trouvent ensuite par son numéro ou dans l'annuaire.
 */
class CourierSignup
{
    public function __construct(private readonly PhoneVerifier $verifier, private readonly CourierNetwork $network) {}

    /**
     * @param  array{name: string, phone: string, password: string, vehicle_type: string, place_ids?: list<int>, listed?: bool}  $data
     * @return array{0: PhoneVerification, 1: string, 2: ?string}
     */
    public function start(array $data, ?string $ip = null): array
    {
        if (User::where('phone', $data['phone'])->exists()) {
            throw new BusinessRuleException('Ce numéro a déjà un compte : connectez-vous.', 'phone');
        }
        if (! array_key_exists($data['vehicle_type'], Courier::VEHICLES)) {
            throw new BusinessRuleException('Choisissez votre véhicule.', 'vehicle_type');
        }

        return $this->verifier->start(VerificationPurpose::CourierSignup, $data['phone'], payload: [
            'name' => $data['name'],
            'password_hash' => Hash::make($data['password']),
            'vehicle_type' => $data['vehicle_type'],
            'place_ids' => array_values(array_map('intval', $data['place_ids'] ?? [])),
            'listed' => (bool) ($data['listed'] ?? true),
        ], ip: $ip);
    }

    public function complete(PhoneVerification $verification): User
    {
        return DB::transaction(function () use ($verification) {
            if (User::where('phone', $verification->phone)->lockForUpdate()->exists()) {
                throw new BusinessRuleException('Ce numéro a déjà un compte : connectez-vous.');
            }
            $payload = $verification->payload;
            $user = new User;
            $user->forceFill([
                'name' => $payload['name'],
                'phone' => $verification->phone,
                'password' => $payload['password_hash'],
                'status' => UserStatus::Active,
                'phone_verified_at' => now(),
            ])->save();
            $profile = $this->network->profileFor($user);
            $this->network->updateProfile($profile, ['vehicle_type' => $payload['vehicle_type'], 'listed' => $payload['listed'], 'place_ids' => $payload['place_ids']]);
            $this->verifier->complete($verification);

            return $user;
        });
    }
}
