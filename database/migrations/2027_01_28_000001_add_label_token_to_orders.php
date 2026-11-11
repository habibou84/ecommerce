<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jeton du QR de l'étiquette collée sur le colis : distinct du lien de suivi du client, pour que quiconque tient
     * le colis (livreur compris) ne voie ni le code de livraison ni les données du client.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('label_token', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['label_token']);
            $table->dropColumn('label_token');
        });
    }
};
