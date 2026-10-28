<?php

namespace Tests\Feature\Messaging;

use App\Models\OnboardingMessage;
use App\Models\PhoneVerification;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Billing\AccessKeys;
use App\Services\Catalog\CatalogWriter;
use App\Services\WhatsApp\MerchantOnboarding;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Accueil des nouveaux commerçants par le WhatsApp du support : bienvenue, premier produit, vente en live, fin de
 * l'essai ; fenêtre de 24 h de Meta, heures calmes, « STOP », textes modifiables dans la console.
 */
class MerchantOnboardingTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    /** @var list<array{to: string, body: ?string, template: ?string, params: list<string>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta' => ['app_id' => '7777', 'app_secret' => 'platform-secret', 'whatsapp_config_id' => '8888', 'webhook_verify_token' => 'verif-123']]);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => function (HttpRequest $r) {
            // Création d'un modèle : pas un message envoyé
            if (str_ends_with($r->url(), '/message_templates')) {
                return Http::response(['id' => 'tpl1', 'status' => 'PENDING']);
            }
            $this->sent[] = [
                'to' => (string) $r['to'],
                'body' => $r['text']['body'] ?? null,
                'template' => $r['template']['name'] ?? null,
                'params' => collect($r['template']['components'][0]['parameters'] ?? [])->pluck('text')->all(),
            ];

            return Http::response(['messages' => [['id' => 'wamid.'.count($this->sent)]]]);
        }]);
        // Numéro du support connecté (configuration manuelle)
        foreach (['mode' => 'manual', 'phone_number_id' => '9001', 'waba_id' => '4242', 'access_token' => Crypt::encryptString('support-token')] as $key => $value) {
            PlatformSetting::put("whatsapp.{$key}", $value);
        }
        $this->travelTo(now()->setTimezone('Africa/Abidjan')->setTime(10, 0));
    }

    private function merchant(string $slug = 'awa', bool $verified = true, ?User $owner = null): Store
    {
        $owner ??= User::factory()->create(['name' => 'Awa Koné', 'phone' => '+2250708091011']);
        $store = $this->createStore(['slug' => $slug, 'name' => 'Awa Mode', 'owner_id' => $owner->id]);
        if ($verified) {
            (new PhoneVerification)->forceFill([
                'purpose' => 'signup', 'phone' => $owner->phone, 'token_hash' => hash('sha256', Str::random()), 'verified_at' => now(), 'expires_at' => now()->addMinutes(15),
            ])->save();
        }

        return $store;
    }

    private function runOnboarding(): array
    {
        app()->forgetScopedInstances();

        return app(MerchantOnboarding::class)->run();
    }

    private function webhook(string $from, string $text): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '4242', 'changes' => [['field' => 'messages', 'value' => [
            'messages' => [['from' => ltrim($from, '+'), 'id' => 'wamid.in'.Str::random(6), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        ], $body);
    }

    public function test_welcome_then_first_product_then_live_within_the_24_hours(): void
    {
        $store = $this->merchant();
        $this->assertSame(0, $this->runOnboarding()['welcome'], 'pas avant une minute');

        $this->travel(2)->minutes();
        $this->assertSame(1, $this->runOnboarding()['welcome']);
        $this->assertSame('2250708091011', ltrim($this->sent[0]['to'], '+'));
        $this->assertStringStartsWith('Bienvenue sur Jibiat Marchand, Awa ! 🎉', $this->sent[0]['body']);
        $this->assertStringContainsString('Vous avez *1 mois de l\'offre Pro offert*', $this->sent[0]['body']);
        $this->assertStringContainsString($store->url('/gestion/catalogue/nouveau'), $this->sent[0]['body']);
        // Recopié dans la console comme message automatique ; jamais deux fois
        $this->assertSame('automatic', SupportMessage::sole()->type);
        $this->assertSame(0, $this->runOnboarding()['welcome']);

        // 3 h après, toujours aucun produit
        $this->travel(3)->hours();
        $this->assertSame(1, $this->runOnboarding()['no_product']);
        $this->assertStringStartsWith('Awa, votre boutique attend son premier produit', $this->sent[1]['body']);

        // Des produits ajoutés ; 20 h après l'inscription il est 6 h : on attend 7 h
        app(CatalogWriter::class)->save($store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 3]]]);
        app(CatalogWriter::class)->save($store, null, ['title' => 'Sac', 'variants' => [['price' => 8000, 'on_hand' => 3]]]);
        $this->travel(17)->hours();
        $this->assertSame(0, $this->runOnboarding()['first_products'], 'heures calmes');
        $this->travel(65)->minutes();
        $this->assertSame(1, $this->runOnboarding()['first_products']);
        $this->assertStringStartsWith('Bravo Awa, 2 produits déjà dans votre boutique 👏', $this->sent[2]['body']);
        $this->assertSame(['welcome' => 'sent', 'no_product' => 'sent', 'first_products' => 'sent'], OnboardingMessage::orderBy('id')->pluck('status', 'step')->all());
    }

    public function test_stop_start_second_store_and_closed_window(): void
    {
        $store = $this->merchant();
        $owner = $store->owner;
        $this->webhook($owner->phone, 'Stop')->assertOk();
        $this->assertNotNull($owner->fresh()->platform_messages_stopped_at);
        $this->assertSame(MerchantOnboarding::STOP_REPLY, $this->sent[0]['body']);

        $this->travel(2)->minutes();
        $this->runOnboarding();
        $this->assertSame('skipped', OnboardingMessage::sole()->status);
        $this->assertCount(1, $this->sent);

        // START : reprend (pour les étapes suivantes)
        $this->webhook($owner->phone, 'START')->assertOk();
        $this->assertNull($owner->fresh()->platform_messages_stopped_at);

        // Deuxième boutique du même commerçant : pas de deuxième bienvenue
        $this->merchant('awa-deux', owner: $owner);
        $this->travel(2)->minutes();
        $this->runOnboarding();
        $this->assertSame('Commerçant déjà accueilli', OnboardingMessage::where('store_id', '!=', $store->id)->value('detail'));

        // Compte existant (pas de message de vérification) avec la connexion officielle : hors des 24 h, rien ne part
        $koffi = $this->merchant('koffi', verified: false, owner: User::factory()->create(['name' => 'Koffi', 'phone' => '+2250102030405']));
        $this->travel(2)->minutes();
        $this->runOnboarding();
        $this->assertSame('Hors des 24 h après la vérification du numéro', OnboardingMessage::where('store_id', $koffi->id)->value('detail'));
        $this->assertCount(2, $this->sent);
    }

    public function test_trial_end_by_meta_template_and_texts_set_in_the_console(): void
    {
        $store = $this->merchant();
        $admin = User::factory()->create(['phone' => '+2250701020304']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $console = fn (string $method, array $data = []) => $this->actingAs($admin)->withSession(['console_admin' => $admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, $method, 'console/whatsapp/onboarding', $data);

        $console('GET')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.texts.welcome', MerchantOnboarding::TEXTS['welcome']);
        $console('PUT', ['enabled' => true, 'texts' => ['welcome' => 'Salut {prenom}, {boutique} est en ligne !'], 'trial_template' => 'Fin essai'])->assertStatus(422);
        $console('PUT', ['enabled' => true, 'texts' => ['welcome' => 'Salut {prenom}, {boutique} est en ligne !'], 'trial_template' => 'fin_essai_jibiat'])->assertOk()
            ->assertJsonPath('data.trial_template', 'fin_essai_jibiat');

        $this->travel(2)->minutes();
        $this->runOnboarding();
        $this->assertSame('Salut Awa, Awa Mode est en ligne !', $this->sent[0]['body']);
        $console('GET')->assertJsonPath('data.stats.welcome.sent', 1);

        // 3 jours avant la fin de l'essai (bien après les 24 h) : modèle validé par Meta
        $this->travelTo($store->currentSubscription->trial_ends_at->copy()->subDays(2)->subHours(12)->setTimezone('Africa/Abidjan')->setTime(10, 0));
        $this->assertSame(1, $this->runOnboarding()['trial_end']);
        $last = end($this->sent);
        $this->assertSame('fin_essai_jibiat', $last['template']);
        $this->assertSame(['Awa', 'Awa Mode'], array_slice($last['params'], 0, 2));
        $this->assertStringEndsWith('/gestion/abonnement', $last['params'][4]);

        // Arrêtés depuis la console : plus rien ne part
        $console('PUT', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->merchant('mariam', owner: User::factory()->create(['name' => 'Mariam', 'phone' => '+2250505050505']));
        $this->travel(2)->minutes();
        $this->assertSame(0, array_sum($this->runOnboarding()));
    }

    public function test_welcome_variants_for_a_premium_card_and_a_partner_invitation(): void
    {
        $keys = app(AccessKeys::class);
        $batch = $keys->createBatch(['kind' => 'founder', 'plan' => 'pro', 'months' => null, 'quantity' => 50, 'distributor_name' => null, 'distributor_phone' => null, 'note' => null]);
        $store = $this->merchant();
        $keys->redeem($batch->keys()->where('number', 7)->value('code'), $store);

        $koffi = $this->merchant('koffi', owner: User::factory()->create(['name' => 'Koffi Yao', 'phone' => '+2250102030405']));
        $koffi->forceFill(['referred_by' => 'rapide'])->save();

        $this->travel(2)->minutes();
        $this->assertSame(2, $this->runOnboarding()['welcome']);
        $bodies = collect($this->sent)->pluck('body', 'to')->mapWithKeys(fn ($b, $to) => [ltrim($to, '+') => $b]);
        $this->assertStringStartsWith('Bienvenue parmi les membres Premium, Awa ! 🏅', $bodies['2250708091011']);
        $this->assertStringContainsString('*n° 007*', $bodies['2250708091011']);
        $this->assertStringContainsString('offre *Pro à vie*', $bodies['2250708091011']);
        // Annuaire injoignable : nom générique de l'entreprise
        $this->assertStringStartsWith('Bienvenue, Koffi ! *Votre entreprise de livraison* vous a invité', $bodies['2250102030405']);
    }

    public function test_the_trial_end_template_is_created_at_meta_and_used_once_approved(): void
    {
        $admin = User::factory()->create(['phone' => '+2250701020304']);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin)->withSession(['console_admin' => $admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, 'POST', 'console/whatsapp/onboarding/template')->assertOk()
            ->assertJsonPath('data.trial_template', 'jm_fin_essai')->assertJsonPath('data.trial_template_status', 'PENDING');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/4242/message_templates') && $r['name'] === 'jm_fin_essai'
            && $r['category'] === 'UTILITY' && count($r['components'][0]['example']['body_text'][0]) === 5);

        // En attente chez Meta : le rappel ne part pas encore (hors des 24 h)
        $store = $this->merchant();
        $this->travelTo($store->currentSubscription->trial_ends_at->copy()->subDays(2)->subHours(12)->setTimezone('Africa/Abidjan')->setTime(10, 0));
        $this->assertSame(0, $this->runOnboarding()['trial_end']);
        $this->assertSame('Hors des 24 h et modèle Meta pas encore approuvé', OnboardingMessage::where('step', 'trial_end')->value('detail'));

        // Approuvé (notification de Meta sur le numéro du support) : il part pour la boutique suivante
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '4242', 'changes' => [['field' => 'message_template_status_update', 'value' => [
            'event' => 'APPROVED', 'message_template_name' => 'jm_fin_essai',
        ]]]]]]);
        $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        ], $body)->assertOk();
        $this->assertSame('APPROVED', app(MerchantOnboarding::class)->settings()['trial_template_status']);
        OnboardingMessage::where('step', 'trial_end')->delete();
        $this->assertSame(1, $this->runOnboarding()['trial_end']);
        $this->assertSame('jm_fin_essai', end($this->sent)['template']);
    }
}
