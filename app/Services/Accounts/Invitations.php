<?php

namespace App\Services\Accounts;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\PhoneVerification;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;

/**
 * Invitation d'un membre de l'équipe. Le lien est à partager (WhatsApp, SMS) ; la personne invitée
 * l'ouvre sur {slug}.jibiat-marchand.com/invitation/{jeton} :
 *  - elle a déjà un compte : son mot de passe suffit ;
 *  - sinon : elle choisit son nom et son mot de passe, puis vérifie son numéro par SMS.
 */
class Invitations
{
    public function __construct(
        private readonly PhoneVerifier $verifier,
        private readonly StoreRoles $roles,
    ) {}

    /**
     * @return array{0: StoreInvitation, 1: string} invitation et lien à partager
     */
    public function invite(Store $store, User $inviter, string $phone, Role $role, ?string $name = null): array
    {
        // Les livreurs sont invités depuis l'écran Livreurs (CourierRoster), pas depuis l'équipe
        if (! in_array($role, [...Role::invitable(), Role::Courier], true)) {
            throw new BusinessRuleException('Ce rôle ne peut pas être donné par invitation.', 'role');
        }

        $phone = PhoneNumber::normalize($phone) ?? throw new BusinessRuleException('Numéro de téléphone invalide.', 'phone');

        $member = User::where('phone', $phone)->first();
        if ($member !== null && $store->members()->where('user_id', $member->id)->exists()) {
            throw new BusinessRuleException('Cette personne fait déjà partie de l\'équipe.', 'phone');
        }

        $token = Str::random(40);

        $invitation = DB::transaction(function () use ($store, $inviter, $phone, $role, $name, $token) {
            // Une nouvelle invitation remplace la précédente pour ce numéro
            StoreInvitation::forStore($store->id)->where('phone', $phone)->pending()->update(['revoked_at' => now()]);

            return StoreInvitation::create([
                'store_id' => $store->id,
                'name' => $name,
                'phone' => $phone,
                'role' => $role,
                'token_hash' => StoreInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(StoreInvitation::TTL_DAYS),
            ]);
        });

        return [$invitation, $store->url('/invitation/'.$token)];
    }

    public function find(Store $store, string $token): ?StoreInvitation
    {
        return StoreInvitation::forStore($store->id)->where('token_hash', StoreInvitation::hashToken($token))->first();
    }

    /**
     * La personne a déjà un compte : elle accepte avec son mot de passe.
     */
    public function acceptWithPassword(StoreInvitation $invitation, string $password): User
    {
        $this->ensurePending($invitation);

        $user = User::where('phone', $invitation->phone)->first();
        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            throw new BusinessRuleException('Mot de passe incorrect.', 'password');
        }
        if (! $user->isActive()) {
            throw new BusinessRuleException('Ce compte est suspendu.');
        }

        $this->join($invitation, $user);

        return $user;
    }

    /**
     * Nouvelle personne : vérification du numéro avant de créer le compte.
     *
     * @return array{0: PhoneVerification, 1: string, 2: ?string}
     */
    public function startNewAccount(StoreInvitation $invitation, string $name, string $password, ?string $ip = null): array
    {
        $this->ensurePending($invitation);

        if (User::where('phone', $invitation->phone)->exists()) {
            throw new BusinessRuleException('Ce numéro a déjà un compte : saisissez son mot de passe.', 'password');
        }

        return $this->verifier->start(VerificationPurpose::Invitation, $invitation->phone, $invitation->store, payload: [
            'invitation_id' => $invitation->id,
            'name' => $name,
            'password_hash' => Hash::make($password),
        ], ip: $ip);
    }

    /**
     * Numéro vérifié : crée le compte et rejoint la boutique.
     */
    public function completeNewAccount(PhoneVerification $verification): User
    {
        $invitation = StoreInvitation::forStore($verification->store_id)->findOrFail($verification->payload['invitation_id']);
        $this->ensurePending($invitation);

        return DB::transaction(function () use ($verification, $invitation) {
            $user = User::where('phone', $verification->phone)->lockForUpdate()->first();
            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $verification->payload['name'],
                    'phone' => $verification->phone,
                    'password' => $verification->payload['password_hash'],
                    'status' => UserStatus::Active,
                ]);
            }
            $user->forceFill(['phone_verified_at' => now()])->save();

            $this->join($invitation, $user);
            $this->verifier->complete($verification);

            return $user;
        });
    }

    private function join(StoreInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            $invitation->store->members()->updateOrCreate(
                ['user_id' => $user->id],
                ['status' => UserStatus::Active, 'invited_by' => $invitation->invited_by, 'joined_at' => now()],
            );
            $this->roles->assign($user, $invitation->store, $invitation->role);

            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            // Livreur invité : sa fiche est reliée à son compte, il accède à l'application /livreur
            Courier::forStore($invitation->store_id)->where('phone', $invitation->phone)->where('status', 'invited')
                ->update(['user_id' => $user->id, 'status' => 'active']);
        });
    }

    /**
     * Page d'arrivée après l'acceptation : l'application livreur pour un livreur, la gestion sinon.
     */
    public function homeFor(User $user, Store $store): string
    {
        return $this->roles->roleOf($user, $store) === Role::Courier ? '/livreur' : '/gestion';
    }

    private function ensurePending(StoreInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            throw new BusinessRuleException('Cette invitation n\'est plus valable. Demandez-en une nouvelle.');
        }
    }
}
