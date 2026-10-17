<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produit copié d'une autre boutique de la même adresse : on garde le produit et les variantes d'origine
 * (la première, même pour une copie de copie) pour retrouver le même article d'une boutique à l'autre
 * (transferts de stock, « Aussi dans… »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('origin_product_id')->nullable()->after('store_id')->constrained('products')->nullOnDelete();
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('origin_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_variant_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_product_id');
        });
    }
};
