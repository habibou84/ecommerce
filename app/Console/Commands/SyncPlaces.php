<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use Database\Seeders\PlacesSeeder;
use Illuminate\Console\Command;
use Jibiat\Socle\Places\Places;

/**
 * Référentiel des lieux (socle) : quand la liste change (nouveaux quartiers), les lieux communs sont mis à jour.
 * Sans changement, rien n'est fait.
 */
class SyncPlaces extends Command
{
    protected $signature = 'places:sync {--force : Synchroniser même si la liste n\'a pas changé}';

    protected $description = 'Met à jour les lieux communs avec la liste du socle';

    public function handle(): int
    {
        $checksum = Places::checksum();
        if (! $this->option('force') && PlatformSetting::find('places_checksum')?->value === $checksum) {
            $this->info('Liste des lieux inchangée.');

            return self::SUCCESS;
        }

        app(PlacesSeeder::class)->run();
        PlatformSetting::put('places_checksum', $checksum);
        $this->info('Lieux communs à jour : '.count(Places::all()).' lieux.');

        return self::SUCCESS;
    }
}
