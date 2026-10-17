<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Place;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private Product $sac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        app(Places::class)->provisionDefaultZone($this->store);
        $writer = app(CatalogWriter::class);
        $this->robe = $writer->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->sac = $writer->save($this->store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function order(bool $delivered = true): Order
    {
        $desk = app(OrderDesk::class);
        $order = $desk->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1], ['variant_id' => $this->sac->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
        if ($delivered) {
            $order = $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), OrderStatus::Completed, $this->store->owner);
        }

        return $order->fresh();
    }

    private function review(Order $order, array $body)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->postJson($this->storeUrl($this->store, '/avis/'.$order->tracking_token), $body);
    }

    public function test_a_customer_reviews_the_products_received_and_the_merchant_publishes(): void
    {
        $order = $this->order();
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.review_url', "http://awa.localhost/avis/{$order->tracking_token}");

        $this->get($this->storeUrl($this->store, '/avis/'.$order->tracking_token))->assertOk()
            ->assertSee('Votre avis compte')->assertSee('Robe wax')->assertSee('Sac en pagne');

        $this->review($order, ['ratings' => [$this->robe->id => ['rating' => 9]]])->assertStatus(422);
        $this->review($order, ['ratings' => [$this->robe->id => ['rating' => null]]])->assertStatus(422)
            ->assertJsonPath('message', 'Donnez une note (de 1 à 5 étoiles) à au moins un article.');
        $this->review($order, ['name' => 'Fatou Diallo', 'ratings' => [
            $this->robe->id => ['rating' => 5, 'comment' => 'Très beau tissu, taille parfaite.'],
            $this->sac->id => ['rating' => 4],
            999999 => ['rating' => 1],
        ]])->assertOk()->assertJsonPath('message', 'Merci pour vos avis !');

        // Prénom et initiale ; en attente de validation, rien sur la boutique
        $review = ProductReview::forStore($this->store->id)->where('product_id', $this->robe->id)->sole();
        $this->assertSame(['Fatou D.', 5, 'pending'], [$review->author_name, $review->rating, $review->status]);
        $this->assertSame(2, ProductReview::forStore($this->store->id)->count());
        $this->get($this->storeUrl($this->store, '/produits/'.$this->robe->slug))->assertOk()->assertDontSee('Avis clients');

        $this->storeApi($this->store, 'GET', 'reviews?status=pending')->assertJsonPath('meta.total', 2)->assertJsonPath('counts.pending', 2);
        $this->storeApi($this->store, 'POST', "reviews/{$review->id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $page = $this->get($this->storeUrl($this->store, '/produits/'.$this->robe->slug))->assertOk()
            ->assertSee('Avis clients')->assertSee('Très beau tissu, taille parfaite.')->assertSee('Fatou D.')->assertSee('5,0 / 5 · 1 avis');
        $this->assertStringContainsString('"aggregateRating":{"@type":"AggregateRating","ratingValue":5', $page->getContent());

        // Renvoyé : remplace l'avis et le remet à valider
        $this->review($order, ['ratings' => [$this->robe->id => ['rating' => 3]]])->assertOk();
        $this->assertSame([3, 'pending'], [$review->fresh()->rating, $review->fresh()->status]);

        $this->storeApi($this->store, 'POST', "reviews/{$review->id}/hide")->assertJsonPath('data.status', 'hidden');
        $this->storeApi($this->store, 'DELETE', "reviews/{$review->id}")->assertOk();
        $this->assertSame(1, ProductReview::forStore($this->store->id)->count());
    }

    public function test_reviews_open_only_once_delivered(): void
    {
        $order = $this->order(delivered: false);
        $this->get($this->storeUrl($this->store, '/avis/'.$order->tracking_token))->assertOk()->assertSee('une fois votre commande livrée');
        $this->review($order, ['ratings' => [$this->robe->id => ['rating' => 5]]])->assertStatus(422);
        $this->get($this->storeUrl($this->store, '/avis/inconnu1234567890'))->assertNotFound();
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.review_url', null);
    }

    public function test_a_review_request_is_sent_the_day_after_delivery(): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        Queue::fake();
        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250700000001', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
            'templates' => ['review_request' => 'jm_demande_avis'], 'automations' => ['review_request' => true],
        ])->assertOk();

        $order = $this->order();
        $message = OutboundMessage::where('event', 'review_request')->sole();
        $this->assertStringContainsString("http://awa.localhost/avis/{$order->tracking_token}", $message->body);
        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->delay !== null && $job->delay->greaterThan(now()->addHours(23)));

        // Bouton manuel sur la commande (lien WhatsApp prérempli)
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertJsonPath('data.messages.1.key', 'review_request');
    }
}
