<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commande confiée à une entreprise de l'annuaire pas encore partenaire : elle attend l'ouverture du compte (demande
 * en un clic), puis part chez elle ; refus ou pas de réponse en 24 h : elle revient « à assigner ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('planned_carrier_request_id')->nullable()->constrained('carrier_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planned_carrier_request_id');
        });
    }
};
