<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connexion directe à une entreprise de Jibiat (contrat socle 1.12) : demande d'ouverture de compte envoyée de
 * serveur à serveur, suivie jusqu'à la décision de l'entreprise (clé d'API, refus ou expiration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Identifiant de la demande, connu de l'entreprise (request_id du contrat)
            $table->uuid('request_id')->unique();
            $table->string('slug', 100);
            $table->string('name');
            $table->string('url', 500);
            // pending, confirmation, connected, declined, cancelled, expired, failed
            $table->string('status', 20)->default('pending');
            $table->timestamp('decide_before')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedSmallInteger('waiting_orders')->default(0);
            $table->foreignId('connection_id')->nullable()->constrained('carrier_connections')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_requests');
    }
};
