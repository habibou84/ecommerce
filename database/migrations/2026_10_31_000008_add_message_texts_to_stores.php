<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Textes des messages au client adaptés par le marchand (messages WhatsApp envoyés depuis son téléphone et
 * demande de confirmation dans la conversation) : seuls les textes modifiés sont enregistrés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('message_texts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('message_texts'));
    }
};
