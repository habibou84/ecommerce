<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lots (pack « robe + sac » : vendre le lot sort chaque article de son stock) et précommandes (article vendu
 * avant son arrivée, avec une date d'expédition prévue et un acompte éventuel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_bundle')->default(false);
            $table->boolean('preorder_enabled')->default(false);
            $table->date('preorder_ship_date')->nullable();
            $table->unsignedTinyInteger('preorder_deposit_percent')->nullable();
        });

        Schema::create('bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('component_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['bundle_variant_id', 'component_variant_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false);
        });

        // Contenu d'un lot au moment de la commande (le stock bouge sur ces articles même si le lot change ensuite)
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('bundle_components')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('bundle_components'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('is_preorder'));
        Schema::dropIfExists('bundle_components');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['is_bundle', 'preorder_enabled', 'preorder_ship_date', 'preorder_deposit_percent']));
    }
};
