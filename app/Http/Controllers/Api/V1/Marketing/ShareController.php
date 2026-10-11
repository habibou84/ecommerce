<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Marketing\ShareImages;
use Illuminate\Http\JsonResponse;

/**
 * Partage d'un produit : lien court et image carrée pour les statuts et publications.
 */
class ShareController extends Controller
{
    public function product(int $product, ShareImages $share): JsonResponse
    {
        return response()->json(['data' => $share->forProduct($this->currentStore(), Product::findOrFail($product))]);
    }
}
