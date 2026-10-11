<?php

namespace Tests\Feature\Catalog;

use App\Enums\StockLocationType;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Services\Catalog\CatalogCsv;
use App\Services\Catalog\CatalogWriter;
use Tests\TestCase;

class CatalogCsvTest extends TestCase
{
    private function csv(): CatalogCsv
    {
        return app(CatalogCsv::class);
    }

    public function test_export_then_import_round_trips_the_catalog(): void
    {
        $store = $this->createStore();
        $product = app(CatalogWriter::class)->save($store, null, [
            'title' => 'T-shirt', 'tags' => ['Été'],
            'options' => [['name' => 'Taille', 'values' => ['M', 'L']]],
            'variants' => [['options' => ['M'], 'price' => 5000, 'sku' => 'TS-M', 'on_hand' => 4], ['options' => ['L'], 'price' => 5500, 'sku' => 'TS-L']],
        ]);
        $warehouse = StockLocation::create(['store_id' => $store->id, 'name' => 'Entrepôt', 'type' => StockLocationType::Warehouse]);

        $export = $this->csv()->export($store);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $export);
        $this->assertStringContainsString('Identifiant;Nom;Description', $export);
        $this->assertStringContainsString('"Stock : Stock principal";"Stock : Entrepôt"', $export);
        $this->assertStringContainsString('t-shirt;T-shirt;;;Été;Actif;Taille;M;;;;;TS-M;5000;;;;;oui;4;0', $export);

        // Modification dans le tableur : nouveau prix, stock à l'entrepôt
        $edited = str_replace('TS-L;5500;;;;;oui;0;0', 'TS-L;6000;;;;;oui;0;7', $export);
        $summary = $this->csv()->import($store, $edited, null);

        $this->assertSame([], $summary['errors']);
        $this->assertSame([0, 1, 2], [$summary['products_created'], $summary['products_updated'], $summary['variants']]);
        $variants = $product->variants()->get();
        $this->assertSame([$product->variants[0]->id, $product->variants[1]->id], $variants->pluck('id')->all());
        $this->assertSame(6000, $variants[1]->price);
        $this->assertSame(7, StockLevel::where('variant_id', $variants[1]->id)->where('location_id', $warehouse->id)->value('on_hand'));
    }

    public function test_a_shopify_export_is_understood(): void
    {
        $store = $this->createStore();
        $shopify = <<<'CSV'
Handle,Title,Body (HTML),Vendor,Tags,Published,Option1 Name,Option1 Value,Option2 Name,Option2 Value,Variant SKU,Variant Grams,Variant Inventory Qty,Variant Price,Variant Compare At Price,Status
robe-wax,Robe wax,<p>Belle robe</p>,Awa,"été, wax",TRUE,Couleur,Rouge,Taille,S,RW-R-S,300,5,15000.00,18000.00,active
robe-wax,,,,,,,Rouge,,M,RW-R-M,300,2,15000.00,,
robe-wax,,,,,,,Bleu,,S,RW-B-S,300,0,16000.00,,
sac,Sac,,,,TRUE,Title,Default Title,,,SAC-1,500,3,"12,500.00",,draft
CSV;

        $summary = $this->csv()->import($store, $shopify, null);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(2, $summary['products_created']);

        $robe = Product::forStore($store->id)->where('slug', 'robe-wax')->with(['variants', 'options.values', 'tags'])->first();
        $this->assertSame(['Rouge / S', 'Rouge / M', 'Bleu / S'], $robe->variants->pluck('title')->all());
        $this->assertSame(['Couleur', 'Taille'], $robe->options->pluck('name')->all());
        $this->assertSame([15000, 18000, 300], [$robe->variants[0]->price, $robe->variants[0]->compare_at_price, $robe->variants[0]->weight_g]);
        $this->assertSame(['été', 'wax'], $robe->tags->pluck('name')->all());
        $this->assertSame(5, StockLevel::where('variant_id', $robe->variants[0]->id)->value('on_hand'));

        $sac = Product::forStore($store->id)->where('slug', 'sac')->with('variants')->first();
        $this->assertFalse($sac->has_variants);
        $this->assertSame(12500, $sac->variants[0]->price);
        $this->assertSame('draft', $sac->status->value);
    }

    public function test_a_dry_run_reports_without_saving_and_errors_point_to_lines(): void
    {
        $store = $this->createStore();
        $csv = "Nom;Prix;Stock\nPagne;8 000 F;3\nSans prix;;1\nBizarre;douze;1\n";

        $summary = $this->csv()->import($store, $csv, null, dryRun: true);

        $this->assertSame(1, $summary['products_created']);
        $this->assertCount(2, $summary['errors']);
        $this->assertSame(3, $summary['errors'][0]['line']);
        $this->assertStringContainsString('prix manquant', $summary['errors'][0]['message']);
        $this->assertStringContainsString('Prix illisible « douze »', $summary['errors'][1]['message']);
        $this->assertSame(0, Product::forStore($store->id)->count());

        // Pour de bon : la ligne valide est enregistrée
        $this->csv()->import($store, $csv, null);
        $pagne = Product::forStore($store->id)->with('variants')->firstOrFail();
        $this->assertSame(8000, $pagne->variants[0]->price);
        $this->assertSame(3, StockLevel::where('variant_id', $pagne->variants[0]->id)->value('on_hand'));
    }

    public function test_windows_encoded_files_and_missing_columns(): void
    {
        $store = $this->createStore();

        $summary = $this->csv()->import($store, mb_convert_encoding("Nom;Prix\nBoucles d'oreilles dorées;3000\n", 'Windows-1252', 'UTF-8'), null);
        $this->assertSame(1, $summary['products_created']);
        $this->assertSame("Boucles d'oreilles dorées", Product::forStore($store->id)->value('title'));
        $this->assertSame(['Aucune colonne de stock : les quantités ne changent pas.'], $summary['warnings']);

        $this->expectExceptionMessage('Colonne « Nom » introuvable');
        $this->csv()->import($store, "Prix;Stock\n1;2\n", null);
    }
}
