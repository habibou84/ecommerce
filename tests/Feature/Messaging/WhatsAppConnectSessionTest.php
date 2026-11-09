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
        $this->storeApi($store, 'POST', 'whatsapp/connect-session', ['key' => $key])->assertCreated();
        $this->storeApi($store, 'GET', "whatsapp/connect-session/{$key}")->assertOk()->assertJsonPath('data.result', null);

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
}
