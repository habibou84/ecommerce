<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versement déclaré par le livreur (espèces remises à la caisse, ou envoi Wave / mobile money avec sa référence),
 * en attente de la confirmation de la boutique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_remittance_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('method', 20); // cash, wave, orange_money, mtn_momo, moov_money
            $table->string('reference', 80)->nullable();
            $table->string('note')->nullable();
            $table->string('proof_path')->nullable();
            $table->unsignedInteger('held_amount'); // espèces détenues au moment de la déclaration
            $table->string('status', 20)->default('pending'); // pending, confirmed, rejected
            $table->foreignId('remittance_id')->nullable()->constrained('courier_remittances')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status']);
            $table->index(['courier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_remittance_declarations');
    }
};
