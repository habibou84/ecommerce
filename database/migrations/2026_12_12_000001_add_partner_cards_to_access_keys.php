<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartes Membre Premium offertes par les entreprises de livraison de Jibiat (lots « partner », un par entreprise et
 * par formule) : mois offerts puis réduction tant que le marchand livre avec l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_key_batches', function (Blueprint $table) {
            $table->string('partner_slug', 80)->nullable()->after('kind');
            $table->decimal('discount_rate', 4, 3)->nullable()->after('months');
            $table->index('partner_slug');
        });

        Schema::table('access_keys', function (Blueprint $table) {
            $table->string('partner_reference', 64)->nullable()->after('code');
            $table->string('partner_reason', 16)->nullable()->after('partner_reference');
            $table->string('merchant_name', 120)->nullable()->after('partner_reason');
            $table->string('merchant_phone', 32)->nullable()->after('merchant_name');
            $table->timestamp('discount_warned_at')->nullable()->after('warned_at');
            $table->boolean('discount_on')->nullable()->after('discount_warned_at');
            $table->index('partner_reference');
        });
    }

    public function down(): void
    {
        Schema::table('access_keys', function (Blueprint $table) {
            $table->dropIndex(['partner_reference']);
            $table->dropColumn(['partner_reference', 'partner_reason', 'merchant_name', 'merchant_phone', 'discount_warned_at', 'discount_on']);
        });
        Schema::table('access_key_batches', function (Blueprint $table) {
            $table->dropIndex(['partner_slug']);
            $table->dropColumn(['partner_slug', 'discount_rate']);
        });
    }
};
