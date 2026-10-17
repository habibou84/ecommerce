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
        $this->storeApi($this->store, 'GET', 'auth/me')->assertJsonPath('data.ai', ['enabled' => true, 'used' => 1, 'limit' => 300]);
        $this->storeApi($this->store, 'GET', 'products')->assertJsonPath('meta.total', 0);
    }

    public function test_the_monthly_quota_of_the_plan(): void
    {
        $plan = Plan::where('code', 'pro')->first();
        $plan->update(['limits' => ['ai_actions_per_month' => 1] + $plan->limits]);
        $this->ai->respond(['title' => 'Sac', 'description' => '', 'brand' => null, 'options' => [], 'tags' => [], 'collections' => [], 'seo_title' => '', 'seo_description' => '', 'to_check' => null]);

        $this->draft()->assertOk();
        $this->draft()->assertStatus(422)->assertJsonPath('message', "Les 1 actions de l'assistant IA de l'offre Pro sont utilisées ce mois-ci. Passez à une offre supérieure pour continuer.");
        $this->assertCount(1, $this->ai->calls);

        // Offre sans quota d'IA : non comprise
        $plan->update(['limits' => array_diff_key($plan->limits, ['ai_actions_per_month' => 0])]);
        $this->draft()->assertStatus(422)->assertJsonPath('message', "L'assistant IA n'est pas compris dans l'offre Pro. Changez d'offre dans Réglages › Abonnement.");
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
