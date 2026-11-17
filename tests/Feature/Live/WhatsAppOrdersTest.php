<?php

namespace Tests\Feature\Live;

use App\Models\LiveItem;
use App\Models\LiveSession;
use App\Models\LiveSessionBill;
use App\Models\Place;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Services\Billing\Credits;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveSales;
use App\Services\Platform\Features;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Commandes WhatsApp sans live : mêmes codes qu'un live, ouvertes 24 h à 7 jours (ou jusqu'à « Fermer »), chaque
 * jour compté comme un live, en pause quand le crédit est vide, fermées par un live.
 */
class WhatsAppOrdersTest extends TestCase
{
    use LiveProductsHavePhotos;

    private Store $store;

    private int $variant;

    private int $phone = 3000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $product = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 500]]]);
        $this->variant = $product->variants()->value('id');
        $this->actingAs($this->store->owner);
    }

    private function open(?int $hours = 168): int
    {
        $id = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Commandes WhatsApp', 'channel' => 'whatsapp', 'items' => [['variant_id' => $this->variant, 'quantity' => 300]]])
            ->assertCreated()->assertJsonPath('data.channel', 'whatsapp')->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$id}/start", $hours ? ['open_hours' => $hours] : [])->assertOk();

        return $id;
    }

    private function orders(int $session, int $count): void
    {
        $item = LiveItem::where('live_session_id', $session)->firstOrFail();
        $place = Place::whereNull('store_id')->where('name', 'Yopougon')->firstOrFail();
        for ($i = 0; $i < $count; $i++) {
            app(LiveSales::class)->reserve($item, ['name' => 'Cliente', 'phone' => '+22507'.str_pad((string) ++$this->phone, 8, '0', STR_PAD_LEFT), 'place_id' => $place->id], 'manual');
        }
    }

    public function test_open_for_seven_days_counted_each_day_then_closed_on_time(): void
    {
        $credits = app(Credits::class);
        $credits->update(['paid' => true, 'welcome_orders' => 0, 'welcome_ai' => 0]);
        $this->store->forceFill(['order_credits' => 100])->save();
        $this->travelTo(now('Africa/Abidjan')->setTime(10, 0));
        $id = $this->open(168);
        $session = LiveSession::find($id);
        $this->assertTrue($session->takesOrders());
        $this->assertSame('live', $session->status);
        $this->storeApi($this->store, 'GET', "lives/{$id}")->assertJsonPath('data.channel', 'whatsapp')->assertJsonPath('data.paused', false)
            ->assertJsonPath('data.closes_at', $session->orders_until->toIso8601String());

        // Jour 1 : 14 commandes ; pas fermé au bout de 12 h comme un live ; retirées du solde le soir
        $this->orders($id, 14);
        $this->storeApi($this->store, 'GET', "lives/{$id}")->assertJsonPath('data.billing.orders', 14)->assertJsonPath('data.billing.per_day', true)
            ->assertJsonPath('data.billing.after', 86);
        $this->travel(15)->hours();
        $credits->closeStale();
        $this->assertSame('live', LiveSession::find($id)->status);
        $this->assertSame([[14, 14]], LiveSessionBill::where('live_session_id', $id)->get()->map(fn ($b) => [$b->orders, $b->amount])->all());
        $this->assertSame(86, $this->store->fresh()->order_credits);

        // Jour 2 : 6 commandes ; comptées une seule fois
        $this->orders($id, 6);
        $this->travel(1)->days();
        $credits->closeStale();
        $credits->closeStale();
        $this->assertSame([[14, 14], [6, 6]], LiveSessionBill::where('live_session_id', $id)->orderBy('day')->get()->map(fn ($b) => [$b->orders, $b->amount])->all());

        // Au bout de 7 jours : fermées et totalisées
        $this->travel(6)->days();
        $credits->closeStale();
        $session = LiveSession::find($id);
        $this->assertSame('ended', $session->status);
        $this->assertSame([20, 20], [$session->billed_orders, $session->billed_amount]);
        $this->assertFalse($session->takesOrders());
        $this->assertSame(80, $this->store->fresh()->order_credits);
    }

    public function test_paused_when_the_balance_runs_out_and_a_live_closes_whatsapp_orders(): void
    {
        $credits = app(Credits::class);
        $credits->update(['paid' => true, 'welcome_orders' => 0, 'welcome_ai' => 0]);
        $this->store->forceFill(['order_credits' => 12])->save();
        $this->travelTo(now('Africa/Abidjan')->setTime(9, 0));
        $id = $this->open(null);
        $this->assertNull(LiveSession::find($id)->orders_until);
        $this->orders($id, 12);
        $this->travel(1)->days();
        $credits->closeStale();
        // Solde à 0 : plus de nouveaux codes, les commandes enregistrées restent
        $this->assertSame(0, $this->store->fresh()->order_credits);
        $session = LiveSession::find($id);
        $this->assertTrue($credits->paused($session));
        $this->assertFalse($session->takesOrders());
        $this->assertNull(LiveSales::ordering($this->store->id));
        $this->storeApi($this->store, 'GET', "lives/{$id}")->assertJsonPath('data.paused', true);
        // Recharge : les commandes reprennent
        $this->store->forceFill(['order_credits' => 100])->save();
        $this->assertTrue(LiveSession::find($id)->takesOrders());

        // Un live ne s'ouvre pas pendant… si : il ferme les commandes WhatsApp (un seul code A1 à la fois)
        $live = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'items' => [['variant_id' => $this->variant, 'quantity' => 10]]])->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$live}/start")->assertOk();
        $this->assertSame('ended', LiveSession::find($id)->status);
        $this->assertNotNull(LiveSession::find($id)->billed_at);
        // … et les commandes WhatsApp n'ouvrent pas pendant un live
        $other = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Commandes WhatsApp', 'channel' => 'whatsapp', 'items' => [['variant_id' => $this->variant, 'quantity' => 10]]])->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$other}/start", ['open_hours' => 24])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Un live est en cours : ouvre les commandes WhatsApp après le live.']);
    }

    public function test_free_mode_and_close_button(): void
    {
        $id = $this->open(24);
        $this->orders($id, 3);
        $this->storeApi($this->store, 'POST', "lives/{$id}/end")->assertOk()->assertJsonPath('data.status', 'ended');
        $session = LiveSession::find($id);
        $this->assertSame([3, 0, 'free_mode'], [$session->billed_orders, $session->billed_amount, $session->billing]);
        // Rouvrir avec ces articles : une nouvelle session WhatsApp
        $copy = $this->storeApi($this->store, 'POST', "lives/{$id}/duplicate")->assertCreated()->json('data.id');
        $this->assertSame('whatsapp', LiveSession::find($copy)->channel);
    }

    public function test_codes_without_live_are_a_console_feature_hidden_by_default(): void
    {
        $features = app(Features::class);
        PlatformSetting::where('key', 'feature.whatsapp_codes')->delete();
        $this->assertSame('hidden', (new Features)->state('whatsapp_codes'));

        // Éteinte : ni ouverture ni lancement, et l'assistant des ventes ne montre pas la carte
        $draft = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'items' => [['variant_id' => $this->variant, 'quantity' => 3]]])->assertCreated()->json('data.id');
        LiveSession::whereKey($draft)->update(['channel' => LiveSession::CHANNEL_WHATSAPP]);
        $features->set('whatsapp_codes', 'hidden');
        $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Commandes WhatsApp', 'channel' => 'whatsapp', 'items' => [['variant_id' => $this->variant, 'quantity' => 3]]])
            ->assertStatus(422)->assertJsonValidationErrors('channel');
        $this->storeApi($this->store, 'POST', "lives/{$draft}/start", ['open_hours' => 168])->assertStatus(422);
        $this->assertNull($this->storeApi($this->store, 'GET', 'whatsapp/sales')->json('data.codes'));

        // Allumée dans la console : de nouveau possible
        $features->set('whatsapp_codes', 'on');
        $this->storeApi($this->store, 'GET', 'whatsapp/sales')->assertJsonPath('data.codes.id', $draft);
        $this->storeApi($this->store, 'POST', "lives/{$draft}/start", ['open_hours' => 168])->assertOk();
    }
}
