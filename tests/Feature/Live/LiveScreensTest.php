<?php

namespace Tests\Feature\Live;

use App\Models\LiveScreen;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsappLink;
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

        // « Tester » : l'écran se signale (« Écran connecté ») quelques secondes
        $this->platformApi('GET', 'screens/'.$created['token'])->assertJsonPath('data.test', false);
        $this->storeApi($this->store, 'POST', 'lives/screens/'.LiveScreen::sole()->id.'/test')->assertOk();
        $this->platformApi('GET', 'screens/'.$created['token'])->assertJsonPath('data.test', true);

        // Pas encore de live : l'écran attend, avec le WhatsApp de la boutique
        $this->platformApi('GET', 'screens/'.$created['token'])->assertJsonPath('data.paired', true)->assertJsonPath('data.live', null)
            ->assertJsonPath('data.store.whatsapp_url', 'https://wa.me/2250700000001')
            ->assertJsonPath('data.store.whatsapp', '+225 07 00 00 00 01');

        // WhatsApp relié par QR code qui n'a donné que son identifiant masqué : écarté, le numéro de la boutique reste
        WhatsappLink::create(['store_id' => $this->store->id, 'status' => 'connected', 'phone' => '+123456789012345', 'connected_at' => now()]);
        $this->platformApi('GET', 'screens/'.$created['token'])->assertJsonPath('data.store.whatsapp', '+225 07 00 00 00 01');

        // WhatsApp resté sur l'ancien numéro à 8 chiffres : remis aux 10 chiffres d'aujourd'hui (05 rétabli)
        WhatsappLink::query()->update(['phone' => '+22595323272']);
        $this->platformApi('GET', 'screens/'.$created['token'])
            ->assertJsonPath('data.store.whatsapp', '+225 05 95 32 32 72')
            ->assertJsonPath('data.store.whatsapp_url', 'https://wa.me/2250595323272')
            ->assertJsonPath('data.store.link', $this->store->url('/live'));
        WhatsappLink::query()->delete();

        // WhatsApp relié à Meta : c'est son numéro qui s'affiche, pas celui de la fiche boutique
        $this->storeApi($this->store, 'PUT', 'whatsapp', [
            'phone_number_id' => '1098765', 'waba_id' => '2233', 'display_phone' => '+2250102030405', 'access_token' => 'EAAG-token', 'app_secret' => 'app-secret',
        ])->assertOk();
        $this->platformApi('GET', 'screens/'.$created['token'])->assertJsonPath('data.store.whatsapp_url', 'https://wa.me/2250102030405');

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
        $link = $this->storeApi($this->store, 'POST', 'lives/screens/link')->assertOk()->assertJsonPath('data.is_link', true)->json('data');
        $this->assertSame($link['url'], $this->storeApi($this->store, 'POST', 'lives/screens/link')->json('data.url'));
        $this->assertMatchesRegularExpression('#/ecran/[A-Za-z0-9]{48}$#', $link['url']);
        $this->storeApi($this->store, 'GET', 'lives/screens')->assertJsonCount(2, 'data');
        $this->storeApi($this->store, 'DELETE', 'lives/screens/'.$link['id'])->assertOk();
        $this->platformApi('GET', 'screens/'.basename($link['url']))->assertNotFound();
    }

    public function test_screens_share_one_computation_and_get_an_empty_answer_when_nothing_changed(): void
    {
        $created = $this->platformApi('POST', 'screens')->assertCreated()->json('data');
        $this->storeApi($this->store, 'POST', 'lives/screens/pair', ['code' => $created['code']])->assertCreated();
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/start")->assertOk();
        $first = $this->platformApi('GET', 'screens/'.$created['token'])->assertOk();

        // Rien de neuf : réponse vide (304), sans renvoyer photos ni QR codes
        $this->withHeaders(['If-None-Match' => $first->headers->get('ETag')]);
        $this->platformApi('GET', 'screens/'.$created['token'])->assertStatus(304)->assertContent('');

        // Une pièce prise : le calcul partagé est refait tout de suite, l'écran reçoit tout
        $this->storeApi($this->store, 'POST', "lives/{$this->live}/reservations", ['code' => 'A2', 'name' => 'Awa Koné', 'phone' => '0707080910'])->assertOk();
        $after = $this->platformApi('GET', 'screens/'.$created['token'])->assertOk();
        $this->assertSame([0, 'Une cliente vient de prendre A2'], [$after->json('data.live.items.1.remaining'), $after->json('data.live.ticker.0.text')]);
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

    public function test_old_screens_are_replaced_or_forgotten_so_the_list_stays_short(): void
    {
        $pair = function (?string $name) {
            $code = $this->platformApi('POST', 'screens')->assertCreated()->json('data.code');

            return $this->storeApi($this->store, 'POST', 'lives/screens/pair', ['code' => $code, 'name' => $name])->assertCreated()->json('data');
        };

        // La même télé reliée trois fois (éteinte entre deux) : une seule ligne reste
        $first = $pair('Télé salon');
        $pair('Télé salon');
        $last = $pair('Télé salon');
        $this->assertSame([$last['id']], LiveScreen::where('store_id', $this->store->id)->pluck('id')->all());

        // Sans nom : « Écran » ; un deuxième sans nom alors que le premier est allumé : « Écran 2 »
        $a = $pair(null);
        $this->assertSame('Écran', $a['name']);
        LiveScreen::whereKey($a['id'])->update(['last_seen_at' => now()]);
        $this->assertSame('Écran 2', $pair(null)['name']);

        // Éteint depuis plus de 7 jours : retiré en ouvrant la liste ; le lien direct et l'écran allumé restent
        $link = $this->storeApi($this->store, 'POST', 'lives/screens/link')->assertOk();
        LiveScreen::whereKey($last['id'])->update(['last_seen_at' => now()->subDays(8)]);
        LiveScreen::where('name', 'Ordinateur ou PRISM (lien)')->update(['last_seen_at' => now()->subDays(30)]);
        $names = collect($this->storeApi($this->store, 'GET', 'lives/screens')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Ordinateur ou PRISM (lien)', 'Écran', 'Écran 2'], $names);
        $this->assertNotSame($first['id'], $last['id']);
    }
}
