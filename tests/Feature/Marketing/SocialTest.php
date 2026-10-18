<?php

namespace Tests\Feature\Marketing;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Place;
use App\Models\Product;
use App\Models\ShortLink;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Store;
use App\Services\Ai\AiClient;
use App\Services\Catalog\CatalogWriter;
use App\Services\Orders\OrderDesk;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class SocialTest extends TestCase
{
    private Store $store;

    private Product $robe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.app_id' => '7777', 'services.meta.app_secret' => 'platform-secret',
            'services.tiktok.client_key' => 'tt-key', 'services.tiktok.client_secret' => 'tt-secret']);
        Storage::fake('public');
        $this->store = $this->createStore(['slug' => 'awa', 'name' => 'Awa Mode']);
        $this->store->forceFill(['storefront_enabled' => true])->save();
        $this->robe = app(CatalogWriter::class)->save($this->store, null, ['title' => 'Robe wax', 'variants' => [['price' => 15000, 'on_hand' => 5]]]);
        $this->actingAs($this->store->owner);
    }

    private function withPhoto(): void
    {
        $this->withCredentials()->withHeaders(['Referer' => $this->storeUrl($this->store, '/gestion')])
            ->post($this->storeUrl($this->store, "/api/v1/products/{$this->robe->id}/images"), ['image' => UploadedFile::fake()->image('a.jpg', 900, 1200)], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    private function connectAll(): void
    {
        SocialAccount::create(['store_id' => $this->store->id, 'network' => 'facebook', 'external_id' => 'PAGE1', 'name' => 'Awa Mode', 'access_token' => 'page-token']);
        SocialAccount::create(['store_id' => $this->store->id, 'network' => 'instagram', 'external_id' => 'IG1', 'name' => 'awamode', 'access_token' => 'page-token', 'page_id' => 'PAGE1']);
        SocialAccount::create(['store_id' => $this->store->id, 'network' => 'tiktok', 'external_id' => 'OPEN1', 'name' => 'Awa', 'access_token' => 'tt-token', 'token_expires_at' => now()->addDay()]);
    }

    private function fakeNetworks(bool $instagramFails = false): void
    {
        Http::fake([
            'graph.facebook.com/*/PAGE1/photos' => Http::response(['id' => 'PH1', 'post_id' => 'PAGE1_POST1']),
            'graph.facebook.com/*/IG1/media_publish' => Http::response(['id' => 'M1']),
            'graph.facebook.com/*/IG1/media' => $instagramFails ? Http::response(['error' => ['message' => 'Image refusée']], 400) : Http::response(['id' => 'C1']),
            'graph.facebook.com/*/M1*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['data' => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']], 'error' => ['code' => 'ok']]),
            'open.tiktokapis.com/v2/post/publish/content/init/' => Http::response(['data' => ['publish_id' => 'p_pub_1'], 'error' => ['code' => 'ok']]),
        ]);
    }

    private function metaWebhook(string $object, array $entries): TestResponse
    {
        $body = json_encode(['object' => $object, 'entry' => $entries]);

        return $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        ], $body);
    }

    public function test_facebook_page_and_instagram_are_connected_together(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()->push(['access_token' => 'short'])->push(['access_token' => 'long']),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => 'PAGE1', 'name' => 'Awa Mode', 'access_token' => 'page-token', 'instagram_business_account' => ['id' => 'IG1', 'username' => 'awamode']],
            ]]),
            'graph.facebook.com/*/PAGE1/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $url = $this->storeApi($this->store, 'POST', 'social/connect/meta')->assertOk()->json('url');
        $this->assertStringContainsString('facebook.com/', $url);
        $this->assertStringContainsString('redirect_uri='.rawurlencode('http://localhost/reseaux/meta/retour'), $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // Retour sur le domaine de la plateforme, puis la gestion de la boutique propose la page
        $back = $this->get('http://localhost/reseaux/meta/retour?code=abc&state='.rawurlencode($query['state']))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith($this->storeUrl($this->store, '/gestion/marketing/reseaux?pages='), $back);
        $key = explode('pages=', $back)[1];
        $this->storeApi($this->store, 'GET', "social/pages/{$key}")->assertOk()->assertJsonPath('data.0.instagram.name', 'awamode')->assertJsonMissingPath('data.0.access_token');
        $this->storeApi($this->store, 'POST', "social/pages/{$key}", ['page_id' => 'PAGE1'])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(['facebook', 'instagram'], SocialAccount::orderBy('network')->pluck('network')->all());
        $this->assertSame(['page-token', 'PAGE1'], [SocialAccount::where('network', 'instagram')->value('page_id') ? 'page-token' : null, SocialAccount::where('network', 'instagram')->value('page_id')]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'PAGE1/subscribed_apps'));
        $this->storeApi($this->store, 'GET', 'social')->assertJsonPath('accounts.0.connected', true)->assertJsonPath('accounts.1.name', 'awamode')->assertJsonPath('accounts.2.connected', false);

        // Un « state » d'une autre connexion est refusé
        $this->get('http://localhost/reseaux/tiktok/retour?code=abc&state='.rawurlencode($query['state']))->assertForbidden();
    }

    public function test_tiktok_is_connected(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => 'tt-token', 'refresh_token' => 'tt-refresh', 'expires_in' => 86400, 'open_id' => 'OPEN1']),
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => 'OPEN1', 'display_name' => 'Awa Mode CI']], 'error' => ['code' => 'ok']]),
        ]);
        $url = $this->storeApi($this->store, 'POST', 'social/connect/tiktok')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->get('http://localhost/reseaux/tiktok/retour?code=xyz&state='.rawurlencode($query['state']))
            ->assertRedirect($this->storeUrl($this->store, '/gestion/marketing/reseaux?connecte=tiktok'));

        $account = SocialAccount::sole();
        $this->assertSame(['tiktok', 'OPEN1', 'Awa Mode CI', 'tt-token', 'tt-refresh'], [$account->network, $account->external_id, $account->name, $account->access_token, $account->refresh_token]);
    }

    public function test_ai_proposes_three_captions(): void
    {
        $ai = (new FakeAiClient)->respond(['captions' => [
            ['text' => 'La robe wax qui fait tourner les têtes 😍 15 000 F. Commandez : {lien}', 'hashtags' => ['Abidjan', '#ModeIvoirienne']],
            ['text' => 'Mariage, baptême, sortie : elle va partout. {lien}', 'hashtags' => ['#Wax']],
            ['text' => 'Dernières pièces ! {lien}', 'hashtags' => []],
        ]]);
        $this->app->instance(AiClient::class, $ai);

        $this->storeApi($this->store, 'POST', "products/{$this->robe->id}/social/captions", ['tone' => 'nouchi'])->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('data.0.hashtags', ['#Abidjan', '#ModeIvoirienne']);
        $this->assertStringContainsString('15 000 F', $ai->lastText());
        $this->assertStringContainsString('Ton demandé : nouchi', $ai->lastText());
    }

    public function test_a_product_is_published_on_the_three_networks_with_a_link_per_network(): void
    {
        $this->withPhoto();
        $this->connectAll();
        $this->fakeNetworks();

        $post = $this->storeApi($this->store, 'POST', "products/{$this->robe->id}/social/posts", [
            'caption' => "Robe wax à 15 000 F 😍\nCommandez : {lien}", 'networks' => ['facebook', 'instagram', 'tiktok'],
        ])->assertCreated()->assertJsonPath('data.status', 'published')->json('data');
        $this->assertSame('https://www.facebook.com/PAGE1_POST1', $post['results']['facebook']['url']);
        $this->assertSame('https://www.instagram.com/p/abc/', $post['results']['instagram']['url']);
        $this->assertSame('p_pub_1', $post['results']['tiktok']['id']);

        // Un lien court par réseau, dans la légende de chaque réseau
        $links = ShortLink::where('social_post_id', $post['id'])->pluck('code', 'network');
        $this->assertCount(3, $links);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'PAGE1/photos') && str_contains($r['caption'], '/p/'.$links['facebook']) && ! str_contains($r['caption'], 'en message'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'IG1/media') && isset($r['caption']) && str_contains($r['caption'], '/p/'.$links['instagram']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'content/init') && $r['media_type'] === 'PHOTO' && $r['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE'
            && str_contains($r['post_info']['description'], '/p/'.$links['tiktok']) && str_ends_with($r['source_info']['photo_images'][0], '-vertical.jpg'));

        // Visuels carré 1080 × 1080 et vertical 1080 × 1920
        $saved = SocialPost::sole();
        $this->assertSame([1080, 1080], array_slice(getimagesizefromstring(Storage::disk('public')->get($saved->square_path)), 0, 2));
        $this->assertSame([1080, 1920], array_slice(getimagesizefromstring(Storage::disk('public')->get($saved->vertical_path)), 0, 2));
    }

    public function test_a_failed_network_does_not_stop_the_others_and_can_be_retried(): void
    {
        $this->withPhoto();
        $this->connectAll();
        $this->fakeNetworks(instagramFails: true);

        $post = $this->storeApi($this->store, 'POST', "products/{$this->robe->id}/social/posts", ['caption' => 'Robe wax', 'networks' => ['facebook', 'instagram']])
            ->assertCreated()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.results.instagram.status', 'failed')->json('data');
        $this->assertStringContainsString('Image refusée', $post['results']['instagram']['error']);
        $this->assertSame('Meta : Image refusée', SocialAccount::where('network', 'instagram')->value('last_error'));

        Http::swap(new Factory);
        $this->fakeNetworks();
        $this->storeApi($this->store, 'POST', "social/posts/{$post['id']}/retry")->assertOk()->assertJsonPath('data.status', 'published');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'PAGE1/photos'));
    }

    public function test_publication_can_be_scheduled(): void
    {
        $this->withPhoto();
        $this->connectAll();
        $this->fakeNetworks();

        $at = now($this->store->timezone)->addHours(3)->format('Y-m-d H:i');
        $post = $this->storeApi($this->store, 'POST', "products/{$this->robe->id}/social/posts", ['caption' => 'Ce soir !', 'networks' => ['facebook'], 'scheduled_at' => $at])
            ->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');
        Http::assertNothingSent();

        $this->artisan('social:publish')->expectsOutputToContain('0 publication(s)')->assertSuccessful();
        $this->travel(4)->hours();
        $this->artisan('social:publish')->expectsOutputToContain('1 publication(s)')->assertSuccessful();
        $this->assertSame('published', SocialPost::find($post['id'])->status);
    }

    public function test_networks_must_be_connected_and_the_product_needs_a_photo(): void
    {
        $this->storeApi($this->store, 'POST', "products/{$this->robe->id}/social/posts", ['caption' => 'x', 'networks' => ['facebook']])->assertStatus(422);
        $this->connectAll();
        $this->storeApi($this->store, 'POST', "products/{$this->robe->id}/social/posts", ['caption' => 'x', 'networks' => ['facebook']])
            ->assertStatus(422)->assertJsonPath('message', 'Ajoutez une photo au produit : les réseaux publient une image.');
    }

    public function test_orders_are_attributed_to_each_network_and_post(): void
    {
        $this->withPhoto();
        $this->connectAll();
        $this->fakeNetworks();
        $postId = $this->storeApi($this->store, 'POST', "products/{$this->robe->id}/social/posts", ['caption' => 'Robe {lien}', 'networks' => ['facebook', 'tiktok']])->json('data.id');
        $code = ShortLink::where('social_post_id', $postId)->where('network', 'facebook')->value('code');

        // Clic sur le lien publié : la visite porte le réseau et la publication
        $this->get($this->storeUrl($this->store, '/p/'.$code))->assertRedirect('/produits/'.$this->robe->slug.'?utm_source=facebook&utm_medium=social&utm_campaign=post-'.$postId);

        $order = fn (array $extra) => app(OrderDesk::class)->create($this->store, $extra + [
            'customer_name' => 'Fatou', 'customer_phone' => '+2250506070809', 'delivery_method' => 'delivery',
            'shipping_place_id' => Place::whereNull('store_id')->where('name', 'Angré')->value('id'), 'shipping_address' => 'Cité',
            'items' => [['variant_id' => $this->robe->variants[0]->id, 'quantity' => 1]],
        ], $this->store->owner);
        $order(['source' => 'online_store', 'utm' => ['utm_source' => 'facebook', 'utm_medium' => 'social', 'utm_campaign' => 'post-'.$postId]]);
        $order(['source' => 'online_store', 'utm' => ['fbclid' => 'abc']]); // publicité Facebook
        $order(['source' => 'tiktok']); // commande saisie, venue de TikTok
        $order(['source' => 'whatsapp']);

        $stats = $this->storeApi($this->store, 'GET', 'social')->assertOk()->json('stats');
        $networks = collect($stats['networks'])->keyBy('network');
        $this->assertSame([2, 1, 1], [$networks['facebook']['orders'], $networks['facebook']['clicks'], $networks['tiktok']['orders']]);
        $this->assertSame(0, $networks['instagram']['orders']);
        $facebookPost = collect($stats['posts'][0]['networks'])->firstWhere('network', 'facebook');
        $this->assertSame([1, 1, 'published'], [$facebookPost['orders'], $facebookPost['clicks'], $facebookPost['status']]);
    }

    public function test_messenger_and_instagram_messages_land_in_the_inbox_and_get_answers(): void
    {
        $this->connectAll();
        Http::fake([
            'graph.facebook.com/*/PSID9*' => Http::response(['name' => 'Koné Aya']),
            'graph.facebook.com/*/IGSID5*' => Http::response(['username' => 'aya_ci']),
            'graph.facebook.com/*/PAGE1/messages' => Http::response(['recipient_id' => 'x', 'message_id' => 'm_out_1']),
        ]);

        $this->metaWebhook('page', [['id' => 'PAGE1', 'messaging' => [
            ['sender' => ['id' => 'PSID9'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'm1', 'text' => 'Bonjour, la robe wax est dispo en M ?']],
            ['sender' => ['id' => 'PAGE1'], 'recipient' => ['id' => 'PSID9'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'm2', 'text' => 'écho', 'is_echo' => true]],
        ]]])->assertOk();
        $this->metaWebhook('page', [['id' => 'PAGE1', 'messaging' => [
            ['sender' => ['id' => 'PSID9'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'm1', 'text' => 'Bonjour, la robe wax est dispo en M ?']],
        ]]])->assertOk(); // rejouée
        $this->metaWebhook('instagram', [['id' => 'IG1', 'messaging' => [
            ['sender' => ['id' => 'IGSID5'], 'recipient' => ['id' => 'IG1'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'ig1', 'attachments' => [['type' => 'image']]]],
        ]]])->assertOk();

        $messenger = Conversation::where('channel', 'messenger')->sole();
        $this->assertSame(['PSID9', 'Koné Aya', 1], [$messenger->contact, $messenger->contact_name, $messenger->messages()->count()]);
        $instagram = Conversation::where('channel', 'instagram')->sole();
        $this->assertSame(['@aya_ci', '📷 Photo'], [$instagram->contact_name, $instagram->last_preview]);

        $list = $this->storeApi($this->store, 'GET', 'conversations')->assertOk()->assertJsonPath('connected', true)->json('data');
        $this->assertEqualsCanonicalizing(['Messenger', 'Instagram'], array_column($list, 'phone_display'));
        $this->assertSame([null, null], array_column($list, 'phone'));

        // Réponse depuis la boîte : envoyée par la page (Messenger comme Instagram)
        $this->storeApi($this->store, 'POST', "conversations/{$messenger->id}/messages", ['body' => 'Oui, la M est disponible à 15 000 F.'])->assertCreated();
        $this->storeApi($this->store, 'POST', "conversations/{$instagram->id}/messages", ['body' => 'Bonjour Aya !'])->assertCreated();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'PAGE1/messages') && $r['recipient']['id'] === 'PSID9' && $r['messaging_type'] === 'RESPONSE');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'PAGE1/messages') && $r['recipient']['id'] === 'IGSID5');
        $this->assertSame('m_out_1', ConversationMessage::where('direction', 'out')->where('conversation_id', $messenger->id)->value('provider_message_id'));

        // Signature invalide : rien n'est enregistré
        $this->call('POST', 'http://api.localhost/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], '{"object":"page"}')->assertStatus(401);
    }
}
