<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarifs de livraison vérifiés par la boutique : tant qu'ils ne le sont pas, le menu WhatsApp le rappelle (le récap
     * et les assistants envoient ces prix aux clients). Boutiques existantes : vérifiés si un tarif a déjà été changé.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('delivery_rates_checked_at')->nullable();
        });

        $changed = DB::table('delivery_zones')->select('store_id')->groupBy('store_id')
            ->havingRaw('count(*) > 1 or max(updated_at) > min(created_at) + interval \'1 minute\'');
        DB::table('stores')->whereIn('id', $changed)->update(['delivery_rates_checked_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('delivery_rates_checked_at');
        });
    }
};
