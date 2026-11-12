<?php

namespace Tests\Feature\Live;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Live interactif : article mystère caché partout jusqu'à sa révélation (indices un par un), moments qui durent
 * (défi, dernière chance, annonce), question du public (commune seulement), réactions, les 3 premiers, et le
 * journal des moments pour le bilan.
 */
class LiveInteractiveTest extends TestCase
{
    use LiveProductsHavePhotos;

    private Store $store;

    private int $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        Http::preventStrayRequests();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 50]]]);
        $montre = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Montre dorée', 'variants' => [['price' => 20000, 'on_hand' => 5]]]);
        $this->live = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'items' => [
            ['variant_id' => $robe->variants()->value('id'), 'quantity' => 20],
            ['variant_id' => $montre->variants()->value('id'), 'quantity' => 5],
        ]])->assertCreated()->json('data.id');
    }

    private function moment(array $data): TestResponse
    {
        return $this->storeApi($this->store, 'POST', "lives/{$this->live}/show/moment", $data);
    }

    private function display(): TestResponse
    {
        return $this->storeApi($this->store, 'GET', "lives/{$this->live}/display")->assertOk();
    }

    public function test_the_mystery_item_stays_hidden_until_it_is_revealed_hint_by_hint(): void
    {
        // Préparation : A2 devient l'article mystère, avec ses indices
        $this->storeApi($this->store, 'PUT', "lives/{$this->live}/mystery", ['code' => 'A2', 'mystery' => true, 'hints' => ['Ça se porte au poignet', 'C\'est doré', '']])
            ->assertOk()->assertJsonPath('data.items.1.mystery', true)->assertJsonPath('data.items.1.revealed', false)
            ->assertJsonPath('data.items.1.hints', ['Ça se porte au poignet', 'C\'est doré']);
        $this->storeApi($this->store, 'PUT', "lives/{$this->live}/mystery", ['code' => 'Z9', 'mystery' => true])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/start")->assertOk();

        // Caché de l'écran, de la page publique du live, et impossible à montrer avant la révélation
        $this->display()->assertJsonCount(1, 'data.live.items')->assertJsonPath('data.live.items.0.code', 'A1');
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->json('GET', $this->storeUrl($this->store, '/live/etat'))
            ->assertOk()->assertJsonCount(1, 'data.items');
        $a2 = $this->storeApi($this->store, 'GET', "lives/{$this->live}")->json('data.items.1.id');
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/feature", ['item_id' => $a2])->assertStatus(422);

        // Annonce, puis le mystère : photo et indices donnés un par un, sans nom ni code
        $this->moment(['type' => 'announce', 'minutes' => 5])->assertOk();
        $this->assertNotNull($this->display()->json('data.live.show.state.announce_at'));
        $this->moment(['type' => 'mystery'])->assertOk();
        $screen = $this->display()->assertJsonPath('data.live.show.state.mystery.level', 0)->assertJsonPath('data.live.show.state.mystery.hints', [])
            ->assertJsonPath('data.live.show.state.announce_at', null);
        $this->assertNotNull($screen->json('data.live.show.state.mystery.photo'));
        $this->assertStringNotContainsString('Montre', $screen->getContent());
        $this->moment(['type' => 'hint'])->assertOk();
        $this->display()->assertJsonPath('data.live.show.state.mystery.hints', ['Ça se porte au poignet']);

        // Révélation : l'article apparaît et devient l'article montré
        $this->moment(['type' => 'reveal'])->assertOk()->assertJsonPath('data.event.data.code', 'A2');
        $this->display()->assertJsonCount(2, 'data.live.items')->assertJsonPath('data.live.featured_item_id', $a2)->assertJsonPath('data.live.show.state.mystery', null);
        $this->storeApi($this->store, 'PUT', "lives/{$this->live}/mystery", ['code' => 'A2', 'mystery' => true])->assertStatus(422);
        // Plus d'article mystère à lancer
        $this->moment(['type' => 'mystery'])->assertStatus(422);
        $this->moment(['type' => 'hint'])->assertStatus(422);
    }

    public function test_lasting_moments_question_reactions_podium_and_the_report(): void
    {
        $this->moment(['type' => 'mystery'])->assertStatus(422); // live pas commencé
        $this->moment(['type' => 'defi'])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/start")->assertOk();

        $this->moment(['type' => 'react', 'emoji' => '🔥'])->assertOk()->assertJsonPath('data.event.data.emoji', '🔥');
        $this->moment(['type' => 'react', 'emoji' => '💸'])->assertStatus(422);
        $this->moment(['type' => 'podium'])->assertStatus(422); // aucun article montré
        $a1 = $this->storeApi($this->store, 'GET', "lives/{$this->live}")->json('data.items.0.id');
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/feature", ['item_id' => $a1])->assertOk();
        $this->moment(['type' => 'podium'])->assertOk()->assertJsonPath('data.event.data.code', 'A1');

        // Défi : de la commande actuelle à +5, avec sa récompense ; arrêt
        $this->moment(['type' => 'defi', 'goal' => 5, 'reward' => 'gift'])->assertOk();
        $this->display()->assertJsonPath('data.live.show.state.defi.from', 0)->assertJsonPath('data.live.show.state.defi.target', 5)
            ->assertJsonPath('data.live.show.state.defi.reward', 'Cadeau surprise pour tous');
        $this->moment(['type' => 'defi_stop'])->assertOk();
        $this->display()->assertJsonPath('data.live.show.state.defi', null);
        $this->moment(['type' => 'last', 'seconds' => 120])->assertOk();
        $this->assertNotNull($this->display()->json('data.live.show.state.last_until'));

        // Question du public : le texte, jamais le nom ni le numéro
        $conversation = Conversation::create(['store_id' => $this->store->id, 'channel' => 'whatsapp', 'contact' => '+2250708091011', 'contact_name' => 'Aminata Koné', 'last_message_at' => now()]);
        $message = ConversationMessage::create(['store_id' => $this->store->id, 'conversation_id' => $conversation->id, 'direction' => 'in', 'type' => 'text', 'body' => "Elle existe\n en bleu ?", 'status' => 'received', 'sent_at' => now()]);
        $this->moment(['type' => 'question'])->assertStatus(422);
        $this->moment(['type' => 'question', 'message_id' => $message->id])->assertOk()->assertJsonPath('data.event.data', ['text' => 'Elle existe en bleu ?', 'place' => null]);
        $screen = $this->display()->assertJsonPath('data.live.show.event.type', 'question');
        $this->assertStringNotContainsString('Aminata', $screen->getContent());
        $this->assertStringNotContainsString('0708091011', $screen->getContent());

        $this->moment(['type' => 'map'])->assertOk();
        $this->moment(['type' => 'how'])->assertOk();

        // Journal pour le bilan : les moments, dans l'ordre (sans l'arrêt du défi)
        $bilan = $this->storeApi($this->store, 'GET', "lives/{$this->live}/bilan")->assertOk()->json('data');
        $this->assertSame(['react', 'podium', 'defi', 'last', 'question', 'map', 'how'], array_column($bilan['moments'], 'type'));
        $this->assertSame(['A1', 'A2'], array_column($bilan['items'], 'code'));
        $this->assertSame([], $bilan['orders']);
    }
}
