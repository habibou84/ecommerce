<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packs et vente en gros :
 *  - un article peut se vendre par lot seulement (par 6, 12…) et avec un minimum par commande ;
 *  - pack au choix : « 3 articles au choix pour 25 000 F », parmi une catégorie ou des articles choisis ;
 *  - modèles montrés aux clients sur WhatsApp (« modèles », « catalogue »), allumés par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('sale_lot')->nullable();
            $table->unsignedSmallInteger('min_order_quantity')->nullable();
        });

        Schema::create('pack_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('price');
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['store_id', 'is_active']);
        });

        Schema::create('pack_offer_product', function (Blueprint $table) {
            $table->foreignId('pack_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['pack_offer_id', 'product_id']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_models')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('whatsapp_models');
        });
        Schema::dropIfExists('pack_offer_product');
        Schema::dropIfExists('pack_offers');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sale_lot', 'min_order_quantity']);
        });
    }
};
