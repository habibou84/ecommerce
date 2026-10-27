<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clés d'accès imprimées en cartes à QR code : lots créés dans la console (fondateur à vie, ou durée en mois),
 * chaque clé activée une seule fois par un espace marchand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_key_batches', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16); // founder | timed
            $table->foreignId('plan_id')->constrained('plans');
            $table->unsignedSmallInteger('months')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('distributor_name', 120)->nullable();
            $table->string('distributor_phone', 32)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('access_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('access_key_batches')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('code', 16)->unique();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('warned_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'number']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_keys');
        Schema::dropIfExists('access_key_batches');
    }
};
