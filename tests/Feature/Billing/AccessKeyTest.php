<?php

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\AccessKey;
use App\Models\AdminAction;
use App\Models\Store;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Billing\AccessKeys;
use App\Services\Billing\Billing;
use App\Services\Catalog\CatalogWriter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Clés d'accès en cartes à QR code : lots créés dans la console, cartes à imprimer, page publique de la clé,
 * activation (à l'inscription ou dans Réglages › Abonnement), carte fondateur en pause sans commande, clé à durée,
 * annulation.
 */
class AccessKeyTest extends TestCase
{
    private const CONSOLE = 'http://admin.localhost';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['phone' => '+2250701020304']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
    }

    private function console(string $method, string $path, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['console_admin' => $this->admin->id, 'console_since' => now()->timestamp])
            ->apiOn(self::CONSOLE, $method, 'console/'.$path, $data);
    }

    private function billing(Store $store): TestResponse
    {
        app()->forgetScopedInstances();
        $this->actingAs($store->owner);

        return $this->storeApi($store, 'GET', 'billing')->assertOk();
    }

    private function redeem(Store $store, string $code): TestResponse
    {
        app()->forgetScopedInstances();
        $this->actingAs($store->owner);

        return $this->storeApi($store, 'POST', 'billing/access-key', ['code' => $code]);
    }

    /**
     * @return list<string> codes du lot, dans l'ordre des numéros
     */
    private function batch(array $data): array
    {
        $id = $this->console('POST', 'access-keys', $data + ['distributor_name' => 'Boutique Mariam', 'distributor_phone' => '0505050505'])->assertCreated()->json('data.id');

        return AccessKey::where('batch_id', $id)->orderBy('number')->pluck('code')->all();
    }

    private function order(Store $store): void
    {
        $robe = app(CatalogWriter::class)->save($store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 10]]]);
        $this->actingAs($store->owner);
        $this->storeApi($store, 'POST', 'orders', [
            'source' => 'whatsapp', 'customer_phone' => '+2250506070809', 'customer_name' => 'Fatou', 'delivery_method' => 'pickup',
            'items' => [['variant_id' => $robe->variants[0]->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    public function test_founder_cards_are_created_printed_and_activated_once(): void
    {
        $this->console('POST', 'access-keys', ['kind' => 'timed', 'plan' => 'starter', 'quantity' => 2])->assertStatus(422)->assertJsonValidationErrors('months');
        $this->console('POST', 'access-keys', ['kind' => 'founder', 'plan' => 'free', 'quantity' => 2])->assertStatus(422)->assertJsonValidationErrors('plan');
        $codes = $this->batch(['kind' => 'founder', 'plan' => 'pro', 'quantity' => 3]);
        $this->assertCount(3, array_unique($codes));
        $this->assertMatchesRegularExpression('/^['.AccessKeys::ALPHABET.']{8}$/', $codes[0]);

        $this->console('GET', 'access-keys')->assertOk()->assertJsonPath('data.batches.0.counts.available', 3)
            ->assertJsonPath('data.batches.0.distributor_phone', '+2250505050505');
        $pdf = $this->console('GET', 'access-keys/'.AccessKey::value('batch_id').'/cards')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        // Page publique du QR code : sans rien sur la boutique
        $display = substr($codes[1], 0, 4).'-'.substr($codes[1], 4);
        $this->platformApi('GET', 'access-keys/'.strtolower($display))->assertOk()
            ->assertJsonPath('data.badge', 'Membre Premium Jibiat Marchand n° 002 / 3')->assertJsonPath('data.plan', 'Pro')->assertJsonPath('data.available', true);
        $this->platformApi('GET', 'access-keys/AAAA-BBBB')->assertNotFound();

        // Activation dans Réglages › Abonnement (boutique à l'offre gratuite)
        $awa = $this->createStore(['slug' => 'awa'], plan: null);
        $this->redeem($awa, 'ZZZZ-ZZZZ')->assertStatus(422)->assertJsonValidationErrors('code');
        $this->redeem($awa, strtolower($display))->assertOk()->assertJsonPath('data.badge', 'Membre Premium Jibiat Marchand n° 002')->assertJsonPath('data.state', 'active');
        $this->billing($awa)->assertJsonPath('data.plan.code', 'pro')->assertJsonPath('data.free.forever', true)->assertJsonPath('data.free.from', 'key')
            ->assertJsonPath('data.access_key.founder', true)->assertJsonPath('data.usage.ai_actions_per_month.limit', 0);
        $this->storeApi($awa, 'GET', 'auth/me')->assertJsonPath('data.store.access_key.badge', 'Membre Premium Jibiat Marchand n° 002');

        // Une seule fois, une seule carte fondateur par boutique
        $this->platformApi('GET', 'access-keys/'.$codes[1])->assertJsonPath('data.available', false);
        $this->redeem($awa, $codes[1])->assertStatus(422)->assertJsonPath('errors.code.0', 'Cette clé est déjà activée sur votre espace marchand.');
        $this->redeem($awa, $codes[0])->assertStatus(422)->assertJsonPath('errors.code.0', 'Votre espace marchand a déjà sa carte Membre Premium.');
        $koffi = $this->createStore(['slug' => 'koffi'], plan: null);
        $this->redeem($koffi, $codes[1])->assertStatus(422)->assertJsonPath('errors.code.0', 'Cette clé a déjà été utilisée.');

        // Annulation depuis la console : retour à l'offre gratuite
        $key = AccessKey::where('code', $codes[1])->sole();
        $this->console('POST', "access-keys/keys/{$key->id}/revoke")->assertOk()->assertJsonPath('data.counts.revoked', 1)->assertJsonPath('data.keys.1.state', 'revoked');
        $this->billing($awa)->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.access_key', null);
        $this->platformApi('GET', 'access-keys/'.$codes[1])->assertNotFound();
        $this->assertSame(['access_keys_created', 'access_keys_printed', 'access_key_revoked'], AdminAction::orderBy('id')->pluck('action')->all());
    }

    public function test_a_founder_card_pauses_without_orders_and_comes_back_with_the_next_one(): void
    {
        [$code] = $this->batch(['kind' => 'founder', 'plan' => 'pro', 'quantity' => 1]);
        // Pendant l'essai Pro : la carte prend le relais tout de suite
        $awa = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->redeem($awa, $code)->assertOk();
        $this->assertSame(SubscriptionStatus::Expired, $awa->subscriptions()->first()->status);
        $this->billing($awa)->assertJsonPath('data.plan.code', 'pro')->assertJsonPath('data.access_key.pause_on', now()->addDays(60)->toDateString());

        // Prévenu 15 jours avant la pause, puis en pause
        $this->travel(46)->days();
        app()->forgetScopedInstances();
        $this->assertSame(['warned' => 1, 'paused' => 0, 'ended' => 0, 'discount' => 0], app(Billing::class)->run()['keys']);
        $this->travel(15)->days();
        app()->forgetScopedInstances();
        $this->assertSame(['warned' => 0, 'paused' => 1, 'ended' => 0, 'discount' => 0], app(Billing::class)->run()['keys']);
        $this->billing($awa)->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.free', null)->assertJsonPath('data.access_key.state', 'paused');
        Notification::assertSentToTimes($awa->owner, BillingNotice::class, 2);

        // Une commande la rallume tout de suite ; le prochain avertissement repart de cette commande
        $this->order($awa);
        $this->billing($awa)->assertJsonPath('data.plan.code', 'pro')->assertJsonPath('data.access_key.state', 'active');
        app()->forgetScopedInstances();
        app(Billing::class)->run();
        $key = AccessKey::sole();
        $this->assertNull($key->paused_at);
        $this->assertNull($key->warned_at);
    }

    public function test_a_timed_key_at_signup_then_its_end(): void
    {
        $codes = $this->batch(['kind' => 'timed', 'plan' => 'starter', 'months' => 6, 'quantity' => 2]);
        [$founder] = $this->batch(['kind' => 'founder', 'plan' => 'pro', 'quantity' => 1]);
        $this->platformApi('GET', 'access-keys/'.$codes[0])->assertJsonPath('data.badge', null)->assertJsonPath('data.months', 6);

        // Inscription avec la clé (compte existant : boutique créée tout de suite)
        User::factory()->create(['phone' => '+2250708091011']);
        $signup = ['store_name' => 'Awa Mode', 'slug' => 'awa-mode', 'name' => 'Awa', 'phone' => '0708091011', 'password' => 'password'];
        $this->platformApi('POST', 'signup', $signup + ['key' => 'ABCD-EFGH'])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->platformApi('POST', 'signup', $signup + ['key' => $codes[0]])->assertCreated();
        $awa = Store::where('slug', 'awa-mode')->sole();

        // L'essai Pro (plus élevé) continue ; à sa fin, la clé Starter prend le relais jusqu'à sa date
        $this->billing($awa)->assertJsonPath('data.plan.code', 'pro')->assertJsonPath('data.subscription.status', 'trialing');
        $this->travel(31)->days();
        app()->forgetScopedInstances();
        app(Billing::class)->run();
        $until = AccessKey::where('code', $codes[0])->value('expires_at');
        $this->billing($awa)->assertJsonPath('data.plan.code', 'starter')->assertJsonPath('data.free.until', $until->toDateString())->assertJsonPath('data.free.forever', false);

        // Une seule clé à durée par boutique ; une carte fondateur peut la remplacer (essayée sur une autre boutique)
        $this->redeem($awa, $codes[1])->assertStatus(422)->assertJsonPath('errors.code.0', 'Votre espace marchand a déjà utilisé une clé à durée : une seule par boutique.');

        // Prévenu 7 jours avant la fin, puis retour à l'offre gratuite
        $this->travelTo($until->copy()->subDays(6));
        app()->forgetScopedInstances();
        $this->assertSame(1, app(Billing::class)->run()['keys']['warned']);
        $this->travelTo($until->copy()->addDay());
        app()->forgetScopedInstances();
        $this->assertSame(1, app(Billing::class)->run()['keys']['ended']);
        $this->billing($awa)->assertJsonPath('data.plan.code', 'free')->assertJsonPath('data.access_key.state', 'ended');

        $this->redeem($awa, $founder)->assertOk()->assertJsonPath('data.founder', true);
        $this->billing($awa)->assertJsonPath('data.plan.code', 'pro');
    }
}
