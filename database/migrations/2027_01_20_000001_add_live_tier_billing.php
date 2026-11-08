<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lives payés par palier de commandes : crédit live rechargé à l'avance (peut passer sous zéro après un gros live),
 * commandes en live offertes utilisées et données en plus par la console ; ce que chaque live a coûté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->integer('live_credit')->default(0);
            $table->unsignedInteger('live_orders_used')->default(0);
            $table->unsignedInteger('live_orders_bonus')->default(0);
        });
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->unsignedInteger('billed_orders')->nullable();
            $table->unsignedInteger('billed_offered')->nullable();
            $table->unsignedInteger('billed_amount')->nullable();
            $table->timestamp('billed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['live_credit', 'live_orders_used', 'live_orders_bonus']);
        });
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn(['billed_orders', 'billed_offered', 'billed_amount', 'billed_at']);
        });
    }
};
