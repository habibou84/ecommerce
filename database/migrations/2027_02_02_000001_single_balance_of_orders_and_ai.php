<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solde unique : des commandes (live, WhatsApp, boost) et des actions IA, achetés ensemble par packs ou en illimité
 * 30 jours. Remplace le crédit live en francs, les paliers par live, les commandes offertes comptées à part et le
 * prix de l'assistant Boost en francs. Le crédit restant de chaque boutique devient des commandes (au prix du plus
 * petit pack), et ses commandes offertes non utilisées s'y ajoutent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->integer('order_credits')->default(0)->after('ai_credits');
            $table->timestamp('credits_welcomed_at')->nullable()->after('order_credits');
        });
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->unsignedInteger('credit_orders')->nullable()->after('ai_actions');
        });
        Schema::table('boosts', function (Blueprint $table) {
            $table->renameColumn('fee', 'credits_used');
        });

        $old = json_decode((string) DB::table('platform_settings')->where('key', 'live.billing')->value('value'), true) ?: [];
        $free = (int) ($old['free_orders'] ?? 300);
        $unit = 25; // prix d'une commande du plus petit pack (2 500 F les 100)
        DB::table('stores')->orderBy('id')->each(function ($store) use ($free, $unit) {
            $left = max(0, $free + (int) $store->live_orders_bonus - (int) $store->live_orders_used);
            $credit = (int) $store->live_credit;
            $orders = $left + ($credit >= 0 ? intdiv($credit, $unit) : -intdiv(-$credit + $unit - 1, $unit));
            DB::table('stores')->where('id', $store->id)->update(['order_credits' => $orders, 'credits_welcomed_at' => now()]);
        });
        // Lives comptés au solde de commandes (et non plus par palier)
        DB::table('live_sessions')->where('billing', 'tier')->update(['billing' => 'credits']);
        // Réglages : seuls « payant », le prix des 30 jours et le prestataire sont repris
        $new = array_filter(['paid' => $old['paid'] ?? null, 'month_price' => $old['month_price'] ?? null, 'provider' => $old['provider'] ?? null], fn ($v) => $v !== null);
        if ($new !== []) {
            DB::table('platform_settings')->updateOrInsert(['key' => 'billing.credits'], ['value' => json_encode($new), 'updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('platform_settings')->where('key', 'live.billing')->delete();

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['live_credit', 'live_orders_used', 'live_orders_bonus', 'live_ai_gift_at', 'live_free_used', 'live_free_bonus']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->integer('live_credit')->default(0);
            $table->unsignedInteger('live_orders_used')->default(0);
            $table->unsignedInteger('live_orders_bonus')->default(0);
            $table->timestamp('live_ai_gift_at')->nullable();
            $table->unsignedInteger('live_free_used')->default(0);
            $table->unsignedInteger('live_free_bonus')->default(0);
            $table->dropColumn(['order_credits', 'credits_welcomed_at']);
        });
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn('credit_orders');
        });
        Schema::table('boosts', function (Blueprint $table) {
            $table->renameColumn('credits_used', 'fee');
        });
        DB::table('live_sessions')->where('billing', 'credits')->update(['billing' => 'tier']);
    }
};
