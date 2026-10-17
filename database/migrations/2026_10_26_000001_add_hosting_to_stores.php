<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Boutiques hébergées à l'adresse d'une autre boutique du même propriétaire (offre Business) : pas de
 * sous-domaine propre, une page {adresse}/{path} pour les clients et la même gestion (sélecteur de boutique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('host_store_id')->nullable()->after('billing_store_id')->constrained('stores')->nullOnDelete();
            $table->string('path', 40)->nullable()->after('slug');
            $table->unique(['host_store_id', 'path']);
        });

        // Boutiques déjà rattachées à un abonnement commun : elles passent à l'adresse de la boutique principale
        $hosts = DB::table('stores')->whereNotNull('billing_store_id')->whereNull('deleted_at')->get(['id', 'slug', 'name', 'billing_store_id']);
        foreach ($hosts as $store) {
            $host = DB::table('stores')->where('id', $store->billing_store_id)->first(['slug']);
            $base = Str::startsWith($store->slug, $host->slug.'-') ? Str::after($store->slug, $host->slug.'-') : $store->slug;
            $path = Str::limit(Str::slug($base) ?: 'boutique', 30, '');
            for ($i = 2; DB::table('stores')->where('host_store_id', $store->billing_store_id)->where('path', $path)->exists(); $i++) {
                $path = Str::limit(Str::slug($base) ?: 'boutique', 30, '').'-'.$i;
            }
            DB::table('stores')->where('id', $store->id)->update(['host_store_id' => $store->billing_store_id, 'path' => $path]);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['host_store_id', 'path']);
            $table->dropConstrainedForeignId('host_store_id');
            $table->dropColumn('path');
        });
    }
};
