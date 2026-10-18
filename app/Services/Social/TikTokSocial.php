<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Support\Tenancy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Compte TikTok de la boutique (Login Kit) et publication de photos (Content Posting API, envoi direct).
 * Les images sont lues par TikTok sur le domaine de la plateforme, qui doit être vérifié chez TikTok.
 */
class TikTokSocial
{
    private const API = 'https://open.tiktokapis.com/v2/';

    public static function enabled(): bool
    {
        return filled(config('services.tiktok.client_key')) && filled(config('services.tiktok.client_secret'));
    }

    public static function redirectUri(): string
    {
        return Tenancy::platformUrl('/reseaux/tiktok/retour');
    }

    public function authorizeUrl(string $state): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key' => config('services.tiktok.client_key'), 'scope' => 'user.info.basic,video.publish',
            'response_type' => 'code', 'redirect_uri' => self::redirectUri(), 'state' => $state,
        ]);
    }

    /**
     * @return array{open_id: string, name: string, access_token: string, refresh_token: ?string, expires_in: int}
     */
    public function connect(string $code): array
    {
        $token = $this->token(['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirectUri()]);
        $user = $this->call($this->client($token['access_token'])->get(self::API.'user/info/', ['fields' => 'open_id,display_name']));

        return [
            'open_id' => (string) ($user['data']['user']['open_id'] ?? $token['open_id'] ?? ''),
            'name' => (string) ($user['data']['user']['display_name'] ?? 'TikTok'),
            'access_token' => (string) $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_in' => (int) ($token['expires_in'] ?? 86400),
        ];
    }

    /**
     * Photos publiées en une fois (carrousel), avec la légende ; TikTok traite la publication ensuite.
     *
     * @param  list<string>  $imageUrls
     * @return array{id: string, url: ?string}
     */
    public function publish(SocialAccount $account, array $imageUrls, string $caption): array
    {
        $client = $this->client($this->freshToken($account));
        // Confidentialité permise pour ce compte (une application pas encore auditée : « moi seulement »)
        $options = $this->call($client->post(self::API.'post/publish/creator_info/query/'))['data']['privacy_level_options'] ?? [];
        $privacy = in_array('PUBLIC_TO_EVERYONE', $options, true) ? 'PUBLIC_TO_EVERYONE' : ($options[0] ?? 'SELF_ONLY');

        $result = $this->call($client->post(self::API.'post/publish/content/init/', [
            'post_info' => ['title' => mb_substr(strtok($caption, "\n") ?: $caption, 0, 90), 'description' => mb_substr($caption, 0, 4000),
                'privacy_level' => $privacy, 'disable_comment' => false, 'auto_add_music' => true],
            'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => array_values($imageUrls)],
            'post_mode' => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ]));

        return ['id' => (string) ($result['data']['publish_id'] ?? ''), 'url' => null];
    }

    private function freshToken(SocialAccount $account): string
    {
        if ($account->token_expires_at === null || $account->token_expires_at->gt(now()->addMinutes(5)) || ! $account->refresh_token) {
            return $account->access_token;
        }
        $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token]);
        $account->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 86400)),
        ])->save();

        return $token['access_token'];
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post(self::API.'oauth/token/', $params + [
                'client_key' => config('services.tiktok.client_key'), 'client_secret' => config('services.tiktok.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('TikTok ne répond pas.');
        }
        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('TikTok : '.($response->json('error_description') ?? $response->json('error') ?? 'connexion refusée'));
        }

        return $response->json();
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->asJson()->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(mixed $response): array
    {
        $code = $response->json('error.code');
        if ($response->failed() || ($code && $code !== 'ok')) {
            throw new RuntimeException('TikTok : '.($response->json('error.message') ?: 'erreur '.$response->status()));
        }

        return $response->json() ?? [];
    }
}
