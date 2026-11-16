<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatisation des livraisons : comment choisir entre plusieurs livreurs (« nearest » : celui qui couvre le lieu puis
 * le moins occupé ; « round_robin » : chacun son tour) et, en envoi automatique, délai sans réponse du livreur avant de
 * passer au suivant (vide : simple rappel à l'équipe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('courier_dispatch', 12)->default('nearest');
            $table->unsignedSmallInteger('mission_timeout_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['courier_dispatch', 'mission_timeout_minutes']));
    }
};
