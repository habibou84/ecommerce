<?php

namespace Tests\Feature\Messaging;

use Tests\TestCase;

/**
 * Connexion WhatsApp depuis l'application installée : la fenêtre Facebook (page de la plateforme) dépose son
 * résultat sous la clé de l'application, qui le récupère une seule fois.
 */
class WhatsAppConnectSessionTest extends TestCase
{
    public function test_the_facebook_window_hands_the_result_back_to_the_app(): void
    {
        $store = $this->createStore(['slug' => 'awa']);
        $other = $this->createStore(['slug' => 'binta']);
        $key = str_repeat('a1', 24);

        // Clé inconnue : rien n'est accepté
        $this->platformApi('POST', 'whatsapp/facebook/result', ['key' => $key, 'result' => ['code' => 'X']])->assertNotFound();

        $this->actingAs($store->owner);
        $browser = $this->storeApi($store, 'POST', 'whatsapp/connect-session', ['key' => $key])->assertCreated()->getCookie('wa_connect', false);
        // Cookie du navigateur qui lance la connexion, valable sur tout le domaine (plateforme comprise), illisible en JS
        $this->assertNotNull($browser);
        $this->assertSame('.localhost', $browser->getDomain());
        $this->assertTrue($browser->isHttpOnly());
        $this->storeApi($store, 'GET', "whatsapp/connect-session/{$key}")->assertOk()->assertJsonPath('data.result', null);

        // Depuis un autre navigateur (sans le cookie, ou avec un faux) : refusé
        $this->platformApi('POST', 'whatsapp/facebook/result', ['key' => $key, 'result' => ['code' => 'X']])->assertNotFound();
        $this->withCredentials()->withUnencryptedCookie('wa_connect', 'faux')->platformApi('POST', 'whatsapp/facebook/result', ['key' => $key, 'result' => ['code' => 'X']])->assertNotFound();

        $this->withUnencryptedCookie('wa_connect', $browser->getValue());
        $this->platformApi('POST', 'whatsapp/facebook/result', ['key' => $key, 'result' => ['code' => 'AQB-code', 'coexistence' => '0', 'pirate' => 'x']])->assertOk();
        // Déposé une seule fois
        $this->platformApi('POST', 'whatsapp/facebook/result', ['key' => $key, 'result' => ['code' => 'autre']])->assertNotFound();

        // Une autre boutique ne le voit pas ; la boutique le reçoit une fois
        $this->actingAs($other->owner);
        $this->storeApi($other, 'GET', "whatsapp/connect-session/{$key}")->assertNotFound();
        $this->actingAs($store->owner);
        $this->storeApi($store, 'GET', "whatsapp/connect-session/{$key}")->assertOk()->assertExactJson(['data' => ['result' => ['code' => 'AQB-code', 'coexistence' => '0']]]);
        $this->storeApi($store, 'GET', "whatsapp/connect-session/{$key}")->assertNotFound();
    }

    public function test_a_link_made_by_another_merchant_cannot_take_the_whatsapp_of_a_store(): void
    {
        $attacker = $this->createStore(['slug' => 'koffi', 'name' => 'Koffi Shop']);
        $victim = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        config(['services.meta.whatsapp_app_id' => '111', 'services.meta.whatsapp_config_id' => '222']);
        $key = str_repeat('b2', 24);
        $this->actingAs($attacker->owner);
        $this->storeApi($attacker, 'POST', 'whatsapp/connect-session', ['key' => $key])->assertCreated();
        $this->logout();

        // Lien de l'attaquant (sa clé, sa boutique) suivi par la victime jusqu'au bout chez Facebook : le code arrive dans
        // le navigateur de la victime, qui n'a pas le cookie de l'attaquant ; il n'est pas déposé pour l'attaquant
        $this->platformApi('POST', 'whatsapp/facebook/result', ['key' => $key, 'result' => ['code' => 'code-de-la-victime']])->assertNotFound();
        $this->actingAs($attacker->owner);
        $this->storeApi($attacker, 'GET', "whatsapp/connect-session/{$key}")->assertOk()->assertJsonPath('data.result', null);
        $this->logout();

        // Le lien envoyé à la victime avec la clé de l'attaquant et l'adresse de la victime : refusé
        $this->platformApi('GET', 'whatsapp/facebook/destination?'.http_build_query(['retour' => $this->storeUrl($victim, '/gestion/whatsapp'), 'cle' => $key]))->assertNotFound();
        // Avec son adresse à lui : la page affiche en grand « Koffi Shop », pas la boutique de la victime
        $this->platformApi('GET', 'whatsapp/facebook/destination?'.http_build_query(['retour' => $this->storeUrl($attacker, '/gestion/whatsapp'), 'cle' => $key]))
            ->assertOk()->assertJsonPath('data.name', 'Koffi Shop')->assertJsonPath('data.app_id', '111')->assertJsonPath('data.config_id', '222');
        // Retour vers la boutique en ligne (scripts publicitaires) ou un site extérieur : refusé
        $this->platformApi('GET', 'whatsapp/facebook/destination?'.http_build_query(['retour' => $this->storeUrl($victim, '/')]))->assertNotFound();
        $this->platformApi('GET', 'whatsapp/facebook/destination?'.http_build_query(['retour' => 'https://evil.example/gestion']))->assertNotFound();
        // Sans clé (navigateur) : l'espace de la victime est affiché tel quel
        $this->platformApi('GET', 'whatsapp/facebook/destination?'.http_build_query(['retour' => $this->storeUrl($victim, '/gestion/whatsapp')]))
            ->assertOk()->assertJsonPath('data.name', 'Awa Mode');
    }
}
