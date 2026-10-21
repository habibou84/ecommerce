<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offres pensées pour la Côte d'Ivoire : paiement à la semaine, recharges de l'assistant, parrainage par une
 * entreprise de livraison, nouveaux prix et offre Gratuit élargie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('weekly_price')->nullable()->after('monthly_price');
        });

        // Une facture paie soit un abonnement, soit une recharge de l'assistant (sans offre)
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->string('kind', 20)->default('subscription')->after('store_id');
            $table->unsignedInteger('ai_actions')->nullable()->after('kind');
            $table->foreignId('plan_id')->nullable()->change();
        });

        Schema::table('stores', function (Blueprint $table) {
            // Actions de l'assistant achetées, utilisées après celles de l'offre, sans date de fin
            $table->unsignedInteger('ai_credits')->default(0);
            // Entreprise de livraison de l'annuaire qui a invité l'e-commerçant (son identifiant)
            $table->string('referred_by', 80)->nullable();
        });

        // Nouveaux prix, seulement là où la console n'a pas déjà changé les prix d'origine
        DB::table('plans')->where('code', 'starter')->where('monthly_price', 5000)
            ->update(['monthly_price' => 3500, 'yearly_price' => 35000, 'weekly_price' => 1500]);
        DB::table('plans')->where('code', 'pro')->where('monthly_price', 15000)
            ->update(['monthly_price' => 12500, 'yearly_price' => 125000]);
        $free = DB::table('plans')->where('code', 'free')->first();
        if ($free !== null) {
            $limits = json_decode((string) $free->limits, true) ?: [];
            if (($limits['orders_per_month'] ?? null) === 30 && ($limits['products'] ?? null) === 20) {
                DB::table('plans')->where('id', $free->id)->update(['limits' => json_encode(['orders_per_month' => 60, 'products' => 30] + $limits)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['ai_credits', 'referred_by']);
        });
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['kind', 'ai_actions']);
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('weekly_price');
        });
    }
};
