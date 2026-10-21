<?php

namespace Tests\Feature\Messaging;

use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * WhatsApp du support dans la console : connexion du numéro en gardant l'application WhatsApp Business (coexistence),
 * messages reçus, réponses, messages envoyés depuis le téléphone, statuts.
 */
class SupportWhatsAppTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta' => ['app_id' => '7777', 'app_secret' => 'platform-secret', 'whatsapp_config_id' => '8888', 'webhook_verify_token' => 'verif-123']]);
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/oauth/access_token') => Http::response(['access_token' => 'support-token']),
                str_ends_with($path, '/subscribed_apps') => Http::response(['success' => true]),
                str_ends_with($path, '/9001') => Http::response(['display_phone_number' => '+225 05 07 01 67 39', 'verified_name' => 'Jibiat Marchand', 'id' => '9001']),
                str_ends_with($path, '/9001/messages') => Http::response(['messages' => [['id' => 'wamid.reponse']]]),
                default => Http::response(['error' => ['message' => 'inattendu '.$path]], 404),
            };
        });
        $admin = User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse', 'name' => 'Équipe Jibiat']);
        $admin->forceFill(['is_super_admin' => true])->save();
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function webhook(array $value): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '4242', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        ], $body);
    }

    public function test_support_number_is_connected_and_messages_are_read_and_answered_in_the_console(): void
    {
        $start = $this->console('POST', 'auth/login', ['phone' => '07 01 02 03 04', 'password' => 'motdepasse'])->assertStatus(202);
        $this->console('POST', 'auth/verify', ['token' => $start->json('verification.token'), 'code' => $start->json('demo_code')])->assertOk();

        $this->console('GET', 'whatsapp')->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('data.embedded.app_id', '7777');
        // Coexistence : le numéro reste sur le téléphone, il n'est pas réactivé chez Meta
        $this->console('POST', 'whatsapp/embedded', ['code' => 'fb-code', 'waba_id' => '4242', 'phone_number_id' => '9001', 'coexistence' => true])
            ->assertOk()->assertJsonPath('data.connected', true)->assertJsonPath('data.display_phone', '+225 05 07 01 67 39')->assertJsonPath('data.coexistence', true);
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/register'));
        // Aucune boutique ne reçoit ce numéro
        $this->assertSame(0, WhatsappAccount::withoutGlobalScopes()->count());

        // Message reçu, rejoué une deuxième fois : enregistré une fois
        $message = ['from' => '2250708091011', 'id' => 'wamid.in1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Bonjour, comment payer mon abonnement ?']];
        $this->webhook(['messages' => [$message], 'contacts' => [['wa_id' => '2250708091011', 'profile' => ['name' => 'Awa']]]])->assertOk();
        $this->webhook(['messages' => [$message]])->assertOk();
        // Réponse faite depuis le téléphone (coexistence) : recopiée dans le fil
        $this->webhook(['message_echoes' => [['from' => '2250507016739', 'to' => '2250708091011', 'id' => 'wamid.tel', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Je regarde.']]]])->assertOk();

        $list = $this->console('GET', 'whatsapp/conversations')->assertOk()->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.contact_name', 'Awa')->assertJsonPath('data.0.contact', '07 08 09 10 11')->assertJsonPath('data.0.can_reply', true);
        $id = $list->json('data.0.id');
        $this->console('GET', "whatsapp/conversations/{$id}")->assertOk()->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'Bonjour, comment payer mon abonnement ?')->assertJsonPath('data.messages.1.author', 'Téléphone');
        $this->assertSame(0, SupportConversation::find($id)->unread_count);

        // Réponse depuis la console, puis statut « lu »
        $this->console('POST', "whatsapp/conversations/{$id}/reply", ['body' => 'Payez depuis Mon abonnement.'])->assertCreated()->assertJsonPath('data.author', 'Équipe Jibiat');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/9001/messages') && $r['to'] === '2250708091011' && $r->hasHeader('Authorization', 'Bearer support-token'));
        $this->webhook(['statuses' => [['id' => 'wamid.reponse', 'status' => 'read']]])->assertOk();
        $this->console('GET', "whatsapp/conversations/{$id}")->assertJsonPath('data.messages.2.status', 'read');

        // Plus de 24 h après le dernier message du client : réponse depuis le téléphone seulement
        SupportConversation::find($id)->update(['last_inbound_at' => now()->subDays(2)]);
        $this->console('POST', "whatsapp/conversations/{$id}/reply", ['body' => 'Relance'])->assertStatus(422);

        $this->console('DELETE', 'whatsapp')->assertOk()->assertJsonPath('data.connected', false);
    }
}
