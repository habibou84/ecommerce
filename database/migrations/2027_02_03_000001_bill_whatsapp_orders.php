<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes WhatsApp payantes hors live : « récap » envoyé par le marchand, panier du catalogue WhatsApp, « modèles ».
 * Sur la commande : son canal payant, si elle compte (décidé à la confirmation : pas pendant l'illimité ni quand les
 * commandes payantes sont éteintes) et quand elle a été retirée du solde. Un jour compté par boutique, chaque soir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('credit_channel', 10)->nullable();
            $table->boolean('credit_due')->nullable();
            $table->timestamp('credit_billed_at')->nullable();
            $table->index(['credit_due', 'credit_billed_at']);
        });

        Schema::create('order_credit_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('orders');
            $table->unsignedInteger('amount');
            $table->json('detail');
            $table->timestamps();
            $table->unique(['store_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_credit_bills');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['credit_due', 'credit_billed_at']);
            $table->dropColumn(['credit_channel', 'credit_due', 'credit_billed_at']);
        });
    }
};
