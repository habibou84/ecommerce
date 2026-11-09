<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acompte :
 *  - par article (pourcentage ou montant fixe par pièce), la précommande comprise ;
 *  - pour la boutique : jamais, au-dessus d'un montant, ou toujours (pourcentage du total) ; la commande attend
 *    l'acompte avant de partir chez le livreur, ou juste un rappel ;
 *  - sur la commande : acompte fixé à la main (pas recalculé ensuite) et rappel envoyé une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('deposit_type', 10)->nullable(); // percent | fixed
            $table->unsignedInteger('deposit_value')->nullable();
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->string('deposit_mode', 10)->default('never'); // never | above | always
            $table->unsignedInteger('deposit_min_total')->nullable();
            $table->unsignedSmallInteger('deposit_rule_percent')->nullable();
            $table->boolean('deposit_blocks')->default(true);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('deposit_manual')->default(false);
            $table->timestamp('deposit_reminded_at')->nullable();
        });

        // Précommandes déjà réglées : leur pourcentage devient l'acompte de l'article
        DB::table('products')->whereNotNull('preorder_deposit_percent')->update([
            'deposit_type' => 'percent',
            'deposit_value' => DB::raw('preorder_deposit_percent'),
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['deposit_manual', 'deposit_reminded_at']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['deposit_mode', 'deposit_min_total', 'deposit_rule_percent', 'deposit_blocks']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['deposit_type', 'deposit_value']);
        });
    }
};
