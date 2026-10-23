<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponses automatiques aux clients (boîte Messages) : désactivées par défaut. Réglages : activées, toujours ou
 * seulement en dehors des heures choisies, plafond par heure. Les réponses envoyées ont le type « auto ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('auto_replies')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('auto_replies');
        });
    }
};
