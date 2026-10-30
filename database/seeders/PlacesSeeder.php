<?php

namespace Database\Seeders;

use App\Models\Place;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Jibiat\Socle\Places\Places;

/**
 * Référentiel commun des lieux de Côte d'Ivoire, repris de la liste du socle (la même que chez Jibiat) : communes
 * d'Abidjan, leurs quartiers et les principales villes, avec leurs variantes d'écriture et leur code. La liste se
 * complète dans le socle ; chaque boutique peut aussi ajouter ses propres lieux. Idempotent.
 */
class PlacesSeeder extends Seeder
{
    public function run(): void
    {
        $ids = [];
        foreach (Places::all() as $place) {
            $parentId = $place['parent'] ? ($ids[$place['parent']] ?? null) : null;
            $ids[$place['code']] = Place::updateOrCreate(
                ['code' => $place['code'], 'store_id' => null],
                ['country' => 'CI', 'parent_id' => $parentId, 'type' => $place['type'], 'name' => $place['name'],
                    'slug' => Str::slug($place['name']), 'aliases' => $place['aliases'] ?: null, 'is_active' => true],
            )->id;
        }
    }
}
