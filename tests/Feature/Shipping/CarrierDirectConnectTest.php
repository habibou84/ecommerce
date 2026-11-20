<?php

namespace Tests\Feature\Shipping;

use App\Models\CarrierConnection;
use App\Models\CarrierRequest;
use App\Models\Store;
use App\Services\Places\Places;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Tests\TestCase;

/**
 * Connexion directe aux entreprises de Jibiat (contrat socle 1.12), simulée avec les exemples du contrat : le
 * marchand touche « Travailler avec … », sans quitter Jibiat Marchand ; la clé arrive dans la réponse ou plus tard
 * avec la décision signée de l'entreprise.
 */
class CarrierDirectConnectTest extends TestCase
{
    private const SECRET = 'secret-connexion-directe';

    private const API = 'https://rapide.jibiat.com/api/public/v1';

    private const DIRECT = 'https://rapide.jibiat.com/api/public/connect/direct';

    private const KEY = 'lv_abcd1234_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Store $store;

    /** @var array<string, \Closure> */
    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jibiat.connect_secret' => self::SECRET]);
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            foreach ($this->remote as $pattern => $response) {
                [$method, $url] = str_contains($pattern, ' ') ? explode(' ', $pattern, 2) : [null, $pattern];
                if (($method === null || $method === $request->method()) && Str::is($url, $request->url())) {
                    return $response($request);
                }
            }

            return Http::response(['message' => 'Introuvable'], 404);
        });
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode', 'address' => 'Angré 8e tranche, Cocody']);
        app(Places::class)->provisionDefaultZone($this->store);
        $this->store->owner->forceFill(['phone' => '+2250708091011', 'phone_verified_at' => now()->subMonth()])->save();
        $this->actingAs($this->store->owner);

        $this->stub([
            'https://jibiat.com/api/public/directory' => fn () => Http::response(Contract::example('directory')),
            self::API.'/me' => fn () => Http::response(Contract::example('me')),
            self::API.'/zones' => fn () => Http::response(Contract::example('zones')),
            self::API.'/webhooks' => fn () => Http::response(['secret' => 'whsec_secret'] + Contract::example('webhook-created'), 201),
        ]);
    }

    /**
     * @param  array<string, \Closure>  $routes
     */
    private function stub(array $routes): void
    {
        $this->remote = $routes + $this->remote;
    }

    /**
     * Réponse connect-direct de l'entreprise (exemple du contrat), pour la demande reçue.
     */
    private function answer(string $example, array $extra = []): \Closure
    {
        return function (HttpRequest $request) use ($example, $extra) {
            $data = array_replace_recursive(Contract::example($example)['data'], $extra);
            $data['request_id'] = $request->method() === 'POST' ? $request['request_id'] : basename($request->url());
            if (isset($data['connection']['api_key'])) {
                $data['connection']['api_key'] = self::KEY;
            }

            return Http::response(['data' => $data], $request->method() === 'POST' ? 201 : 200);
        };
    }

    private function decision(array $body): TestResponse
    {
        $raw = json_encode($body);
        $at = time();

        return $this->call('POST', 'http://api.localhost/api/partners/v1/connections', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_JIBIAT_TIMESTAMP' => (string) $at,
            'HTTP_X_JIBIAT_SIGNATURE' => WebhookSignature::sign(self::SECRET, $at, $raw),
        ], $raw);
    }

    public function test_an_open_company_connects_the_store_in_one_click_without_leaving(): void
    {
        $this->stub(['POST '.self::DIRECT => $this->answer('connect-direct-connected')]);

        $list = $this->storeApi($this->store, 'GET', 'carriers/directory')->assertOk();
        $this->assertTrue($list->json('data.0.direct'));

        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertCreated()
            ->assertJsonPath('data.status', 'connected')->assertJsonPath('data.connection.name', 'Rapide Express');

        // Demande signée, conforme au contrat, avec le téléphone vérifié du gérant et le lieu de ramassage
        Http::assertSent(function (HttpRequest $r) {
            if ($r->url() !== self::DIRECT) {
                return false;
            }
            $this->assertSame([], Contract::validate('connect-direct-request', $r->data()));
            $this->assertTrue(WebhookSignature::verify(self::SECRET, $r->header(Contract::PARTNER_TIMESTAMP_HEADER)[0], $r->body(), $r->header(Contract::PARTNER_SIGNATURE_HEADER)[0]));

            return $r['owner']['phone'] === '+2250708091011' && $r['store']['name'] === 'Awa Mode' && $r['pickup']['place'] === 'abidjan-cocody-angre';
        });
        $connection = CarrierConnection::firstOrFail();
        $this->assertTrue($connection->isActive());
        $this->assertSame(self::KEY, $connection->api_key);
        $this->assertTrue($this->storeApi($this->store, 'GET', 'carriers/directory')->json('data.0.connected'));
    }

    public function test_a_request_waits_for_the_company_then_its_signed_decision_connects_the_store(): void
    {
        $this->stub(['POST '.self::DIRECT => $this->answer('connect-direct-pending')]);

        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $request = CarrierRequest::firstOrFail();
        $this->assertSame(0, CarrierConnection::count());
        $this->assertSame('pending', $this->storeApi($this->store, 'GET', 'carriers/directory')->json('data.0.request.status'));

        // Toucher de nouveau : la même demande, pas une seconde
        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertOk()->assertJsonPath('data.id', $request->id);

        // Décision mal signée : refusée
        $body = [...Contract::example('connect-decision-connected'), 'request_id' => $request->request_id];
        $body['connection']['api_key'] = self::KEY;
        $this->call('POST', 'http://api.localhost/api/partners/v1/connections', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body))->assertStatus(401);

        $this->decision($body)->assertOk()->assertJsonPath('data.status', 'connected');
        $this->assertSame('connected', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->connection_id);
        $this->assertTrue(CarrierConnection::firstOrFail()->isActive());

        // Même décision reçue deux fois : sans effet
        $this->decision($body)->assertOk();
        $this->assertSame(1, CarrierConnection::count());
    }

    public function test_a_declined_or_cancelled_request_is_shown_and_can_be_sent_again(): void
    {
        $this->stub(['POST '.self::DIRECT => $this->answer('connect-direct-pending')]);
        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertCreated();
        $request = CarrierRequest::firstOrFail();

        $this->decision([...Contract::example('connect-decision-declined'), 'request_id' => $request->request_id])->assertOk();
        $entry = $this->storeApi($this->store, 'GET', 'carriers/directory')->json('data.0.request');
        $this->assertSame('declined', $entry['status']);
        $this->assertSame('Nous ne livrons pas encore votre commune de ramassage.', $entry['reason']);

        // Nouvelle demande, puis annulée par le marchand
        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertCreated();
        $second = CarrierRequest::latest('id')->firstOrFail();
        $this->stub(['DELETE '.self::DIRECT.'/*' => $this->answer('connect-direct-pending', ['status' => 'cancelled'])]);
        $this->storeApi($this->store, 'DELETE', "carriers/requests/{$second->id}")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_lost_decision_is_read_back_from_the_company(): void
    {
        $this->stub(['POST '.self::DIRECT => $this->answer('connect-direct-confirmation')]);
        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertCreated()->assertJsonPath('data.status', 'confirmation');
        $request = CarrierRequest::firstOrFail();

        $this->stub(['GET '.self::DIRECT.'/*' => $this->answer('connect-direct-connected')]);
        $this->storeApi($this->store, 'POST', "carriers/requests/{$request->id}/refresh")->assertOk()->assertJsonPath('data.status', 'connected');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && $r->url() === self::DIRECT.'/'.$request->request_id
            && WebhookSignature::verify(self::SECRET, $r->header(Contract::PARTNER_TIMESTAMP_HEADER)[0], '/api/public/connect/direct/'.$request->request_id, $r->header(Contract::PARTNER_SIGNATURE_HEADER)[0]));
    }

    public function test_an_unverified_phone_or_a_company_without_direct_connection_is_refused(): void
    {
        $this->store->owner->forceFill(['phone_verified_at' => null])->save();
        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertStatus(422);

        $this->store->owner->forceFill(['phone_verified_at' => now()])->save();
        $directory = Contract::example('directory');
        unset($directory['data'][0]['direct']);
        $this->stub(['https://jibiat.com/api/public/directory' => fn () => Http::response($directory)]);
        cache()->forget('carrier-directory');
        $this->storeApi($this->store, 'POST', 'carriers/connect/direct', ['slug' => 'rapide'])->assertStatus(422);

        $this->assertSame(0, CarrierRequest::count());
    }
}
