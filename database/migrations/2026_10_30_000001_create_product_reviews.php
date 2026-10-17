<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avis des clients sur les produits reçus : laissés par le lien de la commande livrée, publiés par le marchand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 60);
            $table->unsignedTinyInteger('rating'); // 1 à 5
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('pending'); // pending, published, hidden
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'product_id']);
            $table->index(['store_id', 'status', 'created_at']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
