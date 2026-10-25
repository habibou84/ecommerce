<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parrainage par une entreprise de livraison (stores.referred_by = son slug dans l'annuaire de Jibiat) :
        // part de chaque abonnement payé par la boutique, pendant la durée prévue à partir du premier paiement
        Schema::create('referral_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('partner', 80);
            $table->unsignedInteger('amount');
            $table->string('method', 30)->nullable();     // wave, orange_money, especes…
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['partner', 'paid_at']);
        });

        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('partner', 80);
            $table->foreignId('subscription_invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('invoice_amount');
            $table->decimal('rate', 5, 4);
            $table->unsignedInteger('amount');
            $table->timestamp('earned_at');
            $table->foreignId('referral_payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['partner', 'referral_payout_id']);
            $table->index(['store_id', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');
        Schema::dropIfExists('referral_payouts');
    }
};
