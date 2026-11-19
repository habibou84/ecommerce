<?php

namespace Tests\Feature\Couriers;

use App\Models\Courier;
use App\Models\CourierProfile;
use App\Models\Place;
use App\Models\Store;
use App\Models\User;
use App\Services\Couriers\CourierRoster;
use App\Services\Places\Places;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lien commun des livreurs : inscription sans boutique, ajout par n'importe quelle boutique (numéro ou annuaire),
 * acceptation par le livreur, pièce d'identité exigée par une boutique.
 */
class CourierNetworkTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        app(Places::class)->provisionDefaultZone($this->store);
    }

    /** Inscription au lien commun : numéro vérifié, compte et profil créés, sans boutique */
    private function signUp(string $phone = '0700000003', string $name = 'Moussa Traoré'): User
    {
        $cocody = Place::whereNull('store_id')->where('name', 'Cocody')->value('id');
        $start = $this->platformApi('POST', 'courier-space/signup', [
            'name' => $name, 'phone' => $phone, 'password' => 'motdepasse', 'vehicle_type' => 'moto', 'place_ids' => [$cocody],
        ])->assertStatus(202)->json();
        $this->platformApi('POST', "verifications/{$start['verification']['token']}/confirm", ['code' => $start['demo_code']])
            ->assertCreated()->assertJsonPath('redirect', '/livreur');
        $this->logout();

        return User::where('name', $name)->firstOrFail();
    }

    public function test_a_courier_signs_up_without_a_store_and_a_store_asks_him_by_phone(): void
    {
        $moussa = $this->signUp();
        $this->assertTrue(CourierProfile::where('user_id', $moussa->id)->where('listed', true)->exists());
        // Le même numéro ne s'inscrit pas deux fois
        $this->platformApi('POST', 'courier-space/signup', ['name' => 'Autre', 'phone' => '0700000003', 'password' => 'motdepasse', 'vehicle_type' => 'moto'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        // Connexion au lien commun : aucune boutique encore, il reste dans son espace
        $this->platformApi('POST', 'courier-space/login', ['phone' => '0700000003', 'password' => 'motdepasse'])->assertOk()->assertJsonPath('data.redirect', null);
        $this->platformApi('GET', 'courier-space')->assertOk()->assertJsonPath('data.stores', [])->assertJsonPath('data.requests', [])
            ->assertJsonPath('data.profile.vehicle_type', 'moto')->assertJsonPath('data.profile.places.0.name', 'Cocody');
        $this->logout();

        // La boutique tape son numéro : demande, pas d'invitation ; numéro masqué tant qu'il n'a pas accepté
        $this->actingAs($this->store->owner);
        $res = $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Moussa', 'phone' => '0700000003'])->assertCreated()
            ->assertJsonPath('requested', true)->assertJsonPath('invitation_url', null)->assertJsonPath('data.status', 'requested')->assertJsonPath('data.phone', null);
        $fiche = Courier::findOrFail($res->json('data.id'));
        $this->assertTrue(DatabaseNotification::where('notifiable_id', $moussa->id)->get()->contains(fn ($n) => str_contains($n->data['title'], 'Awa Mode veut que vous livriez')));
        // Pas de course avant qu'il accepte
        $this->assertNull(CourierRoster::of($moussa, $this->store));
        $this->logout();

        // Il accepte au lien commun : la boutique est prévenue, il ouvre son application de la boutique
        $this->actingAs($moussa);
        $this->platformApi('GET', 'courier-space')->assertJsonPath('data.requests.0.store', 'Awa Mode');
        $this->platformApi('POST', "courier-space/requests/{$fiche->id}/accept")->assertOk()->assertJsonPath('data.stores.0.name', 'Awa Mode')->assertJsonPath('data.requests', []);
        $this->assertSame('active', $fiche->fresh()->status);
        $this->assertTrue(DatabaseNotification::where('notifiable_id', $this->store->owner_id)->get()->contains(fn ($n) => str_contains($n->data['title'], 'a accepté de livrer pour vous')));
        $this->assertStringStartsWith($this->storeUrl($this->store, ''), $this->platformApi('POST', 'courier-space/open')->assertOk()->json('data.redirect'));
        $this->storeApi($this->store, 'GET', 'courier/me')->assertOk()->assertJsonPath('data.name', 'Moussa Traoré');

        // Il quitte la boutique (rien en cours) : elle est prévenue
        $this->platformApi('POST', "courier-space/stores/{$fiche->id}/leave")->assertOk()->assertJsonPath('data.stores', []);
        $this->assertSame('inactive', $fiche->fresh()->status);
    }

    public function test_the_directory_and_the_identity_document_a_store_can_require(): void
    {
        $moussa = $this->signUp();
        $this->signUp('0700000004', 'Awa Bamba');
        // Pièce d'identité fournie : en tête de l'annuaire
        $this->signUp('0700000005', 'Yao Kouamé');
        CourierProfile::whereHas('user', fn ($q) => $q->where('name', 'Yao Kouamé'))->update(['id_document_path' => 'couriers/x/id/cni.jpg']);
        $this->actingAs($this->store->owner);
        $names = array_column($this->storeApi($this->store, 'GET', 'couriers/directory')->json('data'), 'name');
        $this->assertSame('Yao K.', $names[0]);
        $this->assertEqualsCanonicalizing(['Awa B.', 'Moussa T.'], array_slice($names, 1));
        $this->logout();
        CourierProfile::whereHas('user', fn ($q) => $q->whereIn('name', ['Awa Bamba', 'Yao Kouamé']))->update(['listed' => false]);

        // Annuaire : livreurs visibles qui livrent dans la commune (ou un de ses quartiers), sans leur numéro
        $this->actingAs($this->store->owner);
        $angre = Place::whereNull('store_id')->where('name', 'Angré')->value('id');
        $list = $this->storeApi($this->store, 'GET', "couriers/directory?place_id={$angre}")->assertOk()->json('data');
        $this->assertSame([['Moussa T.', false]], array_map(fn ($p) => [$p['name'], $p['has_id_document']], $list));
        $this->assertArrayNotHasKey('phone', $list[0]);
        $this->assertSame([], $this->storeApi($this->store, 'GET', 'couriers/directory?place_id='.Place::whereNull('store_id')->where('name', 'Yopougon')->value('id'))->json('data'));

        // La boutique exige la pièce, puis lui demande depuis l'annuaire
        $this->storeApi($this->store, 'PATCH', 'couriers/settings', ['couriers_require_id' => true])->assertOk()->assertJsonPath('data.couriers_require_id', true);
        $fiche = $this->storeApi($this->store, 'POST', "couriers/directory/{$list[0]['id']}/request")->assertCreated()->json('data');
        $this->assertSame([], $this->storeApi($this->store, 'GET', "couriers/directory?place_id={$angre}")->json('data'));
        $this->logout();

        // Sans pièce, il ne peut pas accepter ; avec la pièce, si
        $this->actingAs($moussa);
        $this->platformApi('GET', 'courier-space')->assertJsonPath('data.requests.0.require_id', true);
        $this->platformApi('POST', "courier-space/requests/{$fiche['id']}/accept")->assertStatus(422)->assertJsonValidationErrors('id_document');
        $this->platformApi('POST', 'courier-space/id-document', ['document' => UploadedFile::fake()->image('cni.jpg')])->assertOk()->assertJsonPath('data.profile.has_id_document', true);
        $this->platformApi('POST', 'courier-space/photo', ['photo' => UploadedFile::fake()->image('moi.jpg')])->assertOk();
        $this->platformApi('POST', "courier-space/requests/{$fiche['id']}/accept")->assertOk();
        $this->logout();

        // La boutique voit sa photo, sa pièce et son numéro
        $this->actingAs($this->store->owner);
        $row = collect($this->storeApi($this->store, 'GET', 'couriers')->json('data'))->firstWhere('id', $fiche['id']);
        $this->assertSame(['+2250700000003', true], [$row['phone'], $row['profile']['has_id_document']]);
        $this->get($this->storeUrl($this->store, $row['profile']['id_document_url']))->assertOk();
        $this->get($this->storeUrl($this->store, $row['profile']['photo_url']))->assertOk();
    }

    public function test_a_store_withdraws_a_request_and_the_courier_declines_another(): void
    {
        $moussa = $this->signUp();
        $this->actingAs($this->store->owner);
        $first = $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Moussa', 'phone' => '0700000003'])->assertCreated()->json('data.id');
        $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Moussa', 'phone' => '0700000003'])->assertStatus(422);
        $this->storeApi($this->store, 'POST', "couriers/{$first}/cancel-request")->assertOk();
        $this->assertNull(Courier::find($first));

        $second = $this->storeApi($this->store, 'POST', 'couriers', ['name' => 'Moussa', 'phone' => '0700000003'])->assertCreated()->json('data.id');
        $this->logout();
        $this->actingAs($moussa);
        $this->platformApi('POST', "courier-space/requests/{$second}/decline")->assertOk()->assertJsonPath('data.requests', []);
        $this->assertSame('declined', Courier::findOrFail($second)->status);
        $this->assertTrue(DatabaseNotification::where('notifiable_id', $this->store->owner_id)->get()->contains(fn ($n) => str_contains($n->data['title'], 'a refusé votre demande')));
        // Une demande ne s'accepte plus une fois refusée
        $this->platformApi('POST', "courier-space/requests/{$second}/accept")->assertStatus(422);
    }
}
