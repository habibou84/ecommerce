<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ville de la boutique (livraison locale) et expéditions vers les autres villes : livraison jusqu'à la gare connue
 * d'avance, frais de la compagnie de transport connus au dépôt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('home_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->unsignedInteger('expedition_fee')->nullable();
            $table->boolean('expedition_prepay')->default(true);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('expedition_city_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('expedition_payer', 10)->nullable();
            $table->boolean('expedition_prepay')->default(false);
            $table->unsignedInteger('expedition_fee')->nullable();
            $table->string('expedition_company', 60)->nullable();
            $table->string('expedition_ticket', 60)->nullable();
            $table->string('expedition_receipt_path')->nullable();
            $table->boolean('expedition_advanced')->default(false);
            $table->timestamp('expedition_dropped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expedition_city_id');
            $table->dropColumn(['expedition_payer', 'expedition_prepay', 'expedition_fee', 'expedition_company', 'expedition_ticket', 'expedition_receipt_path', 'expedition_advanced', 'expedition_dropped_at']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_place_id');
            $table->dropColumn(['expedition_fee', 'expedition_prepay']);
        });
    }
};
