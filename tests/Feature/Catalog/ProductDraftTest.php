<?php

namespace Tests\Feature\Catalog;

use App\Models\AiUsage;
use App\Models\Plan;
use App\Models\Store;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class ProductDraftTest extends TestCase
{
    private Store $store;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore(['name' => 'Awa Mode']);
        // L'assistant IA n'est compris dans aucune offre : actions rechargées
        $this->store->forceFill(['ai_credits' => 100])->save();
        $this->actingAs($this->store->owner);
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    private function draft(array $extra = [])
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Referer' => $this->storeUrl($this->store)])->post($this->storeUrl($this->store, 'api/v1/products/draft'), [
            'photos' => [UploadedFile::fake()->image('robe.jpg', 2400, 1800)],
        ] + $extra);
    }

    public function test_a_product_sheet_is_proposed_from_a_photo(): void
    {
        $summer = $this->storeApi($this->store, 'POST', 'collections', ['title' => 'Été'])->json('data.id');
        $this->ai->respond([
            'title' => 'Robe wax évasée', 'description' => 'Robe en tissu wax, coupe évasée.', 'brand' => null,
            'options' => [['name' => 'Taille', 'values' => ['S', 'M', 'M', ' L ']], ['name' => '', 'values' => ['x']]],
            'tags' => ['wax', 'robe'], 'collections' => ['été', 'Inconnue'],
            'seo_title' => 'Robe wax évasée', 'seo_description' => 'Robe wax pour toutes les occasions.',
            'to_check' => 'Vérifiez les tailles disponibles.',
        ]);

        $this->draft(['note' => 'Coton, tailles S à L'])->assertOk()
            ->assertJsonPath('data.title', 'Robe wax évasée')
            ->assertJsonPath('data.options', [['name' => 'Taille', 'values' => ['S', 'M', 'L']]])
            ->assertJsonPath('data.collection_ids', [$summer])
            ->assertJsonPath('data.to_check', 'Vérifiez les tailles disponibles.');

        // Photo réduite envoyée en JPEG, avec les collections de la boutique et la note du marchand
        $image = $this->ai->calls[0]['content'][0];
        $this->assertSame(['image', 'image/jpeg'], [$image['type'], $image['source']['mediaType']]);
        $this->assertLessThanOrEqual(1200, getimagesizefromstring(base64_decode($image['source']['data']))[0]);
        $this->assertStringContainsString('Collections de la boutique : Été', $this->ai->lastText());
        $this->assertStringContainsString('Note du commerçant : Coton, tailles S à L', $this->ai->lastText());

        // Action comptée, consommation notée ; rien n'est créé
        $this->assertSame([1, 1200, 300], [AiUsage::forStore($this->store->id)->count(), AiUsage::forStore($this->store->id)->value('input_tokens'), AiUsage::forStore($this->store->id)->value('output_tokens')]);
        $this->storeApi($this->store, 'GET', 'auth/me')->assertJsonPath('data.ai', ['enabled' => true, 'used' => 1, 'limit' => 300, 'credits' => 100, 'pack' => ['actions' => 100, 'price' => 1000]]);
        $this->storeApi($this->store, 'GET', 'products')->assertJsonPath('meta.total', 0);
    }

    public function test_the_monthly_quota_of_the_plan(): void
    {
        // Chaque offre comprend des actions par mois (réglables dans la console)
        $this->assertSame([10, 50, 300, 1000], Plan::orderBy('sort')->get()->map(fn (Plan $p) => $p->limits['ai_actions_per_month'])->all());
        $this->store->forceFill(['ai_credits' => 0])->save();
        $plan = Plan::where('code', 'pro')->first();
        $plan->update(['limits' => ['ai_actions_per_month' => 1] + $plan->limits]);
        $sheet = ['title' => 'Sac', 'description' => '', 'brand' => null, 'options' => [], 'tags' => [], 'collections' => [], 'seo_title' => '', 'seo_description' => '', 'to_check' => null];
        $this->ai->respond($sheet);

        // Plus rien : message rouge et état de l'assistant pour ouvrir la fenêtre de recharge
        $this->draft()->assertOk();
        $this->draft()->assertStatus(422)
            ->assertJsonPath('message', "Vos 1 actions de l'assistant IA du mois (offre Pro) sont utilisées. Rechargez 100 actions pour 1 000 F pour continuer.")
            ->assertJsonPath('code', 'ai_quota')
            ->assertJsonPath('ai', ['enabled' => true, 'used' => 1, 'limit' => 1, 'credits' => 0, 'pack' => ['actions' => 100, 'price' => 1000]]);
        $this->assertCount(1, $this->ai->calls);

        // Actions achetées : utilisées une à une après le quota de l'offre
        $this->store->forceFill(['ai_credits' => 2])->save();
        $this->ai->respond($sheet);
        $this->ai->respond($sheet);
        $this->draft()->assertOk();
        $this->assertSame(1, $this->store->fresh()->ai_credits);
        $this->draft()->assertOk();
        $this->draft()->assertStatus(422)->assertJsonPath('code', 'ai_quota');
        $this->assertSame(0, $this->store->fresh()->ai_credits);
        $this->assertCount(3, $this->ai->calls);

        // Offre sans actions comprises (réglée à 0 dans la console) : l'assistant marche avec les recharges
        $plan->update(['limits' => ['ai_actions_per_month' => 0] + $plan->limits]);
        $this->draft()->assertStatus(422)->assertJsonPath('message', "L'assistant IA de l'offre Pro marche avec des recharges. Rechargez 100 actions pour 1 000 F pour continuer.");
    }

    public function test_failures_are_explained_and_not_counted(): void
    {
        $this->ai->respond(new AiUnavailable('L\'assistant est momentanément indisponible. Réessayez dans un instant.'));
        $this->draft()->assertStatus(422)->assertJsonPath('message', 'L\'assistant est momentanément indisponible. Réessayez dans un instant.');
        $this->storeApi($this->store, 'GET', 'auth/me')->assertJsonPath('data.ai.used', 0);
        $this->assertFalse(AiUsage::forStore($this->store->id)->sole()->succeeded);

        // Plateforme sans clé : assistant désactivé
        $this->app->instance(AiClient::class, new FakeAiClient(configured: false));
        $this->draft()->assertStatus(422)->assertJsonPath('message', 'L\'assistant IA n\'est pas encore activé sur la plateforme.');
    }
}
