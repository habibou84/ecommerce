<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens d'accès des livreurs : envoyés par WhatsApp, ils ouvrent l'application livreur sans mot de passe.
 * Un seul usage, 7 jours au plus ; seule l'empreinte du jeton est gardée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_access_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['courier_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_access_links');
    }
};
