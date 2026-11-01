<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * État du numéro officiel chez Meta (compte, nom, qualité, vérification, blocages), relu toutes les heures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->json('meta_health')->nullable();
            $table->timestamp('meta_health_at')->nullable();
            // ok, partial (en partie bloqué), blocked ; depuis quand
            $table->string('meta_state', 10)->nullable()->index();
            $table->timestamp('meta_state_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['meta_health', 'meta_health_at', 'meta_state', 'meta_state_since']);
        });
    }
};
