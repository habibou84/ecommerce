<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournée du jour : ordre des arrêts choisi par le livreur, et sa dernière position pendant la tournée quand la
 * boutique l'a activé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedSmallInteger('route_position')->nullable();
        });
        Schema::table('couriers', function (Blueprint $table) {
            $table->decimal('last_latitude', 9, 6)->nullable();
            $table->decimal('last_longitude', 9, 6)->nullable();
            $table->timestamp('last_located_at')->nullable();
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('courier_tracking')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('route_position'));
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn(['last_latitude', 'last_longitude', 'last_located_at']));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('courier_tracking'));
    }
};
