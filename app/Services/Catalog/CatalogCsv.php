<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Services\Stock\StockKeeper;
use App\Support\SpreadsheetCell;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import et export du catalogue en CSV : une ligne par variante, une colonne de stock par emplacement.
 *
 * L'export utilise des en-têtes français et le point-virgule (Excel en français). L'import accepte aussi
 * les en-têtes d'un export Shopify, pour faciliter les migrations. Les photos ne sont pas importées.
 */
class CatalogCsv
{
    public const MAX_LINES = 2000;

    private const HEADERS = [
        'handle' => 'Identifiant', 'title' => 'Nom', 'description' => 'Description', 'brand' => 'Marque', 'tags' => 'Étiquettes',
        'status' => 'Statut', 'option1_name' => 'Option1 nom', 'option1_value' => 'Option1 valeur', 'option2_name' => 'Option2 nom',
        'option2_value' => 'Option2 valeur', 'option3_name' => 'Option3 nom', 'option3_value' => 'Option3 valeur', 'sku' => 'SKU',
        'price' => 'Prix', 'compare_at_price' => 'Prix barré', 'cost_price' => 'Coût', 'weight_g' => 'Poids (g)',
        'barcode' => 'Code-barres', 'is_active' => 'Variante active',
    ];

    // En-têtes reconnus à l'import (minuscules, sans accents) → colonne
    private const ALIASES = [
        'identifiant' => 'handle', 'handle' => 'handle',
        'nom' => 'title', 'title' => 'title', 'name' => 'title', 'produit' => 'title',
        'description' => 'description', 'body (html)' => 'description', 'body' => 'description',
        'marque' => 'brand', 'vendor' => 'brand',
        'etiquettes' => 'tags', 'tags' => 'tags',
        'statut' => 'status', 'status' => 'status',
        'sku' => 'sku', 'variant sku' => 'sku', 'reference' => 'sku',
        'prix' => 'price', 'price' => 'price', 'variant price' => 'price',
        'prix barre' => 'compare_at_price', 'variant compare at price' => 'compare_at_price', 'compare at price' => 'compare_at_price',
        'cout' => 'cost_price', 'cost per item' => 'cost_price', "prix d'achat" => 'cost_price',
        'poids (g)' => 'weight_g', 'variant grams' => 'weight_g', 'poids' => 'weight_g',
        'code-barres' => 'barcode', 'code barre' => 'barcode', 'variant barcode' => 'barcode',
        'variante active' => 'is_active',
        'stock' => 'stock_default', 'variant inventory qty' => 'stock_default', 'quantite' => 'stock_default',
    ];

    public function __construct(
        private readonly CatalogWriter $writer,
        private readonly StockKeeper $stock,
    ) {}

    public function export(Store $store): string
    {
        $locations = StockLocation::forStore($store->id)->where('is_active', true)->orderBy('priority')->orderBy('id')->get();
        $levels = StockLevel::forStore($store->id)->get()->groupBy('variant_id');

        $rows = [array_merge(array_values(self::HEADERS), $locations->map(fn ($l) => "Stock : {$l->name}")->all())];

        Product::forStore($store->id)->with(['options.values', 'variants.option1', 'variants.option2', 'variants.option3', 'tags'])
            ->orderBy('title')->chunk(200, function ($products) use (&$rows, $locations, $levels) {
                foreach ($products as $product) {
                    foreach ($product->variants as $variant) {
                        $row = [
                            $product->slug, $product->title, $product->description, $product->brand,
                            $product->tags->pluck('name')->implode(', '), $product->status->label(),
                        ];
                        foreach ([1, 2, 3] as $n) {
                            $option = $product->options->firstWhere('position', $n);
                            $row[] = $option?->name;
                            $row[] = $variant->{"option{$n}"}?->value;
                        }
                        array_push($row, $variant->sku, $variant->price, $variant->compare_at_price, $variant->cost_price,
                            $variant->weight_g, $variant->barcode, $variant->is_active ? 'oui' : 'non');
                        foreach ($locations as $location) {
                            $row[] = (int) ($levels->get($variant->id)?->firstWhere('location_id', $location->id)?->on_hand ?? 0);
                        }
                        $rows[] = $row;
                    }
                }
            });

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8
        foreach ($rows as $row) {
            fputcsv($out, SpreadsheetCell::row($row), ';', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /**
     * Importe (ou simule, $dryRun) le fichier. Chaque produit est enregistré à part : une erreur sur un produit
     * n'empêche pas les autres. En simulation, rien n'est conservé.
     *
     * @return array{products_created: int, products_updated: int, variants: int, errors: list<array{line: int, message: string}>, warnings: list<string>}
     */
    public function import(Store $store, string $contents, ?User $actor, bool $dryRun = false): array
    {
        [$header, $lines] = $this->parse($contents);
        $columns = $this->mapColumns($store, $header);
        $groups = $this->group($lines, $columns);

        $summary = ['products_created' => 0, 'products_updated' => 0, 'variants' => 0, 'errors' => [], 'warnings' => []];
        if (array_filter($columns, fn ($c) => str_starts_with((string) $c, 'stock_location:') || $c === 'stock_default') === []) {
            $summary['warnings'][] = 'Aucune colonne de stock : les quantités ne changent pas.';
        }

        DB::beginTransaction();
        try {
            foreach ($groups as $group) {
                try {
                    // Compteurs mis à jour seulement si le produit est entièrement enregistré
                    [$created, $variants] = DB::transaction(fn () => $this->importGroup($store, $group, $actor));
                    $summary[$created ? 'products_created' : 'products_updated']++;
                    $summary['variants'] += $variants;
                } catch (BusinessRuleException $e) {
                    $summary['errors'][] = ['line' => $group['line'], 'message' => "« {$group['label']} » : {$e->getMessage()}"];
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $summary;
    }

    /**
     * @return array{0: list<string>, 1: list<array{line: int, cells: list<string>}>}
     */
    private function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle, null, $delimiter, '"', '') ?: [];
        $lines = [];
        $number = 1;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $number++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (count($lines) >= self::MAX_LINES) {
                throw new BusinessRuleException('Le fichier dépasse '.self::MAX_LINES.' lignes : découpez-le.', 'file');
            }
            // Apostrophe ajoutée à l'export devant un texte commençant par = + - @ : retirée à la relecture
            $lines[] = ['line' => $number, 'cells' => array_map(fn ($c) => preg_replace("/^'(?=[=+\-@])/", '', trim((string) $c)), $cells)];
        }

        if ($header === [] || $lines === []) {
            throw new BusinessRuleException('Le fichier est vide.', 'file');
        }

        return [$header, $lines];
    }

    /**
     * @return array<int, string|null> index de colonne → clé (null = ignorée)
     */
    private function mapColumns(Store $store, array $header): array
    {
        $locations = StockLocation::forStore($store->id)->where('is_active', true)->get();

        $columns = array_map(function ($name) use ($locations) {
            $key = Str::of((string) $name)->ascii()->lower()->trim()->toString();

            if (preg_match('/^option\s*([123])\s*(nom|name|valeur|value)$/', $key, $m)) {
                return 'option'.$m[1].(in_array($m[2], ['nom', 'name'], true) ? '_name' : '_value');
            }
            if (preg_match('/^stock\s*:\s*(.+)$/', $key, $m)) {
                $location = $locations->first(fn ($l) => Str::of($l->name)->ascii()->lower()->trim()->toString() === trim($m[1]));

                return $location ? 'stock_location:'.$location->id : null;
            }

            return self::ALIASES[$key] ?? null;
        }, $header);

        if (! in_array('title', $columns, true) && ! in_array('handle', $columns, true)) {
            throw new BusinessRuleException('Colonne « Nom » introuvable. Partez du modèle obtenu avec « Exporter ».', 'file');
        }

        return $columns;
    }

    /**
     * Regroupe les lignes par produit : même identifiant, ou même nom sans identifiant. Une ligne sans nom ni
     * identifiant continue le produit précédent (format Shopify).
     *
     * @return list<array{key: string, label: string, line: int, rows: list<array{line: int, data: array<string, string>}>}>
     */
    private function group(array $lines, array $columns): array
    {
        $groups = [];
        $current = null;

        foreach ($lines as $line) {
            $data = [];
            foreach ($columns as $index => $key) {
                if ($key !== null && ($line['cells'][$index] ?? '') !== '') {
                    $data[$key] = $line['cells'][$index];
                }
            }

            $key = isset($data['handle']) ? Str::slug($data['handle']) : (isset($data['title']) ? Str::slug($data['title']) : $current);
            if ($key === null) {
                continue;
            }

            $groups[$key] ??= ['key' => $key, 'label' => $data['title'] ?? $data['handle'] ?? $key, 'line' => $line['line'], 'rows' => []];
            $groups[$key]['rows'][] = ['line' => $line['line'], 'data' => $data];
            $current = $key;
        }

        return array_values($groups);
    }

    /**
     * @return array{0: bool, 1: int} produit créé ?, nombre de variantes
     */
    private function importGroup(Store $store, array $group, ?User $actor): array
    {
        $first = collect($group['rows'])->first(fn ($r) => isset($r['data']['title']))['data'] ?? $group['rows'][0]['data'];
        $product = Product::forStore($store->id)->where('slug', $group['key'])->with('variants')->first();

        if ($product === null && ! isset($first['title'])) {
            throw new BusinessRuleException('nom du produit manquant.');
        }

        // Options : noms de la première ligne, valeurs dans l'ordre d'apparition
        $options = [];
        foreach ([1, 2, 3] as $n) {
            $name = $first["option{$n}_name"] ?? null;
            // Shopify : produit simple = « Title / Default Title »
            if ($name === null || ($n === 1 && strtolower($name) === 'title' && strtolower($first['option1_value'] ?? '') === 'default title')) {
                break;
            }
            $values = collect($group['rows'])->pluck("data.option{$n}_value")->filter()->unique(fn ($v) => mb_strtolower($v))->values()->all();
            $options[] = ['name' => $name, 'values' => $values];
        }

        $variants = [];
        foreach ($group['rows'] as $row) {
            $data = $row['data'];
            $existing = $product && isset($data['sku']) ? $product->variants->firstWhere('sku', $data['sku']) : null;
            $variant = array_filter([
                'id' => $existing?->id,
                'options' => array_map(fn ($n) => $data["option{$n}_value"] ?? '', array_keys(array_fill(1, count($options), true))),
                'sku' => $data['sku'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'price' => $this->amount($data['price'] ?? null, $row['line'], 'Prix'),
                'compare_at_price' => $this->amount($data['compare_at_price'] ?? null, $row['line'], 'Prix barré'),
                'cost_price' => $this->amount($data['cost_price'] ?? null, $row['line'], 'Coût'),
                'weight_g' => $this->amount($data['weight_g'] ?? null, $row['line'], 'Poids'),
            ], fn ($v) => $v !== null);
            if (isset($data['is_active'])) {
                $variant['is_active'] = ! in_array(Str::ascii(mb_strtolower($data['is_active'])), ['non', 'no', 'false', '0'], true);
            }
            if (! isset($variant['price']) && $existing === null) {
                throw new BusinessRuleException("prix manquant (ligne {$row['line']}).");
            }
            $variants[] = $variant;
        }

        $payload = array_filter([
            'title' => $first['title'] ?? null,
            'description' => $first['description'] ?? null,
            'brand' => $first['brand'] ?? null,
            'status' => isset($first['status']) ? $this->status($first['status']) : null,
            'tags' => isset($first['tags']) ? array_map('trim', explode(',', $first['tags'])) : null,
        ], fn ($v) => $v !== null) + ['options' => $options, 'variants' => $variants];
        if ($product === null) {
            $payload['slug'] = $group['key'];
        }

        $saved = $this->writer->save($store, $product, $payload, $actor);
        $this->importStock($store, $saved, $group['rows'], $options, $actor);

        return [$product === null, count($variants)];
    }

    private function importStock(Store $store, Product $product, array $rows, array $options, ?User $actor): void
    {
        $variants = $product->variants()->with(['option1', 'option2', 'option3'])->get();

        foreach ($rows as $index => $row) {
            $variant = $this->matchVariant($variants, $row['data'], count($options), $index);
            foreach ($row['data'] as $key => $value) {
                $location = match (true) {
                    $key === 'stock_default' => $this->stock->defaultLocation($store),
                    str_starts_with($key, 'stock_location:') => StockLocation::forStore($store->id)->find((int) substr($key, 15)),
                    default => null,
                };
                if ($location !== null && $variant !== null) {
                    $quantity = $this->amount($value, $row['line'], 'Stock');
                    $this->stock->setOnHand($actor, $variant->setRelation('product', $product), $location, (int) $quantity, StockMovementType::Import, 'Import CSV');
                }
            }
        }
    }

    private function matchVariant($variants, array $data, int $optionCount, int $index): ?ProductVariant
    {
        if ($optionCount === 0) {
            return $variants->first();
        }
        if (isset($data['sku']) && ($bySku = $variants->firstWhere('sku', $data['sku']))) {
            return $bySku;
        }

        return $variants->first(function (ProductVariant $v) use ($data, $optionCount) {
            foreach (range(1, $optionCount) as $n) {
                if (mb_strtolower((string) $v->{"option{$n}"}?->value) !== mb_strtolower($data["option{$n}_value"] ?? '')) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * « 12 500 », « 12500 F », « 12500.00 » (Shopify) → 12500.
     */
    private function amount(?string $value, int $line, string $column): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $clean = preg_replace('/[^\d.,\-]/u', '', $value);
        // « 12,500.00 » ou « 12.500,00 » : le dernier séparateur est la virgule décimale, l'autre sépare les milliers
        if (str_contains($clean, '.') && str_contains($clean, ',')) {
            $decimal = strrpos($clean, '.') > strrpos($clean, ',') ? '.' : ',';
            $clean = str_replace([$decimal === '.' ? ',' : '.', $decimal], ['', '.'], $clean);
        }
        if (preg_match('/^-?\d+([.,]\d{1,2})?$/', $clean)) {
            $number = (int) round((float) str_replace(',', '.', $clean));
        } elseif (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $clean)) {
            $number = (int) preg_replace('/[.,]/', '', $clean);
        } else {
            throw new BusinessRuleException("{$column} illisible « {$value} » (ligne {$line}).");
        }

        if ($number < 0) {
            throw new BusinessRuleException("{$column} négatif (ligne {$line}).");
        }

        return $number;
    }

    private function status(string $value): string
    {
        return match (Str::of($value)->ascii()->lower()->trim()->toString()) {
            'brouillon', 'draft' => ProductStatus::Draft->value,
            'archive', 'archived' => ProductStatus::Archived->value,
            default => ProductStatus::Active->value,
        };
    }
}
