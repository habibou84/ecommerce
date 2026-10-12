<?php

namespace Tests\Feature\Finance;

use App\Enums\Role;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe', 'variants' => [['price' => 10000, 'on_hand' => 50]]]);
        $this->actingAs($this->store->owner);
    }

    private function sale(array $payments, int $quantity = 2, array $extra = [])
    {
        return $this->storeApi($this->store, 'POST', 'counter-sales', [
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => $quantity]],
            'payments' => $payments,
        ] + $extra);
    }

    private function onHand(): int
    {
        return StockLevel::where('variant_id', $this->robe->variants[0]->id)->value('on_hand');
    }

    public function test_counter_sale_in_one_step(): void
    {
        // Espèces sans caisse ouverte : refusé, rien n'est créé ; Wave seul : accepté
        $this->sale([['method' => 'cash', 'amount' => 20000]])->assertStatus(422)->assertJsonPath('message', 'Ouvrez la caisse avant d\'encaisser des espèces.');
        $this->sale([['method' => 'wave', 'amount' => 10000]], 1)->assertCreated()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(49, $this->onHand());

        $this->storeApi($this->store, 'POST', 'cash-register/open', ['opening_float' => 20000])->assertCreated()->assertJsonPath('data.expected_cash', 20000);
        $this->storeApi($this->store, 'POST', 'cash-register/open', ['opening_float' => 0])->assertStatus(422);

        // Client anonyme, paiement en deux fois : la commande est terminée, payée, le stock est sorti
        $sale = $this->sale([['method' => 'cash', 'amount' => 15000], ['method' => 'orange_money', 'amount' => 5000]])->assertCreated()
            ->assertJsonPath('data.total', 20000)->assertJsonPath('data.amount_due', 0)->assertJsonPath('data.payment_status', 'paid');
        $order = Order::findOrFail($sale->json('data.id'));
        $this->assertSame(['in_store', 'pickup', 'completed', null, null], [$order->source->value, $order->delivery_method, $order->status->value, $order->customer_id, $order->customer_phone]);
        $this->assertSame(['manual', 'manual'], $order->payments()->pluck('kind')->map->value->all());
        $this->assertSame(47, $this->onHand());
        $this->storeApi($this->store, 'GET', "orders/{$order->id}")->assertOk()->assertJsonPath('data.messages', [])->assertJsonPath('data.call_url', null);

        // Payé plus que le total : refusé et annulé en entier
        $this->sale([['method' => 'cash', 'amount' => 25000]])->assertStatus(422);
        $this->assertSame([47, 2], [$this->onHand(), Order::count()]);

        // Payé en partie sans client : refusé (on ne saurait pas qui doit le reste)
        $this->sale([['method' => 'cash', 'amount' => 4000]], 1)->assertStatus(422)->assertJsonValidationErrors('customer_phone');

        // Client connu, payé en partie : vente à crédit
        $this->sale([['method' => 'cash', 'amount' => 4000]], 1, ['customer_name' => 'Fatou', 'customer_phone' => '05 06 07 08 09'])
            ->assertCreated()->assertJsonPath('data.amount_due', 6000)->assertJsonPath('data.payment_status', 'partially_paid');
        $this->assertSame('+2250506070809', Order::latest('id')->first()->customer?->phone);
        $this->storeApi($this->store, 'GET', 'cash-overview')->assertJsonPath('data.position.customers_due', 6000);

        $this->storeApi($this->store, 'GET', 'cash-register')->assertOk()->assertJsonPath('data.session.expected_cash', 20000 + 15000 + 4000);
    }

    public function test_expenses_withdrawals_and_closing(): void
    {
        // Dépense en espèces sans caisse ouverte : refusée ; payée par Wave : acceptée
        $this->storeApi($this->store, 'POST', 'cash-register/movements', ['type' => 'expense', 'category' => 'transport', 'amount' => 1000])->assertStatus(422);
        $this->storeApi($this->store, 'POST', 'cash-register/movements', ['type' => 'expense', 'category' => 'marketing', 'amount' => 1000, 'method' => 'wave'])->assertCreated();

        $this->storeApi($this->store, 'POST', 'cash-register/open', ['opening_float' => 20000])->assertCreated();
        $this->sale([['method' => 'cash', 'amount' => 20000]])->assertCreated();
        $this->storeApi($this->store, 'POST', 'cash-register/movements', ['type' => 'expense', 'category' => 'stock', 'amount' => 3000, 'note' => 'Tissu'])->assertCreated();
        $this->storeApi($this->store, 'POST', 'cash-register/movements', ['type' => 'withdrawal', 'amount' => 10000, 'note' => 'Banque'])->assertCreated();
        $this->storeApi($this->store, 'POST', 'cash-register/movements', ['type' => 'deposit', 'amount' => 2000, 'note' => 'Monnaie'])->assertCreated();
        $this->storeApi($this->store, 'POST', 'cash-register/movements', ['type' => 'withdrawal', 'amount' => 1000, 'method' => 'wave'])->assertStatus(422);
        $this->storeApi($this->store, 'POST', 'cash-register/movements', ['type' => 'withdrawal', 'amount' => 999999])->assertStatus(422)->assertJsonValidationErrors('amount');

        $register = $this->storeApi($this->store, 'GET', 'cash-register')->assertOk();
        $register->assertJsonPath('data.session.expected_cash', 20000 + 20000 - 3000 - 10000 + 2000)
            ->assertJsonPath('data.movements.0.type', 'deposit')->assertJsonPath('data.movements.2.category_label', 'Achat de marchandises');

        $flows = collect($this->storeApi($this->store, 'GET', 'cash-overview')->json('data.flows.lines'))->keyBy('key');
        $this->assertSame([-4000, -3000], [$flows['expenses']['amount'], $flows['expenses']['cash']]);
        $this->assertSame([0, -8000], [$flows['drawer']['amount'], $flows['drawer']['cash']]);

        // Clôture : 500 F de moins que prévu
        $this->storeApi($this->store, 'POST', 'cash-register/close', ['counted_cash' => 28500, 'note' => 'Billet déchiré'])->assertOk()
            ->assertJsonPath('data.expected_cash', 29000)->assertJsonPath('data.difference', -500);
        $this->storeApi($this->store, 'POST', 'cash-register/close', ['counted_cash' => 0])->assertStatus(422);
        $this->storeApi($this->store, 'GET', 'cash-register')->assertJsonPath('data.session', null)
            ->assertJsonPath('data.suggested_float', 28500)->assertJsonPath('data.closings.0.difference', -500)
            ->assertJsonPath('data.closings.0.closed_by', $this->store->owner->name);
    }

    public function test_who_can_hold_the_register(): void
    {
        $cashier = $this->addMember($this->store, User::factory()->create(), Role::Cashier);
        $this->actingAs($cashier);
        $this->storeApi($this->store, 'POST', 'cash-register/open', ['opening_float' => 5000])->assertCreated();
        $this->sale([['method' => 'cash', 'amount' => 10000]], 1)->assertCreated();
        $this->storeApi($this->store, 'GET', 'products')->assertOk();
        $this->storeApi($this->store, 'POST', 'cash-register/close', ['counted_cash' => 15000])->assertOk()->assertJsonPath('data.difference', 0);

        $this->actingAs($this->addMember($this->store, User::factory()->create(), Role::StockKeeper));
        $this->sale([['method' => 'wave', 'amount' => 10000]], 1)->assertForbidden();
        $this->storeApi($this->store, 'GET', 'cash-register')->assertForbidden();
    }
}
