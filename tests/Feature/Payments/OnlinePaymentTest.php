<?php

namespace Tests\Feature\Payments;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PaymentLink;
use App\Models\Place;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use App\Services\Payments\PaymentDesk;
use App\Services\Places\Places;
use App\Services\Storefront\CartService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    private Store $store;

    private Product $robe;

    /** @var array<string, \Closure> */
    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            foreach ($this->remote as $pattern => $response) {
                [$method, $url] = str_contains($pattern, ' ') ? explode(' ', $pattern, 2) : [null, $pattern];
                if (($method === null || $method === $request->method()) && Str::is($url, $request->url())) {
                    return $response($request);
                }
            }

            return Http::response(['message' => 'introuvable'], 404);
        });

        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        app(Places::class)->provisionDefaultZone($this->store);
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($this->store->owner);
    }

    private function stub(string $pattern, \Closure $response): void
    {
        $this->remote = [$pattern => $response] + $this->remote;
    }

    private function waveAccount(array $extra = []): PaymentAccount
    {
        $id = $this->storeApi($this->store, 'POST', 'payments/accounts', [
            'provider' => 'wave', 'credentials' => ['api_key' => 'wave_sn_prod_xxx', 'webhook_secret' => 'wave_secret'], 'fee_percent' => 1, ...$extra,
        ])->assertCreated()->json('data.id');

        return PaymentAccount::findOrFail($id);
    }

    private function cinetpayAccount(): PaymentAccount
    {
        $id = $this->storeApi($this->store, 'POST', 'payments/accounts', [
            'provider' => 'cinetpay', 'credentials' => ['apikey' => 'cp_key', 'site_id' => '123456', 'secret_key' => 'cp_secret'], 'fee_percent' => 3.5,
        ])->assertCreated()->json('data.id');

        return PaymentAccount::findOrFail($id);
    }

    private function waveSession(string $status = 'processing', ?int $amount = null): \Closure
    {
        return fn (HttpRequest $r) => Http::response([
            'id' => 'cos-18qq25rgr100a', 'amount' => (string) ($amount ?? 31500), 'currency' => 'XOF', 'checkout_status' => $status === 'succeeded' ? 'complete' : 'open',
            'payment_status' => $status, 'wave_launch_url' => 'https://pay.wave.com/c/cos-18qq25rgr100a', 'client_reference' => 'x',
        ]);
    }

    /**
     * Commande passée sur la boutique ; renvoie la réponse (redirection vers le prestataire ou la page merci).
     */
    private function placeOrder(array $payment): TestResponse
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $cart = $this->withCredentials()->withHeaders($headers)
            ->postJson($this->storeUrl($this->store, '/panier/articles'), ['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2])
            ->getCookie(CartService::COOKIE)->getValue();

        return $this->withCredentials()->withHeaders($headers)->withCookie(CartService::COOKIE, $cart)
            ->postJson($this->storeUrl($this->store, '/commande'), [
                'name' => 'Fatou Diallo', 'phone' => '0506070809', 'delivery_method' => 'delivery',
                'place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), ...$payment,
            ]);
    }

    private function webhook(PaymentAccount $account, string $body, array $server): TestResponse
    {
        return $this->call('POST', 'http://api.localhost/webhooks/payments/'.$account->webhook_uuid, [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private function waveWebhook(PaymentAccount $account, Payment $payment, string $id = 'EV_1', ?string $secret = null): TestResponse
    {
        $body = json_encode(['id' => $id, 'type' => 'checkout.session.completed', 'data' => ['id' => $payment->provider_reference, 'client_reference' => $payment->idempotency_key]]);
        $t = (string) time();

        return $this->webhook($account, $body, ['HTTP_WAVE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.$body, $secret ?? 'wave_secret')]);
    }

    public function test_accounts_and_routing_settings(): void
    {
        $wave = $this->waveAccount();
        $cinetpay = $this->cinetpayAccount();

        $settings = $this->storeApi($this->store, 'GET', 'payments/settings')->assertOk();
        $settings->assertJsonPath('data.routes.wave', [$wave->id])->assertJsonPath('data.routes.orange_money', [$cinetpay->id])
            ->assertJsonPath('data.accounts.0.credentials_hint.api_key', '…_xxx')->assertJsonMissingPath('data.accounts.0.credentials');
        $this->assertStringStartsWith('http://api.localhost/webhooks/payments/', $settings->json('data.accounts.0.webhook_url'));
        $this->assertSame('wave_sn_prod_xxx', $wave->fresh()->credential('api_key'));

        // Wave en direct, CinetPay en secours ; Wave ne sait pas encaisser Orange Money
        $this->storeApi($this->store, 'PUT', 'payments/routes', ['routes' => ['wave' => [$wave->id, $cinetpay->id], 'orange_money' => [$cinetpay->id]]])
            ->assertOk()->assertJsonPath('data.routes.wave', [$wave->id, $cinetpay->id]);
        $this->storeApi($this->store, 'PUT', 'payments/routes', ['routes' => ['orange_money' => [$wave->id]]])->assertStatus(422);

        // Modification sans ressaisir les clés
        $this->storeApi($this->store, 'PATCH', "payments/accounts/{$wave->id}", ['fee_percent' => 0.9, 'credentials' => ['api_key' => '']])->assertOk();
        $this->assertSame(['wave_sn_prod_xxx', 0.9], [$wave->fresh()->credential('api_key'), $wave->fresh()->fee_percent]);

        $this->storeApi($this->store, 'PATCH', 'payments/settings', ['online_payment' => 'deposit'])->assertStatus(422);
        $this->storeApi($this->store, 'PATCH', 'payments/settings', ['online_payment' => 'deposit', 'deposit_percent' => 30])->assertOk();
    }

    public function test_paying_online_with_wave_at_checkout(): void
    {
        $wave = $this->waveAccount();
        $this->store->forceFill(['online_payment' => 'optional'])->save();
        $this->stub('POST https://api.wave.com/v1/checkout/sessions', $this->waveSession());

        $this->get($this->storeUrl($this->store, '/produits/robe-wax'))->assertOk();
        $response = $this->placeOrder(['payment' => 'online', 'payment_method' => 'wave'])->assertCreated();
        $this->assertSame('https://pay.wave.com/c/cos-18qq25rgr100a', $response->json('redirect'));

        $payment = Payment::sole();
        $this->assertSame(['pending', 'wave', 31500, 'cos-18qq25rgr100a'], [$payment->status, $payment->provider, $payment->amount, $payment->provider_reference]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.wave.com/v1/checkout/sessions'
            && $r['amount'] === '31500' && $r['currency'] === 'XOF' && $r['client_reference'] === $payment->idempotency_key
            && $r['success_url'] === 'http://awa.localhost/paiement/'.$payment->idempotency_key
            && $r->hasHeader('Authorization', 'Bearer wave_sn_prod_xxx'));

        // Retour du client : tant que Wave n'a pas validé, rien n'est enregistré
        $this->stub('GET https://api.wave.com/v1/checkout/sessions/*', $this->waveSession());
        $this->get($this->storeUrl($this->store, '/paiement/'.$payment->idempotency_key))->assertOk()->assertSee('en cours de vérification');
        $this->assertSame('pending', $payment->fresh()->status);

        $this->stub('GET https://api.wave.com/v1/checkout/sessions/*', $this->waveSession('succeeded'));
        $this->get($this->storeUrl($this->store, '/paiement/'.$payment->idempotency_key))->assertOk()->assertSee('Paiement reçu');

        $order = Order::sole();
        $this->assertSame(['succeeded', 315], [$payment->fresh()->status, $payment->fresh()->fee]);
        $this->assertSame(['paid', OrderStatus::Confirmed, 0], [$order->payment_status->value, $order->status, $order->amountDue()]);
    }

    public function test_payment_needs_a_confirmed_amount_and_a_live_account(): void
    {
        $wave = $this->waveAccount();
        $this->store->forceFill(['online_payment' => 'optional'])->save();
        $this->stub('POST https://api.wave.com/v1/checkout/sessions', $this->waveSession());
        $this->placeOrder(['payment' => 'online', 'payment_method' => 'wave'])->assertCreated();
        $payment = Payment::sole();

        // Réussi mais sans montant : rien n'est validé, le paiement attend une nouvelle lecture
        $this->stub('GET https://api.wave.com/v1/checkout/sessions/*', fn () => Http::response(['id' => 'cos-18qq25rgr100a', 'payment_status' => 'succeeded']));
        app(PaymentDesk::class)->confirm($payment->fresh());
        $this->assertSame('pending', $payment->fresh()->status);

        // Compte resté en mode test : un paiement de démonstration ne valide pas une commande en production
        $wave->forceFill(['mode' => 'test'])->save();
        $this->app['env'] = 'production';
        $this->stub('GET https://api.wave.com/v1/checkout/sessions/*', $this->waveSession('succeeded'));
        app(PaymentDesk::class)->confirm($payment->fresh());
        $this->assertSame(['failed', 'Compte de paiement en mode test'], [$payment->fresh()->status, $payment->fresh()->failure_reason]);
        $this->assertSame('unpaid', Order::sole()->payment_status->value);
    }

    public function test_cash_on_delivery_stays_available_when_payment_is_optional(): void
    {
        $this->waveAccount();
        $this->store->forceFill(['online_payment' => 'optional'])->save();

        $this->get($this->storeUrl($this->store, '/produits/robe-wax'));
        $this->placeOrder(['payment' => 'cod'])->assertCreated()->assertJsonPath('redirect', fn ($url) => str_starts_with($url, '/commande/merci/'));
        $this->assertSame(0, Payment::count());
        $this->placeOrder(['payment' => 'online'])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_fallback_to_the_next_provider_and_webhooks(): void
    {
        $wave = $this->waveAccount();
        $cinetpay = $this->cinetpayAccount();
        $this->storeApi($this->store, 'PUT', 'payments/routes', ['routes' => ['wave' => [$wave->id, $cinetpay->id]]])->assertOk();
        $this->store->forceFill(['online_payment' => 'required'])->save();

        $this->stub('POST https://api.wave.com/v1/checkout/sessions', fn () => Http::response(['message' => 'Service unavailable'], 503));
        $this->stub('POST https://api-checkout.cinetpay.com/v2/payment', fn () => Http::response(['code' => '201', 'message' => 'CREATED', 'data' => ['payment_token' => 'tok', 'payment_url' => 'https://checkout.cinetpay.com/payment/tok']]));

        $this->placeOrder(['payment_method' => 'wave'])->assertCreated()->assertJsonPath('redirect', 'https://checkout.cinetpay.com/payment/tok');
        $this->assertSame(['failed', 'pending'], Payment::orderBy('id')->pluck('status')->all());
        $payment = Payment::where('provider', 'cinetpay')->sole();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/payment') && $r['channels'] === 'WALLET' && $r['site_id'] === '123456'
            && $r['transaction_id'] === str_replace('-', '', $payment->idempotency_key) && $r['notify_url'] === 'http://api.localhost/webhooks/payments/'.$cinetpay->webhook_uuid);

        // Notification CinetPay signée (x-token), puis contrôle par l'API
        $this->stub('POST https://api-checkout.cinetpay.com/v2/payment/check', fn () => Http::response(['code' => '00', 'message' => 'SUCCES', 'data' => ['amount' => '31500', 'currency' => 'XOF', 'status' => 'ACCEPTED', 'payment_method' => 'WAVECI']]));
        $fields = ['cpm_site_id' => '123456', 'cpm_trans_id' => str_replace('-', '', $payment->idempotency_key), 'cpm_trans_date' => '2026-10-11 12:00:00', 'cpm_amount' => '31500', 'cpm_currency' => 'XOF'];
        $signed = implode('', array_map(fn ($f) => $fields[$f] ?? '', ['cpm_site_id', 'cpm_trans_id', 'cpm_trans_date', 'cpm_amount', 'cpm_currency', 'signature', 'payment_method', 'cel_phone_num', 'cpm_phone_prefixe', 'cpm_language', 'cpm_version', 'cpm_payment_config', 'cpm_page_action', 'cpm_custom', 'cpm_designation', 'cpm_error_message']));

        $this->post('http://api.localhost/webhooks/payments/'.$cinetpay->webhook_uuid, $fields, ['x-token' => 'faux'])->assertStatus(401);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->post('http://api.localhost/webhooks/payments/'.$cinetpay->webhook_uuid, $fields, ['x-token' => hash_hmac('sha256', $signed, 'cp_secret')])->assertOk();
        $this->assertSame(['succeeded', 'wave', 1103], [$payment->fresh()->status, $payment->fresh()->method->value, $payment->fresh()->fee]);
        $this->assertSame('paid', Order::sole()->payment_status->value);

        // Adresse de notification vérifiée en GET par CinetPay
        $this->get('http://api.localhost/webhooks/payments/'.$cinetpay->webhook_uuid)->assertOk();
    }

    public function test_wave_webhook_is_signed_deduplicated_and_verified(): void
    {
        $wave = $this->waveAccount();
        $this->store->forceFill(['online_payment' => 'required'])->save();
        $this->stub('POST https://api.wave.com/v1/checkout/sessions', $this->waveSession());
        $this->placeOrder(['payment_method' => 'wave'])->assertCreated();
        $payment = Payment::sole();

        $this->waveWebhook($wave, $payment, 'EV_1', 'mauvais_secret')->assertStatus(401);

        // Wave annonce un montant inférieur : refusé
        $this->stub('GET https://api.wave.com/v1/checkout/sessions/*', $this->waveSession('succeeded', 1000));
        $this->waveWebhook($wave, $payment)->assertOk();
        $this->assertSame(['failed', 'Montant reçu inférieur au montant demandé'], [$payment->fresh()->status, $payment->fresh()->failure_reason]);
        $this->assertSame('unpaid', Order::sole()->payment_status->value);
    }

    public function test_deposit_then_payment_link_with_paydunya(): void
    {
        $this->storeApi($this->store, 'POST', 'payments/accounts', [
            'provider' => 'paydunya', 'mode' => 'test', 'credentials' => ['master_key' => 'mk', 'private_key' => 'pk', 'token' => 'tk'], 'fee_percent' => 2,
        ])->assertCreated();
        $this->store->forceFill(['online_payment' => 'deposit', 'deposit_percent' => 30])->save();
        $this->stub('POST https://app.paydunya.com/sandbox-api/v1/checkout-invoice/create', fn () => Http::response(['response_code' => '00', 'response_text' => 'https://app.paydunya.com/sandbox-checkout/invoice/inv_1', 'token' => 'inv_1']));
        $this->stub('GET https://app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/*', fn () => Http::response(['response_code' => '00', 'status' => 'completed', 'invoice' => ['total_amount' => 9450]]));

        $this->placeOrder(['payment_method' => 'orange_money'])->assertCreated()->assertJsonPath('redirect', 'https://app.paydunya.com/sandbox-checkout/invoice/inv_1');
        $payment = Payment::sole();
        $this->assertSame([9450, 'deposit'], [$payment->amount, $payment->kind->value]); // 30 % de 31 500, arrondi aux 5 F
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'checkout-invoice/create') && $r->hasHeader('PAYDUNYA-MASTER-KEY', 'mk')
            && $r['invoice']['total_amount'] === 9450 && $r['custom_data']['payment_key'] === $payment->idempotency_key);

        // Notification PayDunya (hash = SHA-512 de la clé principale)
        $account = $payment->account;
        $ipn = ['data' => ['hash' => hash('sha512', 'mk'), 'status' => 'completed', 'invoice' => ['token' => 'inv_1'], 'custom_data' => ['payment_key' => $payment->idempotency_key]]];
        $this->post('http://api.localhost/webhooks/payments/'.$account->webhook_uuid, $ipn)->assertOk();

        $order = Order::sole();
        $this->assertSame([9450, 22050, OrderStatus::Confirmed, 'partially_paid'], [$order->amount_paid, $order->amountDue(), $order->status, $order->payment_status->value]);

        // Lien de paiement du reste, depuis la gestion
        $link = $this->storeApi($this->store, 'POST', "orders/{$order->id}/payment-links")->assertCreated();
        $this->assertSame(22050, $link->json('data.amount'));
        $this->assertStringStartsWith('https://wa.me/2250506070809?text=', $link->json('data.whatsapp_url'));
        $token = basename($link->json('data.url'));

        $this->stub('POST https://app.paydunya.com/sandbox-api/v1/checkout-invoice/create', fn () => Http::response(['response_code' => '00', 'response_text' => 'https://app.paydunya.com/sandbox-checkout/invoice/inv_2', 'token' => 'inv_2']));
        $this->stub('GET https://app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/inv_2', fn () => Http::response(['response_code' => '00', 'status' => 'completed', 'invoice' => ['total_amount' => 22050]]));

        // La page du lien fonctionne même boutique fermée
        $this->store->forceFill(['storefront_enabled' => false])->save();
        $this->get($this->storeUrl($this->store, "/payer/{$token}"))->assertOk()->assertSee('22 050 F');
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->postJson($this->storeUrl($this->store, "/payer/{$token}"), ['method' => 'mtn_momo'])
            ->assertOk()->assertJsonPath('redirect', 'https://app.paydunya.com/sandbox-checkout/invoice/inv_2');
        $second = Payment::where('provider_reference', 'inv_2')->sole();
        $this->get($this->storeUrl($this->store, '/paiement/'.$second->idempotency_key))->assertSee('Paiement reçu');

        $this->assertSame(['paid', 0], [$order->fresh()->payment_status->value, $order->fresh()->amountDue()]);
        $this->assertSame('paid', PaymentLink::sole()->status);
        $this->get($this->storeUrl($this->store, "/payer/{$token}"))->assertSee('déjà réglé');
    }

    public function test_refunds_by_api_or_manual(): void
    {
        $wave = $this->waveAccount();
        $this->store->forceFill(['online_payment' => 'required'])->save();
        $this->stub('POST https://api.wave.com/v1/checkout/sessions', $this->waveSession());
        $this->stub('GET https://api.wave.com/v1/checkout/sessions/*', $this->waveSession('succeeded'));
        $this->stub('POST https://api.wave.com/v1/checkout/sessions/*/refund', fn () => Http::response([], 200));
        $this->placeOrder(['payment_method' => 'wave'])->assertCreated();
        $this->get($this->storeUrl($this->store, '/paiement/'.Payment::sole()->idempotency_key));
        $order = Order::sole();

        // Partiel : Wave ne rembourse que le total, remboursement à faire à la main
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/refunds", ['amount' => 1500, 'reason' => 'Geste commercial'])->assertCreated()
            ->assertJsonPath('data.method', 'manual');
        $this->assertSame('partially_refunded', $order->fresh()->payment_status->value);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/refunds", ['amount' => 40000])->assertStatus(422);

        app(OrderDesk::class)->cancel($order->fresh(), CancelReason::CustomerRequest, null, $this->store->owner);
        $this->storeApi($this->store, 'POST', "orders/{$order->id}/refunds", ['amount' => 30000])->assertCreated()->assertJsonPath('data.method', 'manual');
        $this->assertSame([31500, 'refunded'], [$order->fresh()->amount_refunded, $order->fresh()->payment_status->value]);
    }

    public function test_full_refund_through_wave(): void
    {
        $this->waveAccount();
        $this->store->forceFill(['online_payment' => 'required'])->save();
        $this->stub('POST https://api.wave.com/v1/checkout/sessions', $this->waveSession());
        $this->stub('GET https://api.wave.com/v1/checkout/sessions/*', $this->waveSession('succeeded'));
        $this->stub('POST https://api.wave.com/v1/checkout/sessions/*/refund', fn () => Http::response([], 200));
        $this->placeOrder(['payment_method' => 'wave'])->assertCreated();
        $this->get($this->storeUrl($this->store, '/paiement/'.Payment::sole()->idempotency_key));

        $this->storeApi($this->store, 'POST', 'orders/'.Order::sole()->id.'/refunds', ['amount' => 31500])->assertCreated()->assertJsonPath('data.method', 'provider');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.wave.com/v1/checkout/sessions/cos-18qq25rgr100a/refund');
    }

    public function test_fee_simulator_and_report(): void
    {
        $wave = $this->waveAccount();
        $cinetpay = $this->cinetpayAccount();
        foreach (range(1, 3) as $i) {
            app(OrderDesk::class)->create($this->store, [
                'source' => 'whatsapp', 'customer_phone' => '+225050607080'.$i, 'delivery_method' => 'pickup',
                'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 2]],
            ], $this->store->owner);
        }

        $sim = $this->storeApi($this->store, 'GET', 'payments/simulator')->assertOk()->json('data');
        $this->assertSame(30000, $sim['monthly_volume']); // 90 000 F sur 3 mois
        $waveRow = collect($sim['methods'])->firstWhere('method', 'wave');
        $this->assertSame([$wave->id, $cinetpay->id], array_column($waveRow['options'], 'account_id'));
        $this->assertSame($wave->id, $waveRow['best_account_id']);

        $this->storeApi($this->store, 'GET', 'payments/report')->assertOk()->assertJsonPath('data.0.name', 'Wave')->assertJsonPath('data.0.count', 0);
    }
}
