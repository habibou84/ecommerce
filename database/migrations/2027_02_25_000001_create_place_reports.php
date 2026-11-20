<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieux : un seul référentiel (liste commune du socle, comme chez Jibiat). Les boutiques n'ajoutent plus de
 * quartiers : un quartier manquant est signalé, la plateforme l'ajoute au référentiel pour tout le monde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('places')->nullOnDelete();
            // pending, added, rejected
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_reports');
    }
};
