<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étiquette de la course chez l'entreprise de livraison (contrat 1.5, label_url) : lien signé, redemandé quand il
 * expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->string('carrier_label_url', 500)->nullable());
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('carrier_label_url'));
    }
};
