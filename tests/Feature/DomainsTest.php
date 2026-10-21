<?php

namespace Tests\Feature;

use App\Models\StoreDomain;
use App\Models\Subscription;
use App\Services\Domains\DnsResolver;
use App\Support\Tenancy;
use Tests\TestCase;

class DomainsTest extends TestCase
{
    /** @var array<string, array<string, list<string>>> */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.custom_domains' => ['cname_target' => 'domaines.localhost', 'ips' => ['203.0.113.10']]]);
        $this->app->instance(DnsResolver::class, new class($this) extends DnsResolver
        {
            public function __construct(private readonly DomainsTest $test) {}

            public function txt(string $host): array
            {
                return $this->test->dns('txt', $host);
            }

            public function cname(string $host): array
            {
                return $this->test->dns('cname', $host);
            }

            public function a(string $host): array
            {
                return $this->test->dns('a', $host);
            }
        });
    }

    public function dns(string $type, string $host): array
    {
        return $this->records[$type][$host] ?? [];
    }

    public function test_adding_verifying_and_serving_a_custom_domain(): void
    {
        $store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode', 'storefront_enabled' => true]);
        $this->actingAs($store->owner);

        $this->storeApi($store, 'POST', 'domains', ['hostname' => 'awa.localhost'])->assertStatus(422)->assertJsonValidationErrors('hostname');
        $this->storeApi($store, 'POST', 'domains', ['hostname' => 'pas un domaine'])->assertStatus(422);
        $created = $this->storeApi($store, 'POST', 'domains', ['hostname' => 'https://WWW.AwaMode.ci/'])->assertCreated()
            ->assertJsonPath('data.hostname', 'www.awamode.ci')->assertJsonPath('data.is_primary', true)->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.records.1', ['type' => 'CNAME', 'name' => 'www.awamode.ci', 'value' => 'domaines.localhost', 'purpose' => 'Envoie les visiteurs vers votre boutique']);
        $token = $created->json('data.records.0.value');
        $id = $created->json('data.id');

        // Pas encore vérifié : le domaine ne sert rien, Caddy refuse le certificat
        $this->storeApi($store, 'POST', "domains/{$id}/verify")->assertJsonPath('data.verified', false)->assertJsonPath('data.last_error', 'Enregistrement TXT de vérification introuvable (la propagation DNS peut prendre jusqu\'à quelques heures).');
        $this->get('http://www.awamode.ci/')->assertNotFound();
        $this->get('http://localhost/_caddy/ask?domain=www.awamode.ci', ['REMOTE_ADDR' => '127.0.0.1'])->assertNotFound();

        $this->records = ['txt' => ['_jibiat.www.awamode.ci' => [$token]]];
        $this->storeApi($store, 'POST', "domains/{$id}/verify")->assertJsonPath('data.last_error', 'Le domaine ne pointe pas encore vers Jibiat Marchand (enregistrement CNAME ou A).');

        $this->records['cname'] = ['www.awamode.ci' => ['domaines.localhost']];
        $this->storeApi($store, 'POST', "domains/{$id}/verify")->assertJsonPath('data.verified', true)->assertJsonPath('data.last_error', null);

        // Le site est servi sur le domaine, la gestion reste sur le sous-domaine, le lien canonique pointe sur le domaine
        $this->get('http://www.awamode.ci/')->assertOk()->assertSee('<link rel="canonical" href="https://www.awamode.ci/">', false);
        $this->get('http://www.awamode.ci/gestion')->assertRedirect($this->storeUrl($store, '/gestion'));
        $this->call('GET', 'http://localhost/_caddy/ask', ['domain' => 'www.awamode.ci'], [], [], ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();
        $this->call('GET', 'http://localhost/_caddy/ask', ['domain' => 'autre.ci'], [], [], ['REMOTE_ADDR' => '127.0.0.1'])->assertNotFound();
        // Les noms de la plateforme ont leur certificat wildcard : aucun certificat à la demande (noms inventés compris)
        $this->call('GET', 'http://localhost/_caddy/ask', ['domain' => 'a.b.'.Tenancy::domain()], [], [], ['REMOTE_ADDR' => '127.0.0.1'])->assertNotFound();
        $this->call('GET', 'http://localhost/_caddy/ask', ['domain' => 'www.awamode.ci'], [], [], ['REMOTE_ADDR' => '198.51.100.7'])->assertForbidden();

        // Domaine nu : enregistrement A ; un domaine déjà pris est refusé
        $this->storeApi($store, 'POST', 'domains', ['hostname' => 'awamode.ci'])->assertCreated()->assertJsonPath('data.records.1.type', 'A')->assertJsonPath('data.is_primary', false);
        $other = $this->createStore(['slug' => 'koffi']);
        $this->actingAs($other->owner);
        $this->storeApi($other, 'POST', 'domains', ['hostname' => 'www.awamode.ci'])->assertStatus(422)->assertJsonPath('message', 'Ce domaine est déjà déclaré par une boutique.');
        // Un domaine déclaré mais jamais vérifié n'est retenu que 48 h
        $this->storeApi($other, 'POST', 'domains', ['hostname' => 'awamode.ci'])->assertStatus(422);
        $this->travel(49)->hours();
        $this->storeApi($other, 'POST', 'domains', ['hostname' => 'awamode.ci'])->assertCreated();
        $this->storeApi($other, 'POST', 'domains', ['hostname' => 'www.awamode.ci'])->assertStatus(422);

        // Retrait du domaine principal : un autre domaine vérifié prend le relais (aucun ici)
        $this->actingAs($store->owner);
        $this->storeApi($store, 'DELETE', "domains/{$id}")->assertOk();
        $this->assertSame(0, StoreDomain::where('store_id', $store->id)->count());
    }

    public function test_custom_domain_needs_the_pro_plan(): void
    {
        $store = $this->createStore(plan: 'starter');
        $this->actingAs($store->owner);
        $this->storeApi($store, 'GET', 'domains')->assertJsonPath('available', false);
        $this->storeApi($store, 'POST', 'domains', ['hostname' => 'www.boutique.ci'])->assertStatus(422);
        $this->assertSame(1, Subscription::count());
    }
}
