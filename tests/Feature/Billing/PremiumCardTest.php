<?php

namespace Tests\Feature\Billing;

use App\Enums\ShipmentStatus;
use App\Models\AccessKey;
use App\Models\CarrierConnection;
use App\Models\Order;
use App\Models\Place;
use App\Models\Shipment;
use App\Models\Store;
use App\Services\Billing\Billing;
use App\Services\Billing\PremiumCards;
use App\Services\Catalog\CatalogWriter;
use App\Services\Places\Places;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Tests\TestCase;

/**
 * Cartes Membre Premium offertes par les entreprises de Jibiat (contrat socle 1.6) : demande signée, une carte par
 * marchand, lecture et annulation, puis la formule A dans l'espace marchand (mois offerts, réduction tant que la
 * boutique livre avec l'entreprise, réduction arrêtée sinon).
 */
class PremiumCardTest extends TestCase
{
    private const API = 'http://api.localhost/api/partners/v1/premium-cards';

    private const SECRET = 'secret-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.jibiat.premium_secret' => self::SECRET]);
    }

    private function signed(string $method, string $content, ?int $at = null, ?string $secret = null): array
    {
        $at ??= time();

        return [
            Contract::PARTNER_TIMESTAMP_HEADER => (string) $at,
            Contract::PARTNER_SIGNATURE_HEADER => WebhookSignature::sign($secret ?? self::SECRET, $at, $content),
            'Accept' => 'application/json',
        ];
    }

    private function offer(array $body, array $headers = []): TestResponse
    {
        $raw = json_encode($body);
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach (($headers ?: $this->signed('POST', $raw)) as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', self::API, [], [], [], $server, $raw);
    }

    private function read(string $method, string $path): TestResponse
    {
        app()->forgetScopedInstances();

        return $this->withHeaders($this->signed($method, $path))->json($method, 'http://api.localhost'.$path);
    }

    private function request(string $reference = '128'): array
    {
        $body = Contract::example('premium-card-request');
        $body['merchant']['reference'] = $reference;

        return $body;
    }

    private function redeem(Store $store, string $code): TestResponse
    {
        app()->forgetScopedInstances();
        $this->actingAs($store->owner);

        return $this->storeApi($store, 'POST', 'billing/access-key', ['code' => $code]);
    }

    private function billing(Store $store): TestResponse
    {
        app()->forgetScopedInstances();
        $this->actingAs($store->owner);

        return $this->storeApi($store, 'GET', 'billing')->assertOk();
    }

    /**
     * Expédition confiée à l'entreprise (connexion Jibiat à « livraison-express-ci »).
     */
    private function shipWith(Store $store, CarrierConnection $connection): void
    {
        app(Places::class)->provisionDefaultZone($store);
        $variant = app(CatalogWriter::class)->save($store, null, ['title' => 'Sac pagne', 'variants' => [['price' => 9000, 'on_hand' => 50]]])->variants[0]->id;
        $this->actingAs($store->owner);
        $order = $this->storeApi($store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_name' => 'Fatou', 'customer_phone' => '0506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::where('name', 'Angré')->value('id'), 'items' => [['variant_id' => $variant, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        Shipment::create(['store_id' => $store->id, 'order_id' => Order::findOrFail($order)->id, 'mode' => 'carrier', 'status' => ShipmentStatus::Requested,
            'carrier_connection_id' => $connection->id, 'idempotency_key' => (string) Str::uuid(), 'requested_at' => now()]);
    }

    public function test_a_company_offers_reads_and_revokes_cards_with_a_signed_request(): void
    {
        $this->assertSame([], Contract::validate('premium-card-request', $this->request()));

        // Sans signature, signature fausse, trop vieille, ou sans secret réglé : refusé
        $raw = json_encode($this->request());
        $this->call('POST', self::API, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertUnauthorized();
        $this->offer($this->request(), $this->signed('POST', $raw, secret: 'autre'))->assertUnauthorized();
        $this->offer($this->request(), $this->signed('POST', $raw, at: time() - 600))->assertUnauthorized();
        $this->offer($this->request(), $this->signed('POST', $raw.' '))->assertUnauthorized();
        config(['services.jibiat.premium_secret' => '']);
        $this->offer($this->request(), $this->signed('POST', $raw, secret: ''))->assertUnauthorized();
        config(['services.jibiat.premium_secret' => self::SECRET]);
        // Sur la plateforme (pas api.*) : la route n'existe pas
        $this->withHeaders($this->signed('POST', $raw))->postJson('http://localhost/api/partners/v1/premium-cards', $this->request())->assertNotFound();

        // Corps hors contrat
        $bad = $this->request();
        $bad['reason'] = 'cadeau';
        $this->offer($bad)->assertStatus(422);

        // Carte créée, au contrat ; la même demande rend la même carte
        $card = $this->offer($this->request())->assertCreated();
        $this->assertSame([], Contract::validate('premium-card', $card->json()));
        $card->assertJsonPath('data.label', 'Membre Premium Jibiat Marchand n° 001')->assertJsonPath('data.state', 'available')
            ->assertJsonPath('data.plan', 'Pro')->assertJsonPath('data.free_months', 6)->assertJsonPath('data.discount_rate', 0.3)
            ->assertJsonPath('data.offered_by.name', 'Livraison Express CI')->assertJsonPath('data.merchant_reference', '128')
            ->assertJsonPath('data.store', null);
        $code = $card->json('data.code');
        $this->assertStringEndsWith('/cle/'.$code, $card->json('data.url'));
        $this->offer($this->request())->assertOk()->assertJsonPath('data.code', $code);
        $key = AccessKey::where('code', $code)->sole();
        $this->assertSame(['automatic', 'Awa Mode', '+2250708091011'], [$key->partner_reason, $key->merchant_name, $key->merchant_phone]);

        // Deuxième marchand : n° 002 ; la page du QR code dit qui l'offre
        $second = $this->offer($this->request('130'))->assertCreated()->assertJsonPath('data.number', 2)->json('data.code');
        $this->platformApi('GET', 'access-keys/'.$second)->assertOk()->assertJsonPath('data.badge', 'Membre Premium Jibiat Marchand n° 002')
            ->assertJsonPath('data.offered_by.slug', 'livraison-express-ci')->assertJsonPath('data.months', 6)->assertJsonPath('data.discount_rate', 0.3);

        // Liste de l'entreprise, au contrat ; une autre entreprise ne voit rien
        $list = $this->read('GET', '/api/partners/v1/premium-cards?company=livraison-express-ci')->assertOk();
        $this->assertSame([], Contract::validate('premium-card-list', $list->json()));
        $this->assertSame([$second, $code], array_column($list->json('data'), 'code'));
        $this->read('GET', '/api/partners/v1/premium-cards?company=autre')->assertOk()->assertJsonCount(0, 'data');
        // Signature d'une autre requête : refusée
        $this->withHeaders($this->signed('GET', '/api/partners/v1/premium-cards?company=autre'))
            ->getJson('http://api.localhost/api/partners/v1/premium-cards?company=livraison-express-ci')->assertUnauthorized();

        // Annulation : seulement par l'entreprise de la carte ; ensuite une nouvelle carte peut être offerte
        $this->read('DELETE', "/api/partners/v1/premium-cards/{$code}?company=autre")->assertNotFound();
        $revoked = $this->read('DELETE', "/api/partners/v1/premium-cards/{$code}?company=livraison-express-ci")->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->assertSame([], Contract::validate('premium-card', $revoked->json()));
        $this->offer($this->request())->assertCreated()->assertJsonPath('data.number', 3);
    }

    public function test_formula_a_free_months_then_discount_while_delivering_with_the_company(): void
    {
        config(['platform.billing' => ['provider' => 'wave', 'mode' => 'live', 'credentials' => ['api_key' => 'wave_platform', 'webhook_secret' => 'platform_secret'], 'grace_days' => 7, 'reminder_days' => 3]]);
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions*' => fn (HttpRequest $r) => Http::response([
            'id' => 'cos-1', 'amount' => $r['amount'] ?? '0', 'currency' => 'XOF', 'payment_status' => 'processing', 'checkout_status' => 'open', 'wave_launch_url' => 'https://pay.wave.com/c/cos-1',
        ])]);
        $code = $this->offer($this->request())->assertCreated()->json('data.code');

        // Activation : offre Pro pendant 6 mois, boutique parrainée par l'entreprise
        $awa = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode'], plan: null);
        $this->redeem($awa, $code)->assertOk()->assertJsonPath('data.badge', 'Membre Premium Jibiat Marchand n° 001')
            ->assertJsonPath('data.premium', true)->assertJsonPath('data.founder', false)->assertJsonPath('data.state', 'free')
            ->assertJsonPath('data.offered_by', 'Livraison Express CI');
        $this->assertSame('livraison-express-ci', $awa->refresh()->referred_by);
        $until = AccessKey::where('code', $code)->value('expires_at');
        $this->assertSame(now()->addMonths(6)->toDateString(), $until->toDateString());
        $this->billing($awa)->assertJsonPath('data.plan.code', 'pro')->assertJsonPath('data.free.forever', false)
            ->assertJsonPath('data.free.until', $until->toDateString())->assertJsonPath('data.premium_discount', null);
        $card = $this->card($code);
        $this->assertSame(['free', $until->toDateString(), 'Awa Mode'], [$card['state'], $card['free_until'], $card['store']['name']]);
        // Une autre carte Premium n'est pas acceptée
        $other = $this->offer($this->request('200'))->json('data.code');
        $this->redeem($awa, $other)->assertStatus(422)->assertJsonPath('errors.code.0', 'Votre espace marchand a déjà sa carte Membre Premium.');

        // Prévenu 7 jours avant la fin des mois offerts
        $this->travelTo($until->copy()->subDays(6));
        app()->forgetScopedInstances();
        $this->assertSame(1, app(Billing::class)->run()['keys']['warned']);

        // Mois offerts finis, sans livraison avec l'entreprise : offre gratuite, pas de réduction
        $this->travelTo($until->copy()->addDay());
        app()->forgetScopedInstances();
        $this->assertSame(1, app(Billing::class)->run()['keys']['discount']);
        $this->billing($awa)->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.access_key.state', 'lapsed')->assertJsonPath('data.premium_discount', null);

        // Une livraison confiée à l'entreprise : −30 % sur l'abonnement
        $connection = CarrierConnection::create(['store_id' => $awa->id, 'driver' => CarrierConnection::DELIVERY_APP, 'name' => 'Livraison Express CI', 'status' => 'active',
            'remote_account' => ['company' => ['slug' => 'livraison-express-ci', 'name' => 'Livraison Express CI']]]);
        $this->shipWith($awa, $connection);
        $this->billing($awa)->assertJsonPath('data.access_key.state', 'discount')->assertJsonPath('data.premium_discount.rate', 0.3)
            ->assertJsonPath('data.premium_discount.company', 'Livraison Express CI');
        $this->storeApi($awa, 'POST', 'billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly', 'method' => 'wave'])->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['amount'] === '8750');
        app()->forgetScopedInstances();
        $this->assertSame(1, app(Billing::class)->run()['keys']['discount']);
        $this->assertSame('discount', $this->card($code)['state']);

        // Plus de livraison avec l'entreprise depuis 60 jours : réduction arrêtée
        $this->travel(61)->days();
        app()->forgetScopedInstances();
        $this->assertSame(1, app(Billing::class)->run()['keys']['discount']);
        $this->billing($awa)->assertJsonPath('data.access_key.state', 'lapsed')->assertJsonPath('data.premium_discount', null);
        $this->assertSame('lapsed', $this->card($code)['state']);
    }

    public function test_the_formula_is_set_in_the_console_and_kept_by_cards_already_offered(): void
    {
        $first = $this->offer($this->request())->json('data');
        app(PremiumCards::class)->updateSettings(['plan' => 'starter', 'free_months' => 3, 'discount_rate' => 0.5, 'active_days' => 30]);
        app()->forgetScopedInstances();
        $second = $this->offer($this->request('131'))->assertCreated()->json('data');

        $this->assertSame(['Pro', 6, 0.3, 1], [$first['plan'], $first['free_months'], $first['discount_rate'], $first['number']]);
        $this->assertSame(['Starter', 3, 0.5, 2], [$second['plan'], $second['free_months'], $second['discount_rate'], $second['number']]);
    }

    /**
     * @return array<string, mixed> carte telle que l'entreprise la lit
     */
    private function card(string $code): array
    {
        return collect($this->read('GET', '/api/partners/v1/premium-cards?company=livraison-express-ci')->assertOk()->json('data'))->firstWhere('code', $code);
    }
}
