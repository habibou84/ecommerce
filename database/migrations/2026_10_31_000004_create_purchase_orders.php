<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bons de commande aux fournisseurs : préparés (au besoin depuis les articles qui vont manquer), envoyés par
 * WhatsApp, réceptionnés en une ou plusieurs fois (réceptions de stock) et payés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->string('status', 20)->default('draft'); // draft, sent, partial, received, cancelled
            $table->date('expected_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('total_cost')->default(0);
            $table->unsignedInteger('paid_amount')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'number']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedInteger('unit_cost')->nullable();
        });

        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('recurring_expense_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_order_id'));
        Schema::table('stock_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_order_id'));
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
