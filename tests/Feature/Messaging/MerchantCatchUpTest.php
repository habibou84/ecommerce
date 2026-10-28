<?php

namespace Tests\Feature\Messaging;

use App\Models\OnboardingMessage;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\WhatsApp\MerchantCatchUp;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Rattrapage des commerçants inscrits avant les messages d'accueil : groupes, aperçu, modèles marketing créés chez
 * Meta, envoi par lots une fois les modèles approuvés, une seule fois par commerçant.
 */
class MerchantCatchUpTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    /** @var list<array{url: string, to: ?string, template: ?string, params: list<string>}> */
    private array $calls = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta' => ['app_id' => '7777', 'app_secret' => 'platform-secret', 'whatsapp_config_id' => '8888', 'webhook_verify_token' => 'verif-123']]);
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => function (HttpRequest $r) {
            $data = $r->data();
            $this->calls[] = [
                'url' => $r->url(), 'to' => $data['to'] ?? null, 'template' => $data['template']['name'] ?? $data['name'] ?? null, 'category' => $data['category'] ?? null,
                'params' => collect($data['template']['components'][0]['parameters'] ?? [])->pluck('text')->all(),
            ];

            return str_ends_with($r->url(), '/message_templates')
                ? Http::response(['id' => 'tpl', 'status' => 'PENDING'])
                : Http::response(['messages' => [['id' => 'wamid.'.count($this->calls)]]]);
        }]);
        foreach (['mode' => 'manual', 'phone_number_id' => '9001', 'waba_id' => '4242', 'access_token' => Crypt::encryptString('support-token')] as $key => $value) {
            PlatformSetting::put("whatsapp.{$key}", $value);
        }
        $this->admin = User::factory()->create(['phone' => '+2250701020304']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->travelTo(now()->setTimezone('Africa/Abidjan')->setTime(10, 0));
    }

    private function console(string $method, string $path): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['console_admin' => $this->admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, $method, 'console/whatsapp/'.$path);
    }

    private function merchant(string $slug, string $name, string $phone, int $daysAgo = 10): Store
    {
        $owner = User::factory()->create(['name' => $name, 'phone' => $phone]);
        $store = $this->createStore(['slug' => $slug, 'name' => ucfirst($slug).' Shop', 'owner_id' => $owner->id]);
        $store->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $store;
    }

    private function approve(string $template): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '4242', 'changes' => [['field' => 'message_template_status_update', 'value' => [
            'event' => 'APPROVED', 'message_template_name' => $template,
        ]]]]]]);
        $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        ], $body)->assertOk();
    }

    private function runCatchUp(): int
    {
        app()->forgetScopedInstances();

        return app(MerchantCatchUp::class)->run();
    }

    private function sentTo(): array
    {
        return collect($this->calls)->filter(fn ($c) => str_ends_with($c['url'], '/messages'))->map(fn ($c) => [ltrim((string) $c['to'], '+'), $c['template'], $c['params']])->values()->all();
    }

    public function test_groups_templates_and_sending_once_per_merchant(): void
    {
        $empty = $this->merchant('awa', 'Awa Koné', '+2250708091011');
        $products = $this->merchant('koffi', 'Koffi Yao', '+2250102030405');
        app(CatalogWriter::class)->save($products, null, ['title' => 'Sac', 'variants' => [['price' => 8000, 'on_hand' => 3]]]);
        $selling = $this->merchant('mariam', 'Mariam Traoré', '+2250505050505');
        $robe = app(CatalogWriter::class)->save($selling, null, ['title' => 'Robe', 'variants' => [['price' => 15000, 'on_hand' => 3]]]);
        $this->actingAs($selling->owner);
        $this->storeApi($selling, 'POST', 'orders', ['source' => 'whatsapp', 'customer_phone' => '+2250606060606', 'customer_name' => 'Fatou', 'delivery_method' => 'pickup',
            'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]]])->assertCreated();
        // Pas concernés : STOP, déjà accueilli, inscrit hier
        $this->merchant('stop', 'Ali', '+2250707070707')->owner->forceFill(['platform_messages_stopped_at' => now()])->save();
        $welcomed = $this->merchant('deja', 'Fanta', '+2250303030303');
        OnboardingMessage::create(['store_id' => $welcomed->id, 'user_id' => $welcomed->owner_id, 'step' => 'welcome', 'status' => 'sent']);
        $this->merchant('nouveau', 'Yao', '+2250404040404', daysAgo: 1);

        $preview = $this->console('GET', 'catchup')->json('data');
        $this->assertSame([3, 1, 1, 1], [$preview['total'], $preview['groups']['empty']['count'], $preview['groups']['products']['count'], $preview['groups']['selling']['count']]);
        $this->assertStringStartsWith("Bonjour Awa 👋\n\nVotre boutique *Awa Shop*", $preview['groups']['empty']['preview']);
        $this->assertSame('idle', $preview['state']);

        // Pas lancé : rien ne part
        $this->assertSame(0, $this->runCatchUp());

        // Modèles marketing créés chez Meta
        $this->console('POST', 'catchup/templates')->assertOk()->assertJsonPath('data.groups.empty.template_status', 'PENDING');
        $created = collect($this->calls)->filter(fn ($c) => str_ends_with($c['url'], '/message_templates'));
        $this->assertSame(['jm_rattrapage_produits', 'jm_rattrapage_live', 'jm_rattrapage_nouveautes'], $created->pluck('template')->all());
        $this->assertSame(['MARKETING'], $created->pluck('category')->unique()->values()->all());

        // Lancé, modèles en attente : rien ne part, rien n'est noté
        $this->console('POST', 'catchup/start')->assertOk()->assertJsonPath('data.state', 'running');
        $this->assertSame(0, $this->runCatchUp());
        $this->assertSame(0, OnboardingMessage::where('step', 'catchup')->count());

        // Un modèle approuvé : son groupe part
        $this->approve('jm_rattrapage_produits');
        $this->assertSame(1, $this->runCatchUp());
        $this->assertSame([['2250708091011', 'jm_rattrapage_produits', ['Awa', 'Awa Shop', $empty->url('/gestion/catalogue/nouveau')]]], $this->sentTo());

        // Pause, puis les autres approuvés et reprise
        $this->console('POST', 'catchup/pause')->assertOk()->assertJsonPath('data.state', 'paused');
        $this->approve('jm_rattrapage_live');
        $this->approve('jm_rattrapage_nouveautes');
        $this->assertSame(0, $this->runCatchUp());
        $this->console('POST', 'catchup/start')->assertOk();
        $this->travelTo(now()->setTime(22, 0));
        $this->assertSame(0, $this->runCatchUp(), 'heures calmes');
        $this->travelTo(now()->addDay()->setTime(8, 0));
        $this->assertSame(2, $this->runCatchUp());
        $this->assertSame(['jm_rattrapage_produits', 'jm_rattrapage_live', 'jm_rattrapage_nouveautes'], array_column($this->sentTo(), 1));
        $this->assertSame($products->url('/gestion/live'), $this->sentTo()[1][2][2]);

        // Plus personne : terminé, jamais deux fois
        $this->assertSame(0, $this->runCatchUp());
        $this->console('GET', 'catchup')->assertJsonPath('data.state', 'done')->assertJsonPath('data.sent', 3)->assertJsonPath('data.total', 0);
        $this->assertCount(3, $this->sentTo());
    }
}
