<?php

namespace App\Services\Couriers;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierAccessLink;
use App\Models\Scopes\StoreScope;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\User;
use App\Services\Accounts\StoreRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;

/**
 * Accès du livreur par un lien envoyé sur WhatsApp, sans mot de passe : un seul usage, 7 jours au plus. En
 * l'ouvrant, le livreur arrive dans son application (/livreur) et y reste connecté sur son téléphone.
 *
 * Par prudence, le lien ne connecte qu'un compte qui n'a QUE des fiches de livreur : un compte qui gère aussi une
 * boutique (propriétaire, gérant…) ou de la plateforme doit se connecter avec son mot de passe.
 */
class CourierAccess
{
    public function __construct(private readonly StoreRoles $roles) {}

    /**
     * Nouveau lien d'accès (les liens précédents non utilisés cessent de fonctionner).
     */
    public function issue(Courier $courier, ?User $by): string
    {
        if ($courier->status === 'inactive') {
            throw new BusinessRuleException('Ce livreur est désactivé : réactivez-le avant de lui envoyer un accès.');
        }
        $token = Str::random(48);
        DB::transaction(function () use ($courier, $by, $token) {
            CourierAccessLink::forStore($courier->store_id)->where('courier_id', $courier->id)->pending()->update(['revoked_at' => now()]);
            CourierAccessLink::create([
                'store_id' => $courier->store_id,
                'courier_id' => $courier->id,
                'token_hash' => CourierAccessLink::hashToken($token),
                'created_by' => $by?->id,
                'expires_at' => now()->addDays(CourierAccessLink::TTL_DAYS),
            ]);
        });

        return $this->homeFor($courier)->url('/livreur/acces/'.$token);
    }

    /**
     * Adresse de son espace livreur : sa boutique d'accueil s'il livre déjà ailleurs (tout y est réuni), sinon
     * la boutique qui lui envoie le lien.
     */
    public function homeFor(Courier $courier): Store
    {
        $userId = $courier->user_id ?? User::where('phone', $courier->phone)->value('id');

        return ($userId ? SharedCouriers::homeOf($userId) : null) ?? $courier->store;
    }

    /**
     * Message WhatsApp prérempli : ses courses du jour et le lien.
     *
     * @return array{link: string, message: string, whatsapp_url: string}
     */
    public function message(Courier $courier, ?User $by): array
    {
        $link = $this->issue($courier, $by);
        $missions = Shipment::forStore($courier->store_id)->where('courier_id', $courier->id)
            ->whereIn('status', SharedCouriers::ACTIVE_MISSIONS)->with('order.place.parent')->get();
        // Courses du jour : prévues aujourd'hui ou avant (en retard), ou sans date
        $today = CourierMorning::dueToday($missions);
        $first = trim(explode(' ', trim($courier->name))[0] ?? '');
        $store = $courier->store->name;

        if ($today->isEmpty()) {
            $body = "Bonjour {$first}, voici votre accès à l'application livreur de {$store}.";
        } else {
            $communes = $today->map(fn (Shipment $s) => $s->order?->place
                ? ($s->order->place->type === 'neighborhood' ? $s->order->place->parent?->name : $s->order->place->name) : null)
                ->filter()->unique()->take(4)->implode(', ');
            $cash = (int) $today->sum('cod_amount');
            $count = $today->count();
            $body = "Bonjour {$first}, {$store} vous confie {$count} course".($count > 1 ? 's' : '').' aujourd\'hui'
                .($communes !== '' ? " ({$communes})" : '')
                .($cash > 0 ? ', '.Money::format($cash).' à encaisser' : '').'.';
        }
        $message = $body."\n\nVoir vos courses : {$link}\n\nCe lien est personnel : ne le transférez pas. Il s'ouvre une seule fois, puis vous restez connecté sur votre téléphone.";

        return [
            'link' => $link,
            'message' => $message,
            'whatsapp_url' => 'https://wa.me/'.ltrim($courier->phone, '+').'?text='.rawurlencode($message),
        ];
    }

    /**
     * Ouverture du lien : le compte du livreur (créé au besoin, sans mot de passe), ou la raison du refus
     * (invalide, utilise, expire, retire, compte) que la page de connexion explique.
     *
     * @return array{0: ?User, 1: ?string}
     */
    public function consume(string $token, Store $current): array
    {
        if ($token === '' || strlen($token) > 100) {
            return [null, 'invalide'];
        }

        return DB::transaction(function () use ($token, $current) {
            // Le lien peut venir d'une autre boutique que celle de l'adresse (son espace d'accueil réunit tout)
            $link = CourierAccessLink::withoutGlobalScope(StoreScope::class)->where('token_hash', CourierAccessLink::hashToken($token))->lockForUpdate()->first();
            if ($link === null || $link->revoked_at !== null) {
                return [null, 'invalide'];
            }
            $store = Store::find($link->store_id);
            if ($store === null) {
                return [null, 'invalide'];
            }
            if ($link->used_at !== null) {
                return [null, 'utilise'];
            }
            if ($link->expires_at->isPast()) {
                return [null, 'expire'];
            }
            $courier = Courier::forStore($store->id)->lockForUpdate()->find($link->courier_id);
            if ($courier === null || $courier->status === 'inactive') {
                return [null, 'retire'];
            }

            $user = $courier->user_id ? User::find($courier->user_id) : User::where('phone', $courier->phone)->lockForUpdate()->first();
            if ($user !== null && (! $user->isActive() || ! $this->onlyCourier($user, $store))) {
                return [null, 'compte'];
            }
            // Ouvert à l'adresse d'une autre boutique : seulement son espace d'accueil, où il est déjà livreur
            if ($current->id !== $store->id && ($user === null || CourierRoster::of($user, $current) === null)) {
                return [null, 'invalide'];
            }
            if ($user === null) {
                $user = new User;
                $user->forceFill(['name' => $courier->name, 'phone' => $courier->phone, 'password' => null, 'status' => UserStatus::Active]);
            }
            $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now(), 'last_login_at' => now()])->save();

            // Livreur de la boutique : membre avec le rôle livreur, fiche reliée au compte
            $store->members()->updateOrCreate(['user_id' => $user->id], ['status' => UserStatus::Active, 'invited_by' => $link->created_by, 'joined_at' => now()]);
            $this->roles->assign($user, $store, Role::Courier);
            $courier->forceFill(['user_id' => $user->id, 'status' => 'active'])->save();
            // L'invitation classique (avec mot de passe) n'a plus lieu d'être
            StoreInvitation::forStore($store->id)->where('phone', $courier->phone)->pending()->update(['revoked_at' => now()]);
            $link->forceFill(['used_at' => now()])->save();

            return [$user, null];
        });
    }

    /**
     * Couper l'accès (téléphone perdu…) : liens annulés et téléphones déconnectés. La fiche et ses courses restent.
     */
    public function cut(Courier $courier): void
    {
        CourierAccessLink::forStore($courier->store_id)->where('courier_id', $courier->id)->pending()->update(['revoked_at' => now()]);
        if ($courier->user_id === null) {
            return;
        }
        $user = User::find($courier->user_id);
        if ($user === null || ! $this->onlyCourier($user, $courier->store)) {
            return; // compte qui gère aussi une boutique : il garde sa session (il se connecte par mot de passe)
        }
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /**
     * Le compte n'a que des fiches de livreur : ni boutique à lui, ni autre rôle, ni accès à la console.
     */
    private function onlyCourier(User $user, Store $store): bool
    {
        if ($user->is_super_admin || Store::where('owner_id', $user->id)->exists()) {
            return false;
        }
        foreach ($user->memberships()->with('store')->get() as $membership) {
            if ($membership->store && $this->roles->roleOf($user, $membership->store) !== Role::Courier) {
                return false;
            }
        }

        return true;
    }
}
