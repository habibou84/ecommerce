<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\WhatsApp\PlatformWhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;

/**
 * Console : WhatsApp du support (connexion du numéro avec Facebook ou par QR code, conversations, réponses).
 * Ce numéro reçoit aussi les messages de vérification des numéros (inscription, connexion), sans SMS.
 */
class ConsoleWhatsAppController extends Controller
{
    public function __construct(private readonly PlatformWhatsApp $whatsapp) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->whatsapp->present()]);
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:1000'],
            'waba_id' => ['required', 'string', 'max:40', 'regex:/^\d+$/'],
            'phone_number_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
            'coexistence' => ['sometimes', 'boolean'],
        ]);
        $this->whatsapp->connect($data['code'], $data['waba_id'], $data['phone_number_id'] ?? null, (bool) ($data['coexistence'] ?? false));
        $this->log($request, 'support_whatsapp_connected', ['waba_id' => $data['waba_id']]);

        return $this->show();
    }

    /** Connexion par QR code : démarre la session du service et renvoie le QR code (ou l'état si déjà relié) */
    public function qr(Request $request): JsonResponse
    {
        $live = $this->whatsapp->startQr();
        if ($live['status'] === 'connected') {
            $this->log($request, 'support_whatsapp_connected', ['mode' => 'qr']);
        }

        return response()->json(['data' => $this->whatsapp->present() + ['live' => $live]]);
    }

    /** État de la session QR (QR code rafraîchi, téléphone relié) */
    public function qrStatus(): JsonResponse
    {
        return response()->json(['data' => $this->whatsapp->present() + ['live' => $this->whatsapp->qrStatus()]]);
    }

    /** Liaison avec le numéro plutôt que le QR code : code à saisir sur le téléphone */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']], [], ['phone' => 'numéro WhatsApp']);
        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Numéro WhatsApp invalide.']);
        }

        return response()->json(['data' => $this->whatsapp->present() + ['live' => $this->whatsapp->pairQr($phone)]]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->whatsapp->disconnect();
        $this->log($request, 'support_whatsapp_disconnected');

        return $this->show();
    }

    public function conversations(Request $request): JsonResponse
    {
        $page = SupportConversation::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('contact_name', 'ilike', '%'.$s.'%')
                ->when(preg_replace('/\D/', '', $s), fn ($q, $digits) => $q->orWhere('contact', 'like', '%'.$digits.'%'))))
            ->orderByDesc('last_message_at')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (SupportConversation $c) => $this->conversation($c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'unread' => SupportConversation::where('unread_count', '>', 0)->count()],
        ]);
    }

    public function messages(int $conversation): JsonResponse
    {
        $model = SupportConversation::findOrFail($conversation);
        $this->whatsapp->markRead($model);
        $messages = $model->messages()->with('user:id,name')->latest('sent_at')->latest('id')->limit(200)->get()->reverse()->values();

        return response()->json(['data' => $this->conversation($model->fresh()) + [
            'messages' => $messages->map(fn (SupportMessage $m) => $this->message($m)),
        ]]);
    }

    public function reply(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']], [], ['body' => 'message']);
        $message = $this->whatsapp->reply(SupportConversation::findOrFail($conversation), $data['body'], $request->user());

        return response()->json(['data' => $this->message($message->load('user:id,name'))], 201);
    }

    private function conversation(SupportConversation $c): array
    {
        return [
            'id' => $c->id, 'contact' => PhoneNumber::display($c->contact), 'phone' => $c->contact, 'contact_name' => $c->contact_name,
            'last_preview' => $c->last_preview, 'last_message_at' => $c->last_message_at?->toIso8601String(),
            // Par QR code (téléphone relié) : réponse libre à tout moment ; avec Meta : dans les 24 h du dernier message
            'unread_count' => $c->unread_count, 'can_reply' => $this->whatsapp->isQr() || $c->canReply(),
        ];
    }

    private function message(SupportMessage $m): array
    {
        return [
            'id' => $m->id, 'direction' => $m->direction, 'type' => $m->type, 'body' => $m->body, 'status' => $m->status, 'error' => $m->error,
            // Message envoyé sans auteur dans la console : depuis l'application du téléphone
            'author' => $m->direction === 'out' ? ($m->user?->name ?? 'Téléphone') : null,
            'sent_at' => $m->sent_at->toIso8601String(),
        ];
    }

    private function log(Request $request, string $action, array $data = []): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => null, 'action' => $action, 'data' => $data ?: null, 'ip' => $request->ip()]);
    }
}
