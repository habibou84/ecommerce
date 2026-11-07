<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lives payants : lives offerts utilisés et donnés en plus par la console, pass 24 h, lives illimités 30 jours,
 * actions IA offertes au 1er live ; comment chaque live a été payé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedInteger('live_free_used')->default(0);
            $table->unsignedInteger('live_free_bonus')->default(0);
            $table->timestamp('live_pass_until')->nullable();
            $table->timestamp('live_month_until')->nullable();
            $table->timestamp('live_ai_gift_at')->nullable();
        });
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->string('billing', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['live_free_used', 'live_free_bonus', 'live_pass_until', 'live_month_until', 'live_ai_gift_at']);
        });
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn('billing');
        });
    }
};
