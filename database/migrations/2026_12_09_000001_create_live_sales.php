<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vente en live (Facebook, TikTok, Instagram) : articles présentés avec un code court, réservés depuis la
        // page du live (/live) ou par un message WhatsApp contenant le code, liste d'attente quand c'est épuisé
        Schema::create('live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('status', 10)->default('draft'); // draft | live | ended
            $table->unsignedSmallInteger('hold_minutes')->default(120); // réservation WhatsApp sans quartier : libérée après
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        Schema::create('live_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('code', 8); // A1, A2… unique dans le live
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('quantity'); // pièces proposées pendant le live
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['live_session_id', 'code']);
        });

        Schema::create('live_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_item_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10); // page | whatsapp | manual
            $table->string('status', 10); // reserved (quartier à donner) | ordered | waiting | expired | cancelled
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->foreignId('shipping_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('shipping_address', 255)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['live_item_id', 'status']);
            $table->index(['store_id', 'customer_phone', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_reservations');
        Schema::dropIfExists('live_items');
        Schema::dropIfExists('live_sessions');
    }
};
