<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\Expeditions;
use App\Services\Places\Places;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acompte : par article (pourcentage ou montant fixe), règle de la boutique (au-dessus d'un montant, toujours),
 * fixé à la main sur une commande ; la commande attend l'acompte avant le livreur ; paiement reçu → confirmée.
 */
class DepositTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private Product $sac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $writer = app(CatalogWriter::class);
        $this->robe = $writer->save($this->store, null, ['title' => 'Robe sur mesure', 'deposit_type' => 'percent', 'deposit_value' => 50, 'variants' => [['price' => 45000, 'on_hand' => 5]]]);
        $this->sac = $writer->save($this->store, null, ['title' => 'Sac cabas', 'variants' => [['price' => 8000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function order(array $items, array $extra = []): TestResponse
    {
        return $this->storeApi($this->store, 'POST', 'orders', $extra + [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            'items' => array_map(fn ($i) => ['variant_id' => $i[0]->variants()->value('id'), 'quantity' => $i[1]], $items),
        ]);
    }

    private function created(array $items): Order
    {
        return Order::findOrFail($this->order($items)->assertCreated()->json('data.id'));
    }

    public function test_deposit_from_the_article_and_from_the_store_rule(): void
    {
        // 50 % de la robe (45 000 F) : 22 500 F ; le sac n'a pas d'acompte
        $order = $this->created([[$this->robe, 1], [$this->sac, 1]]);
        $this->assertSame(22500, $order->deposit_required);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertOk()->assertJsonPath('data.upfront.deposit_due', 22500);
        // Montant fixe par pièce
        $this->sac->forceFill(['deposit_type' => 'fixed', 'deposit_value' => 2000])->save();
        $this->assertSame(6000, $this->created([[$this->sac, 3]])->deposit_required);

        // Règle de la boutique : au-dessus de 20 000 F, 30 % ; le plus grand des deux est demandé
        $this->storeApi($this->store, 'PUT', 'deposit-settings', ['deposit_mode' => 'above', 'deposit_min_total' => 20000, 'deposit_rule_percent' => 30, 'deposit_blocks' => true])
            ->assertOk()->assertJsonPath('data.deposit_mode', 'above');
        $this->sac->forceFill(['deposit_type' => null, 'deposit_value' => null])->save();
        $big = $this->created([[$this->sac, 3]]);
        $this->assertSame((int) (ceil($big->total * 0.3 / 100) * 100), $big->deposit_required);
        $this->assertNull($this->created([[$this->sac, 1]])->deposit_required);

        // Fixé à la main : gardé quand les articles changent ; vide : recalculé
        $this->storeApi($this->store, 'PUT', "orders/{$order->id}", ['deposit_required' => 10000])->assertOk()->assertJsonPath('data.deposit_required', 10000);
        $this->storeApi($this->store, 'PUT', "orders/{$order->id}", ['deposit_required' => null])->assertOk()->assertJsonPath('data.deposit_required', 22500);
    }

    public function test_the_order_waits_for_the_deposit_then_is_confirmed(): void
    {
        $id = $this->order([[$this->robe, 1]])->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        $this->assertSame(22500, Expeditions::blockingDue($order));

        // Juste un rappel : la commande peut partir
        $this->store->forceFill(['deposit_blocks' => false])->save();
        $this->assertSame(0, Expeditions::blockingDue($order->fresh()));
        $this->store->forceFill(['deposit_blocks' => true])->save();

        // Acompte reçu (Wave) : plus rien n'attend, la commande est confirmée
        $this->storeApi($this->store, 'POST', "orders/{$id}/payments", ['amount' => 22500, 'method' => 'wave', 'kind' => 'deposit'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.upfront.deposit_due', 0);
        $this->assertSame(0, Expeditions::blockingDue($order->fresh()));
    }

    public function test_reminder_is_sent_only_once(): void
    {
        $id = $this->order([[$this->robe, 1]])->assertCreated()->json('data.id');
        $this->travel(21)->hours();
        $this->artisan('orders:deposit-reminders')->assertSuccessful();
        $this->assertNotNull(Order::findOrFail($id)->deposit_reminded_at);
        // Pas de conversation WhatsApp : rien n'est envoyé, et pas de deuxième essai
        $this->artisan('orders:deposit-reminders')->expectsOutputToContain('0 rappel')->assertSuccessful();
    }
}
