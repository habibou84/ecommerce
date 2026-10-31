<?php

namespace Tests\Feature\Couriers;

use App\Models\Courier;
use App\Models\CourierAccessLink;
use App\Models\Order;
use App\Models\Place;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\CourierDayPlan;
use App\Services\Catalog\CatalogWriter;
use App\Services\Couriers\CourierMorning;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Livreur créé pendant qu'on lui confie une course, accès envoyé sur WhatsApp : un lien qui l'ouvre dans son
 * application sans mot de passe (un seul usage), coupure de l'accès, résumé du matin.
 */
class CourierAccessTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        Subscription::create(['store_id' => $this->store->id, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function order(?string $date = null): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809',
            'delivery_method' => 'delivery', 'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            'shipping_address' => 'Cité Arcades', 'confirmed' => true, 'scheduled_date' => $date ?? today()->toDateString(),
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
    }

    /** Nom et numéro seulement, puis la course lui est confiée tout de suite (il n'est pas encore connecté) */
    private function newCourierWithCourse(): Courier
    {
        $id = $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Moussa Koné', 'phone' => '0102030405'])->assertCreated()
            ->assertJsonPath('data.status', 'invited')->json('data.id');
        $this->storeApi($this->store, 'POST', "orders/{$this->order()->id}/shipments", ['courier_id' => $id])->assertCreated();

        return Courier::findOrFail($id);
    }

    private function linkFor(Courier $courier): string
    {
        $data = $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/access-link")->assertOk()->json('data');
        $this->assertStringStartsWith('https://wa.me/2250102030405?text=', $data['whatsapp_url']);
        $this->assertStringContainsString('Bonjour Moussa, Awa Mode vous confie 1 course aujourd\'hui (Cocody), 16 500 F à encaisser.', $data['message']);
        $this->assertStringContainsString($data['link'], $data['message']);

        return $data['link'];
    }

    public function test_a_new_courier_opens_the_link_and_sees_his_course_without_password(): void
    {
        $courier = $this->newCourierWithCourse();
        $link = $this->linkFor($courier);
        $this->assertStringStartsWith($this->storeUrl($this->store, '/livreur/acces/'), $link);

        // Le livreur touche le lien : compte créé sans mot de passe, connecté, dans son application
        $this->logout();
        $this->get($link)->assertRedirect('/livreur');
        $courier->refresh();
        $user = User::findOrFail($courier->user_id);
        $this->assertSame(['active', null], [$courier->status, $user->password]);
        $this->assertAuthenticatedAs($user);
        $this->assertCount(1, $this->storeApi($this->store, 'GET', 'courier/missions')->assertOk()->json('data'));

        // Un seul usage : le même lien, ailleurs, ne connecte plus
        $this->logout();
        $this->get($link)->assertRedirect('/livreur/connexion?lien=utilise');
        $this->assertGuest();
    }

    public function test_the_link_never_opens_an_account_that_manages_a_store(): void
    {
        // Le numéro du livreur est celui du propriétaire d'une autre boutique
        $other = $this->createStore(['slug' => 'koffi', 'name' => 'Koffi Shop']);
        $other->owner->forceFill(['phone' => '+2250102030405'])->save();
        $courier = $this->newCourierWithCourse();
        $link = $this->linkFor($courier);

        $this->logout();
        $this->get($link)->assertRedirect('/livreur/connexion?lien=compte');
        $this->assertGuest();
        $this->assertSame('invited', $courier->fresh()->status);
    }

    public function test_a_new_link_replaces_the_previous_one_and_access_can_be_cut(): void
    {
        $courier = $this->newCourierWithCourse();
        $first = $this->linkFor($courier);
        $second = $this->linkFor($courier);

        $this->logout();
        $this->get($first)->assertRedirect('/livreur/connexion?lien=invalide');
        $this->get($second)->assertRedirect('/livreur');
        $userId = $courier->fresh()->user_id;

        // Téléphone perdu : l'accès est coupé (nouveau lien nécessaire), la fiche et ses courses restent
        $this->logout();
        $this->actingAs($this->store->owner);
        $third = $this->linkFor($courier->fresh());
        $remember = User::findOrFail($userId)->remember_token;
        $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/cut-access")->assertOk();
        $this->assertNotSame($remember, User::findOrFail($userId)->remember_token);
        $this->assertSame(0, CourierAccessLink::forStore($this->store->id)->pending()->count());
        $this->logout();
        $this->get($third)->assertRedirect('/livreur/connexion?lien=invalide');
        $this->assertSame('active', $courier->fresh()->status);
    }

    public function test_courses_of_another_day_wait_and_the_morning_summary_counts_today(): void
    {
        $courier = $this->newCourierWithCourse();
        $this->storeApi($this->store, 'POST', "orders/{$this->order(today()->addDay()->toDateString())->id}/shipments", ['courier_id' => $courier->id])->assertCreated();
        $this->logout();
        $this->get($this->linkForQuietly($courier));
        $missions = $this->storeApi($this->store, 'GET', 'courier/missions')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([today()->toDateString(), today()->addDay()->toDateString()], array_column($missions, 'scheduled_date'));

        Notification::fake();
        $this->assertSame(1, app(CourierMorning::class)->send());
        Notification::assertSentTo(User::findOrFail($courier->fresh()->user_id), CourierDayPlan::class,
            fn (CourierDayPlan $n, array $channels, $notifiable) => $n->toArray($notifiable)['title'] === "Aujourd'hui : 1 course · Awa Mode"
                && $n->toArray($notifiable)['body'] === '16 500 F à encaisser · Cocody');
    }

    private function linkForQuietly(Courier $courier): string
    {
        $this->actingAs($this->store->owner);
        $link = $this->storeApi($this->store, 'POST', "couriers/{$courier->id}/access-link")->json('data.link');
        $this->logout();

        return $link;
    }
}
