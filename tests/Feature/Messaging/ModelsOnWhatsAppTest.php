<?php

namespace Tests\Feature\Messaging;

use App\Models\Collection;
use App\Models\Conversation;
use App\Models\LiveReservation;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\VolumePrice;
use App\Models\WhatsappAccount;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use App\Services\WhatsApp\Inbox;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Modèles de la boutique sur WhatsApp (catégories, photos, « Je prends », taille, adresse, récapitulatif) et vente en
 * gros en live (« A1 x12 », tailles mélangées, lot).
 */
class ModelsOnWhatsAppTest extends TestCase
{
    use LiveProductsHavePhotos;

    private Store $store;

    private Product $robe;

    private Product $body;

    private WhatsappAccount $account;

    /** @var list<array<string, mixed>> */
    private array $out = [];

    private int $id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->giveProductsPhotos();
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/v25.0/*' => function (Request $request) {
            $this->out[] = $request->data();

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.out'.count($this->out)]]]);
        }]);
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $writer = app(CatalogWriter::class);
        $this->robe = $writer->save($this->store, null, ['title' => 'Robe wax', 'options' => [['name' => 'Taille', 'values' => ['S', 'M', 'L']]], 'variants' => [
            ['options' => ['S'], 'price' => 15000, 'on_hand' => 3], ['options' => ['M'], 'price' => 15000, 'on_hand' => 3], ['options' => ['L'], 'price' => 15000, 'on_hand' => 0],
        ]]);
        $this->body = $writer->save($this->store, null, ['title' => 'Body coton', 'options' => [['name' => 'Taille', 'values' => ['M', 'L']]], 'variants' => [
            ['options' => ['M'], 'price' => 6000, 'on_hand' => 30], ['options' => ['L'], 'price' => 6000, 'on_hand' => 30],
        ]]);
        VolumePrice::create(['store_id' => $this->store->id, 'product_id' => $this->body->id, 'min_quantity' => 6, 'price' => 30000]);
        $robes = Collection::create(['store_id' => $this->store->id, 'title' => 'Robes', 'slug' => 'robes']);
        $robes->products()->attach($this->robe->id, ['position' => 1]);
        $bodies = Collection::create(['store_id' => $this->store->id, 'title' => 'Bodies', 'slug' => 'bodies']);
        $bodies->products()->attach($this->body->id, ['position' => 1]);
        $this->actingAs($this->store->owner);
        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250700000001', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
        ])->assertOk();
        $this->account = WhatsappAccount::sole();
        $this->out = [];
    }

    private function inbound(array $message, string $from = '2250506070809'): TestResponse
    {
        $message += ['from' => $from, 'id' => 'wamid.in'.(++$this->id), 'timestamp' => (string) now()->timestamp];
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233', 'changes' => [['field' => 'messages', 'value' => [
            'messages' => [$message], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Fatou Diallo']]],
        ]]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp/'.$this->account->webhook_uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body)->assertOk();
    }

    private function text(string $body): void
    {
        $this->inbound(['type' => 'text', 'text' => ['body' => $body]]);
    }

    private function tap(string $id, string $title, string $kind = 'button_reply'): void
    {
        $this->inbound(['type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => $title]]]);
    }

    private function sent(): array
    {
        [$out, $this->out] = [$this->out, []];

        return $out;
    }

    public function test_the_customer_sees_the_models_and_orders_by_tapping(): void
    {
        $this->text('Bonsoir, vous avez quels modèles ?');
        [$menu] = $this->sent();
        $this->assertSame('list', $menu['interactive']['type']);
        $rows = collect($menu['interactive']['action']['sections'][0]['rows']);
        $this->assertSame(['Bodies', 'Robes', '✨ Nouveautés'], $rows->pluck('title')->all());
        $robes = $rows->firstWhere('title', 'Robes');
        $this->assertStringContainsString('1 modèle · dès 15', $robes['description']);

        // Catégorie touchée : une photo par modèle avec « Je prends », puis la suite / autres catégories
        $this->tap($robes['id'], 'Robes', 'list_reply');
        [$photo, $footer] = $this->sent();
        $this->assertSame('button', $photo['interactive']['type']);
        $this->assertSame('image', $photo['interactive']['header']['type']);
        $this->assertStringContainsString('*Robe wax*', $photo['interactive']['body']['text']);
        $this->assertStringContainsString('Tailles : S, M', $photo['interactive']['body']['text']);
        $this->assertStringNotContainsString('L', explode('Tailles : ', $photo['interactive']['body']['text'])[1]);
        $take = $photo['interactive']['action']['buttons'][0]['reply'];
        $this->assertSame(['models:take:'.$this->robe->id, '🛍️ Je prends'], [$take['id'], $take['title']]);
        $this->assertSame('Robes : 1 à 1 sur 1', $footer['interactive']['body']['text']);

        // « Je prends » → taille → l'adresse (liste des communes)
        $this->tap($take['id'], $take['title']);
        [$sizes] = $this->sent();
        $this->assertSame(['S', 'M'], collect($sizes['interactive']['action']['buttons'])->pluck('reply.title')->all());
        $m = $this->robe->variants->firstWhere('title', 'M');
        $this->tap('models:var:'.$m->id, 'M');
        [$noted, $address] = $this->sent();
        $this->assertStringContainsString("✅ *C'est noté !*\nRobe wax — M", $noted['text']['body']);
        $this->assertStringStartsWith("📍 *DERNIÈRE ÉTAPE : L'ADRESSE*", $address['interactive']['body']['text']);

        // Quartier touché : commande WhatsApp créée (pas une commande du live), puis le récapitulatif
        $angre = Place::whereNull('store_id')->where('name', 'Angré')->firstOrFail();
        $this->tap('live:place:'.$angre->id, 'Angré', 'list_reply');
        $order = Order::with('items')->sole();
        $this->assertSame('whatsapp', $order->source->value);
        $this->assertSame('WhatsApp : commandé depuis les modèles', $order->internal_note);
        $this->assertSame($m->id, $order->items[0]->variant_id);
        $this->assertSame(0, LiveReservation::count());
        $recap = collect($this->sent())->last();
        $this->assertStringContainsString('live:confirm:'.$order->id, json_encode($recap));

        // Un autre modèle s'ajoute à la même commande
        $this->tap('models:var:'.$this->robe->variants->firstWhere('title', 'S')->id, 'S');
        $this->assertSame(2, $order->fresh()->items()->count());
        $this->assertSame(1, Order::count());
    }

    public function test_wholesale_quantity_and_pause_after_a_human_reply(): void
    {
        $this->tap('models:take:'.$this->body->id, '🛍️ Je prends');
        $this->sent();
        $l = $this->body->variants->firstWhere('title', 'L');
        $this->tap('models:var:'.$l->id, 'L');
        [$qty] = $this->sent();
        $this->assertSame('Combien de pièces ?', strtok($qty['interactive']['body']['text'], "\n"));
        $this->assertSame(['1', '6 (gros)'], collect($qty['interactive']['action']['buttons'])->pluck('reply.title')->all());
        // Nombre écrit à la main
        $this->text('12');
        $angre = Place::whereNull('store_id')->where('name', 'Angré')->firstOrFail();
        $this->sent();
        $this->tap('live:place:'.$angre->id, 'Angré', 'list_reply');
        $item = Order::with('items')->sole()->items[0];
        $this->assertSame([12, 60000], [$item->quantity, $item->total]);

        // Réponse de l'équipe : l'assistant se tait (le message reste pour l'équipe)
        $conversation = Conversation::sole();
        app(Inbox::class)->reply($conversation, 'Bonjour, je vous réponds', $this->store->owner);
        $this->sent();
        $this->text('modèles');
        $this->assertSame([], $this->sent());
    }

    public function test_wholesale_in_live(): void
    {
        $this->body->forceFill(['sale_lot' => 6])->save();
        $id = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live gros', 'hold_minutes' => 30, 'items' => [
            ['variant_id' => $this->body->variants[0]->id, 'price' => 6000, 'quantity' => 20],
            ['variant_id' => $this->body->variants[1]->id, 'price' => 6000, 'quantity' => 20],
        ]])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$id}/start")->assertOk();
        $this->sent();

        // Tailles mélangées avec la quantité : 6 M et 6 L gardées, prix de gros annoncé
        $this->text('A1 6 M 6 L');
        $texts = collect($this->sent())->pluck('text.body')->filter()->implode("\n");
        $this->assertSame(12, LiveReservation::where('status', 'reserved')->count());
        $this->assertStringContainsString('💰 *Prix de gros : 60', $texts);

        $angre = Place::whereNull('store_id')->where('name', 'Angré')->firstOrFail();
        $this->tap('live:place:'.$angre->id, 'Angré', 'list_reply');
        $order = Order::with('items')->sole();
        $this->assertSame(60000, (int) $order->items->sum('total'));

        // Lot de 6 : 4 demandées → 6, annoncé au client
        $this->sent();
        $this->inbound(['type' => 'text', 'text' => ['body' => 'A1 x4 M']], '2250102030405');
        $texts = collect($this->sent())->pluck('text.body')->filter()->implode("\n");
        $this->assertStringContainsString('📦 A1 se vend par lot de 6 : je vous en mets 6.', $texts);
        $this->assertSame(6, LiveReservation::where('customer_phone', '+2250102030405')->count());
    }
}
