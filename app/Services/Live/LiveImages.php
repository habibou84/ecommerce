<?php

namespace App\Services\Live;

use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\Order;
use App\Models\Place;
use App\Models\ProductImage;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
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

    /**
     * Carte d'un article pour le live (900 × 1200) : photo, code en très grand, prix (ancien prix barré), nom,
     * pièces restantes et « Envoyez A1 sur WhatsApp » ; version « VENDU » quand l'article est épuisé.
     *
     * Renvoie le chemin du fichier sur le disque du catalogue (adresse publique : url()).
     *
     * @param  array{code: string, title: string, variant: ?string, price: int, was: ?int, remaining: ?int, image: ?ProductImage}  $card
     */
    public function card(int $storeId, array $card, bool $sold = false): string
    {
        $key = substr(md5(json_encode([$card['code'], $card['title'], $card['variant'], $card['price'], $card['was'], $card['remaining'], $card['image']?->path, $sold, 5])), 0, 12);
        $path = "live/{$storeId}/card-{$card['code']}-{$key}.jpg";
        if ($this->disk()->exists($path)) {
            return $path;
        }

        [$w, $h] = [900, 1200];
        $c = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($c, 255, 255, 255);
        $ink = imagecolorallocate($c, 17, 17, 17);
        $red = imagecolorallocate($c, 220, 38, 38);
        $yellow = imagecolorallocate($c, 250, 204, 21);
        $brown = imagecolorallocate($c, 113, 63, 18);
        $night = imagecolorallocate($c, 15, 23, 42);
        $green = imagecolorallocate($c, 37, 211, 102);
        imagefill($c, 0, 0, $night);

        // Photo (ou fond coloré sans photo)
        $photoH = 780;
        $photo = $card['image'] ? $this->load($card['image']) : null;
        if ($photo !== null) {
            $this->whole($c, $photo, 0, 0, $w, $photoH);
        } else {
            for ($y = 0; $y < $photoH; $y++) {
                $t = $y / $photoH;
                imageline($c, 0, $y, $w, $y, imagecolorallocate($c, (int) (249 - 60 * $t), (int) (115 - 70 * $t), (int) (22 + 30 * $t)));
            }
        }

        // Code en très grand, cadre blanc
        $codeW = $this->width($card['code'], 150, 'ExtraBold');
        imagefilledrectangle($c, 30, 30, 30 + $codeW + 80, 250, $white);
        imagefilledrectangle($c, 42, 42, 18 + $codeW + 80, 238, $red);
        imagettftext($c, 150, 0, 70, 215, $white, $this->font('ExtraBold'), $card['code']);

        // Pièces restantes
        if ($card['remaining'] !== null && ! $sold) {
            $label = $card['remaining'] === 1 ? 'Dernière pièce' : $card['remaining'].' pièces';
            $lw = $this->width($label, 36, 'Bold');
            imagefilledrectangle($c, $w - 60 - $lw, 40, $w - 30, 110, imagecolorallocatealpha($c, 15, 23, 42, 20));
            imagettftext($c, 36, 0, $w - 45 - $lw, 92, $white, $this->font('Bold'), $label);
        }

        // Bande jaune : prix, ancien prix barré, nom
        imagefilledrectangle($c, 0, $photoH, $w, $h - 120, $yellow);
        $price = Money::format($card['price']);
        $pw = $this->width($price, 96, 'ExtraBold');
        imagettftext($c, 96, 0, 40, $photoH + 125, $ink, $this->font('ExtraBold'), $price);
        if ($card['was']) {
            $was = Money::format($card['was']);
            $ww = $this->width($was, 44, 'Bold');
            imagettftext($c, 44, 0, 70 + $pw, $photoH + 122, $brown, $this->font('Bold'), $was);
            imagefilledrectangle($c, 68 + $pw, $photoH + 103, 72 + $pw + $ww, $photoH + 108, $brown);
        }
        $name = $card['title'].($card['variant'] ? ' · '.$card['variant'] : '');
        imagettftext($c, 46, 0, 40, $photoH + 210, $ink, $this->font('Bold'), $this->fit($name, 46, 'Bold', $w - 80));

        // Pied : appel à commander
        imagefilledrectangle($c, 0, $h - 120, $w, $h, $night);
        imagefilledellipse($c, 75, $h - 72, 56, 56, $green);
        imagefilledellipse($c, 75, $h - 72, 22, 22, $white);
        imagettftext($c, 36, 0, 125, $h - 58, $white, $this->font('ExtraBold'), $this->fit("Envoyez {$card['code']} sur WhatsApp", 36, 'ExtraBold', $w - 160));
        $this->brand($c, $w, $h - 16, imagecolorallocate($c, 148, 163, 184));

        if ($sold) {
            $this->stamp($c, 'VENDU', (int) ($w / 2), (int) ($photoH / 2) + 40, $red);
        }

        $this->save($path, $c);

        return $path;
    }

    /**
     * Carte « Comment commander » : 4 étapes, numéro WhatsApp et QR code qui ouvre la conversation (chemin du
     * fichier).
     */
    public function howCard(int $storeId, string $storeName, ?string $phone, ?string $whatsappUrl): string
    {
        $key = substr(md5(json_encode([$storeName, $phone, $whatsappUrl, 5])), 0, 12);
        $path = "live/{$storeId}/how-{$key}.jpg";
        if ($this->disk()->exists($path)) {
            return $path;
        }

        [$w, $h] = [900, 1200];
        $c = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            imageline($c, 0, $y, $w, $y, imagecolorallocate($c, (int) (4 - 2 * $t), (int) (120 - 76 * $t), (int) (87 - 53 * $t)));
        }
        $white = imagecolorallocate($c, 255, 255, 255);
        $yellow = imagecolorallocate($c, 250, 204, 21);
        $ink = imagecolorallocate($c, 17, 17, 17);
        $deep = imagecolorallocate($c, 6, 78, 59);

        imagettftext($c, 76, 0, 60, 150, $white, $this->font('ExtraBold'), 'Comment');
        imagettftext($c, 76, 0, 60, 245, $white, $this->font('ExtraBold'), 'commander ?');
        $steps = ['Notez vos codes (A1, A2…)', 'Envoyez « A1 + A3 » sur WhatsApp', 'Touchez votre commune', 'On vous livre'];
        foreach ($steps as $i => $step) {
            $y = 340 + $i * 105;
            imagefilledellipse($c, 100, $y, 76, 76, $yellow);
            imagettftext($c, 40, 0, 86, $y + 15, $ink, $this->font('ExtraBold'), (string) ($i + 1));
            imagettftext($c, $this->size($step, 42, 'Bold', $w - 200), 0, 160, $y + 15, $white, $this->font('Bold'), $step);
        }

        // Encadré blanc : QR code et numéro
        imagefilledrectangle($c, 50, 790, $w - 50, $h - 60, $white);
        if ($whatsappUrl) {
            $png = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'scale' => 10, 'outputBase64' => false, 'imageTransparent' => false])))->render($whatsappUrl);
            $qr = @imagecreatefromstring($png);
            if ($qr) {
                imagecopyresampled($c, $qr, 80, 820, 0, 0, 290, 290, imagesx($qr), imagesy($qr));
            }
        }
        imagettftext($c, 30, 0, 400, 880, $deep, $this->font('Bold'), $this->fit('WhatsApp '.$storeName, 30, 'Bold', $w - 470));
        imagettftext($c, $this->size($phone ?? '', 42, 'ExtraBold', $w - 460), 0, 400, 955, $deep, $this->font('ExtraBold'), $phone ?? '');
        imagettftext($c, 28, 0, 400, 1030, $deep, $this->font('Medium'), 'Scannez pour écrire');
        imagettftext($c, 28, 0, 400, 1075, $deep, $this->font('Medium'), 'Paiement à la livraison');
        $this->brand($c, $w, $h - 18, imagecolorallocate($c, 167, 243, 208));

        $this->save($path, $c);

        return $path;
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    public function contents(string $path): string
    {
        return (string) $this->disk()->get($path);
    }

    /**
     * Tampon incliné (texte et cadre), centré sur (cx, cy).
     */
    private function stamp(GdImage $c, string $text, int $cx, int $cy, int $color): void
    {
        $size = 130;
        $angle = 14;
        $tw = $this->width($text, $size, 'ExtraBold');
        $th = 120;
        $rad = deg2rad($angle);
        [$cos, $sin] = [cos($rad), sin($rad)];
        $corner = fn (float $x, float $y) => [(int) round($cx + $x * $cos + $y * $sin), (int) round($cy - $x * $sin + $y * $cos)];
        $box = fn (float $pad) => array_merge(...array_map(fn ($p) => $corner($p[0], $p[1]), [[-$tw / 2 - $pad, -$th / 2 - $pad], [$tw / 2 + $pad, -$th / 2 - $pad], [$tw / 2 + $pad, $th / 2 + $pad], [-$tw / 2 - $pad, $th / 2 + $pad]]));
        imagefilledpolygon($c, $box(50), $color);
        imagefilledpolygon($c, $box(36), imagecolorallocatealpha($c, 255, 255, 255, 10));
        [$x, $y] = $corner(-$tw / 2, $th / 2 - 8);
        imagettftext($c, $size, $angle, $x, $y, $color, $this->font('ExtraBold'), $text);
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

    // Photo entière (rien n'est coupé) sur un fond flou et assombri de la même photo
    private function whole(GdImage $canvas, GdImage $photo, int $x, int $y, int $width, int $height): void
    {
        // Fond : la photo réduite, floutée puis agrandie par paliers (sans effet de petits carrés)
        $layer = imagecreatetruecolor(max(1, intdiv($width, 16)), max(1, intdiv($height, 16)));
        $this->cover($layer, $photo, 0, 0, imagesx($layer), imagesy($layer));
        foreach ([4, 1] as $step) {
            for ($i = 0; $i < 3; $i++) {
                imagefilter($layer, IMG_FILTER_GAUSSIAN_BLUR);
            }
            $next = imagecreatetruecolor(max(1, intdiv($width, $step)), max(1, intdiv($height, $step)));
            imagecopyresampled($next, $layer, 0, 0, 0, 0, imagesx($next), imagesy($next), imagesx($layer), imagesy($layer));
            $layer = $next;
        }
        imagefilter($layer, IMG_FILTER_GAUSSIAN_BLUR);
        imagecopy($canvas, $layer, $x, $y, 0, 0, $width, $height);
        imagefilledrectangle($canvas, $x, $y, $x + $width, $y + $height, imagecolorallocatealpha($canvas, 0, 0, 0, 80));
        $sw = imagesx($photo);
        $sh = imagesy($photo);
        $scale = min($width / $sw, $height / $sh);
        $dw = (int) round($sw * $scale);
        $dh = (int) round($sh * $scale);
        imagecopyresampled($canvas, $photo, $x + intdiv($width - $dw, 2), $y + intdiv($height - $dh, 2), 0, 0, $dw, $dh, $sw, $sh);
    }

    /** « Propulsé par Jibiat Marchand · jibiat-marchand.com », centré, en petit au pied de l'image */
    private function brand(GdImage $c, int $w, int $baseline, int $color): void
    {
        $text = 'Propulsé par Jibiat Marchand · jibiat-marchand.com';
        imagettftext($c, 20, 0, (int) (($w - $this->width($text, 20, 'Medium')) / 2), $baseline, $color, $this->font('Medium'), $text);
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

    /** Taille de police la plus grande (jusqu'à $max) pour que le texte tienne entier dans la largeur */
    private function size(string $text, int $max, string $weight, int $width): int
    {
        $size = $max;
        while ($size > 18 && $this->width($text, $size, $weight) > $width) {
            $size--;
        }

        return $size;
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
