<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plusieurs boutiques sous un même abonnement (offre Business) et crédit des jours payés non utilisés lors d'un
 * changement d'offre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Boutique dont l'abonnement couvre celle-ci (même propriétaire)
            $table->foreignId('billing_store_id')->nullable()->after('owner_id')->constrained('stores')->nullOnDelete();
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            // Valeur des jours payés non utilisés de l'offre précédente, convertie en jours de la nouvelle offre
            $table->unsignedInteger('credit_amount')->nullable()->after('amount');
            $table->unsignedSmallInteger('credit_days')->nullable()->after('credit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['credit_amount', 'credit_days']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_store_id');
        });
    }
};
