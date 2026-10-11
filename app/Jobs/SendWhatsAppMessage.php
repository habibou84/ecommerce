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
     * @param  list<string>  $buttons
     */
    public function __construct(public int $messageId, public array $params, public array $buttons = []) {}

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
            $id = $cloud->sendTemplate($account, $message->to_phone, (string) ($account->templates[$message->event] ?? ''), $this->params, $this->buttons);
            $message->forceFill(['status' => 'sent', 'provider_message_id' => $id, 'sent_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
            $account->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }
    }
}
