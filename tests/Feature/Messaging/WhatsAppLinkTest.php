<?php

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Store;
use App\Models\WhatsappLink;
use App\Notifications\CustomerMessage;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * WhatsApp relié par QR code (service whatsapp-bridge) : QR code à scanner, messages des clients dans la boîte
 * Messages, réponses sans limite de 24 h, messages écrits depuis le téléphone recopiés dans le fil.
 */
class WhatsAppLinkTest extends TestCase
{
    private Store $store;

    private int $queued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_bridge' => ['url' => 'http://127.0.0.1:3210', 'token' => 'bridge-secret']]);
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:3210/sessions/*/start' => Http::response(['session' => 'x', 'status' => 'qr', 'qr' => 'data:image/png;base64,QR']),
            'http://127.0.0.1:3210/sessions/*/send' => function () {
                $this->queued++;

                return Http::response(['id' => 'qr-'.$this->queued], 202);
            },
            'http://127.0.0.1:3210/sessions/*/logout' => Http::response(['status' => 'logged_out']),
            'http://127.0.0.1:3210/sessions/*' => Http::response(['status' => 'qr', 'qr' => 'data:image/png;base64,QR2']),
        ]);
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
    }

    private function event(array $event, ?string $signature = null): TestResponse
    {
        $body = json_encode($event + ['session' => 'store-'.$this->store->id]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BRIDGE_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'bridge-secret'),
        ], $body);
    }

    private function linked(): WhatsappLink
    {
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk();
        $this->event(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001', 'name' => 'Awa Mode'])->assertOk();

        return WhatsappLink::sole();
    }

    public function test_link_with_qr_code(): void
    {
        $this->storeApi($this->store, 'GET', 'whatsapp/link')->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.status', 'none');

        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.qr', 'data:image/png;base64,QR');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://127.0.0.1:3210/sessions/store-'.$this->store->id.'/start'
            && $r->hasHeader('Authorization', 'Bearer bridge-secret'));
        // Le QR code change : relu auprès du service
        $this->storeApi($this->store, 'GET', 'whatsapp/link')->assertJsonPath('data.qr', 'data:image/png;base64,QR2');

        // Le téléphone a scanné
        $this->event(['type' => 'status', 'status' => 'connected', 'phone' => '+2250700000001', 'name' => 'Awa Mode'])->assertOk();
        $this->storeApi($this->store, 'GET', 'whatsapp/link')->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.phone', '+2250700000001')->assertJsonPath('data.qr', null);
        $this->storeApi($this->store, 'GET', 'conversations')->assertJsonPath('connected', true);

        // Appareil retiré depuis le téléphone
        $this->event(['type' => 'status', 'status' => 'logged_out'])->assertOk();
        $this->storeApi($this->store, 'GET', 'whatsapp/link')->assertJsonPath('data.status', 'disconnected')
            ->assertJsonPath('data.last_error', 'Téléphone déconnecté : scannez un nouveau QR code.');

        // Déconnexion depuis la gestion
        $this->storeApi($this->store, 'DELETE', 'whatsapp/link')->assertOk()->assertJsonPath('data.status', 'none');
        $this->assertSame(0, WhatsappLink::count());
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/logout'));
    }

    public function test_customer_messages_and_replies_without_the_24_hour_limit(): void
    {
        $this->linked();

        $this->event(['type' => 'message', 'id' => 'ABC1', 'from' => '+2250506070809', 'name' => 'Fatou Diallo', 'kind' => 'text', 'text' => 'Bonjour, la robe wax est dispo ?'])->assertOk();
        $this->event(['type' => 'message', 'id' => 'ABC1', 'from' => '+2250506070809', 'kind' => 'text', 'text' => 'Bonjour, la robe wax est dispo ?'])->assertOk(); // rejoué
        $this->event(['type' => 'message', 'id' => 'ABC2', 'from' => '+2250506070809', 'kind' => 'location', 'text' => 'Pharmacie Angré', 'lat' => 5.39, 'lng' => -3.98])->assertOk();
        $this->event(['type' => 'message', 'id' => 'ABC3', 'from' => '+2250506070809', 'kind' => 'image', 'text' => 'celle-ci'])->assertOk();

        $conversation = Conversation::sole();
        $this->assertTrue($conversation->via_link);
        $this->assertSame(['Fatou Diallo', 3], [$conversation->contact_name, $conversation->unread_count]);
        $this->assertSame(1, $this->store->owner->notifications()->where('type', CustomerMessage::class)->count());
        $this->storeApi($this->store, 'GET', "conversations/{$conversation->id}")->assertOk()
            ->assertJsonPath('data.linked', true)->assertJsonPath('data.can_reply', true)->assertJsonPath('data.reply_until', null)
            ->assertJsonPath('data.messages.1.body', "📍 Position : Pharmacie Angré\nhttps://maps.google.com/?q=5.39,-3.98")
            ->assertJsonPath('data.messages.2.body', '📷 Photo : celle-ci');

        // Réponse deux jours plus tard : toujours possible, envoyée par le service
        $this->travel(2)->days();
        $this->storeApi($this->store, 'POST', "conversations/{$conversation->id}/messages", ['body' => 'Oui, en M et L 🙂'])->assertCreated()
            ->assertJsonPath('data.messages.3.direction', 'out')->assertJsonPath('data.messages.3.status', 'sent');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sessions/store-'.$this->store->id.'/send')
            && $r['to'] === '+2250506070809' && $r['text'] === 'Oui, en M et L 🙂');

        // Accusés : lu, puis un « remis » en retard ne fait pas reculer
        $this->event(['type' => 'read', 'id' => 'qr-1'])->assertOk();
        $this->event(['type' => 'delivered', 'id' => 'qr-1'])->assertOk();
        $this->assertSame('read', ConversationMessage::where('provider_message_id', 'qr-1')->value('status'));

        // Échec d'envoi : montré sur le message
        $this->storeApi($this->store, 'POST', "conversations/{$conversation->id}/messages", ['body' => 'Vous êtes là ?'])->assertCreated();
        $this->event(['type' => 'failed', 'id' => 'qr-2', 'error' => 'Ce numéro n\'a pas de compte WhatsApp.'])->assertOk();
        $failed = ConversationMessage::where('provider_message_id', 'qr-2')->sole();
        $this->assertSame(['failed', 'WhatsApp : Ce numéro n\'a pas de compte WhatsApp.'], [$failed->status, $failed->error]);
    }

    public function test_messages_written_on_the_phone_appear_in_the_thread(): void
    {
        $this->linked();
        $this->event(['type' => 'message', 'id' => 'IN1', 'from' => '+2250506070809', 'kind' => 'text', 'text' => 'Bonsoir'])->assertOk();

        $this->event(['type' => 'outgoing', 'id' => 'OUT1', 'to' => '+2250506070809', 'text' => 'Bonsoir Fatou, je vous écoute'])->assertOk();
        $this->event(['type' => 'outgoing', 'id' => 'OUT1', 'to' => '+2250506070809', 'text' => 'Bonsoir Fatou, je vous écoute'])->assertOk();
        // Conversation personnelle sans message du client ici : rien n'est créé
        $this->event(['type' => 'outgoing', 'id' => 'OUT2', 'to' => '+2250102030405', 'text' => 'On se voit demain'])->assertOk();

        $this->assertSame(1, Conversation::count());
        $this->assertSame(['in', 'out'], ConversationMessage::orderBy('id')->pluck('direction')->all());
        $this->assertSame('Bonsoir Fatou, je vous écoute', Conversation::sole()->last_preview);
    }

    public function test_events_must_be_signed_and_belong_to_a_linked_store(): void
    {
        $this->linked();
        $this->event(['type' => 'message', 'id' => 'X', 'from' => '+2250506070809', 'kind' => 'text', 'text' => 'Salut'], 'faux')->assertForbidden();

        $other = $this->createStore(['slug' => 'binta']);
        $body = json_encode(['type' => 'message', 'session' => 'store-'.$other->id, 'id' => 'Y', 'from' => '+2250506070809', 'kind' => 'text', 'text' => 'Salut']);
        $this->call('POST', 'http://api.localhost/webhooks/whatsapp-bridge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $body, 'bridge-secret'),
        ], $body)->assertOk()->assertJsonPath('received', false);

        $this->assertSame(0, ConversationMessage::count());
    }

    public function test_unavailable_without_the_service(): void
    {
        config(['services.whatsapp_bridge.token' => null]);
        $this->storeApi($this->store, 'GET', 'whatsapp/link')->assertJsonPath('data.available', false);
        $this->storeApi($this->store, 'POST', 'whatsapp/link')->assertStatus(422)
            ->assertJsonPath('errors.link.0', 'Connexion par QR code indisponible : le service WhatsApp n\'est pas installé sur le serveur.');
    }
}
