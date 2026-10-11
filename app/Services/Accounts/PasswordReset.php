<?php

namespace App\Services\Accounts;

use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\PhoneVerification;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\PhoneNumber;

/**
 * Mot de passe oublié : code SMS puis nouveau mot de passe. Pour un numéro sans compte, la demande suit
 * le même parcours (sans SMS) afin de ne pas révéler quels numéros ont un compte.
 */
class PasswordReset
{
    public function __construct(private readonly PhoneVerifier $verifier) {}

    /**
     * @return array{0: PhoneVerification, 1: string, 2: ?string}
     */
    public function start(string $phone, ?Store $store, ?string $ip = null): array
    {
        $user = User::where('phone', PhoneNumber::normalize($phone))->first();

        return $this->verifier->start(VerificationPurpose::PasswordReset, $phone, $store, $user, ip: $ip);
    }

    public function complete(PhoneVerification $verification, string $password): User
    {
        $user = $verification->user ?? throw new BusinessRuleException('Code incorrect.', 'code');

        return DB::transaction(function () use ($verification, $user, $password) {
            $user->forceFill(['password' => $password, 'phone_verified_at' => $user->phone_verified_at ?? now()])->save();
            // Les autres sessions de cette personne restent ouvertes : rien ne les relie au mot de passe
            $this->verifier->complete($verification);

            return $user;
        });
    }
}
