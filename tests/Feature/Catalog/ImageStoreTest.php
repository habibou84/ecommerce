<?php

namespace Tests\Feature\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Services\Catalog\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageStoreTest extends TestCase
{
    public function test_a_photo_becomes_three_webp_sizes(): void
    {
        Storage::fake('public');

        $stored = app(ImageStore::class)->store(UploadedFile::fake()->image('robe.jpg', 2400, 1800), 7);

        $this->assertSame([2400, 1800], [$stored['width'], $stored['height']]);
        $this->assertStringStartsWith('stores/7/products/', $stored['path']);
        foreach (['thumb' => 400, 'medium' => 800, 'large' => 1600] as $name => $size) {
            Storage::disk('public')->assertExists("{$stored['path']}/{$name}.webp");
            [$width] = getimagesizefromstring(Storage::disk('public')->get("{$stored['path']}/{$name}.webp"));
            $this->assertSame($size, $width);
        }

        app(ImageStore::class)->delete($stored['path']);
        Storage::disk('public')->assertMissing("{$stored['path']}/thumb.webp");
    }

    public function test_small_photos_are_not_enlarged_and_bad_files_are_refused(): void
    {
        Storage::fake('public');
        $stored = app(ImageStore::class)->store(UploadedFile::fake()->image('petit.png', 300, 200), 7);
        [$width] = getimagesizefromstring(Storage::disk('public')->get("{$stored['path']}/large.webp"));
        $this->assertSame(300, $width);

        $this->expectException(BusinessRuleException::class);
        app(ImageStore::class)->store(UploadedFile::fake()->create('notes.pdf', 10), 7);
    }
}
