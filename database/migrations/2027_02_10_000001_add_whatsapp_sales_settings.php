<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant des ventes WhatsApp : « récap » écrit par le marchand (allumé par défaut) et, après un panier du catalogue
 * WhatsApp, commune demandée puis récapitulatif avec « Je confirme » (allumé par défaut).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_recap')->default(true);
            $table->boolean('whatsapp_cart_flow')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_recap', 'whatsapp_cart_flow']);
        });
    }
};
