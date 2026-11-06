<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Meta a refusé un message automatique faute de moyen de paiement (#131042) : avertissement jusqu'au prochain
    // modèle accepté
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->timestamp('payment_missing_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('payment_missing_at');
        });
    }
};
