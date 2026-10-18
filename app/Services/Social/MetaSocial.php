<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Support\Tenancy;
use RuntimeException;

/**
 * Page Facebook et compte Instagram professionnel de la boutique, via l'application Meta de la plateforme :
 * connexion, publication d'une photo, messages Messenger et Instagram.
 */
class MetaSocial
{
    public const SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_metadata', 'pages_messaging',
        'instagram_basic', 'instagram_content_publish', 'instagram_manage_messages', 'business_management'];

    public function __construct(private readonly WhatsAppCloud $graph) {}

    public static function enabled(): bool
    {
        return filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret'));
    }

    public static function redirectUri(): string
    {
        return Tenancy::platformUrl('/reseaux/meta/retour');
    }

    public function authorizeUrl(string $state): string
    {
        $params = ['client_id' => config('services.meta.app_id'), 'redirect_uri' => self::redirectUri(), 'state' => $state, 'response_type' => 'code'];
        $params += filled(config('services.meta.social_config_id'))
            ? ['config_id' => config('services.meta.social_config_id')]
            : ['scope' => implode(',', self::SCOPES)];

        return 'https://www.facebook.com/'.WhatsAppCloud::VERSION.'/dialog/oauth?'.http_build_query($params);
    }

    /**
     * Pages que la personne gère, avec leur compte Instagram professionnel éventuel (jetons de page sans expiration,
     * tirés d'un jeton de longue durée).
     *
     * @return list<array{id: string, name: string, access_token: string, instagram: ?array{id: string, name: string}}>
     */
    public function pages(string $code): array
    {
        $app = ['client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret')];
        $short = (string) ($this->graph->graph('GET', 'oauth/access_token', null, $app + ['redirect_uri' => self::redirectUri(), 'code' => $code])['access_token'] ?? '');
        if ($short === '') {
            throw new RuntimeException('Meta n\'a pas renvoyé de jeton.');
        }
        $long = (string) ($this->graph->graph('GET', 'oauth/access_token', null, $app + ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $short])['access_token'] ?? $short);

        $pages = $this->graph->graph('GET', 'me/accounts', $long, ['fields' => 'id,name,access_token,instagram_business_account{id,username,name}', 'limit' => 50])['data'] ?? [];

        return array_values(array_map(fn (array $p) => [
            'id' => (string) $p['id'],
            'name' => (string) ($p['name'] ?? ''),
            'access_token' => (string) ($p['access_token'] ?? ''),
            'instagram' => isset($p['instagram_business_account']['id'])
                ? ['id' => (string) $p['instagram_business_account']['id'], 'name' => (string) ($p['instagram_business_account']['username'] ?? $p['instagram_business_account']['name'] ?? '')]
                : null,
        ], $pages));
    }

    /**
     * Messages de la page (et d'Instagram) envoyés à la plateforme.
     */
    public function subscribe(string $pageId, string $pageToken): void
    {
        $this->graph->graph('POST', $pageId.'/subscribed_apps', $pageToken, ['subscribed_fields' => 'messages']);
    }

    /**
     * @return array{id: string, url: ?string}
     */
    public function publish(SocialAccount $account, string $imageUrl, string $caption): array
    {
        if ($account->network === 'facebook') {
            $result = $this->graph->graph('POST', $account->external_id.'/photos', $account->access_token, ['url' => $imageUrl, 'caption' => $caption]);
            $id = (string) ($result['post_id'] ?? $result['id'] ?? '');

            return ['id' => $id, 'url' => $id !== '' ? 'https://www.facebook.com/'.$id : null];
        }

        // Instagram : conteneur, puis publication
        $container = (string) ($this->graph->graph('POST', $account->external_id.'/media', $account->access_token, ['image_url' => $imageUrl, 'caption' => $caption])['id'] ?? '');
        if ($container === '') {
            throw new RuntimeException('Instagram n\'a pas accepté l\'image.');
        }
        $id = (string) ($this->graph->graph('POST', $account->external_id.'/media_publish', $account->access_token, ['creation_id' => $container])['id'] ?? '');
        $url = null;
        try {
            $url = $this->graph->graph('GET', $id, $account->access_token, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (RuntimeException) {
            // lien facultatif
        }

        return ['id' => $id, 'url' => $url];
    }

    /**
     * Réponse à un message Messenger ou Instagram (jeton de la page, dans les 24 h du dernier message du client).
     */
    public function send(SocialAccount $account, string $recipient, string $text): ?string
    {
        $page = $account->network === 'facebook' ? $account->external_id : (string) $account->page_id;

        return $this->graph->graph('POST', $page.'/messages', $account->access_token, [
            'recipient' => ['id' => $recipient], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE',
        ])['message_id'] ?? null;
    }

    /**
     * Nom affiché d'un client qui écrit (facultatif : Meta ne le donne pas toujours).
     */
    public function profileName(SocialAccount $account, string $userId): ?string
    {
        try {
            $profile = $this->graph->graph('GET', $userId, $account->access_token, ['fields' => $account->network === 'facebook' ? 'name' : 'name,username']);
        } catch (RuntimeException) {
            return null;
        }

        return $profile['name'] ?? (isset($profile['username']) ? '@'.$profile['username'] : null);
    }
}
