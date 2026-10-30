<?php

namespace Tests\Feature\Live;

use App\Models\LiveScreen;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Live en images : écran relié par un code à 4 chiffres (télé, tablette, ordinateur), affichage sans données
 * privées, mode choisi par la vendeuse, cartes des articles à enregistrer dans la galerie.
 */
class LiveScreensTest extends TestCase
{
    private Store $store;

    private Product $robe;

    private int $live;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode', 'whatsapp_phone' => '+2250700000001']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $sac = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Sac cabas', 'variants' => [['price' => 8000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
        $this->withCredentials()->withHeaders(['Referer' => $this->storeUrl($this->store, '/gestion')])
            ->post($this->storeUrl($this->store, "/api/v1/products/{$this->robe->id}/images"), ['image' => UploadedFile::fake()->image('robe.jpg', 900, 1200)], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->live = $this->storeApi($this->store, 'POST', 'lives', ['title' => 'Live du soir', 'hold_minutes' => 30, 'items' => [
            ['variant_id' => $this->robe->variants()->value('id'), 'price' => 12000, 'quantity' => 2],
            ['variant_id' => $sac->variants()->value('id'), 'quantity' => 1],
        ]])->assertCreated()->json('data.id');
    }

    public function test_a_screen_is_paired_with_a_code_and_shows_the_live_without_private_data(): void
    {
        // La télé ouvre /ecran : un code à 4 chiffres, rien d'autre tant qu'elle n'est pas reliée
        $created = $this->platformApi('POST', 'screens')->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $created['code']);
        $this->platformApi('GET', 'screens/'.$created['token'])->assertOk()->assertJsonPath('data', ['paired' => false, 'code' => $created['code']]);

        // Mauvais code refusé ; bon code : l'écran est à la boutique
        $this->storeApi($this->store, 'POST', 'lives/screens/pair', ['code' => $created['code'] === '0000' ? '0001' : '0000'])->assertStatus(422);
        $r = $this->storeApi($this->store, 'POST', 'lives/screens/pair', ['code' => $created['code'], 'name' => 'Télé salon']);
        $r->assertCreated()
            ->assertJsonPath('data.name', 'Télé salon');
        $this->assertSame([$this->store->id, null], [LiveScreen::sole()->store_id, LiveScreen::sole()->code]);

        // Pas encore de live : l'écran attend, avec le WhatsApp de la boutique
        $this->platformApi('GET', 'screens/'.$created['token'])->assertJsonPath('data.paired', true)->assertJsonPath('data.live', null)
            ->assertJsonPath('data.store.whatsapp_url', 'https://wa.me/2250700000001');

        // Live lancé, robe montrée, réservations : pièces restantes, VENDU, dernières commandes (commune seulement)
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/start")->assertOk();
        $items = $this->storeApi($this->store, 'GET', "lives/{$this->live}")->json('data.items');
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/feature", ['item_id' => $items[1]['id']])->assertOk();
        $cocody = Place::whereNull('store_id')->where('name', 'Angré')->value('id');
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/reservations", ['code' => 'A2', 'name' => 'Fatou Diallo', 'phone' => '0506070809', 'place_id' => $cocody])->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/reservations", ['code' => 'A1', 'name' => 'Awa Koné', 'phone' => '0707080910'])->assertOk();

        $screen = $this->platformApi('GET', 'screens/'.$created['token'])->assertOk();
        $live = $screen->json('data.live');
        $this->assertSame(['product', $items[1]['id']], [$live['mode'], $live['featured_item_id']]);
        $this->assertSame(['A1', 12000, 15000, 1, 'Robe wax'], [$live['items'][0]['code'], $live['items'][0]['price'], $live['items'][0]['was'], $live['items'][0]['remaining'], $live['items'][0]['title']]);
        $this->assertStringEndsWith('.webp', $live['items'][0]['image']);
        $this->assertSame(0, $live['items'][1]['remaining']);
        $this->assertSame(['Une cliente vient de prendre A1', 'Cocody vient de prendre A2'], array_column($live['ticker'], 'text'));
        $this->assertStringNotContainsString('Fatou', $screen->getContent());
        $this->assertStringNotContainsString('0506070809', $screen->getContent());
        $this->assertNotNull(LiveScreen::sole()->last_seen_at);

        // La vendeuse passe au défilement, puis « Comment commander » ; montrer un article revient à l'article
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/display", ['mode' => 'scroll'])->assertOk();
        $this->assertSame('scroll', $this->platformApi('GET', 'screens/'.$created['token'])->json('data.live.mode'));
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/display", ['mode' => 'how'])->assertOk();
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/feature", ['item_id' => $items[0]['id']])->assertOk();
        $this->assertSame('product', $this->storeApi($this->store, 'GET', "lives/{$this->live}/display")->json('data.live.mode'));

        // Lien direct pour l'ordinateur (LIVE Studio, PRISM) ; écran retiré : il ne montre plus rien
        $link = $this->storeApi($this->store, 'POST', 'lives/screens/link')->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('#/ecran/[A-Za-z0-9]{48}$#', $link['url']);
        $this->storeApi($this->store, 'GET', 'lives/screens')->assertJsonCount(2, 'data');
        $this->storeApi($this->store, 'DELETE', 'lives/screens/'.$link['id'])->assertOk();
        $this->platformApi('GET', 'screens/'.basename($link['url']))->assertNotFound();
    }

    public function test_cards_of_the_live_are_images_ready_for_the_gallery(): void
    {
        $cards = $this->storeApi($this->store, 'GET', "lives/{$this->live}/cards")->assertOk()->json('data');
        $this->assertSame(['A1', 'A2'], array_column($cards['items'], 'code'));
        foreach ([$cards['items'][0]['path'], $cards['items'][0]['sold_path'], $cards['how']['path']] as $path) {
            Storage::disk('public')->assertExists($path);
            [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
            $this->assertSame([900, 1200], [$width, $height]);
        }
        $this->assertNotSame($cards['items'][0]['path'], $cards['items'][0]['sold_path']);

        // Toutes les cartes d'un coup (avec les versions VENDU)
        $zip = $this->storeApi($this->store, 'GET', "lives/{$this->live}/cards.zip?sold=1")->assertOk();
        $this->assertStringContainsString('cartes-du-live-'.$this->live.'.zip', $zip->headers->get('content-disposition'));
        $file = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($file, $zip->streamedContent());
        $archive = new \ZipArchive;
        $archive->open($file);
        $names = array_map(fn ($i) => $archive->getNameIndex($i), range(0, $archive->numFiles - 1));
        sort($names);
        $this->assertSame(['00-comment-commander.jpg', '01-A1-vendu.jpg', '01-A1.jpg', '02-A2-vendu.jpg', '02-A2.jpg'], $names);
    }
}
