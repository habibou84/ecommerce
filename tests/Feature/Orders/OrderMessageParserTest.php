<?php

namespace Tests\Feature\Orders;

use App\Models\DeliveryZone;
use App\Models\Place;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderMessageParser;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use Tests\TestCase;

class OrderMessageParserTest extends TestCase
{
    public function test_a_whatsapp_message_prefills_the_order(): void
    {
        $store = $this->createStore();
        $writer = app(CatalogWriter::class);
        $robe = $writer->save($store, null, [
            'title' => 'Robe wax',
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']], ['name' => 'Couleur', 'values' => ['Rouge', 'Bleu']]],
            'variants' => [['options' => ['S', 'Rouge'], 'price' => 15000], ['options' => ['M', 'Rouge'], 'price' => 15000], ['options' => ['M', 'Bleu'], 'price' => 16000]],
        ]);
        $sac = $writer->save($store, null, ['title' => 'Sac en pagne', 'variants' => [['price' => 9000]]]);
        $writer->save($store, null, ['title' => 'Collier perles', 'variants' => [['price' => 3000]]]);

        $message = "Bonsoir je suis Awa Koné, je veux 2 robes wax taille M couleur bleu et un sac en pagne svp.\n"
            .'Je suis à Yop Siporex, face pharmacie Sainte Rita. 07 08 09 10 11 ou 05 44 55 66 77. Total 41000 F';

        $result = app(OrderMessageParser::class)->parse($store, $message);

        $this->assertSame(['+2250708091011', '+2250544556677'], [$result['phone'], $result['phone2']]);
        $this->assertSame('Awa Koné', $result['customer_name']);
        $this->assertSame('Siporex, Yopougon', $result['place']['label']);
        $this->assertSame('face pharmacie Sainte Rita', $result['landmark']);
        $this->assertSame(41000, $result['amount']);

        $items = collect($result['items'])->keyBy('title');
        $this->assertSame(['Sac en pagne', 'Robe wax'], array_keys($items->all()));
        $this->assertSame([2, $robe->variants[2]->id, 'M / Bleu'], [$items['Robe wax']['quantity'], $items['Robe wax']['variant_id'], $items['Robe wax']['variant_title']]);
        $this->assertSame([1, $sac->variants[0]->id], [$items['Sac en pagne']['quantity'], $items['Sac en pagne']['variant_id']]);
    }

    public function test_names_after_usual_introductions(): void
    {
        $store = $this->createStore();
        $parser = app(OrderMessageParser::class);

        $this->assertSame('Aïcha Bamba', $parser->parse($store, "Bonsoir c'est Aïcha Bamba, je suis à Angré")['customer_name']);
        $this->assertSame('Koffi', $parser->parse($store, "Je m'appelle Koffi et je veux un sac")['customer_name']);
        $this->assertNull($parser->parse($store, "c'est pour un cadeau")['customer_name']);
    }

    public function test_labeled_messages_and_unknown_variants(): void
    {
        $store = $this->createStore();
        app(CatalogWriter::class)->save($store, null, [
            'title' => 'Chemise lin', 'options' => [['name' => 'Taille', 'values' => ['M', 'L']]],
            'variants' => [['options' => ['M'], 'price' => 1], ['options' => ['L'], 'price' => 1]],
        ]);

        $result = app(OrderMessageParser::class)->parse($store, "Nom : Mariam Traoré\nTél : 0102030405\nQuartier : 2 plateaux vallon\nRepère : derrière la station Total\nArticle : chemise lin x3");

        $this->assertSame('Mariam Traoré', $result['customer_name']);
        $this->assertSame('+2250102030405', $result['phone']);
        $this->assertSame('Vallon, Cocody', $result['place']['label']);
        $this->assertSame('derrière la station Total', $result['landmark']);
        $this->assertSame([3, null, true], [$result['items'][0]['quantity'], $result['items'][0]['variant_id'], $result['items'][0]['needs_variant']]);
    }

    public function test_place_search_and_zone_resolution(): void
    {
        $store = $this->createStore();
        $places = app(Places::class);
        $all = $places->forStore($store);
        $matcher = app(PlaceMatcher::class);

        $this->assertSame('Yopougon', $matcher->search('yop', $all)->first()->name);
        $this->assertSame('Deux Plateaux', $matcher->search('2 plateaux', $all)->first()->name);
        $this->assertSame('Grand-Bassam', $matcher->search('bassam', $all)->first()->name);

        // Zone du quartier, sinon de sa commune, sinon de la ville
        $places->provisionDefaultZone($store);
        $cocody = DeliveryZone::create(['store_id' => $store->id, 'name' => 'Cocody', 'fee' => 1000]);
        $cocody->places()->attach(Place::where('name', 'Cocody')->value('id'), ['store_id' => $store->id]);

        $this->assertSame('Cocody', $places->zoneFor($store, Place::where('name', 'Angré')->first())->name);
        $this->assertSame('Abidjan', $places->zoneFor($store, Place::where('name', 'Siporex')->first())->name);
        $this->assertNull($places->zoneFor($store, Place::where('name', 'Bouaké')->first()));

        // Lieu ajouté par une autre boutique : invisible ici
        Place::create(['store_id' => $this->createStore()->id, 'type' => 'neighborhood', 'name' => 'Cité Privée', 'slug' => 'cite-privee']);
        $this->assertTrue($matcher->search('cite privee', app(Places::class)->forStore($store))->isEmpty());
    }
}
