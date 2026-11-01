<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\CashMovement;
use App\Models\Conversation;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Place;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\WhatsApp\TemplateLibrary;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prix de livraison sans réglages (communes puis quartiers, prix retenus depuis les commandes, prix de départ) et
 * expéditions vers les autres villes (livraison jusqu'à la gare, frais de la compagnie connus au dépôt).
 */
class DeliveryRatesTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        Subscription::create(['store_id' => $this->store->id, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'status' => 'active', 'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function place(string $name): Place
    {
        return Place::whereNull('store_id')->where('name', $name)->firstOrFail();
    }

    private function order(string $place, array $extra = []): Order
    {
        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809',
            'delivery_method' => 'delivery', 'shipping_place_id' => $this->place($place)->id, 'shipping_address' => 'Près du marché',
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]], ...$extra,
        ], $this->store->owner);
    }

    /**
     * @return array{0: Courier, 1: User}
     */
    private function courier(): array
    {
        $created = $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Koffi', 'phone' => '0102030405', 'zone_ids' => [DeliveryZone::forStore($this->store->id)->value('id')]])->assertCreated();
        $token = basename($created->json('invitation_url'));
        $this->logout();
        $start = $this->storeApi($this->store, 'POST', "invitations/{$token}/accept", ['name' => 'Koffi', 'password' => 'motdepasse'])->assertStatus(202)->json();
        $this->storeApi($this->store, 'POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])->assertOk();
        $courier = Courier::findOrFail($created->json('data.id'));
        $this->logout();
        $this->actingAs($this->store->owner);

        return [$courier, $courier->user];
    }

    public function test_prices_are_learned_from_orders_without_settings(): void
    {
        // Abidjan au prix de départ : les 13 communes à toucher, chacune avec son prix
        $rates = $this->storeApi($this->store, 'GET', 'delivery-rates')->assertOk()
            ->assertJsonPath('data.home.name', 'Abidjan')->assertJsonPath('data.base_fee', 1500)->json('data');
        $this->assertCount(13, $rates['places']);
        $this->assertSame([1500, false, true], [collect($rates['places'])->firstWhere('name', 'Cocody')['fee'], collect($rates['places'])->firstWhere('name', 'Cocody')['learned'], collect($rates['places'])->firstWhere('name', 'Cocody')['has_children']]);
        $this->assertContains('Bouaké', array_column($rates['cities'], 'name'));

        // Prix retenu pour Yopougon, puis seulement pour Niangon
        $yopougon = $this->place('Yopougon');
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['place_id' => $yopougon->id, 'fee' => 2000])->assertOk();
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['place_id' => $this->place('Niangon')->id, 'fee' => 2500])->assertOk();
        $quartiers = collect($this->storeApi($this->store, 'GET', "delivery-rates/places/{$yopougon->id}")->assertOk()->json('data.places'));
        $this->assertSame([2500, true], [$quartiers->firstWhere('name', 'Niangon')['fee'], $quartiers->firstWhere('name', 'Niangon')['learned']]);
        $this->assertSame([2000, false], [$quartiers->firstWhere('name', 'Selmer')['fee'], $quartiers->firstWhere('name', 'Selmer')['learned']]);

        // Les commandes prennent le prix le plus précis ; la commune la plus livrée passe en tête
        $this->assertSame(2500, (int) $this->order('Niangon')->shipping_fee);
        $this->assertSame(2000, (int) $this->order('Selmer')->shipping_fee);
        $this->assertSame(1500, (int) $this->order('Angré')->shipping_fee);
        $this->assertSame('Yopougon', $this->storeApi($this->store, 'GET', 'delivery-rates')->json('data.places.0.name'));

        // Retenir encore : le même prix change (pas de doublon) ; retiré, le quartier reprend celui de la commune
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['place_id' => $yopougon->id, 'fee' => 1800])->assertOk();
        $this->storeApi($this->store, 'DELETE', 'delivery-rates/places/'.$this->place('Niangon')->id)->assertOk();
        $this->assertSame(1800, (int) $this->order('Niangon')->shipping_fee);
        $this->assertSame(1, DeliveryZone::forStore($this->store->id)->where('name', 'Yopougon')->count());

        // Prix de départ changé (question à l'ouverture de la boutique)
        $this->storeApi($this->store, 'PUT', 'delivery-rates', ['base_fee' => 1000])->assertOk()->assertJsonPath('data.base_fee', 1000);
        $this->assertSame(1000, (int) $this->order('Angré')->shipping_fee);
    }

    public function test_expedition_to_another_city_is_paid_before_and_dropped_at_the_station(): void
    {
        $this->storeApi($this->store, 'POST', 'delivery-rates/remember', ['expedition' => true, 'fee' => 1500])->assertOk()
            ->assertJsonPath('data.expedition', ['fee' => 1500, 'prepay' => true]);

        // Bouaké : expédition, prix convenu (livraison + expédition) compris dans le total, paiement avant par défaut
        $order = $this->order('Bouaké');
        $this->assertSame([$this->place('Bouaké')->id, 'included', true, 1500, 16500], [$order->expedition_city_id, $order->expedition_payer, $order->expedition_prepay, (int) $order->shipping_fee, (int) $order->total]);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.expedition.city', 'Bouaké')->assertJsonPath('data.expedition.prepay_due', 16500);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        // Le livreur ne part pas avant le paiement
        [$courier, $user] = $this->courier();
        // Paie fixe pour une expédition, comme pour une zone de livraison
        $rule = $this->storeApi($this->store, 'POST', 'courier-pay-rules', ['name' => 'Standard', 'type' => 'per_delivery', 'amount' => 1000, 'expedition_amount' => 1200])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'PATCH', "couriers/{$courier->id}", ['pay_rule_id' => $rule])->assertOk();
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['courier_id' => $courier->id])->assertStatus(422)
            ->assertJsonPath('message', 'Paiement avant expédition : le client doit encore payer 16 500 F. Envoyez-lui le lien de paiement.');
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/payments", ['amount' => 16500, 'method' => 'wave'])->assertOk();
        $shipment = Shipment::findOrFail($this->storeApi($this->store, 'POST', "orders/{$order->id}/shipments", ['courier_id' => $courier->id])->assertCreated()->json('data.id'));

        // À la gare : compagnie et frais obligatoires, reçu en photo, frais avancés par le livreur
        $this->logout();
        $this->actingAs($user);
        $this->storeApi($this->store, 'GET', "courier/missions/{$shipment->id}")->assertJsonPath('data.expedition.city', 'Bouaké')->assertJsonPath('data.expedition.pay', 1200)->assertJsonPath('data.requires_code', false);
        $this->storeApi($this->store, 'POST', "courier/missions/{$shipment->id}/steps", ['status' => 'in_transit'])->assertOk();
        $this->storeApi($this->store, 'POST', "courier/missions/{$shipment->id}/steps", ['status' => 'delivered'])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "courier/missions/{$shipment->id}/steps", [
            'status' => 'delivered', 'expedition_company' => 'UTB', 'expedition_fee' => 2000, 'expedition_ticket' => '4521', 'expedition_advanced' => true,
            'photo' => UploadedFile::fake()->image('recu.jpg'),
        ])->assertOk();

        $order->refresh();
        $this->assertSame([OrderStatus::Shipped, 'UTB', 2000, '4521', 16500], [$order->status, $order->expedition_company, (int) $order->expedition_fee, $order->expedition_ticket, (int) $order->total]);
        $this->assertSame(2000, (int) CourierEarning::where('type', 'expedition')->sum('amount'));
        $this->assertSame(1200, (int) CourierEarning::where('type', 'delivery')->sum('amount'));
        $this->logout();
        $this->actingAs($this->store->owner);
        $detail = $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.expedition.receipt', true)->json('data.expedition');
        // Prix convenu : le client ne paie rien de plus ; bilan de l'expédition pour la boutique
        $this->assertStringContainsString(rawurlencode('par UTB, ticket n° 4521. Les frais sont déjà réglés.'), $detail['whatsapp_url']);
        $this->assertSame([1500, 2000], [$detail['balance']['agreed'], $detail['balance']['company_fee']]);
        $this->assertSame([1200, 1500 - 1200 - 2000], [$detail['balance']['delivery_cost'], $detail['balance']['margin']]);
        $this->assertContains('UTB', $detail['companies']);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}/expedition/receipt")->assertOk();

        // Colis récupéré : commande terminée
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/expedition/collected")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_an_expedition_already_paid_when_saved_leaves_at_once_and_the_usual_price_comes_back(): void
    {
        // Payée avant l'enregistrement (Wave) : rien à encaisser, la course peut partir tout de suite
        $id = $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Awa', 'customer_phone' => '0506070809', 'confirmed' => true,
            'delivery_method' => 'delivery', 'shipping_place_id' => $this->place('Bouaké')->id, 'shipping_address' => 'Gare UTB',
            'scheduled_date' => today()->toDateString(), 'shipping_fee' => 3500,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]],
            'paid' => ['all' => true, 'method' => 'wave', 'reference' => 'T2511'],
        ])->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        $this->assertSame([18500, 18500, 0, 'included'], [(int) $order->total, (int) $order->amount_paid, $order->amountDue(), $order->expedition_payer]);
        $this->assertSame(['wave', 'T2511'], [$order->payments()->sole()->method->value, $order->payments()->sole()->provider_reference]);
        [$courier] = $this->courier();
        $this->storeApi($this->store, 'POST', "orders/{$id}/shipments", ['courier_id' => $courier->id])->assertCreated();

        // Prochaine commande pour Bouaké : le prix convenu revient tout seul
        $this->storeApi($this->store, 'POST', 'orders/delivery-options', ['place_id' => $this->place('Bouaké')->id])->assertOk()
            ->assertJsonPath('data.expedition.fee', 3500)->assertJsonPath('data.expedition.usual', true);

        // Une partie seulement : le reste à payer bloque encore le départ
        $part = $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Awa', 'customer_phone' => '0506070808', 'confirmed' => true,
            'delivery_method' => 'delivery', 'shipping_place_id' => $this->place('Bouaké')->id, 'shipping_address' => 'Gare UTB',
            'scheduled_date' => today()->toDateString(), 'shipping_fee' => 3500,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]],
            'paid' => ['amount' => 5000, 'method' => 'orange_money'],
        ])->assertCreated()->json('data');
        $this->assertSame(13500, Order::findOrFail($part['id'])->amountDue());
        $this->storeApi($this->store, 'POST', "orders/{$part['id']}/shipments", ['courier_id' => $courier->id])->assertStatus(422);
    }

    public function test_an_order_in_town_is_saved_when_the_form_sends_empty_expedition_fields(): void
    {
        // Le formulaire envoie tous ses champs, dont ceux de l'expédition laissés vides
        $this->storeApi($this->store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '0506070809',
            'delivery_method' => 'delivery', 'shipping_place_id' => $this->place('Angré')->id, 'shipping_address' => 'Près du marché',
            'scheduled_date' => today()->toDateString(), 'expedition_payer' => null, 'expedition_prepay' => null,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->assertFalse(Order::latest('id')->firstOrFail()->expedition_prepay);
    }

    public function test_expedition_fees_paid_before_departure_or_offered_by_the_store(): void
    {
        // Payés par le client avant le départ : ajoutés à la commande au dépôt (noté par le vendeur)
        $order = $this->order('Yamoussoukro', ['expedition_payer' => 'before', 'expedition_prepay' => false]);
        $this->assertSame([false, 'before'], [$order->expedition_prepay, $order->expedition_payer]);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $total = (int) $order->fresh()->total;
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/expedition", ['company' => 'STIF', 'fee' => 3000, 'ticket' => 'B-12'])->assertOk()
            ->assertJsonPath('data.total', $total + 3000)->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.expedition.payer', 'before');

        // Offerts par la boutique : une dépense de transport
        $offered = $this->order('San-Pédro', ['expedition_payer' => 'store', 'expedition_prepay' => false]);
        $this->storeApi($this->store, 'POST', "orders/{$offered->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->storeApi($this->store, 'POST', "orders/{$offered->id}/expedition", ['company' => 'AVS', 'fee' => 2500])->assertOk();
        $this->assertSame(2500, (int) CashMovement::where('category', 'transport')->sum('amount'));

        // Une commande dans Abidjan n'est pas une expédition
        $this->storeApi($this->store, 'POST', 'orders/'.$this->order('Angré')->id.'/expedition', ['company' => 'UTB', 'fee' => 1000])->assertStatus(422);
    }

    public function test_the_expedition_message_is_submitted_to_meta_for_numbers_connected_before_it_existed(): void
    {
        $sent = [];
        Http::fake(['graph.facebook.com/*' => function (HttpRequest $r) use (&$sent) {
            $sent[] = $r->data();

            return str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/message_templates')
                ? Http::response(['id' => 't9', 'status' => 'PENDING', 'category' => 'UTILITY'])
                : Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.'.count($sent)]]]);
        }]);
        // Numéro connecté en un clic avant le message « Colis expédié »
        $templates = collect(TemplateLibrary::TEMPLATES)->except('order_expedited')->map(fn ($t) => $t['name'])->all();
        $account = WhatsappAccount::create(['store_id' => $this->store->id, 'connection' => 'embedded', 'phone_number_id' => '5555', 'waba_id' => '4444', 'access_token' => 'tok',
            'templates' => $templates, 'template_status' => array_map(fn () => 'APPROVED', $templates), 'automations' => array_map(fn () => true, $templates), 'status' => 'active']);

        // Commande vers Bouaké : le modèle manquant part chez Meta, une seule fois
        $order = $this->order('Bouaké', ['expedition_prepay' => false]);
        $this->order('Daloa', ['expedition_prepay' => false]);
        $this->assertSame(['jm_colis_expedie'], array_column(array_filter($sent, fn ($d) => isset($d['category'])), 'name'));
        $account->refresh();
        $this->assertSame(['jm_colis_expedie', 'PENDING', true], [$account->templates['order_expedited'], $account->template_status['order_expedited'], $account->automations['order_expedited']]);
        $this->assertSame(1, (int) collect($account->templates)->filter(fn ($t) => $t === 'jm_colis_expedie')->count());

        // En vérification chez Meta, client sans conversation récente : rien ne part, le bouton reste
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $sent = [];
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/expedition", ['company' => 'UTB', 'fee' => 2000])->assertOk()
            ->assertJsonPath('data.expedition.message_state', 'pending');
        $this->assertSame([], $sent);

        // Client qui a écrit depuis moins de 24 h : le message part dans la conversation, gratuitement
        Conversation::create(['store_id' => $this->store->id, 'channel' => 'whatsapp', 'contact' => '+2250506070809', 'last_message_at' => now(), 'last_inbound_at' => now()]);
        $second = $this->order('Korhogo', ['expedition_prepay' => false]);
        $this->storeApi($this->store, 'POST', "orders/{$second->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->storeApi($this->store, 'POST', "orders/{$second->id}/expedition", ['company' => 'STIF', 'fee' => 1500, 'ticket' => '77'])->assertOk()
            ->assertJsonPath('data.expedition.message_state', 'sent');
        $this->assertStringContainsString('est partie pour Korhogo par STIF, ticket n° 77', end($sent)['text']['body']);

        // Modèle approuvé : les clients sans conversation récente sont prévenus par le modèle
        app(TemplateLibrary::class)->updateStatus($account->fresh(), 'jm_colis_expedie', 'APPROVED');
        Conversation::query()->update(['last_inbound_at' => now()->subDays(2)]);
        $third = $this->order('Man', ['expedition_prepay' => false]);
        $this->storeApi($this->store, 'POST', "orders/{$third->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->storeApi($this->store, 'POST', "orders/{$third->id}/expedition", ['company' => 'AVS', 'fee' => 2500])->assertOk()
            ->assertJsonPath('data.expedition.message_state', 'sent');
        $this->assertSame('jm_colis_expedie', end($sent)['template']['name']);
    }
}
