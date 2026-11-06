<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Services\Catalog\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Un article sans photo ne passe pas en live : les produits créés par ces tests reçoivent une photo.
 */
trait LiveProductsHavePhotos
{
    protected function giveProductsPhotos(): void
    {
        Storage::fake('public');
        Product::created(function (Product $product) {
            if ($product->images()->exists()) {
                return;
            }
            $stored = app(ImageStore::class)->store(UploadedFile::fake()->image('photo.jpg', 600, 800), $product->store_id);
            $product->images()->create([
                'store_id' => $product->store_id, 'path' => $stored['path'], 'width' => $stored['width'],
                'height' => $stored['height'], 'alt' => $product->title, 'position' => 0,
            ]);
        });
    }
}
