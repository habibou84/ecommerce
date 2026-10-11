<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StoreStatus;
use App\Enums\UserStatus;
use App\Models\Collection;
use App\Models\Courier;
use App\Models\CourierPayRule;
use App\Models\DeliveryZone;
use App\Models\Page;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\StoreRoles;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Storefront\ThemeSettings;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration (local uniquement) : boutique « demo » → http://demo.localhost:8000/gestion
 * Propriétaire : 07 00 00 00 01 / password ; gérant : 07 00 00 00 02 / password.
 * Avec une zone de livraison, quelques produits (dont une robe à 6 variantes) et deux commandes.
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

        app(Places::class)->provisionDefaultZone($store);

        // Livreur de la boutique : application /livreur
        $courierUser = User::updateOrCreate(['phone' => '+2250700000003'], [
            'name' => 'Moussa Traoré', 'password' => 'password', 'phone_verified_at' => now(), 'status' => UserStatus::Active,
        ]);
        $store->members()->updateOrCreate(['user_id' => $courierUser->id], ['status' => UserStatus::Active, 'joined_at' => now()]);
        $roles->assign($courierUser, $store, Role::Courier);
        $rule = CourierPayRule::forStore($store->id)->firstOrCreate(['store_id' => $store->id, 'name' => 'Par course'], [
            'type' => 'per_delivery', 'amount' => 1000, 'failed_attempt_amount' => 300,
        ]);
        $courier = Courier::forStore($store->id)->updateOrCreate(['store_id' => $store->id, 'phone' => $courierUser->phone], [
            'user_id' => $courierUser->id, 'name' => $courierUser->name, 'vehicle_type' => 'moto', 'pay_rule_id' => $rule->id,
            'cash_limit' => 50000, 'status' => 'active',
        ]);
        $courier->zones()->syncWithoutDetaching(DeliveryZone::forStore($store->id)->pluck('id'));

        if (Product::forStore($store->id)->exists()) {
            return;
        }

        $writer = app(CatalogWriter::class);
        $robe = $writer->save($store, null, [
            'title' => 'Robe wax évasée', 'description' => 'Coton wax, coupe évasée, longueur genou.', 'tags' => ['wax', 'nouveauté'],
            'options' => [['name' => 'Taille', 'values' => ['S', 'M', 'L']], ['name' => 'Couleur', 'values' => ['Rouge', 'Bleu']]],
            'variants' => collect(['S', 'M', 'L'])->crossJoin(['Rouge', 'Bleu'])
                ->map(fn ($c) => ['options' => $c, 'price' => 15000, 'cost_price' => 6500, 'on_hand' => 4])->all(),
        ]);
        $sac = $writer->save($store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000, 'cost_price' => 3500, 'sku' => 'SAC-PAGNE', 'on_hand' => 12]]]);
        $writer->save($store, null, ['title' => 'Collier perles', 'variants' => [['price' => 3500, 'cost_price' => 1200, 'sku' => 'COL-PERLES', 'on_hand' => 2]]]);

        $desk = app(OrderDesk::class);
        $place = fn (string $name) => Place::whereNull('store_id')->where('name', $name)->value('id');
        $desk->create($store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => $place('Angré'), 'shipping_address' => 'Cité Arcades', 'shipping_landmark' => 'Face pharmacie Arcades',
            'items' => [['variant_id' => $robe->variants[2]->id, 'quantity' => 1], ['variant_id' => $sac->variants[0]->id, 'quantity' => 1]],
        ], $owner);
        $confirmed = $desk->create($store, [
            'source' => 'facebook', 'customer_name' => 'Mariam Traoré', 'customer_phone' => '+2250102030405', 'delivery_method' => 'delivery',
            'shipping_place_id' => $place('Siporex'), 'confirmed' => true,
            'items' => [['variant_id' => $sac->variants[0]->id, 'quantity' => 2]],
        ], $owner);
        $desk->recordPayment($confirmed, ['amount' => 5000, 'method' => 'wave', 'reference' => 'WAVE-DEMO'], $owner);

        // Boutique en ligne ouverte, avec une collection et une page
        $collection = Collection::create(['store_id' => $store->id, 'title' => 'Accessoires', 'slug' => 'accessoires', 'is_visible_online' => true]);
        $collection->products()->attach([$sac->id => ['position' => 1]]);
        Page::create(['store_id' => $store->id, 'title' => 'Livraison et retours', 'slug' => 'livraison-et-retours',
            'body' => "## Livraison\n\nSous 24 h à Abidjan. Paiement à la livraison, en espèces ou Mobile Money.\n\n## Retours\n\nVous pouvez refuser un article non conforme à la livraison."]);
        app(ThemeSettings::class)->update($store, ['announcement' => 'Livraison sous 24 h à Abidjan · Paiement à la livraison', 'hero_title' => 'La mode wax, livrée chez vous']);
        $store->forceFill(['storefront_enabled' => true])->save();
    }
}
