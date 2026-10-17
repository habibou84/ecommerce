<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Store;
use App\Models\User;
use App\Services\Customers\CustomerBook;
use Tests\TestCase;

class SharedCustomersTest extends TestCase
{
    private Store $mode;

    private Store $kids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mode = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode'], 'business');
        $this->actingAs($this->mode->owner);
        $this->storeApi($this->mode, 'POST', 'my-stores', ['name' => 'Awa Kids', 'path' => 'kids'])->assertCreated();
        $this->kids = Store::where('path', 'kids')->sole();

        $fatou = app(CustomerBook::class)->findOrCreate($this->mode, '+2250102030405', 'Fatou Koné');
        $fatou->forceFill(['orders_count' => 3, 'delivered_count' => 2, 'refused_count' => 1, 'total_spent' => 45000])->save();
        CustomerAddress::create(['store_id' => $this->mode->id, 'customer_id' => $fatou->id, 'address_line' => 'Rue des jardins', 'landmark' => 'Pharmacie', 'is_default' => true]);
    }

    public function test_a_customer_of_another_store_is_recognised(): void
    {
        $this->storeApi($this->kids, 'GET', 'customers/elsewhere?phone=0102030405')->assertOk()
            ->assertJsonPath('data.0.store_name', 'Awa Mode')->assertJsonPath('data.0.name', 'Fatou Koné')
            ->assertJsonPath('data.0.orders_count', 3)->assertJsonPath('data.0.refused_count', 1)
            ->assertJsonPath('data.0.address.address_line', 'Rue des jardins')->assertJsonPath('data.0.is_blacklisted', false);
        $this->storeApi($this->kids, 'GET', 'customers/elsewhere?phone=0700000000')->assertJsonPath('data', []);

        // Devenue cliente ici aussi : sa fiche montre l'autre boutique
        $here = app(CustomerBook::class)->findOrCreate($this->kids, '+2250102030405', 'Fatou');
        $this->storeApi($this->kids, 'GET', "customers/{$here->id}")->assertJsonPath('data.other_stores.0.store_name', 'Awa Mode')
            ->assertJsonPath('data.other_stores.0.customer_id', Customer::forStore($this->mode->id)->value('id'));

        // Liste noire de l'autre boutique visible
        Customer::forStore($this->mode->id)->first()->forceFill(['blacklisted_at' => now(), 'blacklist_reason' => 'Refus répétés'])->save();
        $this->storeApi($this->kids, 'GET', 'customers/elsewhere?phone=+2250102030405')->assertJsonPath('data.0.is_blacklisted', true)
            ->assertJsonPath('data.0.blacklist_reason', 'Refus répétés');
    }

    public function test_only_stores_where_one_sees_the_customers(): void
    {
        $agent = $this->addMember($this->kids, User::factory()->create(), Role::Manager);
        $this->actingAs($agent);
        $this->storeApi($this->kids, 'GET', 'customers/elsewhere?phone=0102030405')->assertOk()->assertJsonPath('data', []);

        $this->addMember($this->mode, $agent, Role::Manager);
        $this->storeApi($this->kids, 'GET', 'customers/elsewhere?phone=0102030405')->assertJsonPath('data.0.name', 'Fatou Koné');
    }
}
