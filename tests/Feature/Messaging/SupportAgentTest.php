<?php

namespace Tests\Feature\Messaging;

use App\Models\HelpMiss;
use App\Models\PlatformSetting;
use App\Models\SupportAgentRun;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Support\SupportAgent;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Assistant du support sur WhatsApp : réponses tirées des fiches du centre d'aide et de l'état de la boutique,
 * passage à l'équipe, silences (désactivé, inscription en cours, équipe qui a la main, plafond du jour), console.
 */
class SupportAgentTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.meta' => ['app_id' => '7777', 'app_secret' => 'platform-secret', 'webhook_verify_token' => 'verif-123']]);
        foreach (['mode' => 'embedded', 'waba_id' => '4242', 'phone_number_id' => '9001', 'display_phone' => '+225 05 07 01 67 39', 'access_token' => Crypt::encryptString('support-token')] as $k => $v) {
            PlatformSetting::put("whatsapp.{$k}", $v);
        }
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/9001/messages')) {
                $this->sent[] = $r->data();

                return Http::response(['messages' => [['id' => 'wamid.out'.count($this->sent)]]]);
            }

            return Http::response(['error' => ['message' => 'inattendu']], 404);
        });
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    private function say(string $text, string $from = '2250708091011'): TestResponse
    {
        static $n = 0;
        $n++;
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '4242', 'changes' => [['field' => 'messages', 'value' => [
            'messages' => [['from' => $from, 'id' => "wamid.agent{$n}", 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Awa K.']]],
        ]]]]]]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        ], $body)->assertOk();
    }

    private function lastText(): string
    {
        return end($this->sent)['text']['body'] ?? '';
    }

    public function test_it_stays_silent_until_enabled_then_answers_from_the_help_center(): void
    {
        $this->say('Bonjour, comment ajouter mes livreurs ?');
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->ai->calls);

        app(SupportAgent::class)->update(true, 3.5);
        $this->ai->agentWill([['chercher_aide', ['question' => 'ajouter mes livreurs']]], "Dans Livraisons › « Mes livreurs », touchez « + Livreur ».\nhttps://localhost/video");
        $this->say('Bonjour, comment ajouter mes livreurs ?');

        $this->assertStringContainsString('« + Livreur »', $this->lastText());
        // La fiche trouvée, avec ses étapes et la page de sa vidéo
        $fiche = $this->ai->toolResults[0]['fiches'][0];
        $this->assertSame('Comment ajouter mes livreurs ou une entreprise de livraison ?', $fiche['question']);
        $this->assertNotEmpty($fiche['etapes']);
        $this->assertStringEndsWith('/video/etapes/etape-08', $fiche['video']);
        // Le modèle reçoit la discussion, pas seulement le dernier message, et les trois outils
        $this->assertStringContainsString('Marchand : Bonjour, comment ajouter mes livreurs ?', $this->ai->lastQuestion);
        $this->assertSame(['chercher_aide', 'ma_boutique', 'passer_a_un_humain'], end($this->ai->calls)['schema']['tools']);
        $this->assertStringContainsString('Vouvoyez', $this->ai->lastSystem);

        $run = SupportAgentRun::sole();
        $this->assertSame(SupportAgentRun::ANSWERED, $run->outcome);
        $this->assertSame(['ajouter-un-livreur', 'faire-livrer'], collect($run->articles)->intersect(['ajouter-un-livreur', 'faire-livrer'])->sort()->values()->all());
        $this->assertGreaterThan(0, $run->cost_usd);
        // Réponse rangée dans la console comme message automatique
        $this->assertSame('automatic', SupportConversation::sole()->messages()->latest('id')->value('type'));
    }

    public function test_it_knows_the_store_of_the_person_writing(): void
    {
        app(SupportAgent::class)->update(true, 3.5);
        $store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $store->owner->forceFill(['phone' => '+2250708091011', 'name' => 'Awa Koné'])->save();

        $this->ai->agentWill([['ma_boutique', ['sujet' => 'prochaine étape']]], 'Il vous reste à ajouter vos produits.');
        $this->say('Je fais quoi maintenant ?');
        $state = $this->ai->toolResults[0];
        $this->assertTrue($state['compte']);
        $this->assertSame('Awa Mode', $state['boutique']);
        $this->assertSame('Awa', $state['prenom']);
        $this->assertNotNull($state['guide_de_demarrage']['prochaine_etape']);
        $this->assertStringContainsString('« Awa Mode »', $this->ai->lastSystem);

        // Numéro sans espace marchand
        $this->ai->agentWill([['ma_boutique', ['sujet' => 'lien']]], 'Écrivez « Inscription » pour créer votre boutique.');
        $this->say('C\'est quoi mon lien ?', '2250101010101');
        $this->assertFalse($this->ai->toolResults[1]['compte']);
    }

    public function test_it_hands_over_to_the_team_and_then_stays_silent(): void
    {
        app(SupportAgent::class)->update(true, 3.5);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->ai->agentWill([['chercher_aide', ['question' => 'remboursement abonnement']], ['passer_a_un_humain', ['raison' => 'Awa demande le remboursement de son abonnement']]],
            'Je préviens l\'équipe : quelqu\'un vous répond ici très vite.');
        $this->say('Je veux être remboursée de mon abonnement');

        $this->assertStringContainsString('quelqu\'un vous répond', $this->lastText());
        $conversation = SupportConversation::sole();
        $this->assertNotNull($conversation->help_requested_at);
        $run = SupportAgentRun::sole();
        $this->assertSame(SupportAgentRun::HANDOFF, $run->outcome);
        $this->assertSame('Awa demande le remboursement de son abonnement', $run->reason);
        // Question sans fiche : notée pour la console (la fiche à écrire)
        $this->assertSame(1, HelpMiss::where('query', 'remboursement abonnement')->count());

        // « Aide demandée » : l'assistant ne répond plus, l'équipe a la main
        $this->say('Allô ?');
        $this->assertCount(1, $this->sent);
        $this->assertCount(1, $this->ai->calls);

        // L'équipe a répondu il y a une heure, « Aide demandée » levée : toujours silencieux pendant 12 h
        $conversation->forceFill(['help_requested_at' => null])->save();
        $conversation->messages()->create(['direction' => 'out', 'type' => 'text', 'body' => 'Bonjour, je regarde.', 'status' => 'sent', 'user_id' => $admin->id, 'sent_at' => now()->subHour()]);
        $this->say('Merci');
        $this->assertCount(1, $this->sent);

        // Treize heures plus tard, il reprend
        $this->travel(13)->hours();
        $this->ai->agentWill([], 'Avec plaisir 🙂');
        $this->say('Bonjour');
        $this->assertSame('Avec plaisir 🙂', $this->lastText());
    }

    public function test_signup_robot_and_daily_budget_come_first(): void
    {
        app(SupportAgent::class)->update(true, 0.5);

        // « Inscription » : le robot d'inscription répond et pose ses questions, pas l'assistant
        $this->say('Inscription');
        $this->say('Awa Mode');
        $this->assertSame([], $this->ai->calls);
        $this->assertSame(0, SupportAgentRun::count());

        // Plafond du jour atteint : rien n'est envoyé, une seule ligne par conversation
        $conversation = SupportConversation::firstOrCreate(['contact' => '+2250505050505']);
        SupportAgentRun::create(['support_conversation_id' => $conversation->id, 'outcome' => SupportAgentRun::ANSWERED, 'cost_usd' => 0.6]);
        $sent = count($this->sent);
        $this->say('Comment ajouter un produit ?', '2250505050505');
        $this->say('Vous êtes là ?', '2250505050505');
        $this->assertCount($sent, $this->sent);
        $this->assertSame(1, SupportAgentRun::where('outcome', SupportAgentRun::BUDGET)->count());
    }

    public function test_console_turns_it_on_and_follows_it(): void
    {
        $admin = User::factory()->create(['phone' => '+2250701020304']);
        $admin->forceFill(['is_super_admin' => true] + self::consoleTwoFactor())->save();
        $console = fn (string $method, array $data = []) => $this->actingAs($admin)->withSession(['console_admin' => $admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, $method, 'console/support-agent', $data);

        $console('GET')->assertOk()->assertJsonPath('data.settings.enabled', false)->assertJsonPath('data.settings.available', true)->assertJsonPath('data.stats.week.alone_percent', null);
        $console('PUT', ['enabled' => true, 'daily_budget' => 0])->assertStatus(422);
        $console('PUT', ['enabled' => true, 'daily_budget' => 2])->assertOk()->assertJsonPath('data.settings.enabled', true)->assertJsonPath('data.settings.daily_budget', 2);

        $this->ai->agentWill([['chercher_aide', ['question' => 'ajouter un produit']]], 'Touchez « + Produit ».');
        $this->say('Comment ajouter un produit ?');
        $this->ai->agentWill([['passer_a_un_humain', ['raison' => 'Compte bloqué']]], 'L\'équipe vous répond.');
        $this->say('Mon compte est bloqué', '2250102030405');
        $console('GET')->assertJsonPath('data.stats.week.answered', 1)->assertJsonPath('data.stats.week.handoff', 1)->assertJsonPath('data.stats.week.alone_percent', 50)
            ->assertJsonPath('data.stats.handoffs.0.reason', 'Compte bloqué');

        // Par QR code, l'assistant ne répond pas
        PlatformSetting::put('whatsapp.mode', 'qr');
        $this->assertFalse(app(SupportAgent::class)->settings()['available']);
    }
}
