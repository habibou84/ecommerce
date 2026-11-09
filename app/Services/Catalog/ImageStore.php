<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Photos du catalogue. Chaque photo devient un dossier de trois déclinaisons WebP (vignette, moyenne, grande),
 * redressées selon l'orientation de l'appareil photo et débarrassées de leurs métadonnées (position GPS…).
 * Pages légères en 3G : la boutique sert la taille utile, jamais l'original.
 */
class ImageStore
{
    public const SIZES = ['thumb' => 400, 'medium' => 800, 'large' => 1600];

    public const MAX_UPLOAD_KB = 10240;

    /** Au-delà, l'image est refusée avant d'être décodée (une petite image de 20 000 × 20 000 points occupe des Go) */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Image trop grande en points pour être ouverte sans danger (chemin d'un fichier ou contenu) ; dimensions
     * illisibles : laissées à la lecture, qui refusera le fichier.
     */
    public static function ensureReasonable(string $source): void
    {
        $size = @is_file($source) ? @getimagesize($source) : @getimagesizefromstring($source);
        if (is_array($size) && (int) $size[0] * (int) $size[1] > self::MAX_PIXELS) {
            throw new BusinessRuleException('Cette image est trop grande. Envoyez une photo de taille normale (moins de 40 millions de points).', 'image');
        }
    }

    /**
     * @return array{path: string, width: int, height: int}
     */
    public function store(UploadedFile $file, int $storeId, string $folder = 'products'): array
    {
        self::ensureReasonable((string) $file->getRealPath());
        try {
            $image = (new ImageManager(new Driver))->read($file->getRealPath());
        } catch (Throwable) {
            throw new BusinessRuleException('Ce fichier n\'est pas une image lisible (JPEG, PNG, WebP).', 'image');
        }

        $path = "stores/{$storeId}/{$folder}/".Str::uuid();
        $width = $image->width();
        $height = $image->height();

        foreach (self::SIZES as $name => $size) {
            $copy = clone $image;
            $copy->scaleDown(width: $size, height: $size);
            $this->disk()->put("{$path}/{$name}.webp", (string) $copy->toWebp(80), 'public');
        }

        return ['path' => $path, 'width' => $width, 'height' => $height];
    }

    /**
     * Photo faite par l'application (montage d'un pack…) enregistrée comme une photo envoyée.
     *
     * @return array{path: string, width: int, height: int}
     */
    public function storeBytes(string $bytes, int $storeId, string $folder = 'products'): array
    {
        self::ensureReasonable($bytes);
        $image = (new ImageManager(new Driver))->read($bytes);
        $path = "stores/{$storeId}/{$folder}/".Str::uuid();
        foreach (self::SIZES as $name => $size) {
            $copy = clone $image;
            $copy->scaleDown(width: $size, height: $size);
            $this->disk()->put("{$path}/{$name}.webp", (string) $copy->toWebp(80), 'public');
        }

        return ['path' => $path, 'width' => $image->width(), 'height' => $image->height()];
    }

    /**
     * @return array{thumb: string, medium: string, large: string}
     */
    public function urls(string $path): array
    {
        return array_map(fn (string $name) => $this->disk()->url("{$path}/{$name}.webp"), array_combine(array_keys(self::SIZES), array_keys(self::SIZES)));
    }

    /**
     * Copie indépendante d'une photo pour une autre boutique (la supprimer d'un côté ne touche pas l'autre).
     */
    public function copy(string $path, int $storeId, string $folder = 'products'): string
    {
        $copy = "stores/{$storeId}/{$folder}/".Str::uuid();
        foreach (array_keys(self::SIZES) as $name) {
            if ($this->disk()->exists("{$path}/{$name}.webp")) {
                $this->disk()->copy("{$path}/{$name}.webp", "{$copy}/{$name}.webp");
                $this->disk()->setVisibility("{$copy}/{$name}.webp", 'public');
            }
        }

        return $copy;
    }

    public function delete(string $path): void
    {
        $this->disk()->deleteDirectory($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.catalog_disk', 'public'));
    }
}
