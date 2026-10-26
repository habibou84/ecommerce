<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Store;
use App\Services\Ai\AiClient;
use App\Services\Catalog\CatalogWriter;
use App\Services\Customers\CustomerBook;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class OrderDraftTest extends TestCase
{
    private Store $store;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['name' => 'Awa Mode']);
        // L'assistant IA n'est compris dans aucune offre : actions rechargées
        $this->store->forceFill(['ai_credits' => 100])->save();
        $this->actingAs($this->store->owner);
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    public function test_a_messy_conversation_becomes_an_order_to_check(): void
    {
        $writer = app(CatalogWriter::class);
        $robe = $writer->save($this->store, null, [
            'title' => 'Robe wax',
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
            'variants' => [['options' => ['S'], 'price' => 15000], ['options' => ['M'], 'price' => 16000]],
        ]);
        $sac = $writer->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000]]]);
        $other = $writer->save($this->createStore(), null, ['title' => 'Montre', 'variants' => [['price' => 50000]]]);
        app(CustomerBook::class)->findOrCreate($this->store, '+2250708091011', 'Awa Koné');

        $this->ai->respond([
            'phone' => '07 08 09 10 11', 'phone2' => null, 'customer_name' => null, 'place' => 'yop siporex',
            'address' => null, 'landmark' => 'face pharmacie Sainte Rita', 'note' => 'Livrer après 18 h', 'amount' => 41000,
            'items' => [
                ['code' => 'V'.$robe->variants[1]->id, 'title' => '2 robe tail M', 'quantity' => 2],
                ['code' => 'P'.$sac->id, 'title' => 'sac pagne', 'quantity' => 1],
                ['code' => 'P'.$robe->id, 'title' => 'robe', 'quantity' => 1],
                ['code' => 'V'.$other->variants[0]->id, 'title' => 'montre', 'quantity' => 1],
                ['code' => null, 'title' => 'chaussures dorées', 'quantity' => 1],
            ],
        ]);

        $text = "cc\nje veu 2 robe tail M é 1 sac pagne\nc pr yop siporex face pharmacie ste rita\n0708091011";
        $r = $this->storeApi($this->store, 'POST', 'orders/draft', ['text' => $text])->assertOk();

        $r->assertJsonPath('data.phone', '+2250708091011')->assertJsonPath('data.place.label', 'Siporex, Yopougon')
            ->assertJsonPath('data.landmark', 'face pharmacie Sainte Rita')->assertJsonPath('data.note', 'Livrer après 18 h')
            ->assertJsonPath('data.amount', 41000)
            // Client déjà connu : son nom
            ->assertJsonPath('data.customer.name', 'Awa Koné')
            ->assertJsonPath('data.unmatched', ['montre', 'chaussures dorées']);
        $items = $r->json('data.items');
        $this->assertSame([
            ['Robe wax', $robe->variants[1]->id, 'M', false, 2, 16000],
            ['Sac en pagne', $sac->variants[0]->id, null, false, 1, 9000],
            ['Robe wax', null, null, true, 1, 15000],
        ], array_map(fn ($i) => [$i['title'], $i['variant_id'], $i['variant_title'], $i['needs_variant'], $i['quantity'], $i['unit_price']], $items));

        // Catalogue de la boutique et conversation envoyés ; jamais les produits d'une autre boutique
        $sent = $this->ai->lastText();
        $this->assertStringContainsString("P{$robe->id} Robe wax : V{$robe->variants[0]->id} S 15 000 F ; V{$robe->variants[1]->id} M 16 000 F", $sent);
        $this->assertStringContainsString("V{$sac->variants[0]->id} Sac en pagne — 9 000 F", $sent);
        $this->assertStringNotContainsString('Montre', $sent);
        $this->assertStringContainsString('je veu 2 robe tail M', $sent);
        $this->assertSame(0, Customer::forStore($this->store->id)->where('phone', '!=', '+2250708091011')->count());
    }

    public function test_a_screenshot_alone_is_enough(): void
    {
        $this->ai->respond(['phone' => null, 'phone2' => null, 'customer_name' => 'Yao', 'place' => null, 'address' => null, 'landmark' => null, 'note' => null, 'amount' => null, 'items' => []]);

        $this->withHeaders(['Accept' => 'application/json', 'Referer' => $this->storeUrl($this->store)])
            ->post($this->storeUrl($this->store, 'api/v1/orders/draft'), ['screenshots' => [UploadedFile::fake()->image('capture.png', 720, 1600)]])
            ->assertOk()->assertJsonPath('data.customer_name', 'Yao');
        $this->assertSame('image', $this->ai->calls[0]['content'][0]['type']);
        $this->assertStringContainsString('voir les captures d\'écran', $this->ai->lastText());

        $this->storeApi($this->store, 'POST', 'orders/draft', [])->assertStatus(422);
    }
}
