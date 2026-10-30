<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écrans du live (télé, tablette, ordinateur) : reliés à une boutique par un code à 4 chiffres, puis affichent
 * l'article montré pendant le live ; mode d'affichage choisi par la vendeuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('code', 4)->nullable()->index();
            $table->string('name', 60)->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('live_sessions', function (Blueprint $table) {
            $table->string('display_mode', 10)->default('product');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', fn (Blueprint $table) => $table->dropColumn('display_mode'));
        Schema::dropIfExists('live_screens');
    }
};
