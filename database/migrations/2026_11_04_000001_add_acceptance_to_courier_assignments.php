<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Missions acceptées par le livreur : vue, acceptée ou refusée (avec le motif), rappel si personne ne répond.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_assignments', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason')->nullable();
            $table->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courier_assignments', fn (Blueprint $table) => $table->dropColumn(['seen_at', 'accepted_at', 'declined_at', 'decline_reason', 'reminded_at']));
    }
};
