<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\ParcelLabel;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Orders\ParcelLabels;
use App\Services\Platform\Features;
use Tests\TestCase;

class ParcelLabelsTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['name' => 'Awa Mode', 'slug' => 'awa', 'phone' => '+2250700000001', 'whatsapp_phone' => '+2250700000001']);
        $this->actingAs($this->store->owner);
    }

    private function order(): Order
    {
        $product = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac', 'variants' => [['price' => 9000, 'on_hand' => 5]]]);

        return app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Salimata Coulibaly', 'customer_phone' => '+2250799000007', 'delivery_method' => 'pickup',
            'items' => [['variant_id' => $product->variants[0]->id, 'quantity' => 1]],
        ], null);
    }

    public function test_batches_codes_pdf_and_console_switches(): void
    {
        $overview = $this->storeApi($this->store, 'GET', 'parcel-labels')->assertOk();
        $this->assertSame(['mini', 'sheet', 'large'], array_column($overview->json('data.formats'), 'code'));

        $batch = $this->storeApi($this->store, 'POST', 'parcel-labels/batches', ['format' => 'mini', 'quantity' => 50])->assertCreated();
        $this->assertSame(50, $batch->json('data.overview.free'));
        $codes = ParcelLabel::forStore($this->store->id)->pluck('code');
        $this->assertCount(50, $codes->unique());
        $codes->each(fn ($c) => $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTWXYZ]{8}$/', $c));

        foreach (['mini', 'sheet', 'large'] as $format) {
            $id = $this->storeApi($this->store, 'POST', 'parcel-labels/batches', ['format' => $format, 'quantity' => 50])->assertCreated()->json('data.id');
            $pdf = $this->storeApi($this->store, 'GET', "parcel-labels/batches/{$id}/pdf")->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }
        $this->storeApi($this->store, 'POST', 'parcel-labels/batches', ['format' => 'mini', 'quantity' => 7])->assertStatus(422);

        // Console : grand format éteint, puis tout éteint (les étiquettes déjà imprimées restent lisibles)
        app(Features::class)->set('labels_large', 'hidden');
        $this->assertSame(['mini', 'sheet'], app(ParcelLabels::class)->formats($this->store->fresh()));
        $this->storeApi($this->store, 'POST', 'parcel-labels/batches', ['format' => 'large', 'quantity' => 50])->assertStatus(422);
        app(Features::class)->set('labels_mini', 'hidden');
        $order = $this->order();
        $this->storeApi($this->store, 'POST', 'parcel-labels/batches', ['format' => 'mini', 'quantity' => 50])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/parcel-labels", ['code' => $codes[0]])->assertStatus(422);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.parcel_labels_enabled', false);
        $this->get($this->storeUrl($this->store, '/e/'.$codes[0]))->assertOk()->assertSee('pas encore utilisée');
    }

    public function test_scan_attach_public_page_and_lookup(): void
    {
        app(ParcelLabels::class)->createBatch($this->store, null, 'mini', 50);
        [$first, $second] = ParcelLabel::forStore($this->store->id)->orderBy('id')->take(2)->get()->all();
        $order = $this->order();
        $other = $this->order();

        // Étiquette pas encore utilisée : seulement la boutique (numéro, lien) et « Crée ta boutique »
        $this->storeApi($this->store, 'GET', 'parcel-labels/lookup?code='.$first->code)->assertOk()->assertJsonPath('data.order_id', null);
        $this->get($this->storeUrl($this->store, '/e/'.$first->code))->assertOk()
            ->assertSee('Étiquette '.$first->display())->assertSee('pas encore utilisée')->assertSee('07 00 00 00 01')->assertSee('Crée ta boutique gratuitement');

        // Scan : lien complet lu par la caméra, puis le même code tapé à la main
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/parcel-labels", ['code' => $first->url()])->assertOk()
            ->assertJsonPath('data.code', $first->display())->assertJsonPath('data.labels.0.code', $first->display());
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/parcel-labels", ['code' => strtolower(str_replace('-', ' ', $first->display()))])->assertOk();
        $this->assertSame($order->id, $first->fresh()->order_id);
        $this->assertSame('parcel_label', $order->events()->latest('id')->value('type'));

        // Déjà sur une autre commande : refusée, rien ne change
        $this->storeApi($this->store, 'POST', "orders/{$other->id}/parcel-labels", ['code' => $first->code])->assertStatus(422)
            ->assertJsonFragment(['message' => 'L\'étiquette '.$first->display().' est déjà sur la commande '.$order->reference().'. Rien n\'a été changé.']);
        $this->storeApi($this->store, 'POST', "orders/{$other->id}/parcel-labels", ['code' => 'pas un code'])->assertStatus(422);

        // Étiquette d'une autre boutique : inconnue ici
        $shop = $this->createStore(['slug' => 'bijoux']);
        app(ParcelLabels::class)->createBatch($shop, null, 'mini', 50);
        $foreign = ParcelLabel::forStore($shop->id)->first();
        $this->storeApi($this->store, 'POST', "orders/{$other->id}/parcel-labels", ['code' => $foreign->code])->assertStatus(422);
        $this->get($this->storeUrl($this->store, '/e/'.$foreign->code))->assertNotFound();

        // Page publique du colis : avancement et boutique, rien du client
        $page = $this->get($this->storeUrl($this->store, '/e/'.$first->code))->assertOk()
            ->assertSee('Colis de la commande '.$order->reference())->assertSee('Contacter')->assertSee('Crée ta boutique gratuitement')
            ->assertDontSee('Salimata')->assertDontSee('99 00 00 07')->assertDontSee('9 000')->assertDontSee($order->tracking_token);
        $this->storeApi($this->store, 'GET', 'parcel-labels/lookup?code='.$first->display())->assertJsonPath('data.order_id', $order->id);
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.parcel_labels.0.code', $first->display())->assertJsonPath('data.parcel_labels_enabled', true);

        // Détacher puis relier à l'autre commande
        $this->storeApi($this->store, 'DELETE', "orders/{$order->id}/parcel-labels/{$first->id}")->assertOk()->assertJsonPath('data.labels', []);
        $this->storeApi($this->store, 'POST', "orders/{$other->id}/parcel-labels", ['code' => $first->code])->assertOk();
        $this->storeApi($this->store, 'POST', "orders/{$other->id}/parcel-labels", ['code' => $second->code])->assertOk()->assertJsonCount(2, 'data.labels');
        $this->storeApi($this->store, 'GET', 'parcel-labels')->assertJsonPath('data.used', 2)->assertJsonPath('data.free', 48);
    }

    public function test_code_reading(): void
    {
        $this->assertSame('7K4Q29XB', ParcelLabels::normalize('https://awa.jibiat-marchand.com/e/7K4Q29XB'));
        $this->assertSame('7K4Q29XB', ParcelLabels::normalize('JM-7K4Q-29XB'));
        $this->assertSame('7K4Q29XB', ParcelLabels::normalize(' jm 7k4q 29xb '));
        $this->assertSame('7K4Q29XB', ParcelLabels::normalize('7k4q29xb'));
        $this->assertNull(ParcelLabels::normalize('JM-7K4Q'));
    }
}
