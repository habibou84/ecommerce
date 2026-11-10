<?php

namespace Tests\Feature\Messaging;

use App\Jobs\DraftSignupProduct;
use App\Models\LiveSession;
use App\Models\OnboardingMessage;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WhatsappSignup;
use App\Notifications\SupportHelpRequested;
use App\Services\Ai\AiClient;
use App\Services\WhatsApp\MerchantOnboarding;
use App\Services\WhatsApp\SignupByWhatsApp;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Inscription par WhatsApp sur le numéro du support : « Inscription », numéro confirmé, nom, prénom, live, récapitulatif,
 * boutique créée avec un lien de connexion ; photo → fiche produit ; « aide », pause quand un humain répond, relance,
 * mot « live », bilan du premier live.
 */
class SignupByWhatsAppTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta' => ['app_id' => '7777', 'app_secret' => 'platform-secret', 'webhook_verify_token' => 'verif-123']]);
        foreach (['mode' => 'embedded', 'waba_id' => '4242', 'phone_number_id' => '9001', 'display_phone' => '+225 05 07 01 67 39', 'access_token' => Crypt::encryptString('support-token')] as $k => $v) {
            PlatformSetting::put("whatsapp.{$k}", $v);
        }
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/9001/messages')) {
                $this->sent[] = $r->data();

                return Http::response(['messages' => [['id' => 'wamid.out'.count($this->sent)]]]);
            }

            return match (true) {
                str_ends_with($path, '/media-1') => Http::response(['url' => 'https://lookaside.fbsbx.com/photo-1']),
                str_contains($r->url(), 'lookaside.fbsbx.com') => Http::response(self::jpeg(), 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response(['error' => ['message' => 'inattendu '.$path]], 404),
            };
        });
    }

    private static function jpeg(): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 120, 160));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function say(string $text, ?string $button = null, string $from = '2250708091011'): TestResponse
    {
        static $n = 0;
        $n++;
        $message = $button !== null
            ? ['from' => $from, 'id' => "wamid.in{$n}", 'timestamp' => (string) now()->timestamp, 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $button, 'title' => $text]]]
            : ['from' => $from, 'id' => "wamid.in{$n}", 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]];

        return $this->webhook(['messages' => [$message], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Awa K.']]]]);
    }

    private function webhook(array $value): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '4242', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        ], $body)->assertOk();
    }

    /** Dernier message envoyé : texte, ou corps et identifiants des boutons */
    private function last(): array
    {
        $m = end($this->sent);

        return [
            'text' => $m['text']['body'] ?? $m['interactive']['body']['text'] ?? '',
            'buttons' => array_map(fn ($b) => $b['reply']['id'], $m['interactive']['action']['buttons'] ?? []),
            'preview' => $m['text']['preview_url'] ?? null,
        ];
    }

    private function texts(): string
    {
        return collect($this->sent)->map(fn ($m) => $m['text']['body'] ?? $m['interactive']['body']['text'] ?? '')->implode("\n---\n");
    }

    /** Message sans texte (vocal, autocollant…) tel que Meta l'envoie */
    private function sendKind(string $type, string $from = '2250708091011'): TestResponse
    {
        static $n = 0;
        $n++;
        $message = ['from' => $from, 'id' => "wamid.kind{$n}", 'timestamp' => (string) now()->timestamp, 'type' => $type, $type => ['id' => "media{$n}"]];

        return $this->webhook(['messages' => [$message], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Awa K.']]]]);
    }

    public function test_voice_notes_stickers_and_emojis_are_never_taken_as_answers(): void
    {
        $this->say('Inscription');
        $this->say('Oui', 'su:yes');
        $this->assertStringContainsString('nom de ta boutique', $this->last()['text']);
        $signup = fn () => WhatsappSignup::where('phone', '+2250708091011')->sole();

        // Autocollant : pas le nom « Autocollant », la question est reposée
        $this->sendKind('sticker');
        $this->assertArrayNotHasKey('store_name', $signup()->data);
        $this->assertSame('name', $signup()->step);
        $this->assertStringContainsString('nom de ta boutique', $this->last()['text']);
        // Vocal : on dit qu'on ne peut pas l'écouter
        $this->sendKind('audio');
        $this->assertStringContainsString('Je ne peux pas écouter les vocaux', $this->texts());
        $this->assertArrayNotHasKey('store_name', $signup()->data);

        // Un vrai nom : l'étape avance (et le compte des réponses incomprises repart de zéro)
        $this->say('Awa Mode');
        $this->assertSame('Awa Mode', $signup()->data['store_name']);
        $this->assertSame('first_name', $signup()->step);

        // Prénom : emojis, « ok », vocal ne passent pas ; à la troisième réponse incomprise, un conseiller prend la main
        $this->say('😂😂😂👍');
        $this->say('ok');
        $this->assertArrayNotHasKey('first_name', $signup()->data);
        $this->assertFalse($signup()->paused());
        $this->sendKind('audio');
        $this->assertTrue($signup()->paused());
        $this->assertStringContainsString('Je passe la main à un conseiller', $this->last()['text']);
        $sent = count($this->sent);
        $this->say('Fatou');
        $this->assertCount($sent, $this->sent); // le robot se tait, l'équipe répond

        // « Inscription » relance le robot, et un vrai prénom passe
        $this->say('Inscription');
        $this->assertSame('confirm', $signup()->step);
    }

    public function test_numbers_outside_ivory_coast_cannot_sign_up(): void
    {
        $this->say('Inscription', from: '33612345678');
        $this->assertStringContainsString('réservé aux numéros de Côte d\'Ivoire', $this->last()['text']);
        $this->assertSame(0, WhatsappSignup::count());
        // Une seule réponse : ensuite le robot laisse la personne à l'équipe
        $sent = count($this->sent);
        $this->say('Inscription', from: '33612345678');
        $this->say('Bonjour ?', from: '33612345678');
        $this->assertCount($sent, $this->sent);
        // Ses trois messages restent dans la console, pour l'équipe
        $this->assertSame(3, SupportConversation::where('contact', '+33612345678')->sole()->messages()->where('direction', 'in')->count());
    }

    public function test_a_video_instead_of_the_photo_gets_a_clear_and_different_answer(): void
    {
        $store = $this->createStore(['slug' => 'awa']);
        WhatsappSignup::create(['phone' => '+2250708091011', 'step' => 'photo', 'store_id' => $store->id]);

        $this->sendKind('video');
        $this->assertStringContainsString('J\'ai reçu une vidéo, mais il me faut une *photo*', $this->last()['text']);
        $this->sendKind('video');
        $this->assertStringContainsString('Si c\'est compliqué, écris *plus tard*', $this->last()['text']);
        $this->sendKind('video');
        $this->assertStringContainsString('Je passe la main à un conseiller', $this->last()['text']);
        $this->assertTrue(WhatsappSignup::where('phone', '+2250708091011')->sole()->paused());
    }

    public function test_a_shop_is_created_from_whatsapp_with_a_login_link_and_a_first_product_from_a_photo(): void
    {
        Queue::fake();
        $this->say('Bonjour, inscription');
        $this->assertStringContainsString('Toi, tu fais ton live sur TikTok', $this->last()['text']);
        $this->assertStringContainsString('07 08 09 10 11', $this->last()['text']);
        $this->assertSame(['su:yes', 'su:other'], $this->last()['buttons']);

        $this->say('✅ Oui', 'su:yes');
        $this->assertStringContainsString('nom de ta boutique', $this->last()['text']);
        // Nom refusé, puis accepté
        $this->say('Jibiat boutique');
        $this->assertStringContainsString('réservé', $this->last()['text']);
        $this->say('Awa Mode');
        $this->assertStringContainsString('ton prénom', $this->last()['text']);
        $this->say('awa');
        $this->assertSame(['su:live-yes', 'su:live-no'], $this->last()['buttons']);
        $this->say('Oui, en live', 'su:live-yes');
        $this->assertStringContainsString('*Awa Mode*', $this->last()['text']);
        $this->assertStringContainsString('awa-mode.localhost', $this->last()['text']);
        $this->assertSame(['su:create', 'su:edit'], $this->last()['buttons']);

        $this->say('🚀 Créer', 'su:create');
        $store = Store::where('slug', 'awa-mode')->firstOrFail();
        $owner = $store->owner;
        $this->assertSame('+2250708091011', $owner->phone);
        $this->assertSame('Awa', $owner->name);
        $this->assertNotNull($owner->phone_verified_at);
        $welcome = $this->sent[count($this->sent) - 2];
        $this->assertStringContainsString('C\'est prêt, Awa', $welcome['text']['body']);
        $this->assertFalse($welcome['text']['preview_url']);
        // L'accueil habituel n'envoie pas un deuxième bienvenue
        $this->assertTrue(OnboardingMessage::where('store_id', $store->id)->where('step', 'welcome')->exists());

        // Le lien ouvre la gestion, une seule fois ; l'aperçu de WhatsApp ne le consomme pas
        preg_match('#(http://awa-mode\.localhost/auth/relais\?jeton=\w+)#', $welcome['text']['body'], $m);
        $this->get($m[1], ['User-Agent' => 'WhatsApp/2.24'])->assertNoContent();
        $this->get($m[1])->assertRedirect('/gestion');
        $this->assertAuthenticatedAs($owner, 'web');
        $this->get($m[1])->assertRedirect('/gestion/connexion');

        // Photo : fiche préparée en file
        $this->webhook(['messages' => [['from' => '2250708091011', 'id' => 'wamid.photo', 'timestamp' => (string) now()->timestamp, 'type' => 'image', 'image' => ['id' => 'media-1']]]]);
        Queue::assertPushed(DraftSignupProduct::class, fn ($job) => $job->mediaId === 'media-1');
        $this->assertSame(
            ['started', 'confirmed', 'name', 'first_name', 'live', 'created', 'photo'],
            array_keys(WhatsappSignup::where('phone', '+2250708091011')->value('reached')),
        );
    }

    public function test_the_photo_becomes_a_draft_product_written_by_the_ai(): void
    {
        $this->app->instance(AiClient::class, (new FakeAiClient)->respond(['title' => 'Robe wax longue', 'description' => 'Belle robe.', 'brand' => null,
            'options' => [['name' => 'Taille', 'values' => ['S', 'M']]], 'tags' => ['robe'], 'collections' => [], 'seo_title' => 'Robe', 'seo_description' => 'Robe', 'to_check' => 'Prix']));
        $store = $this->createStore(['slug' => 'awa']);
        $signup = WhatsappSignup::create(['phone' => $store->owner->phone, 'step' => 'done', 'store_id' => $store->id]);

        // Comme en production : par la tâche en file d'attente (elle plantait avant d'arriver à la fiche)
        (new DraftSignupProduct($signup->id, 'media-1'))->handle(app(SignupByWhatsApp::class));

        $product = Product::forStore($store->id)->latest('id')->firstOrFail();
        $this->assertSame('Robe wax longue', $product->title);
        $this->assertSame('draft', $product->status->value);
        $this->assertSame(1, $product->images()->count());
        $this->assertSame(2, $product->variants()->count());
        $this->assertStringContainsString('*Robe wax longue*', $this->last()['text']);
        $this->assertStringContainsString('Taille : S, M', $this->last()['text']);
        $this->assertStringContainsString('/auth/relais?jeton=', $this->last()['text']);
    }

    public function test_help_pause_when_a_human_answers_and_existing_numbers(): void
    {
        // Numéro déjà inscrit : seulement la connexion
        $store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $phone = ltrim($store->owner->phone, '+');
        $this->say('inscription', from: $phone);
        $this->assertStringContainsString('déjà la boutique *Awa Mode*', $this->last()['text']);
        $this->assertSame(['su:login'], $this->last()['buttons']);
        $this->say('Me connecter', 'su:login', $phone);
        $this->assertStringContainsString('awa.localhost/auth/relais?jeton=', $this->last()['text']);

        // « aide » : l'équipe prend la main, le robot se tait, la conversation passe en tête dans la console et l'équipe
        // est prévenue tout de suite (téléphone, e-mail)
        $admin = User::factory()->create(['phone' => '+2250701010101', 'email' => 'equipe@jibiat.com']);
        $admin->forceFill(['is_super_admin' => true])->save();
        Notification::fake();
        $this->say('inscription');
        $count = count($this->sent);
        $this->say('aide');
        $this->assertStringContainsString('te répond ici très vite', $this->last()['text']);
        $this->assertNotNull(SupportConversation::where('contact', '+2250708091011')->value('help_requested_at'));
        Notification::assertSentTo($admin, SupportHelpRequested::class, fn (SupportHelpRequested $n) => str_contains($n->toArray($admin)['body'], 'aide'));
        $this->say('oui');
        $this->assertCount($count + 1, $this->sent);

        // Le robot ne reprend pas tout seul, même des heures après…
        $this->travel(7)->hours();
        $this->say('oui');
        $this->assertCount($count + 1, $this->sent);
        // … mais quand la console lui rend la main
        app(SignupByWhatsApp::class)->resume('+2250708091011');
        $this->say('oui');
        $this->assertStringContainsString('nom de ta boutique', $this->last()['text']);

        // Réponse faite depuis le téléphone (WhatsApp Business) : le robot se met en pause
        $this->webhook(['message_echoes' => [['from' => '2250507016739', 'to' => '2250708091011', 'id' => 'wamid.tel', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Je t\'aide']]]]);
        $count = count($this->sent);
        $this->say('Awa Mode');
        $this->assertCount($count, $this->sent);
        // « Inscription » relance le robot
        $this->say('Inscription');
        $this->assertCount($count + 1, $this->sent);

        // Annuler
        $this->say('annuler');
        $this->assertStringContainsString('j\'arrête', $this->last()['text']);
        $this->assertSame('idle', WhatsappSignup::where('phone', '+2250708091011')->value('step'));
        // Un message ordinaire hors inscription : rien d'automatique
        $count = count($this->sent);
        $this->say('Bonjour, une question sur mon abonnement');
        $this->assertCount($count, $this->sent);
    }

    public function test_a_whatsapp_still_on_the_old_8_digit_number_finds_the_existing_account(): void
    {
        // Compte Jibiat Marchand au 01 53 14 16 66 ; son WhatsApp écrit encore sous l'ancien numéro 53 14 16 66
        $store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $store->owner->forceFill(['phone' => '+2250153141666'])->save();
        $this->say('Inscription', from: '22553141666');
        $this->assertStringContainsString('déjà la boutique *Awa Mode*', $this->last()['text']);
        $this->assertSame('22553141666', end($this->sent)['to']);
        $this->say('Me connecter', 'su:login', '22553141666');
        $this->assertStringContainsString('awa.localhost/auth/relais?jeton=', $this->last()['text']);
        $this->assertSame(1, User::where('phone', 'like', '%53141666')->count());

        // Numéro sans compte : la boutique est créée au numéro à 10 chiffres
        $this->say('inscription', from: '22547123456');
        $this->assertStringContainsString('07 47 12 34 56', $this->last()['text']);
        $this->say('Oui', 'su:yes', '22547123456');
        $this->say('Fatou Shop', from: '22547123456');
        $this->say('Fatou', from: '22547123456');
        $this->say('Pas encore', 'su:live-no', '22547123456');
        $this->say('Créer', 'su:create', '22547123456');
        $this->assertSame('+2250747123456', Store::where('slug', 'fatou-shop')->firstOrFail()->owner->phone);
        $this->assertSame('22547123456', end($this->sent)['to']);
    }

    public function test_reminder_once_and_live_keyword_and_first_live_summary(): void
    {
        // Arrêtée au nom de la boutique : une relance 3 h après, une seule (hors nuit)
        $this->travelTo(now()->setTimezone('Africa/Abidjan')->setTime(10, 0));
        $this->say('inscription');
        $this->say('Oui', 'su:yes');
        $this->travel(181)->minutes();
        $this->assertSame(1, app(SignupByWhatsApp::class)->remind());
        $this->assertStringContainsString('nom de ta boutique', $this->last()['text']);
        $this->assertSame(0, app(SignupByWhatsApp::class)->remind());

        // « live » d'un commerçant : phrase clé, 3 étapes, vidéo, puis le lien de « Préparer un live »
        $store = $this->createStore(['slug' => 'awa']);
        $phone = ltrim($store->owner->phone, '+');
        $this->say('live', from: $phone);
        $this->assertStringContainsString('Toi, tu fais ton live sur TikTok', $this->sent[count($this->sent) - 2]['text']['body']);
        $this->assertStringContainsString('/video/live/comment-ca-marche-v', $this->sent[count($this->sent) - 2]['text']['body']);
        $this->get($this->platformUrl('/video/live/comment-ca-marche-v'))->assertOk()->assertSee('/videos/live/comment-ca-marche-v.mp4', false);
        $this->get($this->platformUrl('/video/live/..%2F..%2Fenv'))->assertNotFound();
        $this->assertSame(['lv:link', 'lv:wa', 'su:help'], $this->last()['buttons']);
        $this->say('Connecter WhatsApp', 'lv:wa', $phone);
        $this->assertStringContainsString('WhatsApp Business', $this->last()['text']);
        $this->assertStringContainsString('awa.localhost/auth/relais?jeton=', $this->last()['text']);

        // Premier live terminé : bilan par WhatsApp (le commerçant vient d'écrire : fenêtre de 24 h ouverte)
        LiveSession::create(['store_id' => $store->id, 'title' => 'Live 1', 'status' => 'ended', 'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(5)]);
        app(MerchantOnboarding::class)->run();
        $this->assertTrue(OnboardingMessage::where('store_id', $store->id)->where('step', 'first_live')->where('status', 'sent')->exists());
        $this->assertStringContainsString('premier live', collect($this->sent)->map(fn ($m) => $m['text']['body'] ?? '')->filter(fn ($t) => str_contains($t, 'Bravo'))->last());
    }

    public function test_console_link_funnel_and_switch(): void
    {
        $this->say('inscription');
        User::factory()->create(['phone' => '+2250701020304', 'password' => 'motdepasse'])->forceFill(['is_super_admin' => true])->save();
        $this->signInConsole();
        $this->apiOn(self::CONSOLE, 'GET', 'console/whatsapp/signups')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.link', 'https://wa.me/2250507016739?text=Inscription')
            ->assertJsonPath('data.funnel.started', 1)->assertJsonPath('data.funnel.created', 0);
        $this->apiOn(self::CONSOLE, 'PUT', 'console/whatsapp/signups', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $count = count($this->sent);
        $this->say('inscription', from: '2250506070809');
        $this->assertCount($count, $this->sent);
        $this->assertSame(0, User::where('phone', '+2250506070809')->count());
    }
}
