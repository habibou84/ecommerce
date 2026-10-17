<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retours et échanges après livraison : articles rendus (remis en stock), remboursement, et commande d'échange
 * pour les articles de remplacement (livrée comme une commande, le livreur reprend l'article rendu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('exchange_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending, received, cancelled
            $table->string('reason')->nullable();
            $table->boolean('restock')->default(true);
            $table->unsignedInteger('returned_value')->default(0); // valeur des articles rendus (prix payé)
            $table->unsignedInteger('refund_due')->default(0); // à rembourser : valeur rendue moins le remplacement
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'number']);
            $table->index(['store_id', 'status', 'created_at']);
            $table->index('order_id');
        });

        Schema::create('order_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_value'); // prix payé par article (remise déduite)
            $table->foreignId('replacement_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('replacement_quantity')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};
