<?php

namespace Tests\Feature\Live;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\Catalog\CatalogWriter;
use App\Services\Live\LiveCheckout;
use App\Services\Places\DeliveryRates;
use App\Services\Places\Places;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\Money;
use Tests\Concerns\LiveProductsHavePhotos;
use Tests\TestCase;

/**
 * Commande du live sur le WhatsApp officiel, en touchant : photo de l'article réservé, commune puis quartier choisis
 * dans une liste avec le prix de la livraison, récapitulatif en image avec « Je confirme », adresse changée, article
 * ajouté à la commande confirmée, rappel avant de libérer une pièce.
 */
class LiveWhatsAppCheckoutTest extends TestCase
{
    use LiveProductsHavePhotos;

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
        $this->giveProductsPhotos();
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
        // Code envoyé : un court texte (pas de photo, elle arriverait après la question), puis la liste des communes
        $this->text('A1 svp');
        [$reserved, $list] = $this->sent();
        $this->assertSame('text', $reserved['type']);
        $this->assertStringContainsString("✅ *C'est réservé pour vous !*\nA1 Robe wax", $reserved['text']['body']);
        $this->assertMatchesRegularExpression('/A1 Robe wax.* · [\d\x{202F}\x{A0} ]+ F/u', $reserved['text']['body']);
        $this->assertStringStartsWith("📍 *DERNIÈRE ÉTAPE : L'ADRESSE*", $list['interactive']['body']['text']);
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
        $this->assertSame("📍 *Cocody* · livraison 1 500 F\n\n👇 Touchez *« Choisir mon quartier »*", $quartiers['interactive']['body']['text']);
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
        [$confirmed, $photo] = $this->sent();
        $this->assertStringStartsWith('🎉 *COMMANDE CONFIRMÉE !*', $body = $confirmed['text']['body']);
        $this->assertStringContainsString("N° {$order->reference()}", $body);
        // Puis la vraie photo de chaque article, en grand, avec son code et son prix
        $this->assertSame('image', $photo['type']);
        $this->assertStringEndsWith('.jpg', $photo['image']['link']);
        $this->assertStringStartsWith("📷 *Voici les photos de votre commande N° {$order->reference()}*\nA1 · Robe wax", $photo['image']['caption']);

        // Un autre code pendant le live : ajouté à la même commande, nouveau total
        $this->text('A2');
        $this->assertSame(1, Order::count());
        $this->assertSame(21500, (int) $order->fresh()->total);
        // Un seul message : le récapitulatif mis à jour (l'image montre tous les articles)
        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('COMMANDE MISE À JOUR', $messages[0]['image']['caption']);
        $this->assertStringContainsString('21 500 F', $messages[0]['image']['caption']);

        // Ajouter un article : la consigne en texte ; un bouton d'une autre cliente ne touche pas la commande
        $this->tap('live:add', 'Ajouter un article', 'button_reply');
        $this->assertStringContainsString('AJOUTER UN ARTICLE', $this->sent()[0]['text']['body']);
    }

    public function test_commune_prices_win_over_the_city_and_other_cities_are_shipped(): void
    {
        $rates = app(DeliveryRates::class);
        $rates->remember($this->store, $this->place('Cocody'), 2000);
        $this->store->forceFill(['expedition_fee' => 3000, 'expedition_prepay' => true])->save();
        $bouake = Place::whereNull('store_id')->whereNull('parent_id')->where('name', 'Bouaké')->firstOrFail();
        $rates->remember($this->store, $bouake, 4000);

        // Liste des communes : Cocody à son prix (pas celui d'Abidjan), pas de Bouaké, et la ligne des expéditions
        $this->text('A1');
        [, $list] = $this->sent();
        $rows = collect($list['interactive']['action']['sections'][0]['rows']);
        $this->assertStringStartsWith('Livraison '.Money::format(2000), $rows->firstWhere('title', 'Cocody')['description']);
        $this->assertStringStartsWith('Livraison '.Money::format(1500), $rows->firstWhere('title', 'Abobo')['description']);
        $this->assertNull($rows->firstWhere('title', 'Bouaké'));
        $this->assertLessThanOrEqual(10, $rows->count());
        $this->assertSame('Expédition dès '.Money::format(3000), $rows->firstWhere('id', 'live:cities')['description']);

        // Autre ville : la liste des villes avec leur prix, puis la commande au prix de Bouaké, payée avant l'envoi
        $this->tap('live:cities', '🚚 Autre ville');
        [$cities] = $this->sent();
        $row = collect($cities['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Bouaké');
        $this->assertSame('Expédition '.Money::format(4000), $row['description']);
        $this->tap($row['id'], 'Bouaké');
        $order = Order::sole();
        $this->assertTrue($order->isExpedition());
        $this->assertSame(4000, (int) $order->shipping_fee);
        [$recap] = $this->sent();
        $this->assertStringContainsString('🚚 Expédition (Bouaké) : '.Money::format(4000), $recap['interactive']['body']['text']);
        $this->assertStringContainsString("À payer avant l'envoi", $recap['interactive']['body']['text']);
        $this->assertStringNotContainsString('Paiement à la livraison', $recap['interactive']['body']['text']);

        // Je confirme : le montant à payer maintenant, comment payer, puis l'envoi (pas « à payer à la livraison »)
        $this->tap("live:confirm:{$order->id}", '✅ Je confirme', 'button_reply');
        $body = $this->sent()[0]['text']['body'];
        $this->assertStringContainsString(Money::format((int) $order->fresh()->total)." à payer maintenant, avant l'envoi", $body);
        $this->assertStringContainsString('👉', $body);
        $this->assertStringNotContainsString('à payer à la livraison', $body);
    }

    public function test_the_wave_link_is_sent_the_receipt_is_kept_and_the_shop_validates_the_payment(): void
    {
        $this->storeApi($this->store, 'PUT', 'delivery-rates', ['wave_link' => 'pas un lien'])->assertUnprocessable();
        // Tout le message copié depuis Wave : seul le lien est gardé
        $this->storeApi($this->store, 'PUT', 'delivery-rates', ['wave_link' => "Veuillez payer C'joli avec Wave :\nhttps://pay.wave.com/m/M_ci_test/c/ci/."])
            ->assertOk()->assertJsonPath('data.wave_link', 'https://pay.wave.com/m/M_ci_test/c/ci/');
        $this->storeApi($this->store, 'PUT', 'delivery-rates', ['expedition_fee' => 3000, 'expedition_prepay' => true, 'wave_link' => 'https://pay.wave.com/m/M_ci_test/c/ci/'])
            ->assertOk()->assertJsonPath('data.wave_link', 'https://pay.wave.com/m/M_ci_test/c/ci/');
        $this->text('A1');
        $this->sent();
        $this->tap('live:cities', '🚚 Autre ville');
        $row = collect($this->sent()[0]['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Bouaké');
        $this->tap($row['id'], 'Bouaké');
        $order = Order::sole();
        $this->sent();

        // Je confirme : le lien Wave de la boutique et la capture du reçu à envoyer
        $this->tap("live:confirm:{$order->id}", '✅ Je confirme', 'button_reply');
        $body = $this->sent()[0]['text']['body'];
        $due = $order->fresh()->expeditionPrepayDue();
        $this->assertStringContainsString("Payez avec Wave : https://pay.wave.com/m/M_ci_test/c/ci/?amount={$due}", $body);
        $this->assertSame('https://pay.wave.com/m/x/c/ci/?ref=1&amount=500', LiveCheckout::waveLink('https://pay.wave.com/m/x/c/ci/?amount=200&ref=1', 500));
        $this->assertStringContainsString('capture du reçu Wave', $body);

        // La capture du reçu : notée sur la commande, la cliente est remerciée
        $this->inbound(['type' => 'image', 'image' => ['id' => 'MEDIA-RECU', 'mime_type' => 'image/jpeg']]);
        $this->assertNotNull($order->fresh()->payment_proof_at);
        $this->assertStringContainsString('reçu bien reçu', $this->sent()[0]['text']['body']);
        $detail = $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertOk();
        $this->assertNotNull($detail->json('data.expedition.payment_proof_at'));
        $this->assertNotNull($detail->json('data.expedition.payment_proof_conversation_id'));

        // La boutique valide : payé, la cliente est prévenue
        $due = $detail->json('data.expedition.prepay_due');
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/payments", ['amount' => $due, 'method' => 'wave', 'reference' => 'Reçu WhatsApp'])->assertOk()
            ->assertJsonPath('data.expedition.prepay_due', 0);
        $this->assertStringContainsString('Paiement reçu', collect($this->sent())->last()['text']['body']);
    }

    public function test_an_expedition_without_payment_before_keeps_payment_on_delivery(): void
    {
        $this->store->forceFill(['expedition_fee' => 3000, 'expedition_prepay' => false])->save();
        $this->text('A1');
        $this->sent();
        $this->tap('live:cities', '🚚 Autre ville');
        $row = collect($this->sent()[0]['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Bouaké');
        $this->tap($row['id'], 'Bouaké');
        $order = Order::sole();
        $this->assertFalse((bool) $order->expedition_prepay);
        [$recap] = $this->sent();
        $this->assertStringContainsString('Paiement à la livraison', $recap['interactive']['body']['text']);
    }

    public function test_several_codes_come_together_with_the_commune_question(): void
    {
        $this->text('A1 + A2');
        // Un seul court texte pour les deux articles, puis la liste des communes
        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $this->assertSame('text', $sent[0]['type']);
        $this->assertMatchesRegularExpression('/A1 Robe wax · [^\n]+\nA2 Sac cabas/u', $sent[0]['text']['body']);
        $this->assertSame(['interactive', 'list'], [$sent[1]['type'], $sent[1]['interactive']['type']]);
        $this->assertStringContainsString("DERNIÈRE ÉTAPE : L'ADRESSE", $sent[1]['interactive']['body']['text']);
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
        $this->assertStringStartsWith('⏰ *Votre A1 est gardé encore 10 minutes*', $reminder['interactive']['body']['text']);
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
