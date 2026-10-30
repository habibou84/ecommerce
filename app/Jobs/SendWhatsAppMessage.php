<?php

namespace App\Jobs;

use App\Models\OutboundMessage;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Envoi d'un message WhatsApp en file d'attente (une réponse lente de Meta ne ralentit pas la boutique).
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /**
     * @param  list<string>  $params
     * @param  list<string>|array<string, string>  $buttons  modèle : charges utiles ; message libre : charge utile => libellé
     * @param  string|null  $text  message libre à boutons (conversation ouverte depuis moins de 24 h) au lieu du modèle
     */
    public function __construct(public int $messageId, public array $params, public array $buttons = [], public ?string $text = null) {}

    public function handle(WhatsAppCloud $cloud): void
    {
        $message = OutboundMessage::withoutGlobalScopes()->find($this->messageId);
        if ($message === null || $message->status !== 'queued') {
            return;
        }
        $account = WhatsappAccount::withoutGlobalScopes()->where('store_id', $message->store_id)->first();
        if ($account === null) {
            $message->forceFill(['status' => 'failed', 'error' => 'Compte WhatsApp supprimé'])->save();

            return;
        }

        try {
            $id = $this->text !== null
                ? ($this->buttons === [] ? $cloud->sendText($account, $message->to_phone, $this->text) : $cloud->sendButtons($account, $message->to_phone, $this->text, $this->buttons))
                : $cloud->sendTemplate($account, $message->to_phone, (string) ($account->templates[$message->event] ?? ''), $this->params, $this->buttons);
            $message->forceFill(['status' => 'sent', 'provider_message_id' => $id, 'sent_at' => now(), 'error' => null])->save();
            // Un message accepté par Meta : l'erreur précédente est réglée
            if ($account->last_error !== null) {
                $account->forceFill(['last_error' => null])->save();
            }
        } catch (Throwable $e) {
            $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
            $account->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }
    }
}
