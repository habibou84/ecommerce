<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fidélité et parrainage : points gagnés sur les commandes livrées et utilisés en réduction ; code de parrainage
 * du client (réduction pour le filleul à sa première commande, points pour le parrain quand elle est livrée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('loyalty_settings')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->integer('loyalty_points')->default(0);
            $table->string('referral_code', 12)->nullable();
            $table->foreignId('referred_by_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unique(['store_id', 'referral_code']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_used')->default(0);
            $table->unsignedInteger('points_discount')->default(0);
            $table->unsignedInteger('referral_discount')->default(0);
            $table->foreignId('referred_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
        });

        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->integer('points'); // + gagnés, − utilisés
            $table->string('type', 20); // earned, redeemed, refunded, reversed, referral, adjustment
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['store_id', 'customer_id', 'created_at']);
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_entries');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_customer_id');
            $table->dropColumn(['points_used', 'points_discount', 'referral_discount']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'referral_code']);
            $table->dropConstrainedForeignId('referred_by_id');
            $table->dropColumn(['loyalty_points', 'referral_code']);
        });
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('loyalty_settings'));
    }
};
