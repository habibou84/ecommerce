<?php

namespace Tests\Feature\Live;

use App\Models\LiveItem;
use App\Models\Place;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveSales;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Show du live : réglages et moments lancés depuis le téléphone, vus par les écrans ; la roue ne montre que la
 * commune et le code (le nom reste dans l'application).
 */
class LiveShowTest extends TestCase
{
    use LiveProductsHavePhotos;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
    }

    public function test_settings_moments_quarters_and_wheel(): void
    {
        $product = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 50]]]);
        $live = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'items' => [['variant_id' => $product->variants()->value('id'), 'quantity' => 20]]])->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$live}/start")->assertOk();

        $this->storeApi($this->store, 'GET', "lives/{$live}/display")->assertOk()
            ->assertJsonPath('data.live.show.settings.theme', 'fete')->assertJsonPath('data.live.show.settings.ambiance', 'techno')->assertJsonPath('data.live.show.settings.sound', true)->assertJsonCount(1, 'data.live.items.0.photos')->assertJsonPath('data.live.show.event', null);
        // La roue attend des commandes
        $this->storeApi($this->store, 'POST', "lives/{$live}/show/moment", ['type' => 'wheel'])->assertStatus(422);

        $item = LiveItem::where('live_session_id', $live)->firstOrFail();
        foreach ([['Aminata Koné', 'Cocody'], ['Fatou Diallo', 'Cocody'], ['Mariam Touré', 'Yopougon']] as $i => [$name, $place]) {
            app(LiveSales::class)->reserve($item, ['name' => $name, 'phone' => '+2250799000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'place_id' => Place::whereNull('store_id')->where('name', $place)->value('id')], 'manual');
        }

        $this->storeApi($this->store, 'PUT', "lives/{$live}/show", ['theme' => 'neon', 'music' => true, 'light' => true])->assertOk()->assertJsonPath('data.theme', 'neon');
        $this->storeApi($this->store, 'PUT', "lives/{$live}/show", ['theme' => 'disco'])->assertStatus(422);
        $this->storeApi($this->store, 'PUT', "lives/{$live}/show", ['ambiance' => 'zouglou'])->assertOk()->assertJsonPath('data.ambiance', 'zouglou')->assertJsonPath('data.theme', 'neon');
        $this->storeApi($this->store, 'PUT', "lives/{$live}/show", ['ambiance' => 'disco'])->assertStatus(422);
        $goal = $this->storeApi($this->store, 'POST', "lives/{$live}/show/moment", ['type' => 'goal', 'goal' => 30])->assertOk()->json('data.event');
        $this->assertSame(['goal' => 30], $goal['data']);
        $this->storeApi($this->store, 'GET', "lives/{$live}/display")->assertJsonPath('data.live.show.settings.theme', 'neon')->assertJsonPath('data.live.show.settings.goal', 30)
            ->assertJsonPath('data.live.show.event.type', 'goal')
            ->assertJsonPath('data.live.show.quarters', [['name' => 'Cocody', 'orders' => 2], ['name' => 'Yopougon', 'orders' => 1]]);

        $wheel = $this->storeApi($this->store, 'POST', "lives/{$live}/show/moment", ['type' => 'wheel'])->assertOk()->json('data');
        $this->assertCount(3, $wheel['event']['data']['slots']);
        $this->assertContains($wheel['team']['winner'], ['Aminata Koné', 'Fatou Diallo', 'Mariam Touré']);
        $this->assertSame($wheel['event']['data']['slots'][$wheel['event']['data']['winner']], $wheel['team']['label']);
        // Écran : la commune et le code, jamais le nom
        $screen = $this->storeApi($this->store, 'GET', "lives/{$live}/display")->assertJsonPath('data.live.show.event.type', 'wheel');
        $this->assertStringNotContainsString('Aminata', $screen->getContent());
        $this->assertContains('Cocody · A1', $screen->json('data.live.show.event.data.slots'));
        $this->storeApi($this->store, 'POST', "lives/{$live}/show/moment", ['type' => 'feu'])->assertStatus(422);
    }
}
