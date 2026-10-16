<?php

namespace Tests\Feature\Messaging;

use App\Enums\Role;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Notifications\CustomerMessage;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InboxTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private int $sent = 0;

    private ?string $metaError = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/v25.0/*' => function () {
            if ($this->metaError) {
                return Http::response(['error' => ['message' => $this->metaError]], 400);
            }
            $this->sent++;

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.out'.$this->sent]]]);
        }]);

        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function connect(): WhatsappAccount
    {
        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250700000001', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
            'templates' => ['order_confirmation' => 'confirmation_commande'], 'automations' => ['order_confirmation' => true],
        ])->assertOk();

        return WhatsappAccount::sole();
    }

    private function inbound(WhatsappAccount $account, array $messages, array $contacts = []): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233', 'changes' => [['field' => 'messages', 'value' => array_filter(['messages' => $messages, 'contacts' => $contacts])]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp/'.$account->webhook_uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }

    private function text(string $id, string $body, string $from = '2250506070809', ?int $at = null): array
    {
        return ['from' => $from, 'id' => $id, 'timestamp' => (string) ($at ?? now()->timestamp), 'type' => 'text', 'text' => ['body' => $body]];
    }

    public function test_customer_messages_land_in_the_inbox(): void
    {
        $account = $this->connect();
        $contacts = [['wa_id' => '2250506070809', 'profile' => ['name' => 'Fatou Diallo']]];

        $this->inbound($account, [$this->text('wamid.1', 'Bonjour je veux 2 robe wax')], $contacts)->assertOk();
        $this->inbound($account, [
            $this->text('wamid.1', 'Bonjour je veux 2 robe wax'), // notification rejouée : ignorée
            ['from' => '2250506070809', 'id' => 'wamid.2', 'type' => 'location', 'location' => ['latitude' => 5.39, 'longitude' => -3.98, 'name' => 'Pharmacie Angré']],
            ['from' => '2250506070809', 'id' => 'wamid.3', 'type' => 'image', 'image' => ['caption' => 'celle-ci']],
            ['from' => '2250506070809', 'id' => 'wamid.4', 'type' => 'reaction', 'reaction' => ['emoji' => '👍']],
        ])->assertOk();
        $this->inbound($account, [$this->text('wamid.5', 'Bonsoir', '2250102030405')])->assertOk();

        // L'équipe est prévenue d'une nouvelle conversation, pas de chaque message qui suit
        $titles = $this->store->owner->notifications()->where('type', CustomerMessage::class)->get()->pluck('data.title')->all();
        $this->assertEqualsCanonicalizing(['💬 Fatou Diallo', '💬 Message WhatsApp'], $titles);

        $list = $this->storeApi($this->store, 'GET', 'conversations')->assertOk()
            ->assertJsonPath('meta.unread', 2)->assertJsonPath('connected', true)->assertJsonCount(2, 'data');
        $fatou = collect($list->json('data'))->firstWhere('phone', '+2250506070809');
        $this->assertSame(['Fatou Diallo', 3, '📷 Photo : celle-ci', '05 06 07 08 09'], [$fatou['name'], $fatou['unread'], $fatou['preview'], $fatou['phone_display']]);

        $detail = $this->storeApi($this->store, 'GET', "conversations/{$fatou['id']}")->assertOk()
            ->assertJsonPath('data.can_reply', true)->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.1.body', "📍 Position : Pharmacie Angré\nhttps://maps.google.com/?q=5.39,-3.98");
        $this->assertSame("Bonjour je veux 2 robe wax\n📍 Position : Pharmacie Angré\nhttps://maps.google.com/?q=5.39,-3.98\n📷 Photo : celle-ci", $detail->json('data.order_text'));

        // Ouvrir la conversation la marque comme lue
        $this->storeApi($this->store, 'GET', 'conversations/unread')->assertJsonPath('data.count', 1);
        $this->storeApi($this->store, 'GET', 'conversations?filter=unread')->assertJsonCount(1, 'data');
        $this->storeApi($this->store, 'GET', 'conversations?q=fatou')->assertJsonCount(1, 'data');
        $this->storeApi($this->store, 'GET', 'conversations?q=0102')->assertJsonCount(1, 'data');
    }

    public function test_reply_within_24_hours(): void
    {
        $account = $this->connect();
        $this->inbound($account, [$this->text('wamid.1', 'La robe est dispo ?')])->assertOk();
        $conversation = Conversation::sole();

        $this->storeApi($this->store, 'POST', "conversations/{$conversation->id}/messages", ['body' => 'Oui, en M et L 🙂'])->assertCreated()
            ->assertJsonPath('data.messages.1.direction', 'out')->assertJsonPath('data.messages.1.by', $this->store->owner->name)
            ->assertJsonPath('data.preview', 'Oui, en M et L 🙂');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://graph.facebook.com/v25.0/1098765/messages'
            && $r['to'] === '2250506070809' && $r['type'] === 'text' && $r['text']['body'] === 'Oui, en M et L 🙂');

        // Statut renvoyé par Meta
        $body = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.out1', 'status' => 'read']]]]]]]]);
        $this->call('POST', 'http://api.localhost/webhooks/whatsapp/'.$account->webhook_uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body)->assertOk();
        $this->assertSame('read', ConversationMessage::where('direction', 'out')->value('status'));

        // Erreur de Meta : montrée, rien n'est enregistré
        $this->metaError = 'Invalid token';
        $this->storeApi($this->store, 'POST', "conversations/{$conversation->id}/messages", ['body' => 'Encore là ?'])
            ->assertStatus(422)->assertJsonPath('message', 'WhatsApp : Invalid token');
        $this->metaError = null;

        // Plus de 24 h après le dernier message du client : réponse libre refusée
        $this->travel(25)->hours();
        $this->storeApi($this->store, 'GET', "conversations/{$conversation->id}")->assertJsonPath('data.can_reply', false);
        $this->storeApi($this->store, 'POST', "conversations/{$conversation->id}/messages", ['body' => 'Bonjour'])->assertStatus(422);
        $this->assertSame(2, ConversationMessage::count());
    }

    public function test_order_created_from_the_conversation(): void
    {
        $account = $this->connect();
        $this->inbound($account, [$this->text('wamid.1', 'Je veux la robe wax', at: now()->subMinutes(5)->timestamp)], [['wa_id' => '2250506070809', 'profile' => ['name' => 'Fatou']]])->assertOk();
        $conversation = Conversation::sole();

        $order = $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_phone' => '+2250506070809', 'customer_name' => 'Fatou', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'),
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]], 'conversation_id' => $conversation->id,
        ])->assertCreated();

        $detail = $this->storeApi($this->store, 'GET', "conversations/{$conversation->id}")->assertOk()
            ->assertJsonPath('data.order.id', $order->json('data.id'))->assertJsonPath('data.order.reference', '#1001')
            ->assertJsonPath('data.customer.name', 'Fatou')->assertJsonPath('data.order_text', '');
        // La demande de confirmation automatique apparaît dans le fil : boutons dans la conversation ouverte, sans modèle
        $this->assertSame('out', $detail->json('data.messages.1.direction'));
        $this->assertStringStartsWith('🤖 Bonjour Fatou, merci pour votre commande #1001 chez Awa Mode', $detail->json('data.messages.1.body'));
        $this->assertStringEndsWith('[Confirmer] [Annuler]', $detail->json('data.messages.1.body'));
        $this->storeApi($this->store, 'GET', 'conversations?filter=without_order')->assertJsonCount(0, 'data');

        // Nouveau message après la commande : seul lui est proposé pour la commande suivante
        $this->travel(2)->minutes();
        $this->inbound($account, [$this->text('wamid.2', 'Ajoutez un foulard svp')])->assertOk();
        $this->storeApi($this->store, 'GET', "conversations/{$conversation->id}")->assertJsonPath('data.order_text', 'Ajoutez un foulard svp');
    }

    public function test_who_sees_the_inbox(): void
    {
        $account = $this->connect();
        $this->inbound($account, [$this->text('wamid.1', 'Bonjour')])->assertOk();
        $id = Conversation::sole()->id;

        $this->actingAs($this->addMember($this->store, User::factory()->create(), Role::ConfirmationAgent));
        $this->storeApi($this->store, 'GET', "conversations/{$id}")->assertOk();
        $this->storeApi($this->store, 'POST', "conversations/{$id}/messages", ['body' => 'Bonjour !'])->assertCreated();

        $this->actingAs($this->addMember($this->store, User::factory()->create(), Role::StockKeeper));
        $this->storeApi($this->store, 'GET', 'conversations')->assertForbidden();

        // Autre boutique : conversation introuvable
        $other = $this->createStore(['slug' => 'autre']);
        $this->actingAs($other->owner);
        $this->storeApi($other, 'GET', "conversations/{$id}")->assertNotFound();
        $this->storeApi($other, 'GET', 'conversations')->assertJsonCount(0, 'data')->assertJsonPath('connected', false);
    }
}
