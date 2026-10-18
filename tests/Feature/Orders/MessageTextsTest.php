<?php

namespace Tests\Feature\Orders;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use Tests\TestCase;

class MessageTextsTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    public function test_the_merchant_adapts_the_messages(): void
    {
        // Sans commande : aperçu sur une commande d'exemple
        $list = $this->storeApi($this->store, 'GET', 'message-texts')->assertOk()->json('data');
        $this->assertCount(7, $list['messages']);
        $this->assertStringStartsWith('Bonjour Awa, votre commande', collect($list['messages'])->firstWhere('key', 'confirmed')['preview']);

        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 5]]]);
        $order = app(OrderDesk::class)->create($this->store, [
            'source' => 'whatsapp', 'customer_name' => 'Fatou Diallo', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);

        $this->storeApi($this->store, 'PUT', 'message-texts', ['texts' => ['confirmed' => 'Salut {prénom} {inconnu}']])->assertStatus(422);
        $this->storeApi($this->store, 'PUT', 'message-texts', ['texts' => ['confirmed' => "Salut {prenom} 👋 ta commande {commande} est validée.\nReste à payer : {reste}\nÀ très vite chez {boutique} !"]])
            ->assertOk()->assertJsonPath('data.messages.2.custom', true);

        $message = fn () => collect($this->storeApi($this->store, 'GET', "orders/{$order->id}")->json('data.messages'))->firstWhere('key', 'confirmed');
        $this->assertSame("Salut Fatou 👋 ta commande {$order->reference()} est validée.\nReste à payer : 16 500 F\nÀ très vite chez Awa Mode !", str_replace("\u{202F}", ' ', $message()['text']));
        $this->assertStringContainsString(rawurlencode('Salut Fatou'), $message()['url']);

        // Tout est payé : la ligne « Reste à payer » disparaît
        app(OrderDesk::class)->recordPayment(Order::findOrFail($order->id), ['amount' => $order->total, 'method' => PaymentMethod::Wave, 'kind' => PaymentKind::Manual], $this->store->owner);
        $this->assertSame("Salut Fatou 👋 ta commande {$order->reference()} est validée.\nÀ très vite chez Awa Mode !", $message()['text']);

        // Aperçu pendant la saisie, puis retour au texte d'origine
        $this->storeApi($this->store, 'POST', 'message-texts/preview', ['key' => 'thanks', 'text' => 'Merci {prenom} !'])->assertOk()->assertJsonPath('data.preview', 'Merci Fatou !');
        $this->storeApi($this->store, 'PUT', 'message-texts', ['texts' => ['confirmed' => null]])->assertOk()->assertJsonPath('data.messages.2.custom', false);
        $this->assertStringStartsWith('Bonjour Fatou, votre commande', $message()['text']);
    }
}
