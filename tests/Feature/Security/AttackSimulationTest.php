<?php

namespace Tests\Feature\Security;

use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsappLink;
use App\Services\Accounts\Sessions;
use App\Services\WhatsApp\Inbox;
use Tests\TestCase;

/**
 * Attaques simulées (diagnostic de sécurité de novembre 2026) : chaque test rejoue une attaque et vérifie qu'elle
 * échoue. Le livreur pris par un autre commerçant est dans tests/Feature/Couriers/CourierAccessTest.
 */
class AttackSimulationTest extends TestCase
{
    public function test_a_closed_session_is_logged_out_even_outside_the_database(): void
    {
        // Session volée : fermée à distance (administrateur retiré, 2FA réinitialisée…), elle ne sert plus
        $store = $this->createStore();
        $this->actingAs($store->owner);
        $this->storeApi($store, 'GET', 'auth/me')->assertOk();

        Sessions::revoke(User::findOrFail($store->owner_id));
        $this->app['auth']->forgetGuards(); // requête suivante : compte relu dans la base
        $this->storeApi($store, 'GET', 'auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_the_team_cannot_read_or_answer_the_support_messages_of_the_owner(): void
    {
        // WhatsApp du propriétaire relié à la boîte Messages : le lien de connexion envoyé par le support n'y arrive pas
        $store = $this->createStore();
        config(['platform.support_whatsapp' => '+2250507016739']);
        $link = WhatsappLink::create(['store_id' => $store->id, 'status' => 'connected', 'phone' => $store->owner->phone, 'connected_at' => now()]);
        $inbox = app(Inbox::class);
        $this->assertNull($inbox->receiveLinked($link, ['id' => 'm1', 'from' => '2250507016739', 'text' => 'Voici ton lien de connexion : https://…']));
        $this->assertSame(0, Conversation::forStore($store->id)->count());

        // Un employé ne peut pas écrire « Inscription » au support depuis le téléphone du propriétaire
        $conversation = Conversation::forceCreate(['store_id' => $store->id, 'channel' => 'whatsapp', 'contact' => '+2250507016739']);
        $this->expectException(BusinessRuleException::class);
        $inbox->reply($conversation, 'Inscription', $this->addMember($store));
    }
}
