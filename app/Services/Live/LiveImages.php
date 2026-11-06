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

    public const PIN_PER_IMAGE = 12;

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
     * pièces restantes et « Envoie A1 + ta taille » (sans le mot WhatsApp, pour la modération de TikTok) ; version
     * « VENDU » quand l'article est épuisé.
     *
     * Renvoie le chemin du fichier sur le disque du catalogue (adresse publique : url()).
     *
     * @param  array{code: string, title: string, variant: ?string, price: int, was: ?int, remaining: ?int, image: ?ProductImage}  $card
     */
    public function card(int $storeId, array $card, bool $sold = false): string
    {
        $key = substr(md5(json_encode([$card['code'], $card['title'], $card['variant'], $card['price'], $card['was'], $card['remaining'], $card['image']?->path, $card['link'] ?? null, $sold, 9])), 0, 12);
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
        $night = imagecolorallocate($c, 15, 23, 42);
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

        // Pièces restantes
        if ($card['remaining'] !== null && ! $sold) {
            $label = $card['remaining'] === 1 ? 'Dernière pièce' : $card['remaining'].' pièces';
            $lw = $this->width($label, 36, 'Bold');
            imagefilledrectangle($c, $w - 60 - $lw, 40, $w - 30, 110, imagecolorallocatealpha($c, 15, 23, 42, 20));
            imagettftext($c, 36, 0, $w - 45 - $lw, 92, $white, $this->font('Bold'), $label);
        }

        // Bande jaune : le code en très grand (hors de la photo, rien ne cache l'article) et le nom. Ni prix ni adresse
        // de site : TikTok freine les lives qui en montrent
        imagefilledrectangle($c, 0, $photoH, $w, $h - 120, $yellow);
        $codeSize = $this->size($card['code'], 150, 'ExtraBold', 360);
        $codeW = $this->width($card['code'], $codeSize, 'ExtraBold');
        imagefilledrectangle($c, 40, $photoH + 40, 40 + $codeW + 60, $photoH + 260, $red);
        imagettftext($c, $codeSize, 0, 70, $photoH + 150 + (int) ($codeSize / 2), $white, $this->font('ExtraBold'), $card['code']);
        $x = 40 + $codeW + 100;
        $name = $card['title'].($card['variant'] ? ' · '.$card['variant'] : '');
        $lines = $this->wrap($name, 44, 'Bold', $w - 40 - $x, 3);
        foreach ($lines as $i => $line) {
            imagettftext($c, 44, 0, $x, $photoH + 150 - (count($lines) - 1) * 28 + $i * 56 + 16, $ink, $this->font('Bold'), $line);
        }

        // Pied : appel à commander
        imagefilledrectangle($c, 0, $h - 120, $w, $h, $night);
        $call = $this->fit("Envoie {$card['code']} + ta taille", 40, 'ExtraBold', $w - 80);
        $box = imagettfbbox(40, 0, $this->font('ExtraBold'), $call);
        imagettftext($c, 40, 0, (int) (($w - ($box[2] - $box[0])) / 2), $h - 42, $white, $this->font('ExtraBold'), $call);
        // Pas de « Propulsé par … » ni d'adresse de site sur une carte filmée (TikTok)

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
    public function howCard(int $storeId, string $storeName, ?string $phone, ?string $whatsappUrl, ?string $link = null): string
    {
        $key = substr(md5(json_encode([$storeName, $phone, $whatsappUrl, $link, 6])), 0, 12);
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

        // Le site, pour commander sans WhatsApp
        if ($link) {
            $text = 'Ou sur le site : '.$link;
            imagettftext($c, $this->size($text, 34, 'Bold', $w - 120), 0, 60, 750, $yellow, $this->font('Bold'), $text);
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

    /**
     * Une seule image pour plusieurs articles (800 px de large) : bandeau orange avec le titre, puis une ligne par
     * article (miniature avec le code en rouge, nom, détail, prix), et une phrase en bas. Sert à la liste « LIVE » et aux
     * articles réservés après un code : un message au lieu d'une photo par article. Adresse publique de l'image.
     *
     * @param  list<array{code: ?string, title: string, detail: ?string, price: ?int, image: ?ProductImage}>  $lines
     */
    public function board(int $storeId, string $title, array $lines, ?string $footer = null, ?string $subtitle = null): string
    {
        $key = md5(json_encode([$title, $subtitle, $footer, array_map(fn ($l) => [$l['code'], $l['title'], $l['detail'], $l['price'], $l['image']?->id], $lines)]));
        $path = "live/{$storeId}/board-".substr($key, 0, 16).'.jpg';
        if ($this->disk()->exists($path)) {
            return $this->disk()->url($path);
        }

        $row = 124;
        $top = 96 + ($subtitle ? 48 : 0);
        $height = $top + count($lines) * $row + ($footer ? 96 : 16);
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
        imagettftext($c, 28, 0, 32, 60, $white, $this->font('ExtraBold'), $this->fit($title, 28, 'ExtraBold', self::WIDTH - 200));
        imagettftext($c, 24, 0, self::WIDTH - 130, 58, $white, $this->font('ExtraBold'), '● LIVE');
        if ($subtitle) {
            imagettftext($c, 20, 0, 32, 132, $muted, $this->font('Medium'), $this->fit($subtitle, 20, 'Medium', self::WIDTH - 64));
        }

        $y = $top;
        foreach ($lines as $l) {
            $photo = $l['image'] ? $this->load($l['image']) : null;
            if ($photo !== null) {
                $this->cover($c, $photo, 28, $y + 14, 96, 96);
            } else {
                imagefilledrectangle($c, 28, $y + 14, 124, $y + 110, $line);
            }
            if ($l['code']) {
                imagefilledrectangle($c, 28, $y + 14, 28 + 18 + 17 * strlen($l['code']), $y + 48, $red);
                imagettftext($c, 21, 0, 36, $y + 41, $white, $this->font('ExtraBold'), $l['code']);
            }
            $price = $l['price'] !== null ? Money::format($l['price']) : '';
            $priceWidth = $price !== '' ? $this->width($price, 26, 'Bold') : 0;
            if ($price !== '') {
                imagettftext($c, 26, 0, self::WIDTH - 32 - $priceWidth, $y + 72, $ink, $this->font('Bold'), $price);
            }
            $room = self::WIDTH - 148 - 48 - $priceWidth;
            imagettftext($c, 24, 0, 148, $l['detail'] ? $y + 60 : $y + 72, $ink, $this->font('Medium'), $this->fit($l['title'], 24, 'Medium', $room));
            if ($l['detail']) {
                imagettftext($c, 19, 0, 148, $y + 94, $muted, $this->font('Medium'), $this->fit($l['detail'], 19, 'Medium', $room));
            }
            imageline($c, 28, $y + $row - 1, self::WIDTH - 28, $y + $row - 1, $line);
            $y += $row;
        }
        if ($footer) {
            imagefilledrectangle($c, 0, $y, self::WIDTH, $height, $soft);
            imagettftext($c, 26, 0, 32, $y + 58, $ink, $this->font('ExtraBold'), $this->fit($footer, 26, 'ExtraBold', self::WIDTH - 64));
        }

        $this->save($path, $c);

        return $this->disk()->url($path);
    }

    /**
     * Photo à épingler (TikTok, Facebook, statut WhatsApp) : les photos des articles du live et leur code en petit dans
     * le coin, rien d'autre (ni nom, ni prix, ni numéro). En hauteur (1080 × 1920, 3 × 4) ou carrée (1080 × 1080,
     * 4 × 3) : 12 articles au plus par photo. Chemin du fichier sur le disque du catalogue.
     *
     * @param  list<array{code: string, image: ?ProductImage}>  $tiles
     */
    public function pin(int $storeId, array $tiles, bool $square = false): string
    {
        $tiles = array_slice($tiles, 0, self::PIN_PER_IMAGE);
        $key = substr(md5(json_encode([array_map(fn ($t) => [$t['code'], $t['image']?->path], $tiles), $square, 2])), 0, 16);
        $path = "live/{$storeId}/pin-".($square ? 'carre' : 'haut')."-{$key}.jpg";
        if ($this->disk()->exists($path)) {
            return $path;
        }

        [$w, $h, $cols, $rows] = $square ? [1080, 1080, 4, 3] : [1080, 1920, 3, 4];
        $c = imagecreatetruecolor($w, $h);
        $night = imagecolorallocate($c, 15, 23, 42);
        $tile = imagecolorallocate($c, 30, 41, 59);
        $white = imagecolorallocate($c, 255, 255, 255);
        $ink = imagecolorallocate($c, 15, 23, 42);
        imagefill($c, 0, 0, $night);

        // Grille centrée : moins d'articles, moins de lignes (et des cases plus grandes quand il y en a peu)
        $count = max(1, count($tiles));
        if ($count <= 4) {
            [$cols, $rows] = [2, (int) ceil($count / 2)];
        } elseif ($count <= 6) {
            [$cols, $rows] = $square ? [3, (int) ceil($count / 3)] : [2, (int) ceil($count / 2)];
        } else {
            $rows = (int) ceil($count / $cols);
        }
        $gap = 16;
        $pad = 24;
        $tw = intdiv($w - 2 * $pad - ($cols - 1) * $gap, $cols);
        $th = min(intdiv($h - 2 * $pad - ($rows - 1) * $gap, $rows), (int) round($tw * 1.4));
        $top = intdiv($h - ($rows * $th + ($rows - 1) * $gap), 2);
        $size = $square ? 30 : 40;

        foreach (array_values($tiles) as $i => $t) {
            $row = intdiv($i, $cols);
            $inRow = min($cols, $count - $row * $cols);
            $left = intdiv($w - ($inRow * $tw + ($inRow - 1) * $gap), 2);
            $x = $left + ($i % $cols) * ($tw + $gap);
            $y = $top + $row * ($th + $gap);
            imagefilledrectangle($c, $x, $y, $x + $tw, $y + $th, $tile);
            $photo = $t['image'] ? $this->load($t['image']) : null;
            if ($photo) {
                $this->whole($c, $photo, $x, $y, $tw, $th);
            }
            // Code en petit, en bas à gauche
            $bw = $this->width($t['code'], $size, 'ExtraBold') + (int) ($size * 0.7);
            $bh = (int) ($size * 1.45);
            [$bx, $by] = [$x + 10, $y + $th - $bh - 10];
            imagefilledrectangle($c, $bx, $by, $bx + $bw, $by + $bh, $white);
            imagettftext($c, $size, 0, $bx + (int) ($size * 0.35), $by + (int) ($size * 1.2), $ink, $this->font('ExtraBold'), $t['code']);
        }

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

    /**
     * Texte coupé en lignes qui tiennent dans la largeur (au plus $max lignes, la dernière raccourcie si besoin).
     *
     * @return list<string>
     */
    private function wrap(string $text, int $size, string $weight, int $width, int $max): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $this->width($try, $size, $weight) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        $lines[] = $line;
        if (count($lines) > $max) {
            $lines = array_slice($lines, 0, $max);
            $lines[$max - 1] .= ' …';
        }

        return array_map(fn (string $l) => $this->fit($l, $size, $weight, $width), $lines);
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
