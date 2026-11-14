<?php

namespace Tests\Feature\Messaging;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Models\AiUsage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\OrderCreditBill;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Notifications\RecapNotice;
use App\Services\Ai\AiClient;
use App\Services\Ai\Assistant;
use App\Services\Billing\Credits;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Platform\Features;
use App\Services\WhatsApp\RecapOrders;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Commande prise dans une conversation WhatsApp normale : le marchand écrit « récap » depuis le téléphone de la boutique
 * (numéro officiel en coexistence, message renvoyé en écho), le client confirme d'un bouton, ou la boutique confirme ;
 * les commandes WhatsApp hors live confirmées sont retirées du solde le soir.
 */
class RecapOrdersTest extends TestCase
{
    use LiveProductsHavePhotos;

    private Store $store;

    private Product $robe;

    private WhatsappAccount $account;

    /** @var list<array<string, mixed>> */
    private array $out = [];

    private int $id = 0;

    /** @var (callable(Request): mixed)|null */
    private $meta = null;

    private const CLIENT = '2250506070809';

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/v25.0/*' => function (Request $request) {
            // Catalogue et réglages commerce du numéro : réponse propre au test qui la prévoit
            if ($this->meta !== null && preg_match('/product_catalogs|whatsapp_commerce_settings/', $request->url())) {
                return ($this->meta)($request);
            }
            $this->out[] = $request->data();

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.out'.count($this->out)]]]);
        }]);
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'options' => [['name' => 'Taille', 'values' => ['S', 'M']]], 'variants' => [
            ['options' => ['S'], 'price' => 15000, 'on_hand' => 3], ['options' => ['M'], 'price' => 15000, 'on_hand' => 3],
        ]]);
        $this->actingAs($this->store->owner);
        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250700000001', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
        ])->assertOk();
        $this->account = WhatsappAccount::sole();
        $this->out = [];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function webhook(array $value): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp/'.$this->account->webhook_uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body)->assertOk();
    }

    private function client(string $text): void
    {
        $this->webhook(['messages' => [['from' => self::CLIENT, 'id' => 'wamid.in'.(++$this->id), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
            'contacts' => [['wa_id' => self::CLIENT, 'profile' => ['name' => 'Fatou Diallo']]]]);
    }

    private function tap(string $id, string $title, string $kind = 'button_reply'): void
    {
        $this->webhook(['messages' => [['from' => self::CLIENT, 'id' => 'wamid.in'.(++$this->id), 'timestamp' => (string) now()->timestamp,
            'type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => $title]]]]]);
    }

    /** Message écrit par le marchand dans l'application WhatsApp Business du téléphone (écho de Meta) */
    private function merchant(string $text): void
    {
        $this->webhook(['message_echoes' => [['from' => '2250700000001', 'to' => self::CLIENT, 'id' => 'wamid.echo'.(++$this->id),
            'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]]]);
    }

    private function sent(): array
    {
        [$out, $this->out] = [$this->out, []];

        return $out;
    }

    private function variant(string $title): int
    {
        return $this->robe->variants->firstWhere('title', $title)->id;
    }

    /** @return list<string> */
    private function notices(): array
    {
        return $this->store->owner->notifications()->where('type', RecapNotice::class)->oldest('created_at')->get()->pluck('data.title')->all();
    }

    public function test_the_word_recap_and_confirmations_are_recognised(): void
    {
        $this->assertSame('', RecapOrders::recapText('récap'));
        $this->assertSame('2 robes M Angré', RecapOrders::recapText('Récap : 2 robes M Angré'));
        $this->assertSame('', RecapOrders::recapText('*RECAP*'));
        $this->assertNull(RecapOrders::recapText('je vous envoie le récap tout à l\'heure'));
        $this->assertNull(RecapOrders::recapText('récapitulons'));
        $this->assertTrue(RecapOrders::confirms('Confirmé ✅'));
        $this->assertTrue(RecapOrders::confirms('ok confirmée'));
        $this->assertFalse(RecapOrders::confirms('ok'));
        $this->assertFalse(RecapOrders::confirms('je confirme demain'));
    }

    public function test_a_message_from_the_phone_lands_in_the_thread_and_quiets_the_assistants(): void
    {
        $this->client('Bonsoir');
        $this->sent();
        $this->merchant('Bonsoir ma chérie, on a de belles robes');
        $conversation = Conversation::sole();
        $echo = ConversationMessage::where('provider_message_id', 'like', 'wamid.echo%')->sole();
        $this->assertSame(['out', 'text', null], [$echo->direction, $echo->type, $echo->user_id]);
        $this->assertSame('Bonsoir ma chérie, on a de belles robes', $conversation->fresh()->last_preview);

        // Le marchand a la main : « livraison » ne déclenche plus la liste des prix automatique
        $this->client('livraison');
        $this->assertSame([], $this->sent());

        // Écho rejoué : enregistré une seule fois
        $this->webhook(['message_echoes' => [['from' => '2250700000001', 'to' => self::CLIENT, 'id' => $echo->provider_message_id,
            'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Bonsoir ma chérie, on a de belles robes']]]]);
        $this->assertSame(1, ConversationMessage::where('provider_message_id', $echo->provider_message_id)->count());
    }

    public function test_recap_sends_the_order_with_buttons_and_the_customer_confirms(): void
    {
        app(Credits::class)->update(['paid' => true, 'welcome_orders' => 0, 'welcome_ai' => 0]);
        $this->store->forceFill(['order_credits' => 20])->save();
        $this->client('Bonjour, la robe wax en M vous avez ? Je suis à Angré');
        $this->merchant('Oui ma chérie, 15 000');
        $this->sent();

        $this->merchant('récap');
        [$recap] = $this->sent();
        $order = Order::sole();
        $this->assertSame([OrderStatus::PendingConfirmation, 'recap', 'whatsapp'], [$order->status, $order->credit_channel, $order->source->value]);
        $this->assertSame($this->variant('M'), $order->items->sole()->variant_id);
        $this->assertSame('Angré', Place::find($order->shipping_place_id)->name);
        $this->assertSame($order->id, Conversation::sole()->order_id);
        $this->assertSame('button', $recap['interactive']['type']);
        $this->assertSame("live:confirm:{$order->id}", $recap['interactive']['action']['buttons'][0]['reply']['id']);
        $this->assertStringContainsString('Récap envoyé à Fatou Diallo', $this->notices()[0]);

        // Le client touche « Je confirme » : confirmée, comptée (le soir), la boutique est prévenue
        $this->tap("live:confirm:{$order->id}", '✅ Je confirme');
        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertTrue($order->credit_due);
        $this->assertStringContainsString('par la cliente sur WhatsApp (récap)', $order->confirmations()->sole()->note);
        $this->assertStringContainsString('🎉 *COMMANDE CONFIRMÉE !*', collect($this->sent())->pluck('text.body')->filter()->implode("\n"));
        $this->assertSame('✅ Commande '.$order->reference().' confirmée', collect($this->notices())->last());
        $this->assertSame(20, $this->store->fresh()->order_credits);
    }

    public function test_the_size_then_the_commune_are_asked_before_the_recap(): void
    {
        $this->client('Je veux la robe wax');
        $this->sent();
        $this->merchant('Récap');
        [$sizes] = $this->sent();
        $this->assertSame(0, Order::count());
        $this->assertStringContainsString('*Robe wax* : quelle taille ?', $sizes['interactive']['body']['text']);
        $this->assertSame(['S', 'M'], collect($sizes['interactive']['action']['buttons'])->pluck('reply.title')->all());

        $this->tap('recap:var:0:'.$this->variant('S'), 'S');
        [$communes] = $this->sent();
        $order = Order::sole();
        $this->assertNull($order->shipping_place_id);
        $this->assertSame('list', $communes['interactive']['type']);
        $this->assertStringStartsWith('📍 *OÙ VOUS LIVRER ?*', $communes['interactive']['body']['text']);

        $angre = Place::whereNull('store_id')->where('name', 'Angré')->firstOrFail();
        $this->tap('live:place:'.$angre->id, 'Angré', 'list_reply');
        [$recap] = $this->sent();
        $this->assertSame($angre->id, $order->fresh()->shipping_place_id);
        $this->assertSame("live:confirm:{$order->id}", $recap['interactive']['action']['buttons'][0]['reply']['id']);
    }

    public function test_the_merchant_confirms_by_writing_confirme(): void
    {
        $this->client('2 robes wax taille M pour Angré svp');
        $this->merchant('récap');
        $order = Order::sole();
        $this->assertSame(2, $order->items->sole()->quantity);
        $this->sent();

        // « ok » ne confirme rien ; « Confirmé » oui, et le client reçoit la confirmation
        $this->merchant('ok');
        $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
        $this->merchant('Confirmé ✅');
        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertStringContainsString('sans confirmation du client', $order->confirmations()->sole()->note);
        $this->assertStringContainsString('🎉 *COMMANDE CONFIRMÉE !*', collect($this->sent())->pluck('text.body')->filter()->implode("\n"));
    }

    public function test_precisions_after_recap_win_and_a_new_recap_updates_the_same_order(): void
    {
        $this->client('La robe wax M svp, Angré');
        $this->merchant('récap');
        $order = Order::sole();
        $this->sent();

        $this->merchant('récap 3 robes wax S Angré');
        $this->assertSame(1, Order::count());
        $item = $order->fresh()->items->sole();
        $this->assertSame([3, $this->variant('S')], [$item->quantity, $item->variant_id]);
    }

    public function test_nothing_goes_to_the_customer_without_article_or_balance(): void
    {
        $this->client('Bonsoir, vous livrez à Yopougon ?');
        $this->sent();
        $this->merchant('récap');
        $this->assertSame([], $this->sent());
        $this->assertSame(0, Order::count());
        $this->assertStringContainsString('aucun article reconnu', $this->notices()[0]);

        app(Credits::class)->update(['paid' => true, 'welcome_orders' => 0, 'welcome_ai' => 0]);
        $this->store->forceFill(['order_credits' => 0])->save();
        $this->client('La robe wax M pour Angré');
        $this->sent();
        $this->merchant('récap');
        $this->assertSame([], $this->sent());
        $this->assertSame(0, Order::count());
        $this->assertStringContainsString('solde à zéro', (string) collect($this->notices())->last());
    }

    public function test_the_owner_confirms_from_the_notification_button(): void
    {
        $this->client('La robe wax M pour Angré');
        $this->merchant('récap');
        $order = Order::sole();
        $this->sent();

        $this->assertTrue(app(RecapOrders::class)->fromOwner($this->store->owner->phone, "recap:ok:{$this->store->id}:{$order->id}"));
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertStringContainsString('notification WhatsApp', $order->confirmations()->sole()->note);

        // Quelqu'un d'autre ne peut rien confirmer
        $this->client('Et une robe wax S aussi');
        $other = app(OrderDesk::class)->create($this->store, ['source' => 'whatsapp', 'customer_phone' => '+'.self::CLIENT, 'delivery_method' => 'delivery',
            'credit_channel' => 'recap', 'items' => [['variant_id' => $this->variant('S'), 'quantity' => 1]]], null);
        app(RecapOrders::class)->fromOwner('+2250102030405', "recap:ok:{$this->store->id}:{$other->id}");
        $this->assertSame(OrderStatus::PendingConfirmation, $other->fresh()->status);
    }

    public function test_an_unanswered_recap_is_reminded_then_cancelled(): void
    {
        $this->client('La robe wax M pour Angré');
        $this->merchant('récap');
        $order = Order::sole();
        $this->sent();

        $this->travel(RecapOrders::REMIND_HOURS)->hours();
        $this->travel(5)->minutes();
        $this->client('Je regarde');
        $this->sent();
        $this->artisan('recap:remind')->assertSuccessful();
        $this->assertStringContainsString('VOTRE COMMANDE VOUS ATTEND', collect($this->sent())->pluck('text.body')->filter()->implode("\n"));
        $this->artisan('recap:remind')->assertSuccessful();
        $this->assertSame([], collect($this->sent())->pluck('text.body')->filter()->all());

        $this->travel(RecapOrders::EXPIRE_HOURS)->hours();
        $this->artisan('recap:remind')->assertSuccessful();
        $order->refresh();
        $this->assertSame([OrderStatus::Cancelled, CancelReason::Unreachable], [$order->status, $order->cancel_reason]);
        $this->assertNull($order->credit_due);
    }

    public function test_the_assistant_reads_the_conversation_without_using_ai_actions(): void
    {
        $ai = (new FakeAiClient)->respond(['phone' => null, 'phone2' => null, 'customer_name' => 'Fatou', 'place' => 'Angré', 'address' => null, 'landmark' => null,
            'amount' => null, 'note' => null, 'items' => [['code' => 'V'.$this->variant('M'), 'title' => 'Robe wax M', 'quantity' => 1]]]);
        $this->app->instance(AiClient::class, $ai);
        $this->store->forceFill(['ai_credits' => 0])->save();
        $this->client('Je prends la rouge là, celle de la photo, en M');
        $this->merchant('récap');

        $order = Order::sole();
        $this->assertSame($this->variant('M'), $order->items->sole()->variant_id);
        $this->assertSame('Angré', Place::find($order->shipping_place_id)->name);
        $this->assertStringContainsString('Client : Je prends la rouge là', collect($ai->calls[0]['content'])->last()['text']);
        // Comprise dans le prix de la commande : notée, mais ni dans le quota ni prise au solde d'actions IA
        $this->assertSame('whatsapp_recap', AiUsage::sole()->feature);
        $this->assertSame(0, app(Assistant::class)->status($this->store)['used']);
        $this->assertSame(0, $this->store->fresh()->ai_credits);
    }

    public function test_a_whatsapp_catalog_cart_is_a_paid_order(): void
    {
        $sku = $this->robe->variants->firstWhere('title', 'M')->sku ?? 'V'.$this->variant('M');
        $this->webhook(['messages' => [['from' => self::CLIENT, 'id' => 'wamid.cart', 'timestamp' => (string) now()->timestamp, 'type' => 'order',
            'order' => ['product_items' => [['product_retailer_id' => $sku, 'quantity' => 1, 'item_price' => 15000]]]]]]);
        $this->assertSame('catalog', Order::sole()->credit_channel);
    }

    public function test_a_catalog_cart_asks_the_commune_then_sends_the_recap(): void
    {
        $angre = Place::whereNull('store_id')->where('name', 'Angré')->firstOrFail();
        $this->webhook(['messages' => [['from' => self::CLIENT, 'id' => 'wamid.cart', 'timestamp' => (string) now()->timestamp, 'type' => 'order',
            'order' => ['product_items' => [['product_retailer_id' => 'V'.$this->variant('M'), 'quantity' => 1, 'item_price' => 15000]]]]],
            'contacts' => [['wa_id' => self::CLIENT, 'profile' => ['name' => 'Fatou Diallo']]]]);
        [$communes] = $this->sent();
        $this->assertStringContainsString('PANIER BIEN REÇU', $communes['interactive']['body']['text']);
        $order = Order::sole();

        $this->tap('live:place:'.$angre->id, 'Angré', 'list_reply');
        [$recap] = $this->sent();
        $this->assertSame($angre->id, $order->fresh()->shipping_place_id);
        $this->tap("live:confirm:{$order->id}", '✅ Je confirme');
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertStringContainsString('(catalogue)', $order->confirmations()->sole()->note);
    }

    public function test_switches_turn_off_the_cart_follow_up_and_the_recap(): void
    {
        $this->storeApi($this->store, 'PUT', 'whatsapp/cart-flow', ['enabled' => false])->assertOk()->assertJsonPath('data.recap.enabled', true);
        $data = $this->storeApi($this->store, 'PUT', 'whatsapp/recap', ['enabled' => false])->assertOk()->json('data');
        $this->assertFalse($data['recap']['enabled']);

        $this->webhook(['messages' => [['from' => self::CLIENT, 'id' => 'wamid.cart', 'timestamp' => (string) now()->timestamp, 'type' => 'order',
            'order' => ['product_items' => [['product_retailer_id' => 'V'.$this->variant('M'), 'quantity' => 1, 'item_price' => 15000]]]]]]);
        $this->assertSame([], $this->sent());
        $this->merchant('récap');
        $this->assertSame([], $this->sent());
        $this->assertSame(1, Order::count());
    }

    public function test_the_whatsapp_menu_shows_what_is_waiting_and_recent_orders(): void
    {
        $this->client('La robe wax M pour Angré');
        $this->merchant('récap');
        $data = $this->storeApi($this->store, 'GET', 'whatsapp/sales')->assertOk()->json('data');
        $this->assertSame(['unread' => 1, 'recaps' => 1, 'carts' => 0], $data['todo']);
        $this->assertSame(['recap'], array_column($data['orders'], 'channel'));
        $this->assertNotNull($data['phone_message_at']);
        $this->assertSame(['enabled' => true, 'remind_hours' => 2, 'expire_hours' => 24, 'waiting' => 1, 'confirmed_last_30_days' => 0], $data['recap']);
    }

    public function test_the_catalog_is_shown_on_the_number_through_meta(): void
    {
        app(Features::class)->set('whatsapp_catalog', 'on');
        $this->store->forceFill(['marketing' => ['meta_catalog_id' => '777']])->save();
        $calls = [];
        $this->meta = function (Request $request) use (&$calls) {
            if (str_contains($request->url(), '2233/product_catalogs')) {
                return Http::response(['data' => [['id' => '777', 'name' => 'Awa Mode']]]);
            }
            $calls[] = [$request->method(), $request->data()];

            return $request->method() === 'GET'
                ? Http::response(['data' => [['is_catalog_visible' => count($calls) > 1, 'is_cart_enabled' => count($calls) > 1, 'id' => '1']]])
                : Http::response(['success' => true]);
        };
        $before = $this->storeApi($this->store, 'GET', 'whatsapp/sales')->assertOk()->json('data.catalog.number');
        $this->assertSame(['catalog' => ['id' => '777', 'name' => 'Awa Mode'], 'visible' => false, 'cart' => false], $before);

        $after = $this->storeApi($this->store, 'PUT', 'whatsapp/catalog/visible', ['visible' => true])->assertOk()->json('data.catalog.number');
        $this->assertSame(['POST', ['is_catalog_visible' => 'true', 'is_cart_enabled' => 'true']], $calls[1]);
        $this->assertTrue($after['visible'] && $after['cart']);
    }

    public function test_whatsapp_orders_outside_live_are_counted_in_the_evening(): void
    {
        $credits = app(Credits::class);
        $credits->update(['paid' => true, 'welcome_orders' => 0, 'welcome_ai' => 0]);
        $this->store->forceFill(['order_credits' => 12])->save();
        $desk = app(OrderDesk::class);
        $pagne = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Pagne', 'variants' => [['price' => 5000, 'on_hand' => 50]]])->variants->sole()->id;
        $make = fn (string $channel, string $phone) => $desk->create($this->store, ['source' => 'whatsapp', 'customer_phone' => $phone, 'delivery_method' => 'delivery',
            'credit_channel' => $channel, 'items' => [['variant_id' => $pagne, 'quantity' => 1]]], null);
        $confirm = fn (Order $o) => $desk->transition($o, OrderStatus::Confirmed, null);

        $recap = $confirm($make('recap', '+2250501010101'));
        $catalog = $confirm($make('catalog', '+2250502020202'));
        $models = $confirm($make('models', '+2250503030303'));
        $fake = $confirm($make('recap', '+2250504040404'));
        $desk->cancel($fake, CancelReason::WrongNumber, null, null);
        $pending = $make('catalog', '+2250505050505');
        $manual = $confirm($desk->create($this->store, ['source' => 'whatsapp', 'customer_phone' => '+2250506060606', 'delivery_method' => 'delivery',
            'items' => [['variant_id' => $pagne, 'quantity' => 1]]], null));
        $this->assertNull($manual->credit_due);
        $this->assertNull($pending->fresh()->credit_due);

        // Même jour : rien n'est encore retiré
        $this->artisan('credits:whatsapp')->assertSuccessful();
        $this->assertSame(12, $this->store->fresh()->order_credits);

        // Le lendemain : 3 commandes retirées (la fausse ne compte pas), le jour est gardé avec le détail
        $this->travel(1)->days();
        $this->artisan('credits:whatsapp')->assertSuccessful();
        $this->assertSame(9, $this->store->fresh()->order_credits);
        $bill = OrderCreditBill::sole();
        $this->assertSame([3, 3], [$bill->orders, $bill->amount]);
        $this->assertEquals(['recap' => 1, 'catalog' => 1, 'models' => 1], $bill->detail);
        $this->assertNotNull($recap->fresh()->credit_billed_at);
        $this->assertFalse($fake->fresh()->credit_due);
        $this->assertSame(3, $credits->overview($this->store)['usage']['whatsapp_orders']);
        // Passé sous le seuil d'alerte (10) : l'équipe est prévenue
        $this->assertStringContainsString('Il te reste 9 commandes', (string) $this->store->owner->notifications()->latest()->first()?->data['title']);

        // Rejoué : rien de plus ; pendant l'illimité, une commande confirmée ne compte pas
        $this->artisan('credits:whatsapp')->assertSuccessful();
        $this->assertSame(9, $this->store->fresh()->order_credits);
        $this->store->forceFill(['live_month_until' => now()->addDays(30)])->save();
        $this->assertFalse($confirm($make('models', '+2250507070707'))->credit_due);
    }
}
