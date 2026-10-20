<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jours et heures de livraison de la boutique : heure limite pour livrer le jour même, jours sans livraison
 * (jours de la semaine et dates). Sert à proposer la première date de livraison possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('delivery_calendar')->nullable()->after('assignment_mode'); // {cutoff: "14:00", closed_weekdays: [7], closed_dates: ["2026-12-25"]}
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('delivery_calendar'));
    }
};
