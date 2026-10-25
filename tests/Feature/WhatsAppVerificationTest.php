<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\WhatsApp\PlatformWhatsApp;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Vérification des numéros par WhatsApp en premier : le numéro du support, relié par QR code dans la console, reçoit
 * le message prérempli ; aucun SMS tant qu'un WhatsApp est relié. Connexion par WhatsApp (boutique, plateforme,
 * console), mot de passe oublié, création de boutique.
 */
class WhatsAppVerificationTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private int $queued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_bridge' => ['url' => 'http://127.0.0.1:3210', 'token' => 'bridge-secret']]);
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:3210/sessions/platform/start' => Http::response(['session' => 'platform', 'status' => 'qr', 'qr' => 'data:image/png;base64,QR']),
            'http://127.0.0.1:3210/sessions/platform/pair' => Http::response(['session' => 'platform', 'status' => 'qr', 'code' => 'ABCD-EFGH']),
            'http://127.0.0.1:3210/sessions/platform/send' => function () {
                $this->queued++;

                return Http::response(['id' => 'qr-'.$this->queued], 202);
            },
            'http://127.0.0.1:3210/sessions/platform/logout' => Http::response(['status' => 'logged_out']),
            'http://127.0.0.1:3210/sessions/platform' => Http::response(['session' => 'platform', 'status' => 'connected', 'phone' => '+2250100000000', 'name' => 'Jibiat Marchand']),
        ]);
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse', 'name' => 'Équipe Jibiat']);
        $admin->forceFill(['is_super_admin' => true])->save();
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    /** Événement du service pour la session du numéro du support */
    private function event(array $event): TestResponse
    {
        $body = json_encode($event + ['session' => 'platform']);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, 'bridge-secret'),
        ], $body);
    }

    /** Message WhatsApp reçu sur le numéro du support */
    private function message(string $from, string $text, string $id = 'in-1'): TestResponse
    {
        return $this->event(['type' => 'message', 'id' => $id, 'from' => ltrim($from, '+'), 'kind' => 'text', 'text' => $text, 'name' => 'Awa']);
    }

    /** Numéro du support relié par QR code (sans passer par la console) */
    private function linkSupportNumber(): void
    {
        PlatformSetting::put('whatsapp.mode', 'qr');
        $this->event(['type' => 'status', 'status' => 'connected', 'phone' => '+2250100000000', 'name' => 'Jibiat Marchand'])->assertOk();
    }

    public function test_console_links_the_support_number_with_a_qr_code_and_answers_without_time_limit(): void
    {
        // Connexion à la console : mot de passe, puis code SMS (aucun WhatsApp relié pour l'instant)
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202)
            ->assertJsonPath('verification.whatsapp', null)->assertJsonPath('verification.sms.available', true);
        $this->assertCount(1, $this->sentSms());
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();

        $this->console('GET', 'whatsapp')->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('data.bridge', true)->assertJsonPath('data.mode', null);
        $this->console('POST', 'whatsapp/qr')->assertOk()->assertJsonPath('data.live.qr', 'data:image/png;base64,QR')->assertJsonPath('data.mode', 'qr')->assertJsonPath('data.qr_status', 'pending');
        $this->console('POST', 'whatsapp/pair', ['phone' => '01 00 00 00 00'])->assertOk()->assertJsonPath('data.live.code', 'ABCD-EFGH');

        // Le téléphone scanne le QR code : le service prévient
        $this->event(['type' => 'status', 'status' => 'connected', 'phone' => '+2250100000000', 'name' => 'Jibiat Marchand'])->assertOk();
        $this->console('GET', 'whatsapp')->assertOk()->assertJsonPath('data.connected', true)->assertJsonPath('data.mode', 'qr')
            ->assertJsonPath('data.display_phone', '01 00 00 00 00')->assertJsonPath('data.name', 'Jibiat Marchand');

        // Message d'un client, rejoué : une conversation, réponse par le service (pas de fenêtre de 24 h)
        $this->message('+2250708091011', 'Bonjour, comment payer mon abonnement ?')->assertOk();
        $this->message('+2250708091011', 'Bonjour, comment payer mon abonnement ?')->assertOk();
        $list = $this->console('GET', 'whatsapp/conversations')->assertOk()->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.contact_name', 'Awa')->assertJsonPath('data.0.can_reply', true);
        $id = $list->json('data.0.id');
        SupportConversation::find($id)->update(['last_inbound_at' => now()->subDays(2)]);
        $this->console('GET', 'whatsapp/conversations')->assertJsonPath('data.0.can_reply', true);
        $this->console('POST', "whatsapp/conversations/{$id}/reply", ['body' => 'Payez depuis Mon abonnement.'])->assertCreated()->assertJsonPath('data.status', 'sent');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sessions/platform/send') && $r['to'] === '+2250708091011');
        $this->event(['type' => 'read', 'id' => 'qr-1'])->assertOk();
        // Réponse écrite depuis le téléphone : recopiée dans le fil
        $this->event(['type' => 'outgoing', 'id' => 'tel-1', 'to' => '2250708091011', 'text' => 'Je regarde.'])->assertOk();
        $this->console('GET', "whatsapp/conversations/{$id}")->assertOk()->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.1.status', 'read')->assertJsonPath('data.messages.2.author', 'Téléphone');

        // Téléphone déconnecté, puis déconnexion depuis la console
        $this->event(['type' => 'status', 'status' => 'logged_out'])->assertOk();
        $this->console('GET', 'whatsapp')->assertJsonPath('data.connected', false)->assertJsonPath('data.qr_status', 'disconnected');
        $this->console('DELETE', 'whatsapp')->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('data.mode', null);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sessions/platform/logout'));
    }

    public function test_signup_is_verified_by_a_whatsapp_message_without_any_sms(): void
    {
        $this->linkSupportNumber();

        $start = $this->platformApi('POST', 'signup', [
            'store_name' => 'Awa Mode', 'slug' => 'awa-mode', 'name' => 'Awa Koné', 'phone' => '07 08 09 10 11', 'password' => 'motdepasse',
        ])->assertStatus(202)
            ->assertJsonPath('verification.status', 'pending')
            ->assertJsonPath('verification.whatsapp.number', '01 00 00 00 00')
            ->assertJsonPath('verification.sms.available', false)
            ->assertJsonPath('demo_code', null);
        $this->assertCount(0, $this->sentSms());
        $token = $start->json('verification.token');
        $text = $start->json('verification.whatsapp.text');
        $this->assertStringStartsWith('https://wa.me/2250100000000?text=Code%20de%20v', $start->json('verification.whatsapp.link'));

        // Le SMS est refusé tant que WhatsApp est possible
        $this->platformApi('POST', "verifications/{$token}/resend")->assertStatus(422);
        $this->platformApi('GET', "verifications/{$token}")->assertOk()->assertJsonPath('verification.status', 'pending')->assertJsonMissingPath('redirect');

        // Le message envoyé depuis un autre numéro ne vaut rien ; depuis le bon numéro, le numéro est prouvé
        $this->message('+2250511111111', $text, 'in-0')->assertOk();
        $this->assertDatabaseMissing('users', ['phone' => '+2250708091011']);
        $this->message('+2250708091011', $text)->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sessions/platform/send') && str_contains($r['text'], 'Numéro vérifié'));
        $this->assertSame(0, SupportConversation::count());

        // La page qui attendait termine la création, une seule fois
        $redirect = $this->platformApi('GET', "verifications/{$token}")->assertCreated()->assertJsonPath('status', 'completed')->json('redirect');
        $this->assertStringStartsWith('http://awa-mode.localhost/auth/relais?jeton=', $redirect);
        $this->assertNotNull(Store::where('slug', 'awa-mode')->first());
        $this->platformApi('GET', "verifications/{$token}")->assertOk()->assertJsonPath('verification.status', 'completed')->assertJsonMissingPath('redirect');
        $this->platformApi('POST', "verifications/{$token}/confirm", [])->assertStatus(422);
    }

    public function test_whatsapp_login_opens_the_store_session_and_the_console(): void
    {
        $this->linkSupportNumber();
        $store = $this->createStore(['slug' => 'awa']);
        $member = $this->addMember($store, User::factory()->create(['phone' => '+2250708091011']));

        // Numéro inconnu de l'équipe
        $this->storeApi($store, 'POST', 'auth/whatsapp', ['phone' => '07 99 99 99 99'])->assertStatus(422);

        $start = $this->storeApi($store, 'POST', 'auth/whatsapp', ['phone' => '07 08 09 10 11'])->assertStatus(202)
            ->assertJsonPath('verification.purpose', 'login')->assertJsonPath('verification.sms.available', false);
        $token = $start->json('verification.token');
        $this->assertCount(0, $this->sentSms());

        $this->message('+2250708091011', $start->json('verification.whatsapp.text'))->assertOk();
        $this->storeApi($store, 'GET', "verifications/{$token}")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('redirect', '/gestion');
        $this->assertAuthenticatedAs($member, 'web');
        $this->storeApi($store, 'GET', 'auth/me')->assertOk()->assertJsonPath('data.store.slug', 'awa');

        // Console : mot de passe puis message WhatsApp, sans SMS
        $this->logout();
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202)
            ->assertJsonPath('verification.sms.available', false);
        $this->assertCount(0, $this->sentSms());
        $this->message('+2250701020304', $start->json('verification.whatsapp.text'), 'in-2')->assertOk();
        $this->apiOn(self::CONSOLE, 'GET', 'verifications/'.$start->json('verification.token'))->assertOk()->assertJsonPath('redirect', '/console');
        $this->console('GET', 'auth/me')->assertOk()->assertJsonPath('data.name', 'Équipe Jibiat');
    }

    public function test_whatsapp_still_under_the_old_8_digit_number_logs_in(): void
    {
        $this->linkSupportNumber();
        $store = $this->createStore(['slug' => 'awa']);
        $member = $this->addMember($store, User::factory()->create(['phone' => '+2250747123456']));

        $start = $this->storeApi($store, 'POST', 'auth/whatsapp', ['phone' => '07 47 12 34 56'])->assertStatus(202);
        $token = $start->json('verification.token');
        $text = $start->json('verification.whatsapp.text');

        // Un autre numéro qui finit pareil n'est pas le même
        $this->message('+2250547123456', $text)->assertOk();
        $this->storeApi($store, 'GET', "verifications/{$token}")->assertOk()->assertJsonPath('verification.status', 'pending');

        // Compte WhatsApp jamais passé à 10 chiffres : +225 47 12 34 56 est le 07 47 12 34 56
        $this->message('+22547123456', $text, 'in-2')->assertOk();
        $this->storeApi($store, 'GET', "verifications/{$token}")->assertOk()->assertJsonPath('status', 'completed');
        $this->assertAuthenticatedAs($member, 'web');
    }

    public function test_forgotten_password_is_verified_by_whatsapp_then_changed_without_code(): void
    {
        $this->linkSupportNumber();
        $store = $this->createStore(['slug' => 'awa']);
        $member = $this->addMember($store, User::factory()->create(['phone' => '+2250708091011']));

        $start = $this->storeApi($store, 'POST', 'password/forgot', ['phone' => '07 08 09 10 11'])->assertStatus(202);
        $token = $start->json('verification.token');
        $this->assertCount(0, $this->sentSms());

        $this->message('+2250708091011', $start->json('verification.whatsapp.text'))->assertOk();
        // Numéro prouvé : la page demande le mot de passe, sans code
        $this->storeApi($store, 'GET', "verifications/{$token}")->assertOk()->assertJsonPath('verification.status', 'verified')->assertJsonMissingPath('redirect');
        $this->storeApi($store, 'POST', "verifications/{$token}/confirm", ['password' => 'nouveau-mdp'])->assertOk()->assertJsonPath('redirect', '/gestion');
        $this->assertTrue(password_verify('nouveau-mdp', $member->fresh()->password));
    }

    public function test_sms_comes_back_when_the_console_allows_it_as_fallback(): void
    {
        $this->linkSupportNumber();
        // Interrupteur et compteur dans la console
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse', 'channel' => 'phone'])->assertStatus(202);
        $this->message('+2250701020304', $start->json('verification.whatsapp.text'), 'in-c')->assertOk();
        $this->apiOn(self::CONSOLE, 'GET', 'verifications/'.$start->json('verification.token'))->assertOk();
        $this->console('GET', 'whatsapp')->assertOk()->assertJsonPath('data.sms.fallback', false)->assertJsonPath('data.sms.month', 0);
        $this->console('PUT', 'whatsapp/sms', ['fallback' => true])->assertOk()->assertJsonPath('data.sms.fallback', true);
        $this->logout();

        $start = $this->platformApi('POST', 'signup', [
            'store_name' => 'Awa Mode', 'slug' => 'awa-mode', 'name' => 'Awa Koné', 'phone' => '07 08 09 10 11', 'password' => 'motdepasse',
        ])->assertStatus(202)->assertJsonPath('verification.sms.available', true)->assertJsonPath('verification.sms.sent', false);
        $this->assertCount(0, $this->sentSms());

        // La personne choisit le SMS : premier code envoyé à sa demande seulement
        $code = $this->platformApi('POST', 'verifications/'.$start->json('verification.token').'/resend')->assertOk()->json('demo_code');
        $this->assertCount(1, $this->sentSms());
        $this->platformApi('POST', 'verifications/'.$start->json('verification.token').'/confirm', ['code' => $code])->assertCreated();
        $this->assertSame(1, app(PlatformWhatsApp::class)->present()['sms']['month']);
    }
}
