<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lieux couverts par un livreur et lieux des cas particuliers de livraison : les vrais lieux (ville, commune,
 * quartier) au lieu des « zones » de prix. Reprise de l'existant : les lieux des zones déjà cochées. L'ancienne table
 * courier_zones est gardée telle quelle (plus lue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_places', function (Blueprint $table) {
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->primary(['courier_id', 'place_id']);
        });

        // Livreurs : les lieux des zones qu'ils couvraient
        DB::statement(<<<'SQL'
            INSERT INTO courier_places (courier_id, place_id, store_id)
            SELECT DISTINCT cz.courier_id, dzp.place_id, c.store_id
            FROM courier_zones cz
            JOIN couriers c ON c.id = cz.courier_id
            JOIN delivery_zone_places dzp ON dzp.delivery_zone_id = cz.delivery_zone_id
            SQL);

        // Cas particuliers : zones → lieux de ces zones
        DB::table('shipping_rules')->whereNotNull('conditions')->orderBy('id')->each(function (object $rule) {
            $conditions = json_decode($rule->conditions, true) ?: [];
            if (empty($conditions['delivery_zone_ids'])) {
                return;
            }
            $conditions['place_ids'] = DB::table('delivery_zone_places')->whereIn('delivery_zone_id', $conditions['delivery_zone_ids'])
                ->distinct()->pluck('place_id')->map(fn ($id) => (int) $id)->values()->all();
            unset($conditions['delivery_zone_ids']);
            DB::table('shipping_rules')->where('id', $rule->id)->update(['conditions' => $conditions ? json_encode($conditions) : null]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_places');
    }
};
