<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carte de la tournée du livreur : position des emplacements (point de départ) et date de la dernière recherche
 * de la position d'un lieu dans OpenStreetMap (pour ne pas la refaire chaque jour quand le lieu est introuvable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->decimal('latitude', 9, 6)->nullable()->after('landmark');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
        });
        Schema::table('places', function (Blueprint $table) {
            $table->timestamp('located_at')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('stock_locations', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
        Schema::table('places', fn (Blueprint $table) => $table->dropColumn('located_at'));
    }
};
