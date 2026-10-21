<?php

namespace App\Services\Accounts;

use App\Enums\StoreStatus;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\DeviceToken;
use App\Models\PushSubscription;
use App\Models\ScheduledReport;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Suppression de son compte par la personne elle-même (exigée par Google Play) : nom, téléphone et email effacés,
 * accès retirés de toutes les boutiques, téléphones désinscrits des notifications. Les commandes et mouvements
 * qu'elle a enregistrés restent dans les boutiques (comptabilité), sans lien vers elle. Le numéro redevient libre.
 */
class AccountDeletion
{
    public function delete(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw new BusinessRuleException('Mot de passe incorrect.', 'password');
        }
        if ($user->is_super_admin) {
            throw new BusinessRuleException('Un compte d\'administration de la plateforme ne se supprime pas ici.');
        }
        $owned = Store::where('owner_id', $user->id)->where('status', '!=', StoreStatus::Closed->value)->pluck('name');
        if ($owned->isNotEmpty()) {
            throw new BusinessRuleException('Vous êtes propriétaire de '.$owned->implode(', ').' : fermez d\'abord la boutique (Réglages › Données et fermeture), puis supprimez votre compte.');
        }

        DB::transaction(function () use ($user) {
            StoreUser::where('user_id', $user->id)->delete();
            DB::table(config('permission.table_names.model_has_roles'))->where('model_type', $user->getMorphClass())->where('model_id', $user->id)->delete();
            Courier::withoutGlobalScopes()->where('user_id', $user->id)->update(['user_id' => null, 'status' => 'inactive', 'is_available' => false]);
            ScheduledReport::withoutGlobalScopes()->where('user_id', $user->id)->delete();
            DeviceToken::where('user_id', $user->id)->delete();
            PushSubscription::where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->forceFill([
                'name' => 'Compte supprimé',
                'phone' => 'supprime-'.$user->id,
                'email' => null,
                'password' => null,
                'remember_token' => null,
                'status' => UserStatus::Suspended,
            ])->save();
            $user->delete();
        });
    }
}
