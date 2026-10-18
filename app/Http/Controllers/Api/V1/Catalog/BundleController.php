<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\Bundles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contenu d'un lot (pack).
 */
class BundleController extends Controller
{
    public function __construct(private readonly Bundles $bundles) {}

    public function show(int $product): JsonResponse
    {
        $model = Product::findOrFail($product);

        return response()->json(['data' => ['is_bundle' => $model->is_bundle, 'components' => $this->bundles->components($model)]]);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $data = $request->validate([
            'components' => ['present', 'array', 'max:20'],
            'components.*.variant_id' => ['required', 'integer', 'distinct'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['components' => 'articles du lot']);
        $model = $this->bundles->save(Product::findOrFail($product), $data['components']);

        return response()->json(['data' => ['is_bundle' => $model->is_bundle, 'components' => $this->bundles->components($model)]]);
    }
}
