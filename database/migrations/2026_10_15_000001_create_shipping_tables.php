<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entreprises de livraison de la boutique : Delivery App (par API) ou transporteur sans API (manuel)
        Schema::create('carrier_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 20); // delivery_app | manual
            $table->string('name');
            $table->string('base_url')->nullable();           // https://{entreprise}.jibiat.com/api/public/v1
            $table->text('api_key')->nullable();              // chiffré
            $table->string('api_key_prefix', 12)->nullable();
            $table->json('scopes')->nullable();
            $table->json('remote_account')->nullable();       // réponse de GET /me
            $table->uuid('webhook_uuid')->unique();
            $table->text('webhook_secret')->nullable();       // chiffré
            $table->string('remote_webhook_id')->nullable();
            $table->string('contact_phone', 20)->nullable();  // transporteur sans API : WhatsApp
            $table->json('settings')->nullable();             // {fee_payer, pickup_location_id}
            $table->string('status', 20)->default('pending'); // pending | active | error | disabled
            $table->string('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        Schema::create('carrier_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('carrier_connections')->cascadeOnDelete();
            $table->string('remote_id');
            $table->string('name');
            $table->string('parent_name')->nullable();
            $table->boolean('is_shipping')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();

            $table->unique(['connection_id', 'remote_id']);
        });

        Schema::create('carrier_zone_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('carrier_connections')->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_zone_id')->nullable()->constrained('carrier_zones')->nullOnDelete();
            $table->string('matched_by', 10); // auto | manual
            $table->timestamps();

            $table->unique(['connection_id', 'place_id']);
        });

        Schema::create('carrier_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('carrier_connections')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('remote_product_id');
            $table->string('remote_sku')->nullable();
            $table->integer('remote_on_hand')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->unique(['connection_id', 'variant_id']);
            $table->unique(['connection_id', 'remote_product_id']);
        });

        // Entrepôt d'un transporteur : stock déposé chez lui
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->foreignId('carrier_connection_id')->nullable()->after('type')->constrained('carrier_connections')->nullOnDelete();
            $table->string('remote_hub_id')->nullable()->after('carrier_connection_id');
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('mode', 20); // carrier | manual_carrier (own_courier en phase 5)
            $table->string('status', 25);
            $table->foreignId('origin_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('carrier_connection_id')->nullable()->constrained('carrier_connections')->nullOnDelete();
            $table->string('carrier_tracking_code', 40)->nullable();
            $table->string('carrier_tracking_url')->nullable();
            $table->string('carrier_status', 30)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedInteger('cod_amount')->default(0);
            $table->unsignedInteger('collected_amount')->nullable();
            $table->unsignedInteger('carrier_fee')->nullable();
            $table->string('fee_payer', 20)->default('customer'); // customer | store
            $table->unsignedInteger('weight_g')->nullable();
            $table->boolean('is_fragile')->default(false);
            $table->string('delivery_code', 20)->nullable();
            $table->string('proof_photo_url', 1000)->nullable();
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->string('failure_reason')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'sequence']);
            $table->index(['store_id', 'status']);
            $table->unique(['carrier_connection_id', 'carrier_tracking_code']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 25)->nullable();
            $table->string('raw_status', 30)->nullable();
            $table->string('source', 20); // carrier_webhook | carrier_sync | user | system
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('carrier_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('carrier_connections')->cascadeOnDelete();
            $table->string('webhook_id');
            $table->string('event', 40);
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();

            $table->unique(['connection_id', 'webhook_id']);
        });

        Schema::create('carrier_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('carrier_connections')->cascadeOnDelete();
            $table->string('remote_id');
            $table->integer('amount');
            $table->integer('total_collected')->nullable();
            $table->integer('total_fees')->nullable();
            $table->string('method', 20)->nullable();
            $table->string('transaction_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reconciliation_status', 20)->default('pending'); // pending | matched | discrepancy
            $table->integer('difference')->default(0);
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'remote_id']);
        });

        Schema::create('carrier_payout_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('remote_tracking_code', 40);
            $table->integer('collected');
            $table->integer('fees');
            $table->integer('net');
            $table->integer('expected_net')->nullable();
            $table->integer('difference')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_payout_lines');
        Schema::dropIfExists('carrier_payouts');
        Schema::dropIfExists('carrier_webhook_receipts');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_connection_id');
            $table->dropColumn('remote_hub_id');
        });
        Schema::dropIfExists('carrier_product_mappings');
        Schema::dropIfExists('carrier_zone_mappings');
        Schema::dropIfExists('carrier_zones');
        Schema::dropIfExists('carrier_connections');
    }
};
