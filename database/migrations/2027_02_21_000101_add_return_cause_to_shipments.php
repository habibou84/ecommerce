<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raison du retour envoyée par Jibiat (contrat 1.11) : la boutique lit « Refusé par le client : le colis vous sera
 * retourné » au lieu de « Retour du colis demandé » quand ce n'est pas elle qui l'a demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('return_cause', 20)->nullable()->after('return_requested');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('return_cause'));
    }
};
