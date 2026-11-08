<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inscription par WhatsApp (numéro du support) : où en est la conversation de chaque numéro, étapes atteintes
 * (entonnoir de la console), pause quand un humain répond, relance. « Aide demandée » sur la conversation du support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_signups', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('step', 20)->default('idle');
            $table->json('data')->nullable();
            $table->json('reached')->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['step', 'last_inbound_at']);
        });
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->timestamp('help_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_signups');
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropColumn('help_requested_at');
        });
    }
};
