<?php

namespace App\Services\Live;

use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\Order;
use App\Models\Place;
use App\Models\ProductImage;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;

/**
 * Images envoyées sur WhatsApp pendant un live : photo de l'article en JPEG (WhatsApp n'affiche pas le WebP du
 * catalogue) et récapitulatif de la commande (miniatures, codes, prix, livraison, total). Fichiers publics, refaits
 * seulement quand leur contenu change.
 */
class LiveImages
{
    private const WIDTH = 800;

    /**
     * Adresse publique de la photo de l'article, ou null s'il n'en a pas.
     */
    public function item(LiveItem $item): ?string
    {
        $image = $item->variant?->image ?? $item->variant?->product?->images()->orderBy('position')->first();
        if (! $image instanceof ProductImage) {
            return null;
        }
        $path = "live/{$item->store_id}/item-{$item->variant_id}-".substr(md5($image->path), 0, 10).'.jpg';
        if (! $this->disk()->exists($path)) {
            $photo = $this->load($image);
            if ($photo === null) {
                return null;
            }
            $height = (int) round(imagesy($photo) * self::WIDTH / max(1, imagesx($photo)));
            $canvas = imagecreatetruecolor(self::WIDTH, $height);
            imagecopyresampled($canvas, $photo, 0, 0, 0, 0, self::WIDTH, $height, imagesx($photo), imagesy($photo));
            $this->save($path, $canvas);
        }

        return $this->disk()->url($path);
    }

    /**
     * Récapitulatif de la commande en image (adresse publique), ou null si l'image n'a pas pu être faite.
     */
    public function recap(Order $order, string $storeName): ?string
    {
        $order->loadMissing('items.variant.product.images', 'items.variant.image');
        $codes = LiveReservation::forStore($order->store_id)->where('order_id', $order->id)->with('item:id,code,variant_id')->get()
            ->pluck('item.code', 'item.variant_id');
        $place = $order->shipping_place_id ? Place::with('parent')->find($order->shipping_place_id)?->label() : null;
        $lines = $order->items->map(fn ($i) => [
            'code' => $codes[$i->variant_id] ?? null,
            'title' => trim($i->title.($i->variant_title ? ' ('.$i->variant_title.')' : '')),
            'detail' => $i->quantity > 1 ? $i->quantity.' × '.Money::format((int) $i->unit_price) : null,
            'price' => (int) $i->total,
            'image' => $i->variant?->image ?? $i->variant?->product?->images->first(),
        ])->values();
        $key = md5(json_encode([$lines->map(fn ($l) => [$l['code'], $l['title'], $l['detail'], $l['price']]), $place, $order->shipping_fee, $order->total, $storeName]));
        $path = "live/{$order->store_id}/recap-{$order->id}-".substr($key, 0, 10).'.jpg';
        if ($this->disk()->exists($path)) {
            return $this->disk()->url($path);
        }

        $row = 112;
        $height = 96 + $lines->count() * $row + $row + 96;
        $c = imagecreatetruecolor(self::WIDTH, $height);
        $white = imagecolorallocate($c, 255, 255, 255);
        $ink = imagecolorallocate($c, 17, 24, 39);
        $muted = imagecolorallocate($c, 100, 116, 139);
        $line = imagecolorallocate($c, 226, 232, 240);
        $soft = imagecolorallocate($c, 248, 250, 252);
        $red = imagecolorallocate($c, 220, 38, 38);
        imagefill($c, 0, 0, $white);

        // En-tête orange (couleur du live)
        for ($x = 0; $x < self::WIDTH; $x++) {
            $t = $x / self::WIDTH;
            imageline($c, $x, 0, $x, 96, imagecolorallocate($c, (int) (154 + 97 * $t), (int) (52 + 94 * $t), (int) (18 + 42 * $t)));
        }
        imagettftext($c, 30, 0, 32, 60, $white, $this->font('ExtraBold'), Str::limit('Votre commande · '.$storeName, 34));
        imagettftext($c, 24, 0, self::WIDTH - 130, 58, $white, $this->font('ExtraBold'), '● LIVE');

        $y = 96;
        foreach ($lines as $l) {
            $photo = $l['image'] ? $this->load($l['image']) : null;
            if ($photo !== null) {
                $this->cover($c, $photo, 28, $y + 14, 84, 84);
            } else {
                imagefilledrectangle($c, 28, $y + 14, 112, $y + 98, $line);
            }
            if ($l['code']) {
                imagefilledrectangle($c, 28, $y + 14, 28 + 16 + 14 * strlen($l['code']), $y + 42, $red);
                imagettftext($c, 17, 0, 34, $y + 36, $white, $this->font('ExtraBold'), $l['code']);
            }
            $price = Money::format($l['price']);
            $priceWidth = $this->width($price, 26, 'Bold');
            imagettftext($c, 26, 0, self::WIDTH - 32 - $priceWidth, $y + 66, $ink, $this->font('Bold'), $price);
            $titleY = $l['detail'] ? $y + 54 : $y + 66;
            imagettftext($c, 23, 0, 132, $titleY, $ink, $this->font('Medium'), $this->fit($l['title'], 23, 'Medium', self::WIDTH - 132 - 48 - $priceWidth));
            if ($l['detail']) {
                imagettftext($c, 19, 0, 132, $y + 88, $muted, $this->font('Medium'), $l['detail']);
            }
            imageline($c, 28, $y + $row - 1, self::WIDTH - 28, $y + $row - 1, $line);
            $y += $row;
        }

        // Livraison
        imagettftext($c, 40, 0, 44, $y + 72, $ink, $this->font('Bold'), '›');
        $fee = $order->delivery_method === 'pickup' ? 'Retrait' : ($order->shipping_fee > 0 ? Money::format((int) $order->shipping_fee) : 'Offerte');
        $feeWidth = $this->width($fee, 26, 'Bold');
        imagettftext($c, 26, 0, self::WIDTH - 32 - $feeWidth, $y + 66, $ink, $this->font('Bold'), $fee);
        imagettftext($c, 23, 0, 132, $y + 66, $ink, $this->font('Medium'), $this->fit('Livraison'.($place ? ' · '.$place : ''), 23, 'Medium', self::WIDTH - 132 - 48 - $feeWidth));
        $y += $row;

        // Total
        imagefilledrectangle($c, 0, $y, self::WIDTH, $height, $soft);
        imagettftext($c, 30, 0, 32, $y + 60, $ink, $this->font('ExtraBold'), 'Total à payer');
        $total = Money::format((int) $order->total);
        imagettftext($c, 34, 0, self::WIDTH - 32 - $this->width($total, 34, 'ExtraBold'), $y + 62, $ink, $this->font('ExtraBold'), $total);

        $this->save($path, $c);

        return $this->disk()->url($path);
    }

    private function load(ProductImage $image): ?GdImage
    {
        foreach (['large', 'medium', 'thumb'] as $size) {
            $file = "{$image->path}/{$size}.webp";
            if ($this->disk()->exists($file)) {
                $gd = @imagecreatefromstring((string) $this->disk()->get($file));

                return $gd ?: null;
            }
        }

        return null;
    }

    private function cover(GdImage $canvas, GdImage $photo, int $x, int $y, int $width, int $height): void
    {
        $sw = imagesx($photo);
        $sh = imagesy($photo);
        $scale = max($width / $sw, $height / $sh);
        $cw = (int) round($width / $scale);
        $ch = (int) round($height / $scale);
        imagecopyresampled($canvas, $photo, $x, $y, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $width, $height, $cw, $ch);
    }

    private function fit(string $text, int $size, string $weight, int $max): string
    {
        if ($this->width($text, $size, $weight) <= $max) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->width($text.'…', $size, $weight) > $max) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function width(string $text, int $size, string $weight): int
    {
        $box = imagettfbbox($size, 0, $this->font($weight), $text);

        return (int) abs($box[2] - $box[0]);
    }

    private function font(string $weight): string
    {
        return resource_path("fonts/Inter-{$weight}.otf");
    }

    private function save(string $path, GdImage $canvas): void
    {
        ob_start();
        imagejpeg($canvas, null, 85);
        $this->disk()->put($path, (string) ob_get_clean(), 'public');
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.catalog_disk', 'public'));
    }
}
