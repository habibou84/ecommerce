<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapport du soir commun : un seul résumé pour toutes les boutiques réunies sous l'abonnement Business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->boolean('all_stores')->default(false)->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->dropColumn('all_stores');
        });
    }
};
