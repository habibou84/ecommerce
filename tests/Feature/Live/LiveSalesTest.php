<?php

namespace Tests\Feature\Live;

use App\Models\ConversationMessage;
use App\Models\LiveReservation;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Ai\AiClient;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveSales;
use App\Services\Places\Places;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Vente en live : préparation (codes, prix, pièces), réservation sur la page du live (commande créée), par WhatsApp
 * (code, puis quartier), liste d'attente servie dans l'ordre, pièce libérée sans quartier à temps, bilan.
 */
class LiveSalesTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private Product $sac;

    /** @var list<string> */
    private array $whatsapp = [];

    /** @var list<string> */
    private array $paces = [];

    private FakeAiClient $ai;

    /** Image PNG de 1 × 1 pixel (capture simulée) */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_bridge' => ['url' => 'http://127.0.0.1:3210', 'token' => 'bridge-secret']]);
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:3210/sessions/*/start' => Http::response(['session' => 'x', 'status' => 'qr', 'qr' => 'data:image/png;base64,QR']),
            'http://127.0.0.1:3210/sessions/*/media' => fn () => Http::response(['mime' => 'image/png', 'data' => self::PNG]),
            'http://127.0.0.1:3210/sessions/*/send' => function ($request) {
                $this->whatsapp[] = $request->data()['text'] ?? '';
                $this->paces[] = $request->data()['pace'] ?? 'normal';

                return Http::response(['id' => 'qr-'.count($this->whatsapp)], 202);
            },
            'http://127.0.0.1:3210/sessions/*' => Http::response(['status' => 'connected', 'phone' => '+2250700000001']),
        ]);
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->sac = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac cabas', 'variants' => [['price' => 8000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function prepare(int $robes = 1): int
    {
        $id = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live du soir', 'hold_minutes' => 30, 'items' => [
            ['variant_id' => $this->robe->variants()->value('id'), 'price' => 12000, 'quantity' => $robes],
            ['variant_id' => $this->sac->variants()->value('id'), 'quantity' => 5],
        ]])->assertCreated()->assertJsonPath('data.items.0.code', 'A1')->assertJsonPath('data.items.1.code', 'A2')
            ->assertJsonPath('data.items.1.price', 8000)->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$id}/start")->assertOk()->assertJsonPath('data.status', 'live');

        return $id;
    }

    private function page(string $method, string $path, array $data = []): TestResponse
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->json($method, $this->storeUrl($this->store, $path), $data);
    }

    private function bridge(array $event): TestResponse
    {
        $body = json_encode($event + ['session' => 'store-'.$this->store->id]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, 'bridge-secret'),
        ], $body);
    }

    private function message(string $id, string $text, string $from = '2250506070809'): void
    {
        $this->bridge(['type' => 'message', 'id' => $id, 'from' => $from, 'name' => 'Fatou', 'kind' => 'text', 'text' => $text, 'at' => now()->timestamp])->assertOk();
    }

    private function place(string $name): Place
    {
        return Place::whereNull('store_id')->where('name', $name)->firstOrFail();
    }

    public function test_the_live_page_reserves_in_two_taps_and_creates_the_order(): void
    {
        $this->get($this->storeUrl($this->store, '/live'))->assertOk()->assertSee('Pas de live en ce moment');
        $id = $this->prepare(robes: 1);
        $items = $this->page('GET', '/live/etat')->assertOk()->assertJsonPath('data.items.0.remaining', 1)->json('data.items');
        $this->get($this->storeUrl($this->store, '/live'))->assertOk()->assertSee('En direct maintenant')->assertSee('A1');

        $angre = $this->place('Angré');
        $this->page('GET', '/live/lieux?q=angre')->assertOk()->assertJsonPath('data.0.id', $angre->id);
        $this->page('POST', '/live/reserver', ['item_id' => $items[0]['id'], 'name' => 'Fatou', 'phone' => '05 06 07 08 09', 'place_id' => $angre->id, 'address' => 'Face pharmacie'])
            ->assertOk()->assertJsonPath('data.status', 'ordered');
        $order = Order::forStore($this->store->id)->with('items')->sole();
        $this->assertSame(['live', '+2250506070809', $angre->id, 12000], [$order->source->value, $order->customer_phone, $order->shipping_place_id, (int) $order->items->sum('total')]);
        $this->assertStringContainsString('A1', $order->internal_note);

        // Même client, même article : pas de deuxième commande ; un autre client passe en liste d'attente
        $this->page('POST', '/live/reserver', ['item_id' => $items[0]['id'], 'name' => 'Fatou', 'phone' => '0506070809', 'place_id' => $angre->id])->assertOk();
        $this->assertSame(1, Order::count());
        $this->page('POST', '/live/reserver', ['item_id' => $items[0]['id'], 'name' => 'Awa', 'phone' => '0701020304', 'place_id' => $angre->id])
            ->assertOk()->assertJsonPath('data.status', 'waiting');
        $this->page('GET', '/live/etat')->assertJsonPath('data.items.0.remaining', 0)->assertJsonPath('data.items.0.waiting', 1);
        $this->page('POST', '/live/reserver', ['item_id' => $items[0]['id'], 'name' => 'X', 'phone' => '12', 'place_id' => $angre->id])->assertStatus(422);

        // La commande de Fatou est annulée : la pièce va à Awa, dont la commande est créée
        $order->forceFill(['status' => 'cancelled'])->save();
        app(LiveSales::class)->promote(LiveReservation::where('customer_phone', '+2250701020304')->sole()->item);
        $this->assertSame(['ordered'], LiveReservation::where('customer_phone', '+2250701020304')->pluck('status')->all());
        $this->assertSame(2, Order::count());

        // Fin du live : la page ne propose plus rien, le bilan reste
        $this->storeApi($this->store, 'POST', "lives/{$id}/end")->assertOk()->assertJsonPath('data.summary.orders', 2);
        $this->page('POST', '/live/reserver', ['item_id' => $items[1]['id'], 'name' => 'Fatou', 'phone' => '0506070809', 'place_id' => $angre->id])->assertNotFound();
    }

    public function test_the_live_page_takes_several_items_and_pieces_in_one_order(): void
    {
        $this->prepare(robes: 1);
        [$robe, $sac] = $this->page('GET', '/live/etat')->json('data.items');
        $angre = $this->place('Angré');
        $customer = ['name' => 'Fatou', 'phone' => '0506070809', 'place_id' => $angre->id];

        // Une robe et trois sacs : une seule commande, une ligne par article
        $this->page('POST', '/live/reserver', $customer + ['items' => [['item_id' => $robe['id'], 'quantity' => 1], ['item_id' => $sac['id'], 'quantity' => 3]]])
            ->assertOk()->assertJsonPath('data.status', 'ordered')->assertJsonPath('data.reserved', 4)->assertJsonPath('data.waiting', [])
            ->assertJsonPath('message', fn ($m) => str_contains($m, '4 articles'));
        $order = Order::forStore($this->store->id)->with('items')->sole();
        $this->assertSame([12000 + 3 * 8000, [1, 3]], [(int) $order->items->sum('total'), $order->items->sortBy('quantity')->pluck('quantity')->map(fn ($q) => (int) $q)->values()->all()]);
        $this->assertStringContainsString('A1, A2 ×3', $order->internal_note);
        $this->page('GET', '/live/etat')->assertJsonPath('data.items.0.remaining', 0)->assertJsonPath('data.items.1.remaining', 2);

        // Autre client : la robe est partie, deux sacs sur trois restent : commande des deux, le reste en attente
        $this->page('POST', '/live/reserver', ['name' => 'Awa', 'phone' => '0701020304', 'place_id' => $angre->id,
            'items' => [['item_id' => $robe['id'], 'quantity' => 1], ['item_id' => $sac['id'], 'quantity' => 3]]])
            ->assertOk()->assertJsonPath('data.reserved', 2)->assertJsonPath('data.waiting', ['A1', 'A2'])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Déjà parti : A1, A2'));
        $this->assertSame([2, 16000], [Order::count(), (int) Order::latest('id')->first()->items->sum('total')]);
        $this->page('GET', '/live/etat')->assertJsonPath('data.items.0.waiting', 1)->assertJsonPath('data.items.1.waiting', 1);

        // Tout est parti : liste d'attente seulement ; trop de pièces ou article inconnu refusés
        $this->page('POST', '/live/reserver', $customer + ['items' => [['item_id' => $sac['id'], 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.order', null);
        $this->page('POST', '/live/reserver', $customer + ['items' => [['item_id' => $sac['id'], 'quantity' => 11]]])->assertStatus(422);
        $this->page('POST', '/live/reserver', $customer + ['items' => [['item_id' => 999999, 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame(2, Order::count());
    }

    public function test_whatsapp_codes_reserve_then_the_neighbourhood_creates_the_order(): void
    {
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();
        $id = $this->prepare(robes: 1);

        // « a1 et A2 svp » : deux pièces gardées, quartier demandé
        $this->message('M1', 'Je prends a1 et A2 svp');
        $this->assertCount(1, $this->whatsapp);
        $this->assertStringContainsString('A1', $this->whatsapp[0]);
        $this->assertStringContainsString('répondez avec votre quartier', $this->whatsapp[0]);
        $this->assertSame(['reserved', 'reserved'], LiveReservation::orderBy('id')->pluck('status')->all());
        $this->assertSame(0, Order::count());

        // Quelqu'un d'autre veut la A1 : liste d'attente
        $this->message('M2', 'A1', '2250701020304');
        $this->assertStringContainsString('liste d\'attente', end($this->whatsapp));

        // Une question n'est pas un quartier : elle reste à l'équipe
        $this->message('M3a', 'C\'est combien la livraison ?');
        $this->assertSame(0, Order::count());

        // Le quartier arrive : une seule commande pour les deux articles
        $this->message('M3', 'Yopougon, carrefour Siporex');
        $order = Order::forStore($this->store->id)->with('items')->sole();
        $this->assertSame([20000, 'live', 'Yopougon, carrefour Siporex'], [(int) $order->items->sum('total'), $order->source->value, $order->shipping_address]);
        $this->assertNotNull($order->shipping_place_id);
        $this->assertStringContainsString($order->reference(), end($this->whatsapp));
        $this->assertSame(0, ConversationMessage::where('type', 'auto')->where('body', 'like', '%IA%')->count());

        // Bilan de l'équipe
        $this->storeApi($this->store, 'GET', "lives/{$id}")->assertOk()
            ->assertJsonPath('data.summary.orders', 1)->assertJsonPath('data.summary.amount', 20000)->assertJsonPath('data.summary.waiting', 1);
    }

    public function test_a_kept_piece_without_neighbourhood_goes_to_the_waiting_list(): void
    {
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();
        $id = $this->prepare(robes: 1);
        $this->message('M1', 'A1');
        $this->message('M2', 'A1', '2250701020304');

        // Mode assistant : pseudo vu dans les commentaires, sans numéro → attente aussi
        $this->storeApi($this->store, 'POST', "lives/{$id}/reservations", ['code' => 'a1', 'name' => 'mimi_225'])->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$id}/reservations", ['code' => 'Z9', 'name' => 'mimi_225'])->assertStatus(422);

        $this->travel(31)->minutes();
        $this->artisan('live:expire')->assertSuccessful();
        $this->assertSame(['expired', 'reserved', 'waiting'], LiveReservation::orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('s\'est libérée', end($this->whatsapp));

        // Le suivant répond avec son quartier : commande
        $this->message('M3', 'Angré 8e tranche', '2250701020304');
        $this->assertSame('+2250701020304', Order::sole()->customer_phone);

        // Retirer un article réservé : refusé ; annuler la place de mimi
        $this->storeApi($this->store, 'PUT', "lives/{$id}", ['title' => 'Live du soir', 'items' => [['variant_id' => $this->sac->variants()->value('id'), 'quantity' => 5]]])->assertStatus(422);
        $mimi = LiveReservation::where('customer_name', 'mimi_225')->sole();
        $this->storeApi($this->store, 'POST', "lives/{$id}/reservations/{$mimi->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $mimi->fresh()->status);
    }

    public function test_a_screenshot_sent_on_whatsapp_is_read_and_reserves_the_article(): void
    {
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();
        $id = $this->prepare(robes: 1);

        // Capture où l'on voit « A2 » : lue par l'IA, l'article est réservé et le quartier demandé (réponse espacée)
        $this->ai->respond(['code' => 'A2']);
        $this->bridge(['type' => 'message', 'id' => 'IMG1', 'from' => '2250506070809', 'name' => 'Fatou', 'kind' => 'image', 'text' => null, 'at' => now()->timestamp])->assertOk();
        $this->assertSame(['reserved'], LiveReservation::pluck('status')->all());
        $this->assertSame('A2', LiveReservation::sole()->item->code);
        $this->assertStringContainsString('A2', end($this->whatsapp));
        $this->assertSame('slow', end($this->paces));
        $this->assertStringContainsString('A1 : ', $this->ai->calls[0]['system']);

        // Légende avec le code : pas besoin de l'IA
        $this->bridge(['type' => 'message', 'id' => 'IMG2', 'from' => '2250701020304', 'kind' => 'image', 'text' => 'A1 svp', 'at' => now()->timestamp])->assertOk();
        $this->assertCount(1, $this->ai->calls);
        $this->assertSame(2, LiveReservation::count());

        // Code illisible : on demande au client de l'écrire
        $this->ai->respond(['code' => null]);
        $this->bridge(['type' => 'message', 'id' => 'IMG3', 'from' => '2250708091011', 'kind' => 'image', 'text' => null, 'at' => now()->timestamp])->assertOk();
        $this->assertStringContainsString('pas trouvé le code', end($this->whatsapp));
        $this->assertSame(2, LiveReservation::count());

        // Écran de l'équipe : WhatsApp relié par QR code
        $this->storeApi($this->store, 'GET', "lives/{$id}")->assertJsonPath('data.whatsapp', 'link');

        // Sans live en cours, une photo reste un message ordinaire
        $this->storeApi($this->store, 'POST', "lives/{$id}/end")->assertOk();
        $this->bridge(['type' => 'message', 'id' => 'IMG4', 'from' => '2250506070809', 'kind' => 'image', 'text' => null, 'at' => now()->timestamp])->assertOk();
        $this->assertCount(2, $this->ai->calls);
    }
}
