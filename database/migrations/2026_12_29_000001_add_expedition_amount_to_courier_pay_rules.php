<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paie du livreur pour une expédition (colis déposé à la gare) ; vide = montant par défaut
        Schema::table('courier_pay_rules', function (Blueprint $table) {
            $table->unsignedInteger('expedition_amount')->nullable()->after('zone_amounts');
        });
    }

    public function down(): void
    {
        Schema::table('courier_pay_rules', function (Blueprint $table) {
            $table->dropColumn('expedition_amount');
        });
    }
};
