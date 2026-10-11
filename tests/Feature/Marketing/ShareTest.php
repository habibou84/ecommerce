<?php

namespace Tests\Feature\Marketing;

use App\Models\ShortLink;
use App\Services\Catalog\CatalogWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShareTest extends TestCase
{
    public function test_share_link_and_square_image(): void
    {
        Storage::fake('public');
        $store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $store->forceFill(['storefront_enabled' => true])->save();
        $robe = app(CatalogWriter::class)->save($store, null, ['title' => 'Robe wax évasée en pagne hollandais, coupe longue et ceinture assortie', 'variants' => [['price' => 15000, 'compare_at_price' => 20000, 'on_hand' => 5]]]);
        $this->actingAs($store->owner);
        $this->withCredentials()->withHeaders(['Referer' => $this->storeUrl($store, '/gestion')])
            ->post($this->storeUrl($store, "/api/v1/products/{$robe->id}/images"), ['image' => UploadedFile::fake()->image('a.jpg', 900, 1200)], ['Accept' => 'application/json'])
            ->assertCreated();

        $share = $this->storeApi($store, 'POST', "products/{$robe->id}/share")->assertOk()->json('data');
        $link = ShortLink::sole();
        $this->assertSame($this->storeUrl($store, '/p/'.$link->code), $share['url']);
        $this->assertStringContainsString('Robe wax évasée', $share['text']);
        $this->assertStringContainsString('15 000 F au lieu de 20 000 F', $share['text']);

        // Image carrée JPEG 1080 × 1080
        [$width, $height, $type] = getimagesizefromstring(Storage::disk('public')->get($link->share_image_path));
        $this->assertSame([1080, 1080, IMAGETYPE_JPEG], [$width, $height, $type]);

        // Même lien au partage suivant
        $this->storeApi($store, 'POST', "products/{$robe->id}/share")->assertJsonPath('data.url', $share['url']);
        $this->assertSame(1, ShortLink::count());

        $this->get($share['url'])->assertRedirect('/produits/'.$robe->slug.'?utm_source=partage&utm_medium=lien_court&utm_campaign='.$link->code);
        $this->assertSame(1, $link->fresh()->clicks);
        $this->get($this->storeUrl($store, '/p/zzzzzz'))->assertRedirect('/');
    }
}
