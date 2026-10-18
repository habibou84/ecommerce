<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vrai bénéfice : canal des dépenses de publicité (coût par commande livrée de chaque canal) et dépenses qui
 * reviennent chaque mois (loyer, salaires…), enregistrées automatiquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('channel', 20)->nullable()->after('category'); // canal de vente visé par une publicité
            $table->foreignId('recurring_expense_id')->nullable()->after('cash_session_id');
        });

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('channel', 20)->nullable();
            $table->unsignedInteger('amount');
            $table->string('method', 20); // jamais en espèces : rien ne sort du tiroir-caisse sans quelqu'un
            $table->string('note')->nullable();
            $table->unsignedTinyInteger('day_of_month'); // 1 à 28
            $table->boolean('is_active')->default(true);
            $table->date('last_run_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'is_active']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreign('recurring_expense_id')->references('id')->on('recurring_expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropForeign(['recurring_expense_id']);
            $table->dropColumn(['channel', 'recurring_expense_id']);
        });
        Schema::dropIfExists('recurring_expenses');
    }
};
