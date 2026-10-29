<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API Cloud de WhatsApp (graph.facebook.com) au nom du marchand. Un message envoyé à l'initiative de la boutique
 * est toujours un modèle approuvé par Meta ; un texte libre n'est permis qu'en réponse, dans les 24 h.
 */
class WhatsAppCloud
{
    // Version de l'API Graph de Meta (chacune expire environ deux ans après sa sortie)
    public const VERSION = 'v25.0';

    public const GRAPH = 'https://graph.facebook.com/'.self::VERSION.'/';

    // Erreurs de Meta les plus courantes, dites simplement (les autres gardent le texte de Meta)
    private const ERRORS = [
        // Une autre application reliée au même numéro tient la conversation
        131070 => 'ce client est en ce moment pris en charge par une autre application reliée à votre numéro WhatsApp (un autre logiciel ou l\'application WhatsApp Business). Répondez depuis votre téléphone, ou reliez WhatsApp par QR code dans Messages pour répondre d\'ici.',
        131047 => 'le client n\'a pas écrit depuis plus de 24 h : Meta n\'autorise plus de réponse libre par la connexion officielle.',
        131026 => 'message non remis : ce numéro n\'a peut-être pas WhatsApp, ou n\'a pas accepté les conditions récentes de WhatsApp.',
        190 => 'la connexion officielle a expiré : reconnectez votre numéro dans Réglages › WhatsApp.',
    ];

    /**
     * @param  list<string>  $params  variables {{1}}, {{2}}… du corps du modèle
     * @param  list<string>  $buttons  charges utiles des boutons de réponse rapide, dans l'ordre
     * @return string identifiant du message chez Meta
     */
    public function sendTemplate(WhatsappAccount $account, string $to, string $template, array $params, array $buttons = []): string
    {
        $components = [];
        if ($params !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $params)];
        }
        foreach ($buttons as $index => $payload) {
            $components[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $index, 'parameters' => [['type' => 'payload', 'payload' => $payload]]];
        }

        return $this->send($account, $to, [
            'type' => 'template',
            'template' => array_filter(['name' => $template, 'language' => ['code' => 'fr'], 'components' => $components ?: null]),
        ]);
    }

    /**
     * Réponse libre, permise par Meta dans les 24 h qui suivent le dernier message du client.
     */
    public function sendText(WhatsappAccount $account, string $to, string $text): string
    {
        return $this->send($account, $to, ['type' => 'text', 'text' => ['preview_url' => true, 'body' => $text]]);
    }

    /**
     * Message à boutons de réponse (3 au plus), sans modèle : permis comme une réponse libre, dans les 24 h.
     *
     * @param  array<string, string>  $buttons  charge utile => libellé (20 caractères au plus)
     */
    public function sendButtons(WhatsappAccount $account, string $to, string $text, array $buttons): string
    {
        $replies = [];
        foreach ($buttons as $payload => $title) {
            $replies[] = ['type' => 'reply', 'reply' => ['id' => (string) $payload, 'title' => mb_substr($title, 0, 20)]];
        }

        return $this->send($account, $to, ['type' => 'interactive', 'interactive' => [
            'type' => 'button', 'body' => ['text' => mb_substr($text, 0, 1024)], 'action' => ['buttons' => $replies],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function send(WhatsappAccount $account, string $to, array $message): string
    {
        try {
            $response = Http::withToken($account->access_token)->acceptJson()->timeout(15)
                ->post(self::GRAPH.$account->phone_number_id.'/messages', ['messaging_product' => 'whatsapp', 'to' => ltrim($to, '+')] + $message);
        } catch (ConnectionException) {
            throw new RuntimeException('WhatsApp (Meta) ne répond pas.');
        }

        if ($response->failed()) {
            $code = (int) $response->json('error.code');
            throw new RuntimeException('WhatsApp : '.(self::ERRORS[$code] ?? $response->json('error.message') ?? 'erreur '.$response->status()), $code);
        }

        return (string) $response->json('messages.0.id');
    }

    /**
     * Photo reçue (identifiant de média de Meta) en octets : adresse temporaire lue avec le jeton, puis téléchargée ;
     * null si Meta ne la donne plus.
     */
    public function image(WhatsappAccount $account, string $mediaId): ?string
    {
        try {
            $url = Http::withToken($account->access_token)->acceptJson()->timeout(15)->get(self::GRAPH.$mediaId)->json('url');
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                return null;
            }
            $response = Http::withToken($account->access_token)->timeout(30)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Appel à l'API Graph (inscription intégrée : jeton, abonnement du compte, numéro, modèles).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function graph(string $method, string $path, ?string $token, array $data = []): array
    {
        try {
            $request = Http::acceptJson()->timeout(20);
            if ($token) {
                $request = $request->withToken($token);
            }
            $response = $method === 'GET' ? $request->get(self::GRAPH.$path, $data) : $request->post(self::GRAPH.$path, $data);
        } catch (ConnectionException) {
            throw new RuntimeException('Meta ne répond pas.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Meta : '.($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'erreur '.$response->status()));
        }

        return $response->json() ?? [];
    }

    /**
     * Signature des notifications de Meta : X-Hub-Signature-256 = sha256=HMAC(secret de l'application, corps).
     */
    public function verifySignature(WhatsappAccount $account, Request $request): bool
    {
        // Numéro connecté en un clic : les notifications viennent de l'application Meta de la plateforme
        $secret = $account->isEmbedded() ? WhatsAppMetaApp::secret() : $account->app_secret;

        return self::signatureMatches($request, $secret);
    }

    public static function signatureMatches(Request $request, ?string $secret): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256');

        return $secret && $signature !== ''
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
