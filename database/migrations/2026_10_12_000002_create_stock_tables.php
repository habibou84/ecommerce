<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Où se trouve le stock : boutique, entrepôt, domicile (l'entrepôt d'un transporteur arrive en phase 4)
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('shop'); // shop | warehouse | home | carrier_hub
            $table->text('address')->nullable();
            $table->string('landmark')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_pickup_point')->default(false);
            $table->boolean('fulfills_online_orders')->default(true);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active']);
        });

        // Cache des quantités, recalculable depuis stock_movements
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->cascadeOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0); // disponible = on_hand - reserved
            $table->timestamps();

            $table->unique(['variant_id', 'location_id']);
            $table->index(['store_id', 'location_id']);
        });

        // Journal immuable
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->cascadeOnDelete();
            $table->string('type', 20);
            $table->integer('on_hand_change')->default(0);
            $table->integer('reserved_change')->default(0);
            $table->integer('on_hand_after');
            $table->integer('reserved_after');
            $table->unsignedInteger('unit_cost')->nullable();
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['variant_id', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->char('country', 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 60)->nullable();
            $table->unsignedInteger('total_cost')->nullable();
            $table->timestamp('received_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('stock_receipts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_cost')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_location_id')->constrained('stock_locations');
            $table->foreignId('to_location_id')->constrained('stock_locations');
            $table->string('status', 20); // in_transit | received | cancelled
            $table->string('reference', 60)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->string('status', 20); // in_progress | completed | cancelled
            $table->string('note')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->integer('expected'); // en stock au début du comptage
            $table->integer('counted')->nullable();
            $table->timestamps();

            $table->unique(['count_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_receipt_items');
        Schema::dropIfExists('stock_receipts');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_locations');
    }
};
