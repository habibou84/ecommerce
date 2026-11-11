<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commandes WhatsApp sans live : une session « whatsapp » (codes A1, A2… comme un live, sans TikTok), ouverte
     * jusqu'à une heure choisie, et comptée jour par jour comme un live (une ligne par jour compté).
     */
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->string('channel', 10)->default('live');
        });

        Schema::create('live_session_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('orders');
            $table->unsignedInteger('offered');
            $table->unsignedInteger('amount');
            $table->timestamps();
            $table->unique(['live_session_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_session_bills');
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
