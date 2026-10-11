<?php

namespace Tests\Feature\Orders;

use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Orders\OrderDocuments;
use App\Services\Orders\OrderMessages;
use Tests\TestCase;

class OrderDocumentsTest extends TestCase
{
    public function test_invoice_label_and_whatsapp_messages(): void
    {
        $store = $this->createStore(['name' => 'Awa Mode', 'slug' => 'awa']);
        $product = app(CatalogWriter::class)->save($store, null, ['title' => 'Sac', 'variants' => [['price' => 9000, 'on_hand' => 3]]]);
        $order = app(OrderDesk::class)->create($store, [
            'source' => 'facebook', 'customer_name' => 'Awa Koné', 'customer_phone' => '+2250708091011', 'delivery_method' => 'pickup',
            'items' => [['variant_id' => $product->variants[0]->id, 'quantity' => 1]],
        ], null);

        $invoice = app(OrderDocuments::class)->invoice($order);
        $this->assertSame('application/pdf', $invoice->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $invoice->getContent());
        $this->assertStringContainsString('facture-1001.pdf', $invoice->headers->get('Content-Disposition'));

        $label = app(OrderDocuments::class)->label($order);
        $this->assertStringStartsWith('%PDF', $label->getContent());
        $this->assertStringStartsWith('data:image/png;base64,', app(OrderDocuments::class)->qr($order->trackingUrl()));

        $messages = app(OrderMessages::class)->suggestions($order);
        $this->assertSame(['confirm_request'], array_column($messages, 'key'));
        $this->assertStringStartsWith('https://wa.me/2250708091011?text=', $messages[0]['url']);
        $this->assertStringContainsString('commande #1001 chez Awa Mode', $messages[0]['text']);
        $this->assertStringContainsString('1 × Sac', $messages[0]['text']);
        $this->assertStringContainsString("http://awa.localhost/suivi/{$order->tracking_token}", app(OrderMessages::class)->message($order, 'confirmed')['text']);
    }
}
