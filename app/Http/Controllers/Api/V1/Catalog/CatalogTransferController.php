<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogCsv;
use App\Services\Catalog\DeliveryAppImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Import et export du catalogue : CSV (Excel, Shopify) et reprise depuis Delivery App.
 */
class CatalogTransferController extends Controller
{
    public function export(CatalogCsv $csv): Response
    {
        $store = $this->currentStore();

        return response($csv->export($store), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="catalogue-'.$store->slug.'-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    /**
     * dry_run = 1 : rapport sans rien enregistrer (à montrer avant de confirmer).
     */
    public function import(Request $request, CatalogCsv $csv): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:5120'],
            'dry_run' => ['sometimes', 'boolean'],
        ], [], ['file' => 'fichier']);

        $summary = $csv->import($this->currentStore(), (string) file_get_contents($request->file('file')->getRealPath()), $request->user(), $request->boolean('dry_run'));

        return response()->json(['data' => $summary + ['dry_run' => $request->boolean('dry_run')]]);
    }

    public function deliveryAppPreview(Request $request, DeliveryAppImporter $importer): JsonResponse
    {
        $data = $this->deliveryAppCredentials($request);
        $products = $importer->fetch($data['address'], $data['api_key']);

        return response()->json(['data' => collect($products)->map(fn ($p) => [
            'name' => $p['name'] ?? '',
            'sku' => $p['sku'] ?? null,
            'price' => (int) ($p['price'] ?? 0),
            'at_merchant' => (int) collect($p['levels'] ?? [])->whereNull('hub_id')->sum('on_hand'),
            'at_carrier' => (int) collect($p['levels'] ?? [])->whereNotNull('hub_id')->sum('on_hand'),
        ])->values()]);
    }

    public function deliveryAppImport(Request $request, DeliveryAppImporter $importer): JsonResponse
    {
        $data = $this->deliveryAppCredentials($request);
        $summary = $importer->import($this->currentStore(), $importer->fetch($data['address'], $data['api_key']), $request->user());

        return response()->json(['data' => $summary]);
    }

    /**
     * @return array{address: string, api_key: string}
     */
    private function deliveryAppCredentials(Request $request): array
    {
        return $request->validate([
            'address' => ['required', 'string', 'max:200'],
            'api_key' => ['required', 'string', 'max:200'],
        ], [], ['address' => 'adresse de l\'entreprise de livraison', 'api_key' => 'clé API']);
    }
}
