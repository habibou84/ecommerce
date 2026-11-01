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
use Illuminate\Support\Facades\Http;
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
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();
    }

    public function test_a_subscription_payment_refused_by_paydunya_shows_its_reason_in_the_console(): void
    {
        config(['platform.billing' => ['provider' => 'paydunya', 'mode' => 'live', 'grace_days' => 7, 'reminder_days' => 3,
            'credentials' => ['master_key' => 'mk', 'private_key' => 'pk', 'token' => 'tk']]]);
        Http::fake(['app.paydunya.com/*' => Http::response(['response_code' => '1001', 'response_text' => 'Votre compte n\'est pas encore activé'], 200)]);

        // La boutique voit le message simple ; la raison n'est pas montrée au marchand
        $this->asMerchant();
        $this->storeApi($this->store, 'POST', 'billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly', 'method' => 'orange_money'])
            ->assertStatus(422)->assertJsonPath('message', 'Le paiement en ligne est indisponible pour le moment. Réessayez dans quelques minutes.');

        // La console donne la raison de PayDunya et le mode du serveur
        $this->signIn();
        $settings = $this->console('GET', 'payments/settings')->assertOk()->json('data');
        $this->assertSame(['live', 'Awa Mode'], [$settings['mode'], $settings['last_failure']['store']]);
        $this->assertSame("PayDunya (mode réel) : Votre compte n'est pas encore activé [code 1001]", $settings['last_failure']['error']);
        $this->console('GET', 'invoices?status=failed')->assertOk()->assertJsonPath('data.0.error', "PayDunya (mode réel) : Votre compte n'est pas encore activé [code 1001]");
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
        // Lien avec le repère {montant}, ou collé sans https:// : accepté ; une adresse fausse est refusée
        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'https://pay.wave.com/m/M_ci_test/c/ci/?amount={montant}'])
            ->assertOk()->assertJsonPath('data.wave_link', 'https://pay.wave.com/m/M_ci_test/c/ci/?amount={montant}');
        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'pay.wave.com/m/M_ci_test/c/ci/'])
            ->assertOk()->assertJsonPath('data.wave_link', 'https://pay.wave.com/m/M_ci_test/c/ci/');
        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'pas un lien'])->assertStatus(422)->assertJsonValidationErrors('wave_link');

        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'https://pay.wave.com/m/M_ci_test/c/ci/',
            'validator_email' => 'paiements@jibiat.com', 'validator_phone' => '05 07 01 67 39', 'validator_pin' => '2468'])
            ->assertOk()->assertJsonPath('data.provider', 'wave_link')->assertJsonPath('data.pin_set', true)->assertJsonMissingPath('data.validator_pin');
        $qr = $this->apiOn(self::CONSOLE, 'POST', 'console/payments/wave-qr', ['image' => UploadedFile::fake()->image('qr.png', 400, 400)])->assertOk()->json('data.wave_qr_url');
        $this->assertNotNull($qr);
        $this->assertSame(3, AdminAction::where('action', 'billing_settings')->count());

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
        // Pilote « log » des tests : le SMS est écrit dans le journal, pas vraiment envoyé, et c'est dit
        $this->assertSame('journal', SubscriptionInvoice::where('number', $number)->sole()->raw['alerts'][0]['channel']);

        // Le code réglé dans la console (haché) valide depuis le lien
        preg_match('#(http\S+/facturation/valider/\S+)#', $sms['text'], $m);
        $this->assertStringNotContainsString('2468', (string) PlatformSetting::find('billing.validator_pin')->value);
        $this->post($m[1], ['code' => '2468', 'decision' => 'received'])->assertOk()->assertSee('Paiement confirmé');
        $this->assertSame(100, $this->store->fresh()->ai_credits);
    }

    public function test_gmail_is_connected_with_google_and_sends_the_emails(): void
    {
        $idToken = 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'jibiat.marchand@gmail.com', 'email_verified' => true])), '+/', '-_'), '=').'.y';
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.acces', 'expires_in' => 3599, 'refresh_token' => '1//rafraichir',
                'scope' => 'openid https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email', 'id_token' => $idToken]),
            'https://gmail.googleapis.com/*' => Http::response(['id' => 'm1']),
            'https://oauth2.googleapis.com/revoke' => Http::response([]),
        ]);
        $this->signIn();

        // L'application Google : l'identifiant est vérifié, le code secret n'est jamais renvoyé
        $this->console('PUT', 'payments/gmail/client', ['client_id' => 'pas-un-id', 'client_secret' => 'GOCSPX-secret'])->assertStatus(422);
        $this->console('PUT', 'payments/gmail/client', ['client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-secret'])->assertOk()
            ->assertJsonPath('data.mail.gmail.configured', true)->assertJsonPath('data.mail.gmail.connected', false)
            ->assertJsonPath('data.mail.gmail.redirect_uri', 'http://admin.localhost/console/gmail/retour');
        $this->assertStringNotContainsString('GOCSPX', (string) PlatformSetting::find('gmail.client_secret')->value);

        // « Se connecter avec Google » : seulement l'envoi d'e-mails est demandé
        $url = $this->console('POST', 'payments/gmail/connect')->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('123-abc.apps.googleusercontent.com', $query['client_id']);
        $this->assertStringContainsString('gmail.send', $query['scope']);
        $this->assertStringNotContainsString('mail.google.com', $query['scope']);

        // Retour de Google : jeton de la demande obligatoire
        $this->get(self::CONSOLE.'/console/gmail/retour?state=faux&code=abc')->assertRedirect('/console/paiements?gmail=expire');
        $url = $this->console('POST', 'payments/gmail/connect')->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->get(self::CONSOLE.'/console/gmail/retour?state='.$query['state'].'&code=abc')->assertRedirect('/console/paiements?gmail=ok');
        $this->console('GET', 'payments/settings')->assertJsonPath('data.mail.source', 'gmail')->assertJsonPath('data.mail.gmail.email', 'jibiat.marchand@gmail.com');
        $this->assertStringNotContainsString('rafraichir', (string) PlatformSetting::find('gmail.refresh_token')->value);

        // Les alertes partent par l'API Gmail, depuis l'adresse connectée
        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'https://pay.wave.com/m/M_ci_test/c/ci/', 'validator_email' => 'paiements@jibiat.com'])->assertOk();
        $this->console('POST', 'payments/test-alert')->assertOk()->assertJsonPath('data.mail.ok', true);
        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/gmail/v1/users/me/messages/send')) {
                return false;
            }
            $mime = base64_decode(strtr($r['raw'], '-_', '+/'));

            return $r->hasHeader('Authorization', 'Bearer ya29.acces') && str_contains($mime, 'paiements@jibiat.com') && str_contains($mime, 'jibiat.marchand@gmail.com');
        });

        // Déconnexion : l'autorisation est retirée chez Google
        $this->console('DELETE', 'payments/gmail')->assertOk()->assertJsonPath('data.mail.gmail.connected', false);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/revoke') && $r['token'] === '1//rafraichir');
    }

    public function test_mail_server_is_set_in_the_console_and_a_test_alert_reports_what_went_out(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        $this->signIn();
        $this->console('PUT', 'payments/settings', ['provider' => 'wave_link', 'wave_link' => 'https://pay.wave.com/m/M_ci_test/c/ci/',
            'validator_email' => 'paiements@jibiat.com', 'validator_phone' => '0507016739'])->assertOk()
            ->assertJsonPath('data.mail.source', null)->assertJsonPath('data.sms_ready', false);

        // Rien de réglé : l'essai dit pourquoi rien ne part
        $this->console('POST', 'payments/test-alert')->assertOk()->assertJsonPath('data.mail.ok', false)->assertJsonPath('data.sms.ok', false)
            ->assertJsonPath('data.mail.detail', fn ($d) => str_contains($d, 'serveur d\'e-mail'));

        // Gmail réglé dans la console : l'e-mail part par ce serveur, le mot de passe n'est jamais renvoyé
        $this->console('PUT', 'payments/mail', ['host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'jibiat@gmail.com', 'password' => 'abcd efgh ijkl mnop'])
            ->assertOk()->assertJsonPath('data.mail.source', 'console')->assertJsonPath('data.mail.password_set', true)->assertJsonMissingPath('data.mail.password');
        $this->assertStringNotContainsString('abcd', (string) PlatformSetting::find('mail.password')->value);
        $this->console('POST', 'payments/test-alert')->assertOk()->assertJsonPath('data.mail.ok', true);
        Notification::assertSentOnDemand(WavePaymentToValidate::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'paiements@jibiat.com'
            && $n->toMail($to)->mailer === 'console_smtp' && $n->toMail($to)->from[0] === 'jibiat@gmail.com');
        $this->assertSame('smtp.gmail.com', config('mail.mailers.console_smtp.host'));

        // Hôte vide : réglage retiré
        $this->console('PUT', 'payments/mail', ['host' => ''])->assertOk()->assertJsonPath('data.mail.source', null);
    }
}
