<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui livre, choisi dès la saisie de la commande (livreur ou entreprise de livraison), et tarifs des entreprises
 * par zone (entreprises sans API : grille saisie par le marchand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('planned_courier_id')->nullable()->after('delivery_zone_id')->constrained('couriers')->nullOnDelete();
            $table->foreignId('planned_carrier_connection_id')->nullable()->after('planned_courier_id')->constrained('carrier_connections')->nullOnDelete();
        });

        Schema::table('carrier_zones', function (Blueprint $table) {
            $table->unsignedInteger('fee')->nullable()->after('parent_name');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_zones', fn (Blueprint $table) => $table->dropColumn('fee'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planned_carrier_connection_id');
            $table->dropConstrainedForeignId('planned_courier_id');
        });
    }
};
