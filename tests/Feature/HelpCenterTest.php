<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\HelpArticle;
use App\Models\HelpMiss;
use App\Models\Store;
use App\Models\User;
use App\Services\Help\HelpCenter;
use App\Support\StartSteps;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Centre d'aide : fiches de départ, écriture dans la console (images, vidéo), lecture et recherche dans
 * l'application, avis des marchands, recherches sans réponse.
 */
class HelpCenterTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private Store $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(HelpArticle::disk());
        $this->admin = User::factory()->create(['phone' => '+2250701020304']);
        $this->admin->forceFill(['is_super_admin' => true] + self::consoleTwoFactor())->save();
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['console_admin' => $this->admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function shop(string $method, string $path, array $data = []): TestResponse
    {
        $this->actingAs($this->store->owner);

        return $this->storeApi($this->store, $method, $path, $data);
    }

    public function test_default_articles_are_installed_once_and_never_overwritten(): void
    {
        $count = HelpArticle::count();
        $this->assertGreaterThanOrEqual(15, $count);
        $article = HelpArticle::where('slug', 'ajouter-un-produit')->firstOrFail();
        $this->assertSame('/videos/etapes/etape-06.mp4', strtok($article->video_url, '?'));
        // Chaque vidéo et chaque écran cités existent
        foreach (HelpArticle::all() as $a) {
            if ($a->video_url) {
                $this->assertFileExists(public_path(strtok($a->video_url, '?')), $a->slug);
            }
            if ($a->screen) {
                $this->assertMatchesRegularExpression('#^/gestion/[a-z]#', $a->screen, $a->slug);
            }
        }

        $article->update(['title' => 'Mon titre']);
        HelpArticle::where('slug', 'alertes')->delete();
        $this->assertSame(1, app(HelpCenter::class)->installDefaults());
        $this->assertSame('Mon titre', $article->fresh()->title);
        $this->assertSame($count, HelpArticle::count());
    }

    public function test_merchants_read_search_and_rate_articles(): void
    {
        $list = $this->shop('GET', 'help')->assertOk()->assertJsonPath('data.results', null);
        $this->assertSame('Bien démarrer', $list->json('data.categories.demarrer'));
        $this->assertContains('ajouter-un-produit', array_column($list->json('data.articles'), 'slug'));

        // Les mots du marchand : sans accents, au pluriel, avec des mots courants
        $this->shop('GET', 'help?q='.urlencode('Comment ajouter mes livreurs svp'))->assertOk()->assertJsonPath('data.results.0.slug', 'ajouter-un-livreur');
        $this->shop('GET', 'help?q='.urlencode('mot de passe oublie'))->assertJsonPath('data.results.0.slug', 'mot-de-passe-oublie');
        $this->assertSame(0, HelpMiss::count());

        // Sans réponse : la recherche est notée pour la console, une seule ligne par question
        $this->shop('GET', 'help?q='.urlencode('Facture normalisée DGI'))->assertOk()->assertJsonPath('data.results', []);
        $this->shop('GET', 'help?q='.urlencode('facture  normalisée dgi'))->assertOk();
        $this->assertSame(2, HelpMiss::where('query', 'facture normalisée dgi')->value('hits'));

        $show = $this->shop('GET', 'help/ajouter-un-produit')->assertOk()
            ->assertJsonPath('data.video.file', true)->assertJsonPath('data.screen', '/gestion/catalogue/nouveau')->assertJsonPath('data.my_vote', null);
        // Vidéo d'une étape : sa dernière version, même si la fiche a été créée avant qu'elle soit refaite
        HelpArticle::where('slug', 'ajouter-un-produit')->update(['video_url' => '/videos/etapes/etape-06.mp4?v=1']);
        $this->shop('GET', 'help/ajouter-un-produit')->assertJsonPath('data.video.url', StartSteps::all()[5]['video'])->assertJsonPath('data.video.seconds', StartSteps::all()[5]['seconds']);
        $this->assertNotEmpty($show->json('data.steps'));
        $this->assertSame(2, HelpArticle::where('slug', 'ajouter-un-produit')->value('views'));

        $this->shop('POST', 'help/ajouter-un-produit/vote', ['helpful' => false])->assertOk();
        $this->shop('POST', 'help/ajouter-un-produit/vote', ['helpful' => true])->assertOk()->assertJsonPath('data.my_vote', true);
        $this->shop('GET', 'help/ajouter-un-produit')->assertJsonPath('data.my_vote', true);
        $this->assertDatabaseCount('help_feedback', 1);

        // Fiche masquée : ni dans la liste, ni dans la recherche, ni lisible
        HelpArticle::where('slug', 'ajouter-un-produit')->update(['is_published' => false]);
        $this->shop('GET', 'help/ajouter-un-produit')->assertNotFound();
        $this->assertNotContains('ajouter-un-produit', array_column($this->shop('GET', 'help')->json('data.articles'), 'slug'));
    }

    public function test_console_writes_articles_with_images_and_video(): void
    {
        $this->shop('GET', 'help')->assertOk();
        $this->storeApi($this->store, 'GET', 'console/help')->assertNotFound();

        $this->console('POST', 'help', ['title' => 'Comment imprimer un reçu ?', 'category' => 'inconnue', 'summary' => 'Ouvre la commande.', 'is_published' => true])->assertStatus(422);
        $this->console('POST', 'help', ['title' => 'Comment imprimer un reçu ?', 'category' => 'commandes', 'summary' => 'Ouvre la commande.', 'is_published' => true, 'screen' => 'https://ailleurs.example'])->assertStatus(422);
        $res = $this->console('POST', 'help', ['title' => 'Comment imprimer un reçu ?', 'category' => 'commandes', 'summary' => 'Ouvre la commande et touche « Reçu ».',
            'steps' => ['Ouvre la commande', 'Touche « Reçu »'], 'keywords' => 'ticket facture', 'screen' => '/gestion/commandes', 'is_published' => true])->assertOk();
        $article = HelpArticle::where('slug', 'comment-imprimer-un-recu')->firstOrFail();
        $this->assertSame(1, AdminAction::where('action', 'help_article_added')->count());
        $this->assertContains($article->id, array_column($res->json('data.articles'), 'id'));
        // Même question : une autre adresse
        $this->console('POST', 'help', ['title' => 'Comment imprimer un reçu ?', 'category' => 'commandes', 'summary' => 'Autre.', 'is_published' => false])->assertOk();
        $this->assertTrue(HelpArticle::where('slug', 'comment-imprimer-un-recu-2')->exists());

        // Les mots-clés font trouver la fiche
        $this->shop('GET', 'help?q=ticket')->assertJsonPath('data.results.0.slug', 'comment-imprimer-un-recu');

        $this->console('POST', "help/{$article->id}/image", ['image' => UploadedFile::fake()->image('ecran.png', 390, 844)])->assertOk();
        $this->console('POST', "help/{$article->id}/image", ['image' => UploadedFile::fake()->image('ecran2.png', 390, 844)])->assertOk();
        $this->console('POST', "help/{$article->id}/video", ['video' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')])->assertStatus(422);
        $this->console('POST', "help/{$article->id}/video", ['video' => UploadedFile::fake()->create('recu.mp4', 500, 'video/mp4')])->assertOk();
        $article->refresh();
        $this->assertCount(2, $article->images);
        Storage::disk(HelpArticle::disk())->assertExists([...$article->images, $article->video_path]);
        $this->shop('GET', 'help/comment-imprimer-un-recu')->assertJsonCount(2, 'data.images')->assertJsonPath('data.video.file', true);

        $first = $article->images[0];
        $this->console('DELETE', "help/{$article->id}/images/0")->assertOk();
        Storage::disk(HelpArticle::disk())->assertMissing($first);
        $this->assertCount(1, $article->fresh()->images);

        $this->console('PUT', "help/{$article->id}", ['title' => 'Comment imprimer un reçu ?', 'category' => 'commandes', 'summary' => 'Nouvelle réponse.', 'is_published' => false])->assertOk();
        $this->assertSame('comment-imprimer-un-recu', $article->fresh()->slug);
        $this->shop('GET', 'help/comment-imprimer-un-recu')->assertNotFound();

        // Recherche sans réponse retirée, fiche supprimée avec ses fichiers
        $this->shop('GET', 'help?q='.urlencode('carte bancaire visa'));
        $this->console('DELETE', 'help/misses/'.HelpMiss::value('id'))->assertOk()->assertJsonPath('data.misses', []);
        $files = [...$article->fresh()->images, $article->video_path];
        $this->console('DELETE', "help/{$article->id}")->assertOk();
        Storage::disk(HelpArticle::disk())->assertMissing($files);
        $this->assertNull(HelpArticle::find($article->id));
    }
}
