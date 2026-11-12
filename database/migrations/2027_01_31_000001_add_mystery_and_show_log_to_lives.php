<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live interactif : article mystère (caché partout jusqu'à sa révélation, avec ses indices) et journal des moments
     * du show (pour le bilan : commandes reçues juste après chaque moment).
     */
    public function up(): void
    {
        Schema::table('live_items', function (Blueprint $table) {
            $table->boolean('mystery')->default(false);
            $table->json('mystery_hints')->nullable();
            $table->timestamp('revealed_at')->nullable();
        });
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->json('show_log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('live_items', function (Blueprint $table) {
            $table->dropColumn(['mystery', 'mystery_hints', 'revealed_at']);
        });
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn('show_log');
        });
    }
};
