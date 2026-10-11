<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductImageController extends Controller
{
    public const MAX_IMAGES = 20;

    public function __construct(private readonly ImageStore $images) {}

    public function store(Request $request, int $product): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.ImageStore::MAX_UPLOAD_KB],
            'alt' => ['nullable', 'string', 'max:255'],
        ], [], ['image' => 'photo']);

        $model = Product::findOrFail($product);
        if ($model->images()->count() >= self::MAX_IMAGES) {
            throw new BusinessRuleException('Vingt photos au plus par produit.', 'image');
        }

        $stored = $this->images->store($request->file('image'), $model->store_id);
        $image = $model->images()->create([
            'store_id' => $model->store_id,
            'path' => $stored['path'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'alt' => $request->input('alt') ?: $model->title,
            'position' => (int) $model->images()->max('position') + 1,
        ]);

        return response()->json(['data' => ['id' => $image->id, 'alt' => $image->alt, 'urls' => $image->urls()]], 201);
    }

    /**
     * Ordre des photos : la première est la photo principale.
     */
    public function reorder(Request $request, int $product): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $model = Product::findOrFail($product);

        DB::transaction(function () use ($request, $model) {
            foreach ($request->input('ids') as $position => $id) {
                $model->images()->whereKey($id)->update(['position' => $position]);
            }
        });

        return response()->json(['message' => 'Ordre enregistré.']);
    }

    public function destroy(int $product, int $image): JsonResponse
    {
        $model = ProductImage::where('product_id', $product)->findOrFail($image);
        $this->images->delete($model->path);
        $model->delete();

        return response()->json(['message' => 'Photo supprimée.']);
    }
}
