<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Fichiers privés (preuves de livraison, pièces d'identité des livreurs, reçus, photos reçues sur WhatsApp) : jamais
 * servis directement, toujours par l'application après contrôle des droits.
 *
 * Disque choisi par PRIVATE_DISK (« local » par défaut ; « r2-private » pour Cloudflare R2, voir
 * docs/FICHIERS-R2.md). Sur un stockage objet, plusieurs serveurs web voient les mêmes fichiers.
 */
class Files
{
    public static function privateDisk(): string
    {
        return (string) config('filesystems.private_disk', 'local');
    }

    public static function private(): FilesystemAdapter
    {
        return Storage::disk(self::privateDisk());
    }
}
