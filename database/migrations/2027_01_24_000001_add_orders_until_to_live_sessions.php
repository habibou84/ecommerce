<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Terminer le live » en gardant les commandes actives 24 h ou 48 h : les codes marchent encore jusqu'à cette heure
 * (commandes comptées dans le même live, prix calculé quand elles s'arrêtent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->timestamp('orders_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn('orders_until');
        });
    }
};
