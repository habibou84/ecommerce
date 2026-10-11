<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Enums\UserStatus;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\StoreRoles;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration (local uniquement) : boutique « demo » → http://demo.localhost:8000/gestion
 * Propriétaire : 07 00 00 00 01 / password ; gérante : 07 00 00 00 02 / password.
 */
class DemoSeeder extends Seeder
{
    public function run(StoreRoles $roles): void
    {
        $owner = User::updateOrCreate(['phone' => '+2250700000001'], [
            'name' => 'Awa Koné', 'password' => 'password', 'phone_verified_at' => now(), 'status' => UserStatus::Active,
        ]);
        $manager = User::updateOrCreate(['phone' => '+2250700000002'], [
            'name' => 'Yao Kouassi', 'password' => 'password', 'phone_verified_at' => now(), 'status' => UserStatus::Active,
        ]);

        $store = Store::updateOrCreate(['slug' => 'demo'], [
            'owner_id' => $owner->id, 'name' => 'Awa Mode', 'tagline' => 'Prêt-à-porter et accessoires à Abidjan',
            'phone' => $owner->phone, 'whatsapp_phone' => $owner->phone, 'status' => StoreStatus::Active,
        ]);

        foreach ([[$owner, Role::Owner], [$manager, Role::Manager]] as [$user, $role]) {
            $store->members()->updateOrCreate(['user_id' => $user->id], ['status' => UserStatus::Active, 'joined_at' => now()]);
            $roles->assign($user, $store, $role);
        }
    }
}
