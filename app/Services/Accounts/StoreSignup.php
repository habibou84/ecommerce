<?php

namespace App\Services\Accounts;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\PhoneVerification;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Rules\StoreSlug;
use App\Services\Billing\AccessKeys;
use App\Services\Places\Places;
use App\Support\TrialOffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Création d'une boutique depuis jibiat-marchand.com.
 *  - Numéro inconnu : un compte est créé après vérification du numéro par SMS.
 *  - Numéro qui a déjà un compte (membre d'une autre boutique) : son mot de passe suffit.
 */
class StoreSignup
{
    public function __construct(
        private readonly PhoneVerifier $verifier,
        private readonly StoreRoles $roles,
    ) {}

    /**
     * @param  array{store_name: string, slug: string, name: string, phone: string, password: string, partner?: ?string, key?: ?string}  $data
     * @return array{store: ?Store, user: ?User, verification: ?PhoneVerification, token: ?string, demo_code: ?string}
     */
    public function start(array $data, ?string $ip = null): array
    {
        $existing = User::where('phone', $data['phone'])->first();

        if ($existing !== null) {
            if (! Hash::check($data['password'], (string) $existing->password)) {
                throw new BusinessRuleException('Ce numéro a déjà un compte Jibiat Marchand : saisissez son mot de passe.', 'password');
            }
            if (! $existing->isActive()) {
                throw new BusinessRuleException('Ce compte est suspendu.', 'phone');
            }

            $store = $this->createStore($existing, $data['store_name'], $data['slug'], partner: $data['partner'] ?? null);
            $this->applyKey($store, $data['key'] ?? null);

            return ['store' => $store, 'user' => $existing, 'verification' => null, 'token' => null, 'demo_code' => null];
        }

        [$verification, $token, $code] = $this->verifier->start(VerificationPurpose::Signup, $data['phone'], payload: [
            'store_name' => $data['store_name'],
            'slug' => $data['slug'],
            'name' => $data['name'],
            'partner' => $data['partner'] ?? null,
            'key' => $data['key'] ?? null,
            'password_hash' => Hash::make($data['password']),
        ], ip: $ip);

        return ['store' => null, 'user' => null, 'verification' => $verification, 'token' => $token, 'demo_code' => $code];
    }

    /**
     * Numéro vérifié : crée le compte et la boutique.
     *
     * @return array{0: User, 1: Store}
     */
    public function complete(PhoneVerification $verification): array
    {
        $payload = $verification->payload;

        $result = DB::transaction(function () use ($verification, $payload) {
            // Compte créé entre-temps avec ce numéro (autre onglet) : on le reprend
            $user = User::where('phone', $verification->phone)->lockForUpdate()->first() ?? new User;
            if (! $user->exists) {
                $user->forceFill([
                    'name' => $payload['name'],
                    'phone' => $verification->phone,
                    'password' => $payload['password_hash'],
                    'status' => UserStatus::Active,
                ]);
            }
            $user->forceFill(['phone_verified_at' => now()])->save();

            $store = $this->createStore($user, $payload['store_name'], $payload['slug'], partner: $payload['partner'] ?? null);
            $this->verifier->complete($verification);

            return [$user, $store];
        });
        $this->applyKey($result[1], $payload['key'] ?? null);

        return $result;
    }

    /**
     * Clé d'accès de la carte scannée à l'inscription. Prise entre-temps : la boutique garde l'essai habituel.
     */
    private function applyKey(Store $store, ?string $code): void
    {
        if (blank($code)) {
            return;
        }
        try {
            app(AccessKeys::class)->redeem($code, $store);
        } catch (BusinessRuleException) {
            // Clé utilisée ou annulée depuis la saisie
        }
    }

    /**
     * Nouvelle boutique du propriétaire, avec l'essai de l'offre (sauf si un abonnement existant la couvre).
     * Invitée par une entreprise de livraison de l'annuaire ($partner) : l'essai dure un mois de plus.
     */
    public function createStore(User $owner, string $name, string $slug, bool $trial = true, ?string $partner = null): Store
    {
        // Adresse prise entre la saisie et la vérification du numéro
        $validator = Validator::make(['slug' => $slug], ['slug' => [new StoreSlug]]);
        if ($validator->fails()) {
            throw new BusinessRuleException($validator->errors()->first('slug').' Choisissez une autre adresse.', 'slug');
        }

        return DB::transaction(function () use ($owner, $name, $slug, $trial, $partner) {
            // Propriétaire et statut ne sont jamais remplis en masse
            $store = (new Store)->forceFill([
                'owner_id' => $owner->id,
                'slug' => $slug,
                'name' => $name,
                'phone' => $owner->phone,
                'whatsapp_phone' => $owner->phone,
                'status' => StoreStatus::Trial,
                'referred_by' => $partner,
            ]);
            $store->save();

            $store->members()->create(['user_id' => $owner->id, 'status' => UserStatus::Active, 'joined_at' => now()]);
            $this->roles->assign($owner, $store, Role::Owner);
            app(Places::class)->provisionDefaultZone($store);

            $plan = Plan::where('code', config('platform.trial_plan'))->first();
            if ($trial && $plan !== null) {
                $days = $partner !== null ? TrialOffer::partnerDays() : TrialOffer::days();
                $store->subscriptions()->create([
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => now()->addDays($days),
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays($days),
                ]);
            }

            return $store;
        });
    }
}
