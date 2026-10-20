<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que l'entreprise de livraison prévoit pour la course : date de livraison, décision attendue de la boutique
 * (après un échec), retour demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('rescheduled_to');
            $table->boolean('needs_decision')->default(false)->after('scheduled_date');
            $table->boolean('return_requested')->default(false)->after('needs_decision');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn(['scheduled_date', 'needs_decision', 'return_requested']));
    }
};
