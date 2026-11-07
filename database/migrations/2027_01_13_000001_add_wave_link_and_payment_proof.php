<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien de paiement Wave de la boutique (envoyé à la cliente pour une expédition payée avant l'envoi) et reçu de
 * paiement envoyé par la cliente sur WhatsApp, à valider par la boutique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('wave_link', 255)->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('payment_proof_at')->nullable();
            $table->unsignedBigInteger('payment_proof_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('wave_link'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['payment_proof_at', 'payment_proof_message_id']));
    }
};
