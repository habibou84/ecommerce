<?php

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\TemplateLibrary;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmbeddedSignupTest extends TestCase
{
    private Store $store;

    /** @var list<string> */
    private array $failing = [];

    /** @var array<string, string> numéro lu chez Meta */
    private array $number = ['display_phone_number' => '+225 07 00 00 00 01', 'verified_name' => 'Awa Mode', 'id' => '5555'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta' => ['app_id' => '7777', 'app_secret' => 'platform-secret', 'whatsapp_config_id' => '8888', 'webhook_verify_token' => 'verif-123']]);
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            foreach ($this->failing as $part) {
                if (str_contains($path, $part)) {
                    return Http::response(['error' => ['message' => 'Number already registered']], 400);
                }
            }

            return match (true) {
                str_ends_with($path, '/oauth/access_token') => Http::response(['access_token' => 'biz-token', 'token_type' => 'bearer']),
                str_ends_with($path, '/subscribed_apps'), str_ends_with($path, '/register') => Http::response(['success' => true]),
                str_ends_with($path, '/phone_numbers') => Http::response(['data' => [['id' => '5555']]]),
                str_ends_with($path, '/message_templates') => Http::response(['id' => 't1', 'status' => 'PENDING', 'category' => 'UTILITY']),
                str_ends_with($path, '/5555') => Http::response($this->number),
                default => Http::response(['error' => ['message' => 'inattendu '.$path]], 404),
            };
        });

        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
    }

    private function platformWebhook(array $entries, string $secret = 'platform-secret'): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => $entries]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    public function test_one_click_connection(): void
    {
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertJsonPath('data.embedded.app_id', '7777')->assertJsonPath('data.embedded.config_id', '8888');

        $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'code-abc', 'waba_id' => '4444', 'phone_number_id' => '5555'])->assertOk()
            ->assertJsonPath('data.account.connection', 'embedded')->assertJsonPath('data.account.display_phone', '+225 07 00 00 00 01')
            ->assertJsonPath('data.account.templates.order_confirmation', 'jm_confirmation_commande')
            ->assertJsonPath('data.account.template_status.order_shipped', 'PENDING')->assertJsonPath('data.warnings', []);

        $account = WhatsappAccount::sole();
        $this->assertSame(['biz-token', '4444', '5555', null, true], [$account->access_token, $account->waba_id, $account->phone_number_id, $account->app_secret, $account->automates('order_confirmation')]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $account->two_step_pin);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'oauth/access_token?client_id=7777&client_secret=platform-secret&code=code-abc'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/4444/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer biz-token'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/5555/register') && $r['pin'] === $account->two_step_pin && $r['messaging_product'] === 'whatsapp');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/4444/message_templates') && $r['name'] === 'jm_confirmation_commande'
            && $r['language'] === 'fr' && $r['components'][1]['buttons'][0]['text'] === 'Confirmer' && count($r['components'][0]['example']['body_text'][0]) === 3);
        Http::assertSentCount(4 + count(TemplateLibrary::TEMPLATES));

        // Aucune variable en tout début ou toute fin de texte (refusé par Meta), autant d'exemples que de variables
        foreach (TemplateLibrary::TEMPLATES as $event => $t) {
            $this->assertDoesNotMatchRegularExpression('/^\{\{|\}\}\s*$/', $t['body'], $event);
            $this->assertSame(count(CustomerMessenger::EVENTS[$event]['variables']), preg_match_all('/\{\{\d\}\}/', $t['body']), $event);
            $this->assertCount(preg_match_all('/\{\{\d\}\}/', $t['body']), $t['example'], $event);
        }
    }

    public function test_number_provided_by_meta_and_display_name_review_are_explained(): void
    {
        // Numéro américain choisi dans la fenêtre de Meta : averti dès la connexion
        $this->number = ['display_phone_number' => '+1 711-565-6408', 'verified_name' => 'Awa Mode', 'name_status' => 'PENDING_REVIEW', 'id' => '5555'];
        $warnings = $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'c', 'waba_id' => '4444', 'phone_number_id' => '5555'])->assertOk()
            ->assertJsonPath('data.account.name_status', 'PENDING_REVIEW')->assertJsonPath('data.account.meta_number', true)
            ->json('data.warnings');
        $this->assertStringContainsString('numéro américain fourni par Meta (+1 711-565-6408)', $warnings[0]);

        // Erreur de Meta expliquée en français, avec le message d'origine gardé
        $account = WhatsappAccount::sole();
        $account->forceFill(['last_error' => 'WhatsApp : (#131037) WhatsApp provided number needs display name approval before message can be sent.'])->save();
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertJsonPath('data.account.error_help.title', 'Meta vérifie le nom affiché de votre numéro');

        // Nom approuvé par Meta : relu au plus toutes les 10 minutes, l'erreur disparaît
        $this->number = ['verified_name' => 'Awa Mode', 'name_status' => 'APPROVED', 'id' => '5555'];
        $this->travel(11)->minutes();
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertJsonPath('data.account.name_status', 'APPROVED')
            ->assertJsonPath('data.account.last_error', null)->assertJsonPath('data.account.error_help', null);

        // Erreur inconnue : gardée telle quelle, sans explication ; un numéro ivoirien n'est pas « fourni par Meta »
        $account->refresh()->forceFill(['last_error' => 'Meta : autre chose', 'display_phone' => '+225 07 00 00 00 01'])->save();
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertJsonPath('data.account.error_help', null)->assertJsonPath('data.account.meta_number', false);
    }

    public function test_coexistence_errors_and_missing_configuration(): void
    {
        // Numéro gardé dans l'application WhatsApp Business : pas d'activation ; numéro trouvé sur le compte
        $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'c', 'waba_id' => '4444', 'coexistence' => true])->assertOk()
            ->assertJsonPath('data.account.phone_number_id', '5555');
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/register'));

        // Activation refusée ou modèle refusé : connecté quand même, avec les avertissements
        $this->failing = ['/register', '/message_templates'];
        $response = $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'c', 'waba_id' => '4444', 'phone_number_id' => '5555'])->assertOk()
            ->assertJsonPath('data.account.last_error', 'Activation du numéro : Meta : Number already registered');
        $this->assertCount(1 + count(TemplateLibrary::TEMPLATES), $response->json('data.warnings'));
        $this->assertSame('ERROR', WhatsappAccount::sole()->template_status['order_shipped']);
        $this->assertSame(1, WhatsappAccount::count());

        // Code refusé par Meta : rien n'est connecté
        WhatsappAccount::query()->delete();
        $this->failing = ['/oauth/access_token'];
        $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'c', 'waba_id' => '4444'])->assertStatus(422)
            ->assertJsonPath('message', 'Connexion à WhatsApp impossible. Meta : Number already registered');
        $this->assertSame(0, WhatsappAccount::count());

        // Application Meta de la plateforme pas configurée : pas de bouton, connexion refusée
        config(['services.meta.whatsapp_config_id' => null]);
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertJsonPath('data.embedded', null);
        $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'c', 'waba_id' => '4444'])->assertStatus(422);
    }

    public function test_whatsapp_through_the_already_approved_app_of_jibiat(): void
    {
        // WhatsApp par l'application Meta de Jibiat ; réseaux sociaux toujours sur celle de la plateforme
        config(['services.meta.whatsapp_app_id' => '1212', 'services.meta.whatsapp_app_secret' => 'jibiat-secret']);
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertJsonPath('data.embedded.app_id', '1212');

        $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'code-abc', 'waba_id' => '4444', 'phone_number_id' => '5555'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'oauth/access_token?client_id=1212&client_secret=jibiat-secret&code=code-abc'));
        // Notifications de ce compte envoyées ici, pas à l'adresse de Jibiat
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/4444/subscribed_apps')
            && $r['override_callback_uri'] === 'http://api.localhost/webhooks/whatsapp' && $r['verify_token'] === 'verif-123');

        // Notifications signées avec le secret de l'application de Jibiat : acceptées
        $message = ['id' => '4444', 'changes' => [['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => '2250506070809', 'profile' => ['name' => 'Fatou']]],
            'messages' => [['from' => '2250506070809', 'id' => 'wamid.9', 'type' => 'text', 'text' => ['body' => 'Bonjour']]],
        ]]]];
        $this->platformWebhook([$message], 'jibiat-secret')->assertOk();
        $this->assertTrue(ConversationMessage::query()->where('body', 'Bonjour')->exists());
        $this->platformWebhook([$message], 'platform-secret')->assertOk();
        $this->platformWebhook([$message], 'faux')->assertStatus(401);
    }

    public function test_platform_webhook(): void
    {
        $this->get('http://api.localhost/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verif-123&hub_challenge=42')->assertOk()->assertSee('42');
        $this->get('http://api.localhost/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=faux&hub_challenge=42')->assertForbidden();

        $this->storeApi($this->store, 'POST', 'whatsapp/embedded', ['code' => 'c', 'waba_id' => '4444', 'phone_number_id' => '5555'])->assertOk();

        $message = ['id' => '4444', 'changes' => [['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => '2250506070809', 'profile' => ['name' => 'Fatou']]],
            'messages' => [['from' => '2250506070809', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Bonjour, la robe est dispo ?']]],
        ]]]];
        $this->platformWebhook([$message], 'faux')->assertStatus(401);
        $this->platformWebhook([$message, ['id' => '9999', 'changes' => $message['changes']]])->assertOk(); // compte inconnu : ignoré
        $this->assertSame(['Fatou', 'Bonjour, la robe est dispo ?'], [Conversation::sole()->contact_name, Conversation::sole()->last_preview]);
        $this->assertSame($this->store->id, Conversation::withoutGlobalScopes()->sole()->store_id);

        $this->platformWebhook([['id' => '4444', 'changes' => [['field' => 'message_template_status_update', 'value' => ['event' => 'APPROVED', 'message_template_name' => 'jm_confirmation_commande']]]]])->assertOk();
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertJsonPath('data.account.template_status.order_confirmation', 'APPROVED')
            ->assertJsonPath('data.account.template_status.order_shipped', 'PENDING');
    }

    public function test_manual_accounts_of_the_platform_app_receive_their_messages(): void
    {
        // Numéro de test de Meta, connecté à la main avec l'application de la plateforme
        $manual = fn (Store $store) => WhatsappAccount::withoutGlobalScopes()->create(['store_id' => $store->id, 'phone_number_id' => '6666', 'waba_id' => '4444',
            'access_token' => 'tok', 'verify_token' => Str::random(20), 'webhook_uuid' => (string) Str::uuid(), 'status' => 'active']);
        $manual($this->store);

        $message = fn (string $id) => ['id' => '4444', 'changes' => [['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => '2250506070809', 'profile' => ['name' => 'Fatou']]],
            'messages' => [['from' => '2250506070809', 'id' => $id, 'type' => 'text', 'text' => ['body' => 'Bonjour']]],
        ]]]];
        $this->platformWebhook([$message('wamid.1')])->assertOk();
        $this->assertSame($this->store->id, Conversation::withoutGlobalScopes()->sole()->store_id);

        // Deux boutiques déclarent le même compte WhatsApp à la main : le message ne va à aucune des deux
        $manual($this->createStore(['slug' => 'autre']));
        $this->platformWebhook([$message('wamid.2')])->assertOk();
        $this->assertSame(1, ConversationMessage::withoutGlobalScopes()->count());
    }

    public function test_the_platform_webhook_has_its_own_wide_rate_limit(): void
    {
        // Une seule adresse pour toutes les boutiques : sa limite n'est partagée ni avec les paiements ni avec les transporteurs
        $route = Route::getRoutes()->getByName('webhooks.whatsapp.platform');
        $this->assertContains('throttle:meta-webhooks', $route->gatherMiddleware());
        $this->assertNotContains('throttle:carrier-webhooks', $route->gatherMiddleware());
        $this->assertGreaterThanOrEqual(20000, RateLimiter::limiter('meta-webhooks')(Request::create('/'))->maxAttempts);

        // Les autres adresses sans identifiant ne partagent plus la même limite
        $billing = Request::create('/webhooks/billing', 'POST');
        $billingRoute = Route::getRoutes()->getByName('webhooks.billing')->bind($billing);
        $billing->setRouteResolver(fn () => $billingRoute);
        $this->assertSame('carrier-webhooks:webhooks.billing', RateLimiter::limiter('carrier-webhooks')($billing)->key);

        // Une rafale de notifications (live chargé) passe au-delà de l'ancienne limite de 600 par minute
        for ($i = 0; $i < 620; $i++) {
            $status = $this->platformWebhook([])->status();
        }
        $this->assertSame(200, $status);
    }
}
