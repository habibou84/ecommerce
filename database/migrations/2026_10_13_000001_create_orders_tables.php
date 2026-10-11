<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Référentiel commun des lieux (ville › commune › quartier) ; une boutique peut ajouter les siens
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->char('country', 2)->default('CI');
            $table->foreignId('parent_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('type', 20); // city | commune | neighborhood
            $table->string('name');
            $table->string('slug');
            $table->json('aliases')->nullable(); // ["yop", "deux plateaux"]
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country', 'parent_id']);
            $table->index('store_id');
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('local'); // local | shipping (V2)
            $table->unsignedInteger('fee')->default(0);
            $table->unsignedInteger('express_fee')->nullable();
            $table->unsignedInteger('free_above')->nullable();
            $table->string('eta_label', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('delivery_zone_places', function (Blueprint $table) {
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->primary(['delivery_zone_id', 'place_id']);
            $table->unique(['store_id', 'place_id']); // un lieu dans une seule zone par boutique
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 20);
            $table->string('phone2', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 20)->nullable();
            $table->boolean('accepts_marketing')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->string('blacklist_reason')->nullable();
            // Compteurs recalculés à chaque changement de statut d'une de ses commandes
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('refused_count')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'phone']);
            $table->index(['store_id', 'last_order_at']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address_line')->nullable();
            $table->string('landmark')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20);
            $table->string('external_reference', 100)->nullable();
            $table->string('status', 25);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('fulfillment_status', 20)->default('unfulfilled');
            // Instantané client et livraison
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20);
            $table->string('delivery_method', 20)->default('delivery'); // delivery | pickup
            $table->string('shipping_name')->nullable();
            $table->string('shipping_phone', 20)->nullable();
            $table->string('shipping_phone2', 20)->nullable();
            $table->foreignId('shipping_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('shipping_place_label')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_landmark')->nullable();
            $table->decimal('shipping_latitude', 9, 6)->nullable();
            $table->decimal('shipping_longitude', 9, 6)->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('stock_locations')->nullOnDelete(); // emplacement qui prépare
            $table->date('scheduled_date')->nullable();
            $table->string('time_slot', 20)->nullable();
            // Montants
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('discount_total')->default(0);
            $table->unsignedInteger('shipping_fee')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('amount_paid')->default(0);
            $table->unsignedInteger('amount_refunded')->default(0);
            $table->unsignedInteger('cost_total')->nullable();
            $table->unsignedInteger('deposit_required')->nullable();
            $table->string('discount_code', 40)->nullable();
            // Suivi
            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->unsignedSmallInteger('risk_score')->nullable();
            $table->json('utm')->nullable();
            $table->string('tracking_token', 32)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('callback_at')->nullable(); // relance prévue (file de confirmation)
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 30)->nullable();
            $table->string('cancel_note')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'number']);
            $table->index(['store_id', 'status', 'created_at']);
            $table->index(['store_id', 'customer_phone']);
            $table->index(['store_id', 'source', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('variant_title')->nullable();
            $table->string('sku', 60)->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('compare_at_price')->nullable();
            $table->unsignedInteger('unit_cost')->nullable();
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('total');
            $table->unsignedInteger('weight_g')->nullable();
            $table->boolean('requires_shipping')->default(true);
            $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->string('stock_state', 20)->nullable(); // reserved | shipped | released | returned
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamps();
        });

        // Journal immuable de la commande
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('data')->nullable();
            $table->string('actor_type', 20)->default('user');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // call | whatsapp | sms | whatsapp_button
            $table->string('outcome', 20); // confirmed | no_answer | wrong_number | refused | callback | changed
            $table->string('note')->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        // Paiements : saisis à la main pour l'instant (acompte, Wave, espèces à la livraison) ; en ligne en phase 6
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // deposit | manual | cod_cash | cod_mobile | online | carrier_cod
            $table->unsignedBigInteger('payment_account_id')->nullable(); // phase 6
            $table->string('provider', 20)->nullable();
            $table->string('method', 20); // cash | wave | orange_money | mtn_momo | moov_money | card | bank | other
            $table->unsignedInteger('amount');
            $table->unsignedInteger('fee')->nullable();
            $table->char('currency', 3)->default('XOF');
            $table->string('status', 20)->default('succeeded');
            $table->string('provider_reference')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['store_id', 'status', 'created_at']);
        });

        // Notifications internes : rattachées à une boutique (une personne peut en avoir plusieurs)
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['notifiable_id', 'store_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_id', 'store_id', 'read_at']);
            $table->dropConstrainedForeignId('store_id');
        });
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_confirmations');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('delivery_zone_places');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('places');
    }
};
