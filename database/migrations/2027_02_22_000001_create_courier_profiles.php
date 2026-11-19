<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livreurs inscrits au lien commun (jibiat-marchand.com/livreur) : leur profil (véhicule, communes, photo, pièce
 * d'identité, présence dans l'annuaire des boutiques), indépendant des boutiques. Une boutique qui l'ajoute crée sa
 * fiche « demandée » ; il l'accepte ou la refuse. Une boutique peut exiger la pièce d'identité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vehicle_type', 20)->default('moto');
            $table->string('photo_path')->nullable();
            $table->string('id_document_path')->nullable();
            $table->timestamp('id_document_at')->nullable();
            // Visible dans l'annuaire des livreurs (les boutiques le trouvent par commune)
            $table->boolean('listed')->default(true);
            $table->timestamps();
            $table->index('listed');
        });
        Schema::create('courier_profile_places', function (Blueprint $table) {
            $table->foreignId('courier_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->primary(['courier_profile_id', 'place_id']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('couriers_require_id')->default(false);
        });
        Schema::table('couriers', function (Blueprint $table) {
            // Demande d'une boutique à un livreur inscrit : il l'accepte (active) ou la refuse (declined)
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('answered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn(['requested_at', 'answered_at']));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('couriers_require_id'));
        Schema::dropIfExists('courier_profile_places');
        Schema::dropIfExists('courier_profiles');
    }
};
