<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thèmes de la plateforme (définis dans config/themes.php, recopiés ici par ThemeSeeder)
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('version', 10);
            $table->json('settings_schema');
            $table->string('preview_path')->nullable();
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Réglages d'un thème pour une boutique
        Schema::create('store_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained();
            $table->json('settings');
            $table->json('draft_settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'theme_id']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('active_theme_id')->nullable()->after('favicon_path')->constrained('store_themes')->nullOnDelete();
            // La boutique en ligne n'est visible du public qu'une fois ouverte par le marchand
            $table->boolean('storefront_enabled')->default(false)->after('active_theme_id');
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('body')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 20); // main | footer
            $table->json('items');        // [{label, type: home|products|collection|page|url, target}]
            $table->timestamps();

            $table->unique(['store_id', 'handle']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique(); // cookie du visiteur
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 20)->nullable(); // saisi en premier : relance du panier abandonné (phase 6)
            $table->string('discount_code', 40)->nullable();
            $table->json('utm')->nullable();
            $table->string('status', 20)->default('active'); // active | converted | abandoned | expired
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('recovery_sent_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status', 'last_activity_at']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('pages');
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_theme_id');
            $table->dropColumn('storefront_enabled');
        });
        Schema::dropIfExists('store_themes');
        Schema::dropIfExists('themes');
    }
};
