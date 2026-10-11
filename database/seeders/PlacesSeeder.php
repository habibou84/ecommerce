<?php

namespace Database\Seeders;

use App\Models\Place;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Référentiel commun des lieux de Côte d'Ivoire : communes d'Abidjan, leurs quartiers les plus courants et les
 * principales villes. Les variantes d'écriture (« yop », « 2 plateaux ») aident à reconnaître les adresses saisies
 * par les clients. Liste à compléter au fil des boutiques ; chaque boutique peut aussi ajouter ses propres lieux.
 * Idempotent.
 */
class PlacesSeeder extends Seeder
{
    private const ABIDJAN = [
        'Abobo' => [['abobo'], ['Abobo Gare', 'Abobo Baoulé', 'PK 18', 'Avocatier', 'Anador', 'Sagbé', "N'Dotré", 'Belleville', 'Akéikoi', 'Samaké']],
        'Adjamé' => [['adjame'], ['Liberté', '220 Logements', 'Williamsville', 'Bracodi', 'Paillet', 'Dallas']],
        'Anyama' => [['anyama'], []],
        'Attécoubé' => [['attecoube'], ['Santé', 'Locodjro', 'Agban', 'Abobo-Doumé']],
        'Bingerville' => [['binger'], []],
        'Cocody' => [['cocody'], [
            'Angré', 'Riviera 2' => ['riviera ii', 'riv 2'], 'Riviera 3' => ['riviera iii', 'riv 3'], 'Riviera Palmeraie' => ['palmeraie'],
            'Riviera Golf' => ['golf'], 'Deux Plateaux' => ['2 plateaux', 'ii plateaux', '2plateaux'], 'Vallon', 'Blockhauss', 'Danga',
            'Saint-Jean', 'Faya', 'Bonoumin', "M'Pouto" => ['mpouto'], 'Akouédo', 'Ambassades', 'Cité des Arts', 'Mermoz', 'Attoban',
        ]],
        'Koumassi' => [['koumassi'], ['Remblais', 'Grand Campement', 'Prodomo', 'Zone industrielle de Koumassi']],
        'Marcory' => [['marcory'], ['Zone 4' => ['zone iv'], 'Biétry', 'Anoumabo', 'Marcory Résidentiel', 'Champroux']],
        'Plateau' => [['plateau'], ['Cité administrative', 'Indénié']],
        'Port-Bouët' => [['port bouet', 'portbouet'], ['Vridi', 'Gonzagueville', 'Aéroport', 'Jean Folly', 'Adjouffou', 'Derrière Wharf']],
        'Songon' => [['songon'], []],
        'Treichville' => [['treich', 'treichville'], ['Arras', 'Biafra', 'Avenue 16', 'Gare de Bassam']],
        'Yopougon' => [['yop', 'yopougon', 'yopou'], [
            'Siporex', 'Niangon', 'Selmer', 'Toits Rouges', 'Maroc', 'Andokoi', 'Sideci', 'Wassakara', 'Gesco', 'Ananeraie',
            'Koweït' => ['koweit'], 'Kouté', 'Banco', 'Port-Bouët 2' => ['port bouet 2', 'pb2'], 'Micao', 'Yao Séhi', 'Mamie Adjoua',
        ]],
    ];

    private const CITIES = [
        'Grand-Bassam' => ['bassam'], 'Bouaké' => ['bouake'], 'Yamoussoukro' => ['yakro'], 'San-Pédro' => ['san pedro'],
        'Daloa' => [], 'Korhogo' => [], 'Man' => [], 'Gagnoa' => [], 'Abengourou' => [], 'Assinie' => [], 'Jacqueville' => [],
        'Dabou' => [], 'Divo' => [], 'Soubré' => [], 'Bondoukou' => [],
    ];

    public function run(): void
    {
        $abidjan = $this->place(null, 'city', 'Abidjan', ['abj']);

        foreach (self::ABIDJAN as $commune => [$aliases, $neighborhoods]) {
            $parent = $this->place($abidjan->id, 'commune', $commune, $aliases);
            foreach ($neighborhoods as $key => $value) {
                [$name, $alias] = is_string($key) ? [$key, $value] : [$value, []];
                $this->place($parent->id, 'neighborhood', $name, $alias);
            }
        }

        foreach (self::CITIES as $city => $aliases) {
            $this->place(null, 'city', $city, $aliases);
        }
    }

    private function place(?int $parentId, string $type, string $name, array $aliases): Place
    {
        return Place::updateOrCreate(
            ['parent_id' => $parentId, 'slug' => Str::slug($name), 'store_id' => null],
            ['country' => 'CI', 'type' => $type, 'name' => $name, 'aliases' => $aliases ?: null, 'is_active' => true],
        );
    }
}
