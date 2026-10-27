<?php

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DeliveryZone;
use App\Models\Place;
use App\Models\Store;
use App\Services\Ai\AiClient;
use App\Services\Catalog\CatalogWriter;
use App\Services\Couriers\SharedCouriers;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class ReplySuggestionTest extends TestCase
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

    public function test_a_reply_is_proposed_with_real_prices_stock_and_delivery_fees(): void
    {
        $writer = app(CatalogWriter::class);
        SharedCouriers::within($this->store, function () use ($writer) {
            $writer->save($this->store, null, [
                'title' => 'Robe wax', 'options' => [['name' => 'Taille', 'values' => ['S', 'M']]],
                'variants' => [['options' => ['S'], 'price' => 15000, 'on_hand' => 0], ['options' => ['M'], 'price' => 16000, 'on_hand' => 4]],
            ]);
            $zone = DeliveryZone::create(['store_id' => $this->store->id, 'name' => 'Cocody', 'type' => 'places', 'fee' => 1500, 'free_above' => 30000, 'eta_label' => '24 h', 'is_active' => true]);
            $zone->places()->attach(Place::where('name', 'Angré')->value('id'), ['store_id' => $this->store->id]);
        });
        $conversation = Conversation::create(['store_id' => $this->store->id, 'channel' => 'whatsapp', 'contact' => '+2250708091011', 'contact_name' => 'Awa',
            'last_message_at' => now(), 'last_inbound_at' => now()]);
        ConversationMessage::create(['store_id' => $this->store->id, 'conversation_id' => $conversation->id, 'direction' => 'in', 'type' => 'text',
            'body' => 'Bonsoir, la robe wax en S c combien ? livraison à Angré ?', 'status' => 'received', 'sent_at' => now()]);

        $this->ai->respond(['reply' => 'Bonsoir Awa ! La taille S est épuisée, la M est à 16 000 F. Livraison à Angré : 1 500 F.', 'to_check' => null]);
        $this->storeApi($this->store, 'POST', "conversations/{$conversation->id}/suggest")->assertOk()
            ->assertJsonPath('data.reply', 'Bonsoir Awa ! La taille S est épuisée, la M est à 16 000 F. Livraison à Angré : 1 500 F.')
            ->assertJsonPath('data.to_check', null);

        $sent = $this->ai->lastText();
        $this->assertMatchesRegularExpression('/V\d+ S 15 000 F \(épuisé\) ; V\d+ M 16 000 F \(4 dispo\)/', $sent);
        $this->assertStringContainsString('Cocody (Angré) : 1 500 F, offerte dès 30 000 F, 24 h', $sent);
        $this->assertStringContainsString('Client (', $sent);
        $this->assertStringContainsString('la robe wax en S c combien', $sent);

        // Rien n'est envoyé au client
        $this->assertSame(1, ConversationMessage::forStore($this->store->id)->count());
    }

    public function test_only_the_team_that_handles_messages(): void
    {
        $conversation = Conversation::create(['store_id' => $this->store->id, 'channel' => 'whatsapp', 'contact' => '+2250708091011', 'last_message_at' => now()]);
        $other = $this->createStore();
        $this->actingAs($other->owner);
        $this->storeApi($other, 'POST', "conversations/{$conversation->id}/suggest")->assertNotFound();
        $this->assertSame([], $this->ai->calls);
    }
}
