<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guide de démarrage : étapes que la boutique a choisi de passer (les autres sont détectées sur ses données).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('setup_guide')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('setup_guide'));
    }
};
