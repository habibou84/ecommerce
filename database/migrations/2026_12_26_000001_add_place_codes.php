<?php

use Database\Seeders\PlacesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Jibiat\Socle\Places\Places;

/**
 * Code des lieux de la liste commune (socle 1.7), le même chez Jibiat : les lieux communs le reçoivent, ceux qui
 * manquent sont ajoutés ; les zones des entreprises de livraison gardent le code que Jibiat leur donne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('code', 120)->nullable()->after('slug');
            $table->index('code');
        });
        Schema::table('carrier_zones', function (Blueprint $table) {
            $table->string('place_code', 120)->nullable()->after('parent_name');
        });

        // Lieux communs déjà là : même code que dans le socle (nom de la ville, puis de la commune, puis du quartier)
        $places = DB::table('places')->whereNull('store_id')->get(['id', 'parent_id', 'name'])->keyBy('id');
        $codeOf = function ($place) use (&$codeOf, $places): string {
            $parent = $place->parent_id ? $places->get($place->parent_id) : null;

            return ($parent ? $codeOf($parent).'-' : '').Str::slug($place->name);
        };
        foreach ($places as $place) {
            $code = $codeOf($place);
            if (Places::find($code) !== null) {
                DB::table('places')->where('id', $place->id)->update(['code' => $code]);
            }
        }

        // Lieux du socle qui manquent encore
        (new PlacesSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('carrier_zones', fn (Blueprint $table) => $table->dropColumn('place_code'));
        Schema::table('places', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropColumn('code');
        });
    }
};
