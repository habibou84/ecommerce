<?php

namespace Tests\Feature\Live;

use App\Models\ConversationMessage;
use App\Models\LiveReservation;
use App\Models\LiveSession;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Ai\AiClient;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveSales;
use App\Services\Live\LiveScreens;
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
        // WhatsApp par QR code autorisé pour la boutique (console)
        $this->store->forceFill(['whatsapp_qr_enabled' => true])->save();
        // L'assistant IA n'est compris dans aucune offre : actions rechargées
        $this->store->forceFill(['ai_credits' => 100])->save();
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
        // Aucun WhatsApp relié : la page « Vendre en live » propose de le relier
        $this->storeApi($this->store, 'GET', 'lives')->assertOk()->assertJsonPath('meta.whatsapp', null);
        $items = $this->page('GET', '/live/etat')->assertOk()->assertJsonPath('data.items.0.remaining', 1)->json('data.items');
        $this->get($this->storeUrl($this->store, '/live'))->assertOk()->assertSee('EN DIRECT')->assertSee('A1')->assertSee('Propulsé par')->assertSee('Jibiat Marchand');

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

    public function test_flash_price_for_a_few_minutes_is_kept_by_the_reservation(): void
    {
        $id = $this->prepare(robes: 3);
        $items = $this->storeApi($this->store, 'GET', "lives/{$id}")->json('data.items');
        $angre = $this->place('Angré');

        // Prix live plus bas que le prix du live, pour 5 minutes : l'article est montré, le chrono part
        $this->storeApi($this->store, 'POST', "lives/{$id}/items/{$items[0]['id']}/flash", ['price' => 12000, 'minutes' => 5])->assertStatus(422);
        $state = $this->storeApi($this->store, 'POST', "lives/{$id}/items/{$items[0]['id']}/flash", ['price' => 9900, 'minutes' => 5])->assertOk()
            ->assertJsonPath('data.items.0.price', 9900)->assertJsonPath('data.items.0.flash.was', 12000)->assertJsonPath('data.featured_item_id', $items[0]['id']);
        $this->assertNotNull($state->json('data.items.0.flash.ends_at'));
        $this->page('GET', '/live/etat')->assertJsonPath('data.items.0.price', 9900);
        $live = app(LiveScreens::class)->live(LiveSession::findOrFail($id));
        $this->assertSame([9900, 15000], [$live['items'][0]['price'], $live['items'][0]['was']]); // prix catalogue barré
        $this->assertNotNull($live['items'][0]['flash_ends_at']);

        // Prise pendant le chrono : commande au prix flash ; après le chrono, retour au prix du live
        $this->page('POST', '/live/reserver', ['item_id' => $items[0]['id'], 'name' => 'Fatou', 'phone' => '0506070809', 'place_id' => $angre->id])->assertOk();
        $this->assertSame(9900, (int) Order::forStore($this->store->id)->with('items')->sole()->items->sum('total'));
        $this->travel(6)->minutes();
        $this->page('GET', '/live/etat')->assertJsonPath('data.items.0.price', 12000);
        $this->page('POST', '/live/reserver', ['item_id' => $items[0]['id'], 'name' => 'Awa', 'phone' => '0701020304', 'place_id' => $angre->id])->assertOk();
        $this->assertSame([9900, 12000], LiveReservation::orderBy('id')->pluck('unit_price')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(21900, $this->storeApi($this->store, 'GET', "lives/{$id}")->json('data.summary.amount'));

        // Arrêter une vente flash en cours
        $this->storeApi($this->store, 'POST', "lives/{$id}/items/{$items[1]['id']}/flash", ['price' => 7000, 'minutes' => 2])->assertOk()->assertJsonPath('data.items.1.price', 7000);
        $this->storeApi($this->store, 'POST', "lives/{$id}/items/{$items[1]['id']}/flash", ['price' => null])->assertOk()->assertJsonPath('data.items.1.price', 8000);
    }

    public function test_stream_link_featured_item_summary_and_redo(): void
    {
        $robe = $this->robe->variants()->value('id');
        $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live', 'stream_url' => 'pas un lien', 'items' => [['variant_id' => $robe, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('stream_url');
        $id = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live du soir', 'stream_url' => 'https://www.tiktok.com/@awamode/live', 'items' => [
            ['variant_id' => $robe, 'price' => 12000, 'quantity' => 1],
            ['variant_id' => $this->sac->variants()->value('id'), 'quantity' => 5],
        ]])->assertCreated()->assertJsonPath('data.stream_url', 'https://www.tiktok.com/@awamode/live')->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$id}/start")->assertOk();
        $items = $this->storeApi($this->store, 'GET', "lives/{$id}")->json('data.items');

        // Article montré : mis en avant sur la page du client ; un article d'un autre live est refusé
        $this->storeApi($this->store, 'POST', "lives/{$id}/feature", ['item_id' => 999999])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "lives/{$id}/feature", ['item_id' => $items[1]['id']])->assertOk()->assertJsonPath('data.featured_item_id', $items[1]['id']);
        $this->page('GET', '/live/etat')->assertJsonPath('data.featured_item_id', $items[1]['id'])->assertJsonPath('data.stream_url', 'https://www.tiktok.com/@awamode/live')
            ->assertJsonPath('data.summary', null);
        $this->get($this->storeUrl($this->store, '/live'))->assertOk()->assertSee('Regarder le live')->assertSee('Présenté en ce moment');

        // Bilan : articles épuisés, comparaison avec le live précédent
        $this->page('POST', '/live/reserver', ['item_id' => $items[0]['id'], 'name' => 'Fatou', 'phone' => '0506070809', 'place_id' => $this->place('Angré')->id])->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$id}/end")->assertOk()->assertJsonPath('data.summary.sold_out', 1)
            ->assertJsonPath('data.summary.amount', 12000)->assertJsonPath('data.previous_amount', null);
        $this->storeApi($this->store, 'POST', "lives/{$id}/feature", ['item_id' => null])->assertStatus(422);
        $this->storeApi($this->store, 'GET', 'lives')->assertJsonPath('data.0.minutes', 0);

        // Refaire ce live : nouveau live en préparation, mêmes articles et prix, même lien
        $copy = $this->storeApi($this->store, 'POST', "lives/{$id}/duplicate")->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.items.0.price', 12000)->assertJsonPath('data.items.1.code', 'A2')
            ->assertJsonPath('data.stream_url', 'https://www.tiktok.com/@awamode/live')->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$copy}/start")->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$copy}/end")->assertOk()->assertJsonPath('data.previous_amount', 12000);
    }

    public function test_the_dashboard_puts_the_live_first(): void
    {
        // Aucun live : invitation à en préparer un
        $this->storeApi($this->store, 'GET', 'dashboard')->assertOk()->assertJsonPath('data.live.state', 'idle')
            ->assertJsonPath('data.live.session', null)->assertJsonPath('data.live.last', null);

        // Live préparé, pas encore lancé
        $draft = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Arrivage wax', 'hold_minutes' => 30, 'items' => [
            ['variant_id' => $this->robe->variants()->value('id'), 'quantity' => 1],
        ]])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'GET', 'dashboard')->assertJsonPath('data.live.state', 'ready')
            ->assertJsonPath('data.live.session.id', $draft)->assertJsonPath('data.live.session.items_count', 1);

        // En direct : montant vendu, réservations, articles épuisés
        $this->storeApi($this->store, 'POST', "lives/{$draft}/start")->assertOk();
        $item = $this->page('GET', '/live/etat')->json('data.items.0.id');
        $this->page('POST', '/live/reserver', ['item_id' => $item, 'name' => 'Fatou', 'phone' => '0506070809', 'place_id' => $this->place('Angré')->id])->assertOk();
        $this->storeApi($this->store, 'GET', 'dashboard')->assertJsonPath('data.live.state', 'live')
            ->assertJsonPath('data.live.session.summary.amount', 15000)->assertJsonPath('data.live.session.summary.reservations', 1)
            ->assertJsonPath('data.live.session.sold_out', 1);

        // Terminé : de nouveau l'invitation, avec le résultat du dernier live
        $this->storeApi($this->store, 'POST', "lives/{$draft}/end")->assertOk();
        $this->storeApi($this->store, 'GET', 'dashboard')->assertJsonPath('data.live.state', 'idle')
            ->assertJsonPath('data.live.last.id', $draft)->assertJsonPath('data.live.last.orders', 1)->assertJsonPath('data.live.last.amount', 15000);
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

    public function test_items_are_added_during_the_live_without_changing_announced_codes(): void
    {
        $id = $this->prepare(robes: 1);
        [$robe] = $this->page('GET', '/live/etat')->json('data.items');
        $angre = $this->place('Angré');
        $this->page('POST', '/live/reserver', ['item_id' => $robe['id'], 'name' => 'Fatou', 'phone' => '0506070809', 'place_id' => $angre->id])->assertOk();
        $this->page('POST', '/live/reserver', ['item_id' => $robe['id'], 'name' => 'Awa', 'phone' => '0701020304', 'place_id' => $angre->id])
            ->assertJsonPath('data.status', 'waiting');
        $foulard = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Foulard', 'variants' => [['price' => 4500, 'on_hand' => 10]]]);

        // Modifier la liste pendant le live renumérotait les codes : refusé, on ajoute à la place
        $this->storeApi($this->store, 'PUT', "lives/{$id}", ['title' => 'Live du soir', 'items' => [['variant_id' => $foulard->variants()->value('id'), 'quantity' => 1]]])
            ->assertStatus(422);

        // Nouvel article : code suivant (A3) ; deux robes en plus : A1 garde son code et Awa est servie
        $this->storeApi($this->store, 'POST', "lives/{$id}/items", ['items' => [
            ['variant_id' => $foulard->variants()->value('id'), 'price' => 4000, 'quantity' => 3],
            ['variant_id' => $this->robe->variants()->value('id'), 'quantity' => 2],
        ]])->assertOk()->assertJsonPath('meta.codes', ['A3', 'A1'])
            ->assertJsonPath('data.items.0.code', 'A1')->assertJsonPath('data.items.0.quantity', 3)->assertJsonPath('data.items.0.remaining', 1)
            ->assertJsonPath('data.items.1.code', 'A2')->assertJsonPath('data.items.2.code', 'A3')->assertJsonPath('data.items.2.price', 4000);
        $this->assertSame(['ordered'], LiveReservation::where('customer_phone', '+2250701020304')->pluck('status')->all());
        $this->page('GET', '/live/etat')->assertJsonPath('data.items.2.remaining', 3);

        // Live terminé : plus d'ajout
        $this->storeApi($this->store, 'POST', "lives/{$id}/end")->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$id}/items", ['items' => [['variant_id' => $foulard->variants()->value('id'), 'quantity' => 1]]])->assertStatus(422);
    }

    public function test_whatsapp_codes_with_quantities_and_new_codes_join_the_pending_order(): void
    {
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();
        $this->prepare(robes: 2);

        $this->assertSame(['A2' => 3, 'A1' => 1], LiveSales::quantities('A2 x3 et A1'));
        $this->assertSame(['A2' => 2, 'A3' => 4], LiveSales::quantities('je prends 2 A2 puis A3 4 pièces'));
        $this->assertSame(['A2' => 3], LiveSales::quantities('3xA2'));
        $this->assertSame(['A1' => 1], LiveSales::quantities('A1, 15000 F, Riviera 3'));

        // « A2 x3 » : trois sacs gardés ; renvoyer le même message ne réserve rien de plus
        $this->message('Q1', 'A2 x3 svp');
        $this->assertStringContainsString('× 3 : réservées', end($this->whatsapp));
        $this->message('Q2', 'A2 x3 svp');
        $this->assertSame(3, LiveReservation::count());

        $this->message('Q3', 'Yopougon, carrefour Siporex');
        $order = Order::forStore($this->store->id)->with('items')->sole();
        $this->assertSame([3, 24000], [(int) $order->items->sole()->quantity, (int) $order->items->sum('total')]);

        // Nouveau code après la commande : ajouté à la même commande, sans redemander le quartier
        $this->message('Q4', '2 A1');
        $this->assertStringContainsString("Ajouté à votre commande {$order->reference()}", end($this->whatsapp));
        $this->assertSame(1, Order::count());
        $order->refresh()->load('items');
        $this->assertSame([24000 + 2 * 12000, 'Yopougon, carrefour Siporex'], [(int) $order->items->sum('total'), $order->shipping_address]);
        $this->assertSame(['ordered'], LiveReservation::distinct()->pluck('status')->all());

        // Commande confirmée par l'équipe mais pas encore partie : une pièce de plus s'y ajoute (une seule livraison),
        // et le même message renvoyé n'ajoute rien
        $order->forceFill(['status' => 'confirmed'])->save();
        $before = LiveReservation::where('customer_phone', '+2250506070809')->count();
        $this->message('Q5', 'A2 x4');
        $this->assertStringContainsString("Ajouté à votre commande {$order->reference()}", end($this->whatsapp));
        $this->assertSame([1, $before + 1], [Order::count(), LiveReservation::where('customer_phone', '+2250506070809')->count()]);
        $this->message('Q6', 'A2 x4');
        $this->assertSame($before + 1, LiveReservation::where('customer_phone', '+2250506070809')->count());
    }

    public function test_one_code_per_product_with_sizes_and_colours_read_or_asked_on_whatsapp(): void
    {
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->bridge(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001'])->assertOk();
        $ensemble = app(CatalogWriter::class)->save($this->store, null, [
            'title' => 'Ensemble pagne',
            'options' => [['name' => 'Taille', 'values' => ['S', 'M', 'L']], ['name' => 'Couleur', 'values' => ['Rouge', 'Bleu']]],
            'variants' => [
                ['options' => ['S', 'Rouge'], 'price' => 20000, 'on_hand' => 5],
                ['options' => ['M', 'Rouge'], 'price' => 20000, 'on_hand' => 5],
                ['options' => ['L', 'Rouge'], 'price' => 20000, 'on_hand' => 5],
                ['options' => ['L', 'Bleu'], 'price' => 20000, 'on_hand' => 5],
            ],
        ]);
        $variant = fn (string $title) => $ensemble->variants()->where('title', $title)->value('id');

        // Préparation : la robe puis toutes les variantes de l'ensemble → un seul code pour l'ensemble (A2)
        $id = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live tailles', 'items' => [
            ['variant_id' => $this->robe->variants()->value('id'), 'quantity' => 2],
            ['variant_id' => $variant('S / Rouge'), 'quantity' => 2],
            ['variant_id' => $variant('M / Rouge'), 'quantity' => 1],
            ['variant_id' => $variant('L / Rouge'), 'quantity' => 1],
            ['variant_id' => $variant('L / Bleu'), 'quantity' => 1],
        ]])->assertCreated()->json('data.id');
        $this->assertSame(['A1', 'A2', 'A2', 'A2', 'A2'], array_column($this->storeApi($this->store, 'GET', "lives/{$id}")->json('data.items'), 'code'));
        $this->storeApi($this->store, 'POST', "lives/{$id}/start")->assertOk();

        // Tout dit dans le message : « A2 rouge L » garde la bonne variante
        $this->message('V1', 'A2 rouge L svp');
        $this->assertStringContainsString('L / Rouge', end($this->whatsapp));
        $this->assertSame('L / Rouge', LiveReservation::with('item.variant')->sole()->item->variant->title);

        // Seulement le code : « Quelle taille ? » avec ce qui reste (la L rouge est partie : L reste en bleu)
        $this->message('V2', 'A2', '2250701020304');
        $this->assertStringContainsString('Quelle taille ?', end($this->whatsapp));
        $this->assertStringContainsString('S, M ou L', end($this->whatsapp));
        $this->message('V3', 'L', '2250701020304');
        // Une seule variante L reste (bleue) : gardée sans demander la couleur
        $this->assertSame('L / Bleu', LiveReservation::with('item.variant')->where('customer_phone', '+2250701020304')->sole()->item->variant->title);

        // Taille puis couleur demandées l'une après l'autre ; une réponse hors des choix est rappelée
        $this->message('V4', 'A2', '2250702030405');
        $this->message('V5', 'XXL', '2250702030405');
        $this->assertStringContainsString('Répondez avec un de ces choix', end($this->whatsapp));
        $this->message('V6', 's', '2250702030405');
        $this->assertSame('S / Rouge', LiveReservation::with('item.variant')->where('customer_phone', '+2250702030405')->sole()->item->variant->title);

        // Écran : une carte pour l'ensemble, tailles avec les pièces qui restent ; dernière pièce prise : affichée
        $live = app(LiveScreens::class)->live(LiveSession::findOrFail($id));
        $this->assertSame(['A1', 'A2'], array_column($live['items'], 'code'));
        $sizes = $live['items'][1]['options'][0];
        $this->assertSame(['Taille', ['S' => 1, 'M' => 1, 'L' => 0]], [$sizes['name'], array_column($sizes['values'], 'remaining', 'value')]);
        $this->assertSame(2, $live['items'][1]['remaining']);
        $this->assertStringContainsString('vient de prendre A2 (S, Rouge)', $live['ticker'][0]['text']);

        // Page du live : une carte par produit avec les tailles à toucher
        $this->get($this->storeUrl($this->store, '/live'))->assertOk()->assertSee('data-live-group="A2"', false)->assertSee('data-value="M"', false);

        // Prix flash sur la carte : toutes les variantes du produit
        $this->storeApi($this->store, 'POST', "lives/{$id}/items/{$live['items'][1]['id']}/flash", ['price' => 15000, 'minutes' => 5])->assertOk();
        $this->assertSame([15000], array_values(array_unique(array_column(array_filter($this->page('GET', '/live/etat')->json('data.items'), fn ($i) => $i['code'] === 'A2'), 'price'))));
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
        $this->storeApi($this->store, 'GET', 'lives')->assertJsonPath('meta.whatsapp', 'link');

        // Sans live en cours, une photo reste un message ordinaire
        $this->storeApi($this->store, 'POST', "lives/{$id}/end")->assertOk();
        $this->bridge(['type' => 'message', 'id' => 'IMG4', 'from' => '2250506070809', 'kind' => 'image', 'text' => null, 'at' => now()->timestamp])->assertOk();
        $this->assertCount(2, $this->ai->calls);
    }
}
