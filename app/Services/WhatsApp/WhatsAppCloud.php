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
    public const GRAPH = 'https://graph.facebook.com/v20.0/';

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
            throw new RuntimeException('WhatsApp : '.($response->json('error.message') ?? 'erreur '.$response->status()));
        }

        return (string) $response->json('messages.0.id');
    }

    /**
     * Signature des notifications de Meta : X-Hub-Signature-256 = sha256=HMAC(secret de l'application, corps).
     */
    public function verifySignature(WhatsappAccount $account, Request $request): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256');

        return $account->app_secret && $signature !== ''
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $account->app_secret), $signature);
    }
}
