<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live : rappel envoyé une seule fois à la cliente dont la pièce gardée va être libérée faute de quartier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_reservations', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_reservations', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
