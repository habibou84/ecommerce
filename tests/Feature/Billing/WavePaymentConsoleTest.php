<?php

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\AdminAction;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Notifications\WavePaymentToValidate;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paiement des abonnements réglé dans la console : moyen actif, lien et QR code Wave, validateur ; alerte par e-mail
 * puis SMS, « J'ai payé », et validation depuis la console.
 */
class WavePaymentConsoleTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse', 'name' => 'Équipe Jibiat']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function asMerchant(): void
    {
        $this->logout();
        $this->actingAs($this->store->owner);
    }

    private function signIn(): void
    {
        $this->logout();
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();
    }

    public function test_billing_is_configured_from_the_console_with_mail_alerts_and_validation(): void
    {
        Notification::fake();
        $this->console('GET', 'payments/settings')->assertUnauthorized();
        $this->signIn();

        // Un prestataire sans ses clés sur le serveur ne peut pas être choisi
        config(['platform.billing.credentials' => []]);
        $this->console('GET', 'payments/settings')->assertOk()->assertJsonPath('data.providers.0.available', true)->assertJsonPath('data.providers.2.available', false);
        $this->console('PUT', 'payments/settings', ['provider' => 'cinetpay'])->assertStatus(422)->assertJsonValidationErrors('provider');

        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'https://pay.wave.com/m/M_ci_test/c/ci/',
            'validator_email' => 'paiements@jibiat.com', 'validator_phone' => '05 07 01 67 39', 'validator_pin' => '2468'])
            ->assertOk()->assertJsonPath('data.provider', 'wave_link')->assertJsonPath('data.pin_set', true)->assertJsonMissingPath('data.validator_pin');
        $qr = $this->apiOn(self::CONSOLE, 'POST', 'console/payments/wave-qr', ['image' => UploadedFile::fake()->image('qr.png', 400, 400)])->assertOk()->json('data.wave_qr_url');
        $this->assertNotNull($qr);
        $this->assertSame(1, AdminAction::where('action', 'billing_settings')->count());

        $this->asMerchant();
        // L'e-commerçant voit le QR code ; « Payer » crée la facture et prévient le validateur par e-mail (pas de SMS)
        $this->storeApi($this->store, 'GET', 'billing')->assertJsonPath('data.manual_payment', true)->assertJsonPath('data.wave_qr_url', $qr);
        $number = $this->storeApi($this->store, 'POST', 'billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly', 'method' => 'wave', 'payer_phone' => '0708091011'])
            ->assertCreated()->assertJsonPath('redirect', 'https://pay.wave.com/m/M_ci_test/c/ci/?amount=12500')->json('data.number');
        Notification::assertSentOnDemand(WavePaymentToValidate::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'paiements@jibiat.com');
        $this->assertNull(collect($this->sentSms())->firstWhere('to', '+2250507016739'));

        // « J'ai payé » : seconde alerte, une seule fois
        $this->storeApi($this->store, 'POST', "billing/invoices/{$number}/paid")->assertOk()->assertJsonPath('data.claimed_at', fn ($v) => $v !== null);
        $this->storeApi($this->store, 'POST', "billing/invoices/{$number}/paid")->assertOk();
        Notification::assertSentOnDemandTimes(WavePaymentToValidate::class, 2);

        // Console : paiement à valider, signalé payé
        $this->signIn();
        $pending = $this->console('GET', 'payments/pending')->assertOk()->assertJsonPath('meta.claimed', 1)
            ->assertJsonPath('data.0.number', $number)->assertJsonPath('data.0.payer_phone', '07 08 09 10 11')->assertJsonPath('data.0.alerts.1.channel', 'mail');
        $this->console('POST', 'payments/'.$pending->json('data.0.id').'/decide', ['decision' => 'received'])->assertOk()->assertJsonPath('data.status', 'paid');
        $sub = $this->store->currentSubscription()->first();
        $this->assertSame([SubscriptionStatus::Active, 'pro'], [$sub->status, $sub->plan->code]);
        $this->console('GET', 'payments/pending')->assertJsonCount(0, 'data');
        $this->assertSame(1, AdminAction::where('action', 'billing_wave_received')->count());
    }

    public function test_sms_is_sent_when_the_mail_fails_and_the_console_pin_is_used(): void
    {
        $this->signIn();
        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'https://pay.wave.com/m/M_ci_test/c/ci/',
            'validator_email' => 'paiements@jibiat.com', 'validator_phone' => '0507016739', 'validator_pin' => '2468'])->assertOk();
        // Le serveur d'e-mail ne répond pas : SMS à la place
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        $this->asMerchant();

        $number = $this->storeApi($this->store, 'POST', 'billing/ai-pack', ['method' => 'wave', 'payer_phone' => '0708091011'])->assertCreated()->json('data.number');
        $sms = collect($this->sentSms())->firstWhere('to', '+2250507016739');
        $this->assertNotNull($sms);
        $this->assertStringContainsString('1 000 F', $sms['text']);
        $this->assertSame('sms', SubscriptionInvoice::where('number', $number)->sole()->raw['alerts'][0]['channel']);

        // Le code réglé dans la console (haché) valide depuis le lien
        preg_match('#(http\S+/facturation/valider/\S+)#', $sms['text'], $m);
        $this->assertStringNotContainsString('2468', (string) PlatformSetting::find('billing.validator_pin')->value);
        $this->post($m[1], ['code' => '2468', 'decision' => 'received'])->assertOk()->assertSee('Paiement confirmé');
        $this->assertSame(100, $this->store->fresh()->ai_credits);
    }
}
