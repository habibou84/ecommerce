<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('status', 20)->default('active'); // draft | active | archived
            $table->boolean('is_visible_online')->default(true);
            $table->boolean('has_variants')->default(false);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->boolean('requires_shipping')->default(true);
            $table->boolean('is_fragile')->default(false);
            $table->unsignedInteger('low_stock_threshold')->nullable();
            // Cache recalculé depuis les variantes (listes, tri, filtres)
            $table->unsignedInteger('price_min')->nullable();
            $table->unsignedInteger('price_max')->nullable();
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
        });
        // Adresse unique parmi les produits non supprimés
        DB::statement('CREATE UNIQUE INDEX products_store_slug_unique ON products (store_id, slug) WHERE deleted_at IS NULL');

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('position'); // 1 à 3
            $table->timestamps();

            $table->unique(['product_id', 'position']);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_id')->constrained('product_options')->cascadeOnDelete();
            $table->string('value', 60);
            $table->unsignedSmallInteger('position');
            $table->string('swatch', 7)->nullable(); // #RRGGBB
            $table->timestamps();

            $table->unique(['option_id', 'value']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path'); // dossier des déclinaisons WebP (voir ImageStore)
            $table->string('alt')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option1_value_id')->nullable()->constrained('product_option_values');
            $table->foreignId('option2_value_id')->nullable()->constrained('product_option_values');
            $table->foreignId('option3_value_id')->nullable()->constrained('product_option_values');
            $table->string('title');
            $table->string('sku', 60)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->unsignedInteger('price');
            $table->unsignedInteger('compare_at_price')->nullable();
            $table->unsignedInteger('cost_price')->nullable();
            $table->unsignedInteger('weight_g')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'barcode']);
        });
        // Une combinaison d'options par produit (NULL compris : PostgreSQL 15+), un SKU par boutique
        DB::statement('CREATE UNIQUE INDEX product_variants_combination_unique ON product_variants (product_id, option1_value_id, option2_value_id, option3_value_id) NULLS NOT DISTINCT WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_variants_store_sku_unique ON product_variants (store_id, sku) WHERE sku IS NOT NULL AND deleted_at IS NULL');

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('type', 10)->default('manual'); // manual | smart (V1)
            $table->json('rules')->nullable();
            $table->string('sort_order', 20)->default('manual');
            $table->boolean('is_visible_online')->default(true);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
        });

        Schema::create('collection_products', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->primary(['collection_id', 'product_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 7)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('taggable_type', 30); // product | customer | order
            $table->unsignedBigInteger('taggable_id');

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('collection_products');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('products');
    }
};
