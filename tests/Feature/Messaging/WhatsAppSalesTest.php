<?php

namespace Tests\Feature\Messaging;

use App\Enums\OrderStatus;
use App\Jobs\SendAutoReply;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Services\Ai\AiClient;
use App\Services\Catalog\CatalogWriter;
use App\Services\Messaging\AutoReplier;
use App\Services\Places\Places;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Vendre sur WhatsApp : paniers du catalogue WhatsApp reçus comme commandes, réponses automatiques et leurs garde-fous.
 */
class WhatsAppSalesTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private FakeAiClient $ai;

    private int $sent = 0;

    private int $queued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_bridge' => ['url' => 'http://127.0.0.1:3210', 'token' => 'bridge-secret']]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v25.0/*' => function () {
                $this->sent++;

                return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.out'.$this->sent]]]);
            },
            'http://127.0.0.1:3210/sessions/*/start' => Http::response(['session' => 'x', 'status' => 'qr', 'qr' => 'data:image/png;base64,QR']),
            'http://127.0.0.1:3210/sessions/*/send' => function () {
                $this->queued++;

                return Http::response(['id' => 'qr-'.$this->queued], 202);
            },
            'http://127.0.0.1:3210/sessions/*' => Http::response(['status' => 'connected', 'phone' => '+2250700000001']),
        ]);
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);

        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        // WhatsApp par QR code autorisé pour la boutique (console)
        $this->store->forceFill(['whatsapp_qr_enabled' => true])->save();

        // L'assistant IA n'est compris dans aucune offre : actions rechargées

        $this->store->forceFill(['ai_credits' => 100])->save();
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function connect(): WhatsappAccount
    {
        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250700000001', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
        ])->assertOk();

        return WhatsappAccount::sole();
    }

    private function inbound(WhatsappAccount $account, array $messages): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233', 'changes' => [['field' => 'messages', 'value' => [
            'messages' => $messages, 'contacts' => [['wa_id' => '2250506070809', 'profile' => ['name' => 'Fatou Diallo']]],
        ]]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp/'.$account->webhook_uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }

    private function bridge(array $event): TestResponse
    {
        $body = json_encode($event + ['session' => 'store-'.$this->store->id]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, 'bridge-secret'),
        ], $body);
    }

    private function text(string $id, string $body): array
    {
        return ['from' => '2250506070809', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $body]];
    }

    private function variantRef(): string
    {
        return 'V'.$this->robe->variants()->value('id');
    }

    private function enable(array $settings = []): void
    {
        $this->storeApi($this->store, 'PUT', 'whatsapp/sales', $settings + ['enabled' => true, 'when' => 'always', 'from' => '19:00', 'to' => '08:00', 'per_hour' => 15])
            ->assertOk()->assertJsonPath('data.auto_replies.enabled', $settings['enabled'] ?? true);
    }

    private function lastIncoming(): ConversationMessage
    {
        return ConversationMessage::forStore($this->store->id)->where('direction', 'in')->latest('id')->firstOrFail();
    }

    public function test_a_cart_from_the_official_whatsapp_catalog_becomes_an_order(): void
    {
        $account = $this->connect();
        $cart = ['from' => '2250506070809', 'id' => 'wamid.cart', 'timestamp' => (string) now()->timestamp, 'type' => 'order', 'order' => [
            'catalog_id' => '777', 'text' => 'Livrez-moi demain svp',
            'product_items' => [
                ['product_retailer_id' => $this->variantRef(), 'quantity' => 2, 'item_price' => 15000, 'currency' => 'XOF'],
                ['product_retailer_id' => 'ANCIEN-12', 'quantity' => 1, 'item_price' => 5000, 'currency' => 'XOF'],
            ],
        ]];

        $this->inbound($account, [$cart])->assertOk();
        $this->inbound($account, [$cart])->assertOk(); // notification rejouée : pas de deuxième commande

        $order = Order::forStore($this->store->id)->with('items')->sole();
        $this->assertSame(['whatsapp', OrderStatus::PendingConfirmation, '+2250506070809', 35000], [$order->source->value, $order->status, $order->customer_phone, (int) $order->items->sum('total')]);
        $this->assertSame([2, 1], $order->items->pluck('quantity')->all());
        $this->assertSame([$this->robe->variants()->value('id'), null], $order->items->pluck('variant_id')->all());
        $this->assertSame('Article du catalogue (ANCIEN-12)', $order->items[1]->title);
        $this->assertSame('Livrez-moi demain svp', $order->customer_note);

        $conversation = Conversation::forStore($this->store->id)->sole();
        $this->assertSame($order->id, $conversation->order_id);
        $message = $this->lastIncoming();
        $this->assertSame('order', $message->type);
        $this->assertStringContainsString("🛒 Commande depuis le catalogue WhatsApp\n2 × Robe wax — 30 000 F", str_replace("\u{202F}", ' ', $message->body));
        $this->assertStringContainsString("Commande {$order->reference()} créée, à confirmer", $message->body);

        $this->storeApi($this->store, 'GET', 'whatsapp/sales')->assertOk()->assertJsonPath('data.catalog.orders_last_30_days', 1)
            ->assertJsonPath('data.channel', 'official');
    }

    public function test_a_resent_cart_does_not_duplicate_and_a_new_cart_joins_the_pending_order(): void
    {
        $account = $this->connect();
        $cart = fn (string $id, array $items) => ['from' => '2250506070809', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'order',
            'order' => ['catalog_id' => '777', 'product_items' => $items]];
        $robe = ['product_retailer_id' => $this->variantRef(), 'quantity' => 2, 'item_price' => 15000, 'currency' => 'XOF'];

        // Même panier renvoyé : pas de deuxième commande
        $this->inbound($account, [$cart('wamid.c1', [$robe])])->assertOk();
        $this->inbound($account, [$cart('wamid.c2', [$robe])])->assertOk();
        $order = Order::forStore($this->store->id)->sole();
        $this->assertStringContainsString("Même panier que la commande {$order->reference()}", $this->lastIncoming()->body);

        // Autre panier : ajouté à la commande encore à confirmer (une robe de plus, un nouvel article)
        $this->inbound($account, [$cart('wamid.c3', [['product_retailer_id' => $this->variantRef(), 'quantity' => 1, 'item_price' => 15000, 'currency' => 'XOF'],
            ['product_retailer_id' => 'FOULARD', 'quantity' => 2, 'item_price' => 3000, 'currency' => 'XOF']])])->assertOk();
        $order = Order::forStore($this->store->id)->with('items', 'events')->sole();
        $this->assertSame([[3, 2], 51000], [$order->items->pluck('quantity')->map(fn ($q) => (int) $q)->all(), (int) $order->items->sum('total')]);
        $this->assertStringContainsString("Articles ajoutés à la commande {$order->reference()}", $this->lastIncoming()->body);
        $this->assertTrue($order->events->contains('type', 'items_added'));
        $this->storeApi($this->store, 'GET', 'orders')->assertOk()->assertJsonPath('data.0.possible_duplicate', false);

        // Commande déjà confirmée : le panier suivant est une nouvelle commande, signalée « Doublon ? »
        $order->forceFill(['status' => OrderStatus::Confirmed])->save();
        $this->inbound($account, [$cart('wamid.c4', [$robe])])->assertOk();
        $this->assertSame(2, Order::forStore($this->store->id)->count());
        $this->storeApi($this->store, 'GET', 'orders')->assertOk()->assertJsonPath('data.0.possible_duplicate', true)->assertJsonPath('data.1.possible_duplicate', true);
        $this->storeApi($this->store, 'GET', 'orders/confirmation-queue')->assertOk()->assertJsonPath('data.0.possible_duplicate', true);
    }

    public function test_a_cart_from_the_linked_whatsapp_catalog(): void
    {
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();

        $this->bridge(['type' => 'message', 'id' => 'ABC1', 'from' => '2250506070809', 'name' => 'Fatou', 'kind' => 'order', 'text' => null,
            'items' => [['ref' => $this->variantRef(), 'name' => 'Robe wax', 'quantity' => 1, 'price' => 15000]], 'total' => 15000])->assertOk();

        $order = Order::forStore($this->store->id)->sole();
        $this->assertSame(1, $order->items()->count());
        $this->assertTrue(Conversation::forStore($this->store->id)->sole()->via_link);

        // Détail illisible : le panier arrive quand même, la commande est à créer depuis la conversation
        $this->bridge(['type' => 'message', 'id' => 'ABC2', 'from' => '2250506070809', 'kind' => 'order', 'items' => [], 'total' => 30000])->assertOk();
        $this->assertSame(1, Order::forStore($this->store->id)->count());
        $this->assertStringContainsString("WhatsApp n'a pas transmis le détail du panier", $this->lastIncoming()->body);
    }

    public function test_auto_replies_are_off_by_default_and_warn_for_a_linked_number(): void
    {
        Queue::fake();
        $account = $this->connect();
        $this->storeApi($this->store, 'GET', 'whatsapp/sales')->assertOk()
            ->assertJsonPath('data.auto_replies.enabled', false)->assertJsonPath('data.auto_replies.per_hour', 15)
            ->assertJsonPath('data.channel', 'official');

        $this->inbound($account, [$this->text('wamid.1', 'La robe wax est dispo ?')])->assertOk();
        Queue::assertNotPushed(SendAutoReply::class);

        $this->storeApi($this->store, 'PUT', 'whatsapp/sales', ['enabled' => true, 'when' => 'away', 'from' => '19:00', 'to' => '19:00', 'per_hour' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors(['to', 'per_hour']);

        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();
        $this->storeApi($this->store, 'GET', 'whatsapp/sales')->assertJsonPath('data.channel', 'linked');
    }

    public function test_a_simple_question_gets_an_automatic_reply(): void
    {
        Queue::fake();
        $account = $this->connect();
        $this->enable();

        $this->inbound($account, [$this->text('wamid.1', 'Bonsoir, la robe wax est dispo ? combien ?')])->assertOk();
        Queue::assertPushed(SendAutoReply::class, fn ($job) => $job->messageId === $this->lastIncoming()->id && $job->delay !== null);

        $this->ai->respond(['reply' => 'Bonsoir ! Oui, la robe wax est disponible à 15 000 F.', 'to_check' => null, 'send_alone' => true]);
        $this->assertNull(app(AutoReplier::class)->run($this->lastIncoming()->id));

        $this->assertStringContainsString('Mode automatique', $this->ai->calls[0]['system']);
        $this->assertMatchesRegularExpression('/V\d+ Robe wax — 15 000 F \(10 dispo\)/', $this->ai->lastText());
        $reply = ConversationMessage::forStore($this->store->id)->where('direction', 'out')->sole();
        $this->assertSame(['auto', 'sent', 'Bonsoir ! Oui, la robe wax est disponible à 15 000 F.', null], [$reply->type, $reply->status, $reply->body, $reply->user_id]);
        $this->assertSame(1, $this->sent);

        $conversation = Conversation::forStore($this->store->id)->sole();
        $this->storeApi($this->store, 'GET', "conversations/{$conversation->id}")->assertJsonPath('data.messages.1.by', 'Réponse automatique');
        $this->storeApi($this->store, 'GET', 'whatsapp/sales')->assertJsonPath('data.sent_last_7_days', 1);
    }

    public function test_guardrails(): void
    {
        Queue::fake();
        $account = $this->connect();
        $this->enable(['per_hour' => 2]);
        $replier = app(AutoReplier::class);

        // L'assistant a un doute : rien n'est envoyé
        $this->inbound($account, [$this->text('wamid.1', 'Ma commande n\'est jamais arrivée, remboursez-moi')])->assertOk();
        $this->ai->respond(['reply' => 'Nous vérifions.', 'to_check' => 'Réclamation', 'send_alone' => false]);
        $this->assertSame('unsure', $replier->run($this->lastIncoming()->id));

        // Le client a encore écrit : seul son dernier message obtient une réponse
        $first = $this->lastIncoming()->id;
        $this->inbound($account, [$this->text('wamid.2', 'Et la robe wax ?')])->assertOk();
        $this->assertSame('not_last', $replier->run($first));

        // L'équipe vient de répondre : elle a la main pendant 30 minutes
        $conversation = Conversation::forStore($this->store->id)->sole();
        $this->storeApi($this->store, 'POST', "conversations/{$conversation->id}/messages", ['body' => 'Je regarde ça'])->assertCreated();
        $this->inbound($account, [$this->text('wamid.3', 'Merci, et la taille ?')])->assertOk();
        $this->assertSame('team', $replier->run($this->lastIncoming()->id));

        // Plafond par heure de la boutique
        $this->travel(31)->minutes();
        foreach ([4, 5] as $i) {
            $this->inbound($account, [$this->text("wamid.{$i}", 'Dispo ?')])->assertOk();
            $this->ai->respond(['reply' => 'Oui, disponible.', 'to_check' => null, 'send_alone' => true]);
            $this->assertNull($replier->run($this->lastIncoming()->id));
        }
        $this->inbound($account, [$this->text('wamid.6', 'Encore une question')])->assertOk();
        $this->assertSame('hourly_limit', $replier->run($this->lastIncoming()->id));

        // Seulement la nuit : en journée, pas de réponse automatique
        $this->travelTo(now('Africa/Abidjan')->setTime(12, 0));
        $this->enable(['when' => 'away', 'from' => '19:00', 'to' => '08:00']);
        $this->assertSame('hours', $replier->run($this->lastIncoming()->id));

        // Désactivées : plus rien
        $this->enable(['enabled' => false]);
        $this->assertSame('disabled', $replier->run($this->lastIncoming()->id));
        $this->assertSame(2, ConversationMessage::forStore($this->store->id)->where('type', 'auto')->count());
    }

    public function test_an_automatic_reply_goes_through_the_linked_whatsapp(): void
    {
        Queue::fake();
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();
        $this->enable();

        $this->bridge(['type' => 'message', 'id' => 'IN1', 'from' => '2250506070809', 'kind' => 'text', 'text' => 'Robe wax dispo ?'])->assertOk();
        $this->ai->respond(['reply' => 'Oui, 15 000 F.', 'to_check' => null, 'send_alone' => true]);
        $this->assertNull(app(AutoReplier::class)->run($this->lastIncoming()->id));

        $reply = ConversationMessage::forStore($this->store->id)->where('type', 'auto')->sole();
        $this->assertSame(['queued', 'qr-1'], [$reply->status, $reply->provider_message_id]);
        $this->assertTrue(WhatsappLink::sole()->isConnected());
    }
}
