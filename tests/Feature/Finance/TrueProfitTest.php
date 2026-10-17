<?php

namespace Tests\Feature\Finance;

use App\Enums\OrderStatus;
use App\Models\CashMovement;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Finance\RecurringExpenses;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\Reports\DailyAdvice;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TrueProfitTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'UTC'));
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->actingAs($this->store->owner);
    }

    private function deliveredOrder(Product $product, string $source, int $i): void
    {
        $desk = app(OrderDesk::class);
        $order = $desk->create($this->store, [
            'source' => $source, 'customer_name' => 'Fatou', 'customer_phone' => '+2250506070'.(800 + $i), 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'confirmed' => true,
            'items' => [['variant_id' => $product->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
        $desk->transition($desk->transition($order, OrderStatus::Shipped, $this->store->owner), OrderStatus::Completed, $this->store->owner);
    }

    public function test_advertising_cost_per_delivered_order_and_tip(): void
    {
        $robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'cost_price' => 5000, 'on_hand' => 50]]]);
        foreach (range(1, 3) as $i) {
            $this->deliveredOrder($robe, 'facebook', $i);
        }
        $this->deliveredOrder($robe, 'whatsapp', 9);

        // Publicité Facebook payée par Wave hier ; une dépense en espèces ne peut pas être datée dans le passé
        $this->storeApi($this->store, 'POST', 'cash-register/movements', [
            'type' => 'expense', 'category' => 'marketing', 'channel' => 'facebook', 'amount' => 45000, 'method' => 'wave', 'occurred_on' => '2026-10-16',
        ])->assertCreated();
        $this->assertSame('2026-10-16 12:00', CashMovement::first()->occurred_at->format('Y-m-d H:i'));
        $this->storeApi($this->store, 'POST', 'cash-register/movements', [
            'type' => 'expense', 'category' => 'transport', 'amount' => 2000, 'method' => 'cash', 'occurred_on' => '2026-10-10',
        ])->assertStatus(422);

        $stats = $this->storeApi($this->store, 'GET', 'stats?from=2026-10-01&to=2026-10-17')->assertOk()->json('data');
        $facebook = collect($stats['by_source'])->firstWhere('source', 'facebook');
        $this->assertSame(45000, $facebook['ad_spend']);
        $this->assertSame(15000, $facebook['ad_cost_per_delivered']);
        $this->assertSame(3 * 10000 - 45000, $facebook['margin_after_ads']);
        $this->assertNull(collect($stats['by_source'])->firstWhere('source', 'whatsapp')['ad_spend']);
        $this->assertSame(45000, $stats['profit']['ad_spend']);
        $this->assertSame((int) round($stats['profit']['profit'] / 4), $stats['profit']['per_delivered']);

        $tips = implode(' ', app(DailyAdvice::class)->tips($this->store));
        $this->assertStringContainsString('Votre publicité Facebook coûte', $tips);

        // Rapport du soir : bénéfice du mois pour qui voit les finances
        $this->assertStringContainsString('Bénéfice depuis le 1er du mois', $this->storeApi($this->store, 'GET', 'reports/schedule')->json('data.preview'));
    }

    public function test_monthly_expenses_are_recorded_on_their_day(): void
    {
        $this->storeApi($this->store, 'POST', 'recurring-expenses', ['category' => 'rent', 'amount' => 100000, 'method' => 'cash', 'day_of_month' => 5])
            ->assertStatus(422);
        // Le 5 est passé : première fois le mois prochain ; le 20 arrive ce mois-ci
        $this->storeApi($this->store, 'POST', 'recurring-expenses', ['category' => 'rent', 'amount' => 100000, 'method' => 'bank', 'day_of_month' => 5, 'note' => 'Loyer'])
            ->assertCreated()->assertJsonPath('data.next_date', '2026-11-05');
        $this->storeApi($this->store, 'POST', 'recurring-expenses', ['category' => 'salary', 'amount' => 60000, 'method' => 'orange_money', 'day_of_month' => 20])
            ->assertCreated()->assertJsonPath('data.next_date', '2026-10-20');

        $expenses = app(RecurringExpenses::class);
        $this->assertSame(0, $expenses->run());

        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'UTC'));
        $this->assertSame(1, $expenses->run());
        $this->assertSame(0, $expenses->run());
        $salary = CashMovement::where('category', 'salary')->sole();
        $this->assertSame(['2026-10-20', 60000, 'orange_money', null], [$salary->occurred_at->toDateString(), $salary->amount, $salary->method, $salary->cash_session_id]);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00', 'UTC'));
        $this->assertSame(1, $expenses->run());
        $this->assertSame(100000, (int) CashMovement::where('category', 'rent')->sum('amount'));

        $list = $this->storeApi($this->store, 'GET', 'recurring-expenses')->assertOk()->json('data');
        $this->assertSame(['2026-12-05', '2026-11-20'], array_column($list, 'next_date'));

        // Pause : plus rien n'est noté
        $this->storeApi($this->store, 'PUT', 'recurring-expenses/'.$list[1]['id'], ['category' => 'salary', 'amount' => 60000, 'method' => 'orange_money', 'day_of_month' => 20, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.next_date', null);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 08:00', 'UTC'));
        $this->assertSame(0, $expenses->run());
    }
}
