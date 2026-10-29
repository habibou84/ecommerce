<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liaison WhatsApp par QR code (non officielle, « Relier WhatsApp ») autorisée boutique par boutique, depuis la console.
 * Éteinte par défaut ; allumée pour les boutiques qui l'utilisent déjà, pour qu'elles ne perdent rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_qr_enabled')->default(false);
        });

        DB::table('stores')->whereIn('id', DB::table('whatsapp_links')->select('store_id'))->update(['whatsapp_qr_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('whatsapp_qr_enabled');
        });
    }
};
