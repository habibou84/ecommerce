<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alertes sur les commandes qui attendent (sans livreur, pas ramassées, à confirmer...) : réglages de la boutique,
 * heure du dernier changement de statut d'une expédition, et alertes déjà envoyées (une seule fois chacune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('alert_settings')->nullable();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable();
        });
        DB::table('shipments')->update(['status_changed_at' => DB::raw('updated_at')]);

        Schema::create('order_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('subject', 80);
            $table->unsignedTinyInteger('level')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['store_id', 'kind', 'subject', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_alerts');
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('status_changed_at'));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('alert_settings'));
    }
};
