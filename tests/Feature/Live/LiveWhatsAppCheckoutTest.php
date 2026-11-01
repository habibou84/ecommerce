<?php

namespace Tests\Feature\Live;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Commande du live sur le WhatsApp officiel, en touchant : photo de l'article réservé, commune puis quartier choisis
 * dans une liste avec le prix de la livraison, récapitulatif en image avec « Je confirme », adresse changée, article
 * ajouté à la commande confirmée, rappel avant de libérer une pièce.
 */
class LiveWhatsAppCheckoutTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private Product $sac;

    private WhatsappAccount $account;

    /** @var list<array<string, mixed>> messages envoyés à Meta */
    private array $out = [];

    private int $id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/v25.0/*' => function (Request $request) {
            $this->out[] = $request->data();

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.out'.count($this->out)]]]);
        }]);
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->sac = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac cabas', 'variants' => [['price' => 8000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
        // Photo de la robe (WebP dans le catalogue, envoyée en JPEG sur WhatsApp)
        $this->withCredentials()->withHeaders(['Referer' => $this->storeUrl($this->store, '/gestion')])
            ->post($this->storeUrl($this->store, "/api/v1/products/{$this->robe->id}/images"), ['image' => UploadedFile::fake()->image('robe.jpg', 900, 1200)], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250700000001', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
        ])->assertOk();
        $this->account = WhatsappAccount::sole();
        $id = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live du soir', 'hold_minutes' => 30, 'items' => [
            ['variant_id' => $this->robe->variants()->value('id'), 'price' => 12000, 'quantity' => 3],
            ['variant_id' => $this->sac->variants()->value('id'), 'quantity' => 5],
        ]])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'POST', "lives/{$id}/start")->assertOk();
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

    private function text(string $body, string $from = '2250506070809'): void
    {
        $this->inbound(['type' => 'text', 'text' => ['body' => $body]], $from);
    }

    private function tap(string $id, string $title, string $kind = 'list_reply'): void
    {
        $this->inbound(['type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => $title]]]);
    }

    /**
     * @return list<array<string, mixed>> messages envoyés depuis le dernier appel
     */
    private function sent(): array
    {
        [$out, $this->out] = [$this->out, []];

        return $out;
    }

    private function place(string $name): Place
    {
        return Place::whereNull('store_id')->where('name', $name)->firstOrFail();
    }

    public function test_the_customer_orders_by_tapping_and_confirms(): void
    {
        // Code envoyé : photo de la robe avec la légende, puis la liste des communes avec le prix
        $this->text('A1 svp');
        [$photo, $list] = $this->sent();
        $this->assertSame('image', $photo['type']);
        $this->assertStringEndsWith('.jpg', $photo['image']['link']);
        $this->assertStringContainsString('✅ A1 (Robe wax, 12 000 F) : réservée pour vous.', $photo['image']['caption']);
        $this->assertSame(['interactive', 'list'], [$list['type'], $list['interactive']['type']]);
        $rows = $list['interactive']['action']['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $cocody = collect($rows)->firstWhere('title', 'Cocody');
        $this->assertSame('live:commune:'.$this->place('Cocody')->id, $cocody['id']);
        $this->assertSame('Livraison 1 500 F · livré sous 24 h', $cocody['description']);
        $this->assertSame(['live:other', 'Autre commune…'], [end($rows)['id'], end($rows)['title']]);

        // Commune touchée : la liste de ses quartiers
        $this->tap($cocody['id'], 'Cocody');
        [$quartiers] = $this->sent();
        $this->assertSame('Cocody, livraison 1 500 F. Dans quel quartier ?', $quartiers['interactive']['body']['text']);
        $angre = collect($quartiers['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Angré');
        $this->assertNotNull($angre);

        // Quartier touché : la commande est créée, récapitulatif en image avec le total et trois boutons. Même avec la
        // demande de confirmation automatique activée, pas de second message « Confirmer / Annuler » pour cette commande
        $this->account->forceFill(['automations' => ['order_confirmation' => true]])->save();
        $this->tap($angre['id'], 'Angré');
        $order = Order::sole();
        $this->assertSame([OrderStatus::PendingConfirmation, $this->place('Angré')->id, 1500, 13500], [$order->status, $order->shipping_place_id, (int) $order->shipping_fee, (int) $order->total]);
        [$recap] = $this->sent();
        $this->assertSame('button', $recap['interactive']['type']);
        $this->assertStringEndsWith('.jpg', $recap['interactive']['header']['image']['link']);
        $this->assertStringContainsString('*Total à payer : 13 500 F*', $recap['interactive']['body']['text']);
        $this->assertSame(["live:confirm:{$order->id}", "live:address:{$order->id}", 'live:add'], array_column(array_column($recap['interactive']['action']['buttons'], 'reply'), 'id'));

        // Changer l'adresse : nouvelle liste, Yopougon sans quartier proposé ici → la commune suffit
        $this->tap("live:address:{$order->id}", 'Changer l\'adresse', 'button_reply');
        $this->assertSame('list', $this->sent()[0]['interactive']['type']);
        $this->tap('live:place:'.$this->place('Riviera 2')->id, 'Riviera 2');
        $this->assertSame($this->place('Riviera 2')->id, $order->fresh()->shipping_place_id);
        $this->assertSame("live:confirm:{$order->id}", $this->sent()[0]['interactive']['action']['buttons'][0]['reply']['id']);

        // Je confirme : la commande passe confirmée, sans appel
        $this->tap("live:confirm:{$order->id}", '✅ Je confirme', 'button_reply');
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertStringContainsString("Commande n° {$order->reference()} confirmée", $this->sent()[0]['text']['body']);

        // Un autre code pendant le live : ajouté à la même commande, nouveau total
        $this->text('A2');
        $this->assertSame(1, Order::count());
        $this->assertSame(21500, (int) $order->fresh()->total);
        $messages = $this->sent();
        $this->assertStringContainsString('A2 (Sac cabas, 8 000 F) : réservée pour vous.', $messages[0]['text']['body']);
        $this->assertStringContainsString('mise à jour', $messages[1]['image']['caption']);
        $this->assertStringContainsString('21 500 F', $messages[1]['image']['caption']);

        // Ajouter un article : la consigne en texte ; un bouton d'une autre cliente ne touche pas la commande
        $this->tap('live:add', 'Ajouter un article', 'button_reply');
        $this->assertStringContainsString('Envoyez le code de l\'article', $this->sent()[0]['text']['body']);
    }

    public function test_several_codes_come_together_with_the_commune_question(): void
    {
        $this->text('A1 + A2');
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(['interactive', 'list'], [$sent[0]['type'], $sent[0]['interactive']['type']]);
        $body = $sent[0]['interactive']['body']['text'];
        $this->assertStringContainsString('A1 (Robe wax, 12 000 F)', $body);
        $this->assertStringContainsString('A2 (Sac cabas, 8 000 F)', $body);
        $this->assertStringContainsString('Où faut-il vous livrer', $body);
    }

    public function test_a_typed_neighbourhood_still_works_and_a_reminder_comes_before_the_piece_is_released(): void
    {
        $this->text('A1');
        $this->sent();

        // Rappel un quart d'heure avant la fin : une seule fois, avec la liste des communes
        $this->travel(20)->minutes();
        $this->artisan('live:expire')->assertOk();
        [$reminder] = $this->sent();
        $this->assertSame('list', $reminder['interactive']['type']);
        $this->assertStringStartsWith('⏰ Votre A1 est gardé encore 10 minutes', $reminder['interactive']['body']['text']);
        $this->artisan('live:expire')->assertOk();
        $this->assertSame([], $this->sent());

        // Quartier écrit plutôt que touché : même récapitulatif avec « Je confirme »
        $this->text('Angré, face pharmacie');
        $order = Order::sole();
        $this->assertSame($this->place('Angré')->id, $order->shipping_place_id);
        $this->assertSame("live:confirm:{$order->id}", $this->sent()[0]['interactive']['action']['buttons'][0]['reply']['id']);

        // Le bouton d'une autre commande (ou d'une autre cliente) est refusé
        $this->tap('live:confirm:999999', '✅ Je confirme', 'button_reply');
        $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
        $this->assertStringContainsString('n\'existe plus', $this->sent()[0]['text']['body']);
    }
}
