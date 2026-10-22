<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp de la boutique relié par QR code (comme WhatsApp Web) pour la boîte Messages
        Schema::create('whatsapp_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('phone', 30)->nullable();
            $table->string('name', 120)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        // Dernier message du client reçu par le WhatsApp relié : la réponse repart par le même chemin
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('via_link')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('via_link'));
        Schema::dropIfExists('whatsapp_links');
    }
};
