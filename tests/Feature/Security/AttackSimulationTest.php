<?php

namespace Tests\Feature\Security;

use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsappLink;
use App\Services\Accounts\Sessions;
use App\Services\Catalog\ImageStore;
use App\Services\WhatsApp\Inbox;
use App\Support\MailText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

    public function test_a_decompression_bomb_is_refused_before_being_opened(): void
    {
        // En-tête PNG d'une image de 20 000 × 20 000 points (quelques octets, plus d'1 Go une fois ouverte)
        $ihdr = 'IHDR'.pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
        $this->expectException(BusinessRuleException::class);
        ImageStore::ensureReasonable($png);
    }

    public function test_a_store_name_cannot_become_a_button_in_an_email(): void
    {
        // « Espace marchand : [Vérifier et confirmer](https://pirate…) » dans l'e-mail du validateur : reste du texte
        $this->assertSame('\\[Vérifier\\]\\(https://pirate.example/v\\)', MailText::plain('[Vérifier](https://pirate.example/v)'));
    }

    public function test_an_unknown_visitor_cannot_use_up_the_attempts_of_everyone(): void
    {
        // Avant : toutes les requêtes sans numéro (changer son mot de passe, accepter une invitation, actions de la
        // console…) partageaient un seul compteur, qu'un inconnu épuisait en 30 requêtes par heure
        $keys = function (Request $request): array {
            return array_map(fn ($limit) => $limit->key, RateLimiter::limiter('login')($request));
        };
        $stranger = Request::create('/api/v1/invitations/xxx/accept', 'POST', server: ['REMOTE_ADDR' => '6.6.6.6']);
        $owner = Request::create('/api/v1/auth/password', 'PUT', server: ['REMOTE_ADDR' => '1.2.3.4']);
        $store = $this->createStore();
        $owner->setUserResolver(fn () => $store->owner);
        $this->assertEmpty(array_intersect($keys($stranger), $keys($owner)));
    }
}
