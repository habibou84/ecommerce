<?php

use Database\Seeders\PlacesSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Référentiel des lieux 1.13 (socle) : nouveaux quartiers et villes. Ensuite, « places:sync » le refait seul à chaque
 * changement de la liste.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PlacesSeeder::class)->run();
    }

    public function down(): void {}
};
