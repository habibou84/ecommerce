<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock envoyé d'une boutique à une autre de la même adresse (immédiat : même propriétaire).
 * Chaque côté garde aussi ses mouvements de stock, qui renvoient à ce transfert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('to_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('from_location_id')->constrained('stock_locations');
            $table->foreignId('to_location_id')->constrained('stock_locations');
            $table->json('lines'); // [{from_variant_id, to_variant_id, title, quantity}]
            $table->unsignedInteger('quantity'); // total des articles
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['from_store_id', 'created_at']);
            $table->index(['to_store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_transfers');
    }
};
