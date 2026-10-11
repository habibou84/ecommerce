<?php

namespace Tests\Feature\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockLevel;
use App\Services\Catalog\DeliveryAppImporter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryAppImporterTest extends TestCase
{
    private function importer(): DeliveryAppImporter
    {
        return app(DeliveryAppImporter::class);
    }

    public function test_only_delivery_app_domains_are_called(): void
    {
        $this->assertSame('https://entreprise1.jibiat.com/api/public/v1', $this->importer()->apiUrl('entreprise1.jibiat.com'));
        $this->assertSame('https://entreprise1.jibiat.com/api/public/v1', $this->importer()->apiUrl('https://Entreprise1.jibiat.com/marchand'));

        $this->expectException(BusinessRuleException::class);
        $this->importer()->apiUrl('http://169.254.169.254/latest');
    }

    public function test_products_are_fetched_page_by_page_and_imported(): void
    {
        $store = $this->createStore();
        Http::fake([
            'entreprise1.jibiat.com/api/public/v1/products?per_page=200&page=1' => Http::response([
                'data' => [
                    ['id' => 1, 'sku' => 'SAC-1', 'name' => 'Sac pagne', 'price' => 12000, 'available' => 5, 'levels' => [
                        ['hub_id' => null, 'on_hand' => 3, 'reserved' => 0], ['hub_id' => 4, 'on_hand' => 2, 'reserved' => 0],
                    ]],
                ],
                'links' => ['next' => 'https://entreprise1.jibiat.com/api/public/v1/products?page=2'],
            ]),
            'entreprise1.jibiat.com/api/public/v1/products?per_page=200&page=2' => Http::response([
                'data' => [['id' => 2, 'sku' => null, 'name' => 'Collier', 'price' => 3000, 'available' => 0, 'levels' => []]],
                'links' => ['next' => null],
            ]),
        ]);

        $products = $this->importer()->fetch('entreprise1.jibiat.com', 'lv_abc_123');
        $this->assertCount(2, $products);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer lv_abc_123'));

        $summary = $this->importer()->import($store, $products, null);
        $this->assertSame(['created' => 2, 'skipped' => 0, 'at_carrier' => 2], $summary);

        $sac = Product::forStore($store->id)->where('title', 'Sac pagne')->with('variants')->first();
        $this->assertSame([12000, 'SAC-1'], [$sac->variants[0]->price, $sac->variants[0]->sku]);
        $this->assertSame(3, StockLevel::where('variant_id', $sac->variants[0]->id)->value('on_hand'));

        // Deuxième import : les SKU déjà présents sont ignorés
        $this->assertSame(1, $this->importer()->import($store, [$products[0]], null)['skipped']);
    }

    public function test_api_errors_are_explained(): void
    {
        Http::fake(['*' => Http::response(['message' => 'x'], 401)]);
        $this->expectExceptionMessage('Clé API refusée');
        $this->importer()->fetch('entreprise1.jibiat.com', 'mauvaise');
    }
}
