<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes du livreur chez l'entreprise de livraison (contrat 1.3, champ « ratings ») : la boutique peut-elle encore le
 * noter, sa note, celle de son client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->json('carrier_ratings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('carrier_ratings');
        });
    }
};
