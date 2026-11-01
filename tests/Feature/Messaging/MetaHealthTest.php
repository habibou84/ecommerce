<?php

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * « État chez Meta » du numéro officiel : lecture chez Meta, ce qui bloque d'abord avec la page où agir, bandeaux
 * (accueil, Messages) et liste de la console.
 */
class MetaHealthTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private Store $store;

    /** Réponse de Meta pour « health_status » */
    private array $health = ['can_send_message' => 'AVAILABLE', 'entities' => [['entity_type' => 'BUSINESS', 'id' => '9900', 'can_send_message' => 'AVAILABLE']]];

    private string $review = 'APPROVED';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $fields = $r->data()['fields'] ?? '';

            return match (true) {
                str_ends_with($path, '/4444') && $fields === 'health_status' => Http::response(['health_status' => $this->health, 'id' => '4444']),
                str_ends_with($path, '/4444') => Http::response(['account_review_status' => $this->review, 'business_verification_status' => 'not_verified', 'id' => '4444']),
                str_ends_with($path, '/5555') => Http::response(['quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'name_status' => 'APPROVED', 'verified_name' => 'Awa Mode', 'id' => '5555']),
                default => Http::response(['error' => ['message' => 'inattendu '.$path]], 404),
            };
        });
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->actingAs($this->store->owner);
        WhatsappAccount::create(['store_id' => $this->store->id, 'connection' => 'embedded', 'phone_number_id' => '5555', 'waba_id' => '4444',
            'display_phone' => '+225 07 00 00 00 01', 'access_token' => 'biz-token', 'status' => 'active']);
    }

    public function test_everything_fine_then_blocked_by_meta(): void
    {
        // Jamais lu : « Pas encore vérifié »
        $this->storeApi($this->store, 'GET', 'whatsapp')->assertOk()->assertJsonPath('data.account.meta.state', 'unknown');

        $this->artisan('whatsapp:health')->assertSuccessful();
        $account = WhatsappAccount::sole();
        $this->assertSame('ok', $account->meta_state);
        $response = $this->storeApi($this->store, 'GET', 'whatsapp')->assertOk()
            ->assertJsonPath('data.account.meta.state', 'ok')->assertJsonPath('data.account.meta.reason', null);
        $lines = collect($response->json('data.account.meta.lines'))->keyBy('key');
        $this->assertSame('Validé par Meta', $lines['review']['detail']);
        $this->assertSame('Nom affiché « Awa Mode »', $lines['name']['title']);
        $this->assertStringContainsString('1 000 clients par jour', $lines['quality']['detail']);
        // Vérification de l'entreprise : conseil, avec la page de Meta de cette entreprise
        $this->assertSame('info', $lines['verification']['tone']);
        $this->assertStringContainsString('business_id=9900', $lines['verification']['action']['url']);
        $this->storeApi($this->store, 'GET', 'dashboard')->assertOk()->assertJsonMissingPath('data.whatsapp_meta');

        // Lu il y a moins d'une heure : pas relu par la tâche planifiée
        $reads = fn () => Http::recorded(fn (HttpRequest $r) => str_contains((string) ($r->data()['fields'] ?? ''), 'quality_rating'))->count();
        $this->assertSame(1, $reads());
        $this->artisan('whatsapp:health')->assertSuccessful();
        $this->assertSame(1, $reads());

        // Meta bloque les envois : vérification de l'entreprise demandée
        $this->health = ['can_send_message' => 'BLOCKED', 'entities' => [['entity_type' => 'BUSINESS', 'id' => '9900', 'can_send_message' => 'BLOCKED',
            'errors' => [['error_code' => 141000, 'error_description' => 'Business verification required.', 'possible_solution' => 'Verify your business.']]]]];
        $response = $this->storeApi($this->store, 'POST', 'whatsapp/health')->assertOk()
            ->assertJsonPath('data.account.meta.state', 'blocked')
            ->assertJsonPath('data.account.meta.reason', 'Meta demande de vérifier votre entreprise')
            ->assertJsonPath('data.account.meta.lines.0.tone', 'bad');
        $this->assertStringContainsString('settings/security', $response->json('data.account.meta.lines.0.action.url'));
        $this->assertSame('blocked', WhatsappAccount::sole()->meta_state);

        // Bandeaux : accueil et Messages
        $this->storeApi($this->store, 'GET', 'dashboard')->assertOk()
            ->assertJsonPath('data.whatsapp_meta.state', 'blocked')->assertJsonPath('data.whatsapp_meta.reason', 'Meta demande de vérifier votre entreprise');
        $this->storeApi($this->store, 'GET', 'conversations')->assertOk()->assertJsonPath('meta_block.state', 'blocked');
    }

    public function test_console_lists_the_stores_to_help(): void
    {
        WhatsappAccount::sole()->forceFill(['meta_health' => ['can_send' => 'BLOCKED', 'errors' => [['code' => 141000, 'description' => 'Business verification required.', 'solution' => '', 'level' => 'BLOCKED']]],
            'meta_health_at' => now(), 'meta_state' => 'blocked', 'meta_state_since' => now()->subDays(2)])->save();
        $admin = User::factory()->create(['phone' => '+2250701020304']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin)->withSession(['console_admin' => $admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, 'GET', 'console/whatsapp-health', ['filter' => 'help'])->assertOk()
            ->assertJsonPath('counts.blocked', 1)->assertJsonPath('data.0.store.name', 'Awa Mode')
            ->assertJsonPath('data.0.state', 'blocked')->assertJsonPath('data.0.reason', 'Meta demande de vérifier votre entreprise');
    }

    public function test_missing_payment_method_is_partial_and_unreadable_meta_keeps_the_last_state(): void
    {
        $this->artisan('whatsapp:health')->assertSuccessful();
        // Meta a refusé un envoi faute de moyen de paiement
        WhatsappAccount::sole()->forceFill(['last_error' => 'Meta : Business eligibility payment issue (#131042)', 'meta_health_at' => now()->subHours(2)])->save();
        $this->artisan('whatsapp:health')->assertSuccessful();
        $this->assertSame('partial', WhatsappAccount::sole()->meta_state);
        $response = $this->storeApi($this->store, 'GET', 'whatsapp')->assertOk()
            ->assertJsonPath('data.account.meta.state', 'partial')
            ->assertJsonPath('data.account.meta.lines.0.key', 'payment')
            ->assertJsonPath('data.account.meta.lines.0.action.label', 'Ajouter une carte chez Meta');
        $this->assertStringContainsString('billing_hub', $response->json('data.account.meta.lines.0.action.url'));
        // En partie bloqué : sur l'accueil, pas de bandeau rouge dans Messages
        $this->storeApi($this->store, 'GET', 'dashboard')->assertJsonPath('data.whatsapp_meta.state', 'partial');
        $this->storeApi($this->store, 'GET', 'conversations')->assertJsonPath('meta_block', null);

        // Compte refusé par Meta : bloqué, avec « Faire appel »
        $this->review = 'REJECTED';
        $this->storeApi($this->store, 'POST', 'whatsapp/health')->assertOk()
            ->assertJsonPath('data.account.meta.state', 'blocked')->assertJsonPath('data.account.meta.lines.0.action.label', 'Faire appel chez Meta');
        $since = WhatsappAccount::sole()->meta_state_since;
        $this->assertNotNull($since);

        // Meta illisible (jeton expiré) : l'état reste, la date de lecture avance
        WhatsappAccount::sole()->forceFill(['access_token' => 'expired'])->save();
        Http::fake(fn () => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401));
        $this->storeApi($this->store, 'POST', 'whatsapp/health')->assertOk()->assertJsonPath('data.account.meta.state', 'blocked');
        $this->assertEquals($since, WhatsappAccount::sole()->meta_state_since);
        $this->assertSame(0, Conversation::count());
    }
}
