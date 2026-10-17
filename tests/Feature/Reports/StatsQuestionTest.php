<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\AiUsage;
use App\Models\Place;
use App\Models\Store;
use App\Services\Ai\AiClient;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class StatsQuestionTest extends TestCase
{
    private Store $store;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    public function test_a_question_is_answered_from_the_real_figures(): void
    {
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'low_stock_threshold' => 5, 'variants' => [['price' => 15000, 'on_hand' => 6]]]);
        $desk = app(OrderDesk::class);
        foreach ([1, 2] as $i) {
            $order = $desk->create($this->store, [
                'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '+225050607080'.$i, 'delivery_method' => 'delivery',
                'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
                'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]],
            ], $this->store->owner);
            $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), OrderStatus::Completed, $this->store->owner);
        }

        $today = now($this->store->timezone)->toDateString();
        $this->ai->agentWill([['rapport_ventes', ['du' => $today, 'au' => $today]], ['etat_stock', ['limite' => 10]]], 'Vous avez livré 2 robes wax aujourd\'hui, pour 30 000 F.');

        $this->storeApi($this->store, 'POST', 'stats/ask', ['question' => 'Combien de robes livrées aujourd\'hui ?'])->assertOk()
            ->assertJsonPath('data.answer', 'Vous avez livré 2 robes wax aujourd\'hui, pour 30 000 F.');

        // Outils : vrais chiffres de la boutique
        [$report, $stock] = $this->ai->toolResults;
        $this->assertSame([2, 33000], [$report['overview']['delivered'], $report['overview']['revenue']]); // livraison comprise
        $this->assertSame('Robe wax', $report['by_product'][0]['title']);
        $this->assertSame([['article' => 'Robe wax', 'disponible' => 4, 'etat' => 'stock bas']], $stock['articles']);
        $this->assertStringContainsString("({$today})", $this->ai->lastSystem);
        $this->assertSame('stats_question', AiUsage::forStore($this->store->id)->sole()->feature);
    }

    public function test_only_with_advanced_stats_and_finance_rights(): void
    {
        $free = $this->createStore(['slug' => 'petit'], 'free');
        $this->actingAs($free->owner);
        $this->storeApi($free, 'POST', 'stats/ask', ['question' => 'Combien de ventes ?'])->assertForbidden()->assertJsonPath('upgrade', true);
        $this->assertSame([], $this->ai->calls);
    }
}
