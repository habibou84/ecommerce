<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prix par quantité d'un produit (toutes variantes confondues) : « 3 pour 25 000 F »
        Schema::create('volume_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('price'); // prix du lot de min_quantity articles

            $table->unique(['product_id', 'min_quantity']);
        });

        // Codes promo (code renseigné) et ventes flash (remise automatique, sans code)
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('title')->nullable();
            $table->string('type', 20); // percentage | fixed_amount | free_shipping
            $table->unsignedInteger('value')->default(0);
            $table->string('applies_to', 20)->default('all'); // all | collections | products
            $table->json('target_ids')->nullable();
            $table->unsignedInteger('min_subtotal')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
            $table->index(['store_id', 'is_active']);
        });

        Schema::create('discount_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->timestamp('created_at');

            $table->unique(['discount_id', 'order_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Vente flash appliquée au prix de l'article
            $table->foreignId('discount_id')->nullable()->after('discount_amount')->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('conversion_sent_at')->nullable()->after('utm'); // achat envoyé à l'API Conversions de Meta
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedInteger('free_shipping_above')->nullable()->after('deposit_percent');
            $table->json('marketing')->nullable()->after('free_shipping_above'); // {meta_pixel_id, tiktok_pixel_id, ga4_id}
            $table->text('meta_capi_token')->nullable()->after('marketing'); // chiffré
            $table->string('assignment_mode', 10)->default('suggest')->after('meta_capi_token'); // off | suggest | auto
        });

        // Affectation automatique : la première règle qui correspond choisit le livreur ou le transporteur
        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('priority')->default(1);
            $table->json('conditions')->nullable(); // {delivery_zone_ids, is_fragile, min_amount, max_amount, max_weight_g}
            $table->string('action', 20); // own_courier | carrier | cheapest | fastest | manual
            $table->foreignId('carrier_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Liens courts de partage ({slug}.jibiat-marchand.com/p/{code}) et image carrée prête pour les statuts
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->string('target_type', 20); // product
            $table->unsignedBigInteger('target_id');
            $table->string('share_image_path')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'target_type', 'target_id']);
        });

        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 10)->default('daily');
            $table->string('channel', 10); // whatsapp | email
            $table->time('send_at')->default('20:00');
            $table->timestamp('last_sent_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'user_id', 'frequency', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
        Schema::dropIfExists('short_links');
        Schema::dropIfExists('shipping_rules');
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['free_shipping_above', 'marketing', 'meta_capi_token', 'assignment_mode']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('conversion_sent_at'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('discount_id'));
        Schema::dropIfExists('discount_usages');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('volume_prices');
    }
};
