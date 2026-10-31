<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\SocialAccount;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Services\Messaging\ReplySuggester;
use App\Services\Orders\OrderMessageParser;
use App\Services\Platform\Features;
use App\Services\WhatsApp\Inbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jibiat\Socle\PhoneNumber;

/**
 * Boîte « Messages » de la gestion : conversations WhatsApp des clients, réponses de l'équipe, et texte à analyser
 * pour créer la commande depuis la conversation.
 */
class ConversationController extends Controller
{
    // Messages chargés à la fois dans le fil (les plus récents, puis « Voir les messages précédents »)
    private const PAGE = 100;

    public function __construct(private readonly Inbox $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['filter' => ['nullable', 'in:all,unread,without_order'], 'q' => ['nullable', 'string', 'max:60']]);

        $query = $this->visible()->whereNotNull('last_inbound_at')->with('order.store')
            ->when($request->input('filter') === 'unread', fn ($q) => $q->where('unread_count', '>', 0))
            ->when($request->input('filter') === 'without_order', fn ($q) => $q->whereNull('order_id'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->input('q'));
                $digits = preg_replace('/\D/', '', $term);
                $q->where(fn ($w) => $w->where('contact_name', 'ilike', '%'.$term.'%')
                    ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('contact', 'like', '%'.substr($digits, -8).'%')));
            })
            ->orderByDesc('last_message_at');
        $page = $query->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Conversation $c) => $this->summary($c))->values(),
            'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'unread' => $this->unreadCount()],
            // WhatsApp relié (QR code ou numéro) : sinon la boîte propose de le relier
            'linked' => WhatsappLink::query()->where('status', 'connected')->exists(),
            'connected' => WhatsappAccount::query()->exists() || WhatsappLink::query()->where('status', 'connected')->exists()
                || (app(Features::class)->enabled('meta_messages', $this->currentStore()) && SocialAccount::query()->whereIn('network', ['facebook', 'instagram'])->exists()),
        ]);
    }

    public function unread(): JsonResponse
    {
        return response()->json(['data' => ['count' => $this->unreadCount()]]);
    }

    public function show(int $conversation): JsonResponse
    {
        $model = $this->visible()->with(['order.store', 'customer'])->findOrFail($conversation);
        $this->inbox->markRead($model);

        return response()->json(['data' => $this->detail($model)]);
    }

    /**
     * Messages plus anciens que le premier affiché (« Voir les messages précédents »).
     */
    public function older(Request $request, int $conversation): JsonResponse
    {
        $before = (int) $request->validate(['before' => ['required', 'integer']])['before'];
        $model = $this->visible()->findOrFail($conversation);
        $page = $model->messages()->with('user:id,name')->where('id', '<', $before)
            ->latest('sent_at')->latest('id')->limit(self::PAGE + 1)->get();

        return response()->json(['data' => [
            'messages' => $page->take(self::PAGE)->reverse()->values()->map(fn (ConversationMessage $m) => $this->message($m)),
            'has_more' => $page->count() > self::PAGE,
        ]]);
    }

    public function reply(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']], [], ['body' => 'message']);
        $model = $this->visible()->findOrFail($conversation);
        $this->inbox->reply($model, trim($data['body']), $request->user());

        return response()->json(['data' => $this->detail($model->fresh(['order.store', 'customer']))], 201);
    }

    /**
     * Réponse proposée par l'assistant IA (jamais envoyée sans le marchand).
     */
    public function suggest(Request $request, int $conversation, ReplySuggester $suggester): JsonResponse
    {
        return response()->json(['data' => $suggester->suggest($this->visible()->with(['store', 'order'])->findOrFail($conversation), $request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function message(ConversationMessage $m, bool $orderHint = false): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'type' => $m->type,
            'body' => $m->body,
            'order_hint' => $orderHint,
            'status' => $m->status,
            'error' => $m->error,
            // Réponse d'un membre de l'équipe, message automatique, ou écrit depuis le téléphone de la boutique
            'by' => $m->user?->name ?? ($m->direction === 'out' ? match ($m->type) {
                'template' => 'Message automatique', 'auto' => 'Réponse automatique', default => 'Depuis le téléphone',
            } : null),
            'sent_at' => $m->sent_at->toIso8601String(),
        ];
    }

    private function unreadCount(): int
    {
        return $this->visible()->where('unread_count', '>', 0)->count();
    }

    /**
     * Conversations affichées : Messenger et Instagram seulement quand la console les a allumés.
     *
     * @return Builder<Conversation>
     */
    private function visible(): Builder
    {
        return Conversation::query()->when(! app(Features::class)->enabled('meta_messages', $this->currentStore()), fn ($q) => $q->where('channel', 'whatsapp'));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Conversation $c): array
    {
        return [
            'id' => $c->id,
            'channel' => $c->channel,
            'name' => $c->contact_name,
            // Messenger et Instagram : identifiant du client chez Meta, pas un numéro
            'phone' => $c->channel === 'whatsapp' ? $c->contact : null,
            'phone_display' => $c->channel === 'whatsapp' ? PhoneNumber::display($c->contact) : (['messenger' => 'Messenger', 'instagram' => 'Instagram'][$c->channel] ?? $c->channel),
            'preview' => $c->last_preview,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'unread' => $c->unread_count,
            'order' => $c->order ? ['id' => $c->order->id, 'reference' => $c->order->reference()] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Conversation $c): array
    {
        $page = $c->messages()->with('user:id,name')->latest('sent_at')->latest('id')->limit(self::PAGE + 1)->get();
        $messages = $page->take(self::PAGE)->reverse()->values();
        $since = $c->order?->created_at;
        // WhatsApp relié par QR code : réponse possible à tout moment, depuis le numéro de la boutique
        $linked = $this->inbox->linkFor($c) !== null;
        // Messages récents du client qui contiennent peut-être une commande : « Faire une commande » sous chacun
        $recent = $messages->where('direction', 'in')->take(-40);
        $hints = array_flip(app(OrderMessageParser::class)->orderHints($c->store, $recent->pluck('body', 'id')->map(fn ($b) => (string) $b)->all()));

        return $this->summary($c) + [
            'customer' => $c->customer ? ['id' => $c->customer->id, 'name' => $c->customer->name] : null,
            'linked' => $linked,
            'can_reply' => $linked || $c->canReply(),
            'reply_until' => $linked ? null : $c->last_inbound_at?->addHours(Conversation::REPLY_WINDOW_HOURS)->toIso8601String(),
            'has_more' => $page->count() > self::PAGE,
            'messages' => $messages->map(fn (ConversationMessage $m) => $this->message($m, isset($hints[$m->id]))),
            // Ce que le client a écrit depuis la dernière commande créée ici : texte proposé à l'analyse
            'order_text' => $messages->filter(fn (ConversationMessage $m) => $m->direction === 'in' && ($since === null || $m->sent_at->gt($since)))
                ->pluck('body')->take(-20)->implode("\n"),
        ];
    }
}
