<?php

namespace App\Services\Marketing;

use App\Models\Product;
use App\Models\ShortLink;
use App\Models\Store;
use App\Services\Promotions\Pricing;
use App\Services\Storefront\ThemeSettings;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;

/**
 * Partage d'un produit sur les réseaux : lien court ({slug}.jibiat-marchand.com/p/{code}) et image carrée
 * 1080 × 1080 (photo, nom, prix, boutique), prête pour un statut WhatsApp, Facebook ou TikTok.
 */
class ShareImages
{
    private const SIZE = 1080;

    private const PHOTO_HEIGHT = 760;

    public function __construct(private readonly Pricing $pricing, private readonly ThemeSettings $themes) {}

    /**
     * @return array{url: string, image_url: string, text: string, clicks: int}
     */
    public function forProduct(Store $store, Product $product): array
    {
        $link = ShortLink::forStore($store->id)->firstOrCreate(
            ['store_id' => $store->id, 'target_type' => 'product', 'target_id' => $product->id],
            ['code' => $this->newCode()],
        );
        $url = $store->url('/p/'.$link->code);

        $product->loadMissing(['images' => fn ($q) => $q->orderBy('position'), 'variants' => fn ($q) => $q->where('is_active', true)]);
        $price = $this->price($store, $product);

        // Image refaite à chaque partage : prix et vente flash du moment
        $path = "stores/{$store->id}/share/product-{$product->id}.jpg";
        $this->disk()->put($path, $this->render($store, $product, $price, $url), 'public');
        $link->forceFill(['share_image_path' => $path])->save();

        $text = $product->title.' — '.Money::format($price['price'])
            .($price['compare_at'] ? ' au lieu de '.Money::format($price['compare_at']) : '')
            ."\nCommandez ici : ".$url;

        return [
            'url' => $url,
            'image_url' => $this->disk()->url($path).'?v='.now()->timestamp,
            'text' => $text,
            'clicks' => (int) $link->clicks,
        ];
    }

    /**
     * Prix le plus bas parmi les variantes en vente (vente flash comprise).
     *
     * @return array{price: int, compare_at: ?int, flash: bool, from: bool}
     */
    private function price(Store $store, Product $product): array
    {
        $context = $this->pricing->context($store->id, [$product->id]);
        $prices = $product->variants->each->setRelation('product', $product)->map(fn ($v) => $this->pricing->unit($context, $v));
        $best = $prices->sortBy('price')->first() ?? ['price' => (int) $product->price_min, 'compare_at' => null, 'discount' => null];

        return [
            'price' => $best['price'],
            'compare_at' => $best['compare_at'],
            'flash' => $best['discount'] !== null,
            'from' => $prices->pluck('price')->unique()->count() > 1,
        ];
    }

    /**
     * @param  array{price: int, compare_at: ?int, flash: bool, from: bool}  $price
     */
    private function render(Store $store, Product $product, array $price, string $url): string
    {
        $theme = $this->themes->resolve($store)['settings'];
        [$r, $g, $b] = $this->rgb((string) ($theme['primary_color'] ?? '#047857'));
        $dark = (0.299 * $r + 0.587 * $g + 0.114 * $b) < 150;

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        $brand = imagecolorallocate($canvas, $r, $g, $b);
        $text = $dark ? imagecolorallocate($canvas, 255, 255, 255) : imagecolorallocate($canvas, 17, 24, 39);
        $muted = $dark ? imagecolorallocatealpha($canvas, 255, 255, 255, 40) : imagecolorallocatealpha($canvas, 17, 24, 39, 40);
        imagefill($canvas, 0, 0, $brand);

        $photo = $this->photo($product);
        if ($photo !== null) {
            $this->cover($canvas, $photo, 0, 0, self::SIZE, self::PHOTO_HEIGHT);
        }
        $top = $photo !== null ? self::PHOTO_HEIGHT : 280;

        // Badge de promotion sur la photo
        if ($price['compare_at']) {
            $percent = (int) round(100 - $price['price'] * 100 / $price['compare_at']);
            $label = $price['flash'] ? "VENTE FLASH −{$percent} %" : "PROMO −{$percent} %";
            $red = imagecolorallocate($canvas, 220, 38, 38);
            $width = $this->textWidth($label, 34, 'ExtraBold');
            imagefilledrectangle($canvas, 40, 40, 40 + $width + 48, 40 + 72, $red);
            imagettftext($canvas, 34, 0, 64, 40 + 52, imagecolorallocate($canvas, 255, 255, 255), $this->font('ExtraBold'), $label);
        }

        // Nom (2 lignes au plus), prix, boutique et lien
        $y = $top + 78;
        foreach (array_slice($this->wrap($product->title, 44, 'Bold', self::SIZE - 120), 0, 2) as $line) {
            imagettftext($canvas, 44, 0, 60, $y, $text, $this->font('Bold'), $line);
            $y += 60;
        }
        $priceText = ($price['from'] ? 'dès ' : '').Money::format($price['price']);
        $priceY = max($y + 36, $top + 210);
        imagettftext($canvas, 64, 0, 60, $priceY, $text, $this->font('ExtraBold'), $priceText);
        if ($price['compare_at']) {
            $x = 60 + $this->textWidth($priceText, 64, 'ExtraBold') + 30;
            $old = Money::format($price['compare_at']);
            imagettftext($canvas, 38, 0, $x, $priceY, $muted, $this->font('Medium'), $old);
            imagesetthickness($canvas, 4);
            imageline($canvas, $x - 4, $priceY - 14, $x + $this->textWidth($old, 38, 'Medium') + 4, $priceY - 14, $muted);
        }
        $footer = $store->name.'  ·  '.preg_replace('#^https?://#', '', $url);
        imagettftext($canvas, 28, 0, 60, self::SIZE - 40, $muted, $this->font('Medium'), Str::limit($footer, 60));

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }

    private function photo(Product $product): ?GdImage
    {
        $image = $product->images->first();
        if ($image === null) {
            return null;
        }
        foreach (['large', 'medium'] as $size) {
            $file = "{$image->path}/{$size}.webp";
            if ($this->catalogDisk()->exists($file)) {
                $gd = @imagecreatefromstring((string) $this->catalogDisk()->get($file));

                return $gd ?: null;
            }
        }

        return null;
    }

    /**
     * Recadre la photo pour remplir la zone (comme object-fit: cover).
     */
    private function cover(GdImage $canvas, GdImage $photo, int $x, int $y, int $width, int $height): void
    {
        $sw = imagesx($photo);
        $sh = imagesy($photo);
        $scale = max($width / $sw, $height / $sh);
        $cw = (int) round($width / $scale);
        $ch = (int) round($height / $scale);
        imagecopyresampled($canvas, $photo, $x, $y, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $width, $height, $cw, $ch);
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $size, string $weight, int $max): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $this->textWidth($candidate, $size, $weight) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) > 2) {
            $lines[1] = Str::limit($lines[1], max(1, mb_strlen($lines[1]) - 2), '…');
        }

        return $lines;
    }

    private function textWidth(string $text, int $size, string $weight): int
    {
        $box = imagettfbbox($size, 0, $this->font($weight), $text);

        return (int) abs($box[2] - $box[0]);
    }

    private function font(string $weight): string
    {
        return resource_path("fonts/Inter-{$weight}.otf");
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return strlen($hex) === 6 ? [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))] : [4, 120, 87];
    }

    private function newCode(): string
    {
        do {
            $code = Str::lower(Str::random(6));
        } while (ShortLink::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    private function disk(): Filesystem
    {
        return $this->catalogDisk();
    }

    private function catalogDisk(): Filesystem
    {
        return Storage::disk(config('filesystems.catalog_disk', 'public'));
    }
}
