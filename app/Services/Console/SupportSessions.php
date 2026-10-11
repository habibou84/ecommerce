<?php

namespace App\Services\Console;

use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\AdminAction;
use App\Models\Store;
use App\Models\SupportSession;
use App\Models\User;
use App\Notifications\SupportAccess;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\StoreRoles;
use App\Services\Accounts\TeamNotifier;

/**
 * Sessions de support : un administrateur de la plateforme entre dans la gestion d'une boutique avec un motif,
 * pour une durée limitée, avec le rôle « support » (ni abonnement, ni équipe, ni comptes de paiement).
 * La boutique est prévenue, voit l'accès en cours et peut y mettre fin ; tout est journalisé.
 */
class SupportSessions
{
    public function __construct(
        private readonly StoreRoles $roles,
        private readonly LoginHandoff $handoff,
        private readonly TeamNotifier $notifier,
    ) {}

    /**
     * @return array{0: SupportSession, 1: string} session et lien de connexion à la gestion
     */
    public function start(Store $store, User $admin, string $reason, int $minutes, ?string $ip = null): array
    {
        if (! $admin->is_super_admin) {
            throw new BusinessRuleException('Réservé aux administrateurs de la plateforme.');
        }
        if ($admin->activeMembershipIn($store) !== null) {
            throw new BusinessRuleException('Vous faites déjà partie de l\'équipe de cette boutique.');
        }

        SupportSession::forStore($store->id)->where('admin_id', $admin->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        $session = SupportSession::create([
            'store_id' => $store->id,
            'admin_id' => $admin->id,
            'reason' => $reason,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(max(5, min($minutes, config('platform.support_session_minutes')))),
            'ip' => $ip,
        ]);
        $this->roles->assign($admin, $store, Role::Support);

        AdminAction::create(['admin_id' => $admin->id, 'store_id' => $store->id, 'action' => 'support_session', 'data' => ['reason' => $reason, 'until' => $session->expires_at->toIso8601String()], 'ip' => $ip]);
        $this->notifier->notify($store, Permission::TeamManage->value, new SupportAccess($session->setRelation('admin', $admin)));

        return [$session, $this->handoff->url($admin, $store)];
    }

    public function end(SupportSession $session, ?User $by): void
    {
        if ($session->ended_at === null) {
            $session->forceFill(['ended_at' => now(), 'ended_by' => $by?->id])->save();
        }
        $admin = $session->admin;
        $store = Store::find($session->store_id);
        if ($admin && $store && $admin->activeMembershipIn($store) === null) {
            $this->roles->revoke($admin, $store);
        }
    }

    /**
     * Sessions arrivées à échéance : le rôle temporaire est retiré.
     */
    public function expireDue(): int
    {
        $due = SupportSession::withoutGlobalScopes()->whereNull('ended_at')->where('expires_at', '<=', now())->with('admin')->get();
        foreach ($due as $session) {
            $session->forceFill(['ended_at' => $session->expires_at])->save();
            $this->end($session, null);
        }

        return $due->count();
    }

    public function ongoingFor(User $user, Store $store): ?SupportSession
    {
        return $user->is_super_admin ? SupportSession::forStore($store->id)->where('admin_id', $user->id)->ongoing()->first() : null;
    }
}
