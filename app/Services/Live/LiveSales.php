<?php

namespace App\Services\Live;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\QuotaExceededException;
use App\Jobs\ReadLiveCapture;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\LiveSession;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Services\Ai\Assistant;
use App\Services\Orders\OrderDesk;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\WhatsApp\Inbox;
use App\Services\WhatsApp\WhatsAppBridge;
use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Vente en live (Facebook, TikTok, Instagram). Chaque article présenté a un code court (A1, A2…), un prix et un
 * nombre de pièces. Le client réserve :
 *  - sur la page du live ({boutique}/live) avec son nom, son numéro et son quartier : la commande est créée tout de suite ;
 *  - par WhatsApp en envoyant le code : la pièce est gardée, l'application lui demande son quartier, et sa réponse
 *    crée la commande (sans réponse au bout de hold_minutes, la pièce est libérée) ;
 *  - par l'équipe (mode assistant) : pseudo ou numéro et code.
 * Épuisé : liste d'attente, servie dans l'ordre dès qu'une pièce se libère.
 */
class LiveSales
{
    public function __construct(private readonly OrderDesk $desk, private readonly Places $places, private readonly PlaceMatcher $matcher) {}

    /**
     * @param  array{title: string, hold_minutes?: int, items: list<array{variant_id: int, price?: ?int, quantity: int}>}  $data
     */
    public function save(Store $store, ?LiveSession $session, array $data, ?User $user): LiveSession
    {
        if ($session?->status === 'ended') {
            throw new BusinessRuleException('Ce live est terminé : créez-en un nouveau.');
        }
        if ($session?->status === 'live') {
            // Les codes déjà annoncés ne doivent pas changer : pendant le live, on ajoute (addItems)
            throw new BusinessRuleException('Le live est en cours : ajoutez les articles depuis l\'écran du live.');
        }
        $variants = ProductVariant::forStore($store->id)->with('product')->whereIn('id', collect($data['items'])->pluck('variant_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($store, $session, $data, $user, $variants) {
            $session ??= new LiveSession(['store_id' => $store->id, 'status' => 'draft', 'created_by' => $user?->id]);
            $session->fill(['title' => $data['title'], 'hold_minutes' => $data['hold_minutes'] ?? $session->hold_minutes ?? 120])->save();

            // Codes réattribués dans l'ordre de la liste : codes provisoires d'abord, pour ne pas croiser l'index unique
            LiveItem::forStore($store->id)->where('live_session_id', $session->id)->get()
                ->each(fn (LiveItem $item) => $item->forceFill(['code' => '#'.$item->id])->saveQuietly());
            $kept = [];
            foreach (array_values($data['items']) as $i => $line) {
                $variant = $variants->get($line['variant_id']);
                if ($variant === null || $variant->product === null) {
                    throw new BusinessRuleException('Un article du live n\'existe plus dans votre catalogue.', "items.{$i}.variant_id");
                }
                $item = LiveItem::forStore($store->id)->where('live_session_id', $session->id)->where('variant_id', $variant->id)->first()
                    ?? new LiveItem(['store_id' => $store->id, 'live_session_id' => $session->id, 'variant_id' => $variant->id]);
                $item->fill([
                    'code' => self::code($i),
                    'price' => $line['price'] ?? $variant->price,
                    'quantity' => max($line['quantity'], $item->exists ? $this->holding($item) : 0),
                    'position' => $i,
                ]);
                $item->save();
                $kept[] = $item->id;
            }
            $removed = LiveItem::forStore($store->id)->where('live_session_id', $session->id)->whereNotIn('id', $kept)->get();
            foreach ($removed as $item) {
                if ($item->reservations()->exists()) {
                    throw new BusinessRuleException("La {$item->code} a déjà des réservations : elle ne peut plus être retirée du live.");
                }
                $item->delete();
            }

            return $session->fresh('items.variant.product');
        });
    }

    /**
     * Articles ajoutés pendant le live (ou avant) : les codes déjà annoncés ne changent pas, un nouvel article prend
     * le code libre suivant ; un article déjà présent reçoit les pièces en plus (liste d'attente servie).
     *
     * @param  list<array{variant_id: int, price?: ?int, quantity: int}>  $lines
     * @return Collection<int, LiveItem> articles ajoutés ou complétés
     */
    public function addItems(LiveSession $session, array $lines): Collection
    {
        if ($session->status === 'ended') {
            throw new BusinessRuleException('Ce live est terminé : créez-en un nouveau.');
        }
        $variants = ProductVariant::forStore($session->store_id)->with('product')->whereIn('id', collect($lines)->pluck('variant_id'))->get()->keyBy('id');

        $touched = DB::transaction(function () use ($session, $lines, $variants) {
            LiveSession::forStore($session->store_id)->lockForUpdate()->find($session->id);
            $existing = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->get();
            if ($existing->count() + count($lines) > 60) {
                throw new BusinessRuleException('60 articles au plus dans un live.');
            }
            $used = $existing->pluck('code')->all();
            $position = (int) $existing->max('position');
            $index = 0;
            $touched = collect();
            foreach (array_values($lines) as $i => $line) {
                $variant = $variants->get($line['variant_id']);
                if ($variant === null || $variant->product === null) {
                    throw new BusinessRuleException('Cet article n\'existe plus dans votre catalogue.', "items.{$i}.variant_id");
                }
                $item = $existing->firstWhere('variant_id', $variant->id);
                if ($item !== null) {
                    $item->update(['quantity' => $item->quantity + $line['quantity']]);
                } else {
                    while (in_array($code = self::code($index), $used, true)) {
                        $index++;
                    }
                    $used[] = $code;
                    $item = LiveItem::create([
                        'store_id' => $session->store_id, 'live_session_id' => $session->id, 'variant_id' => $variant->id,
                        'code' => $code, 'price' => $line['price'] ?? $variant->price, 'quantity' => $line['quantity'], 'position' => ++$position,
                    ]);
                    $existing->push($item);
                }
                $touched->push($item);
            }

            return $touched;
        });
        // Pièces en plus sur un article épuisé : la liste d'attente est servie
        $touched->each(fn (LiveItem $item) => $this->promote($item->fresh()));

        return $touched;
    }

    public function start(LiveSession $session): LiveSession
    {
        if ($session->status === 'ended') {
            throw new BusinessRuleException('Ce live est terminé.');
        }
        if ($session->items()->doesntExist()) {
            throw new BusinessRuleException('Ajoutez au moins un article avant de lancer le live.');
        }
        if (LiveSession::forStore($session->store_id)->where('status', 'live')->whereKeyNot($session->id)->exists()) {
            throw new BusinessRuleException('Un autre live est en cours : terminez-le d\'abord.');
        }
        $session->update(['status' => 'live', 'started_at' => $session->started_at ?? now()]);

        return $session;
    }

    /**
     * Fin du live : plus de nouvelles réservations ; les clients déjà servis peuvent encore donner leur quartier.
     */
    public function end(LiveSession $session): LiveSession
    {
        $session->update(['status' => 'ended', 'ended_at' => now()]);

        return $session;
    }

    public static function current(int $storeId): ?LiveSession
    {
        return LiveSession::forStore($storeId)->where('status', 'live')->latest('started_at')->first();
    }

    /**
     * Pièces gardées pour un article (une commande annulée rend sa pièce).
     */
    public function holding(LiveItem $item): int
    {
        return LiveReservation::forStore($item->store_id)->where('live_item_id', $item->id)->whereIn('status', LiveReservation::HOLDING)
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereHas('order', fn ($o) => $o->where('status', '!=', 'cancelled')))
            ->count();
    }

    public function remaining(LiveItem $item): int
    {
        return max(0, $item->quantity - $this->holding($item));
    }

    /**
     * Réservation d'un article. Avec le quartier (page du live, équipe) : commande créée tout de suite.
     *
     * @param  array{name?: ?string, phone?: ?string, place_id?: ?int, address?: ?string}  $customer
     */
    public function reserve(LiveItem $item, array $customer, string $source): LiveReservation
    {
        $session = $item->session;
        if ($session->status !== 'live') {
            throw new BusinessRuleException('Ce live n\'est pas en cours.');
        }
        $phone = PhoneNumber::normalize($customer['phone'] ?? null);
        $placeId = isset($customer['place_id']) ? $this->places->forStore($session->store)->firstWhere('id', (int) $customer['place_id'])?->id : null;

        $reservation = DB::transaction(function () use ($item, $session, $customer, $source, $phone, $placeId) {
            LiveItem::forStore($item->store_id)->lockForUpdate()->find($item->id);
            if ($phone !== null) {
                $mine = LiveReservation::forStore($item->store_id)->where('live_item_id', $item->id)->where('customer_phone', $phone)
                    ->whereIn('status', [...LiveReservation::HOLDING, 'waiting'])->first();
                if ($mine !== null) {
                    return $mine; // déjà réservé (ou en attente) par ce numéro
                }
            }
            $complete = $phone !== null && ($placeId !== null || filled($customer['address'] ?? null));

            return LiveReservation::create([
                'store_id' => $item->store_id,
                'live_session_id' => $session->id,
                'live_item_id' => $item->id,
                'source' => $source,
                'status' => $this->remaining($item) > 0 ? 'reserved' : 'waiting',
                'customer_name' => filled($customer['name'] ?? null) ? mb_substr(trim($customer['name']), 0, 120) : null,
                'customer_phone' => $phone,
                'shipping_place_id' => $placeId,
                'shipping_address' => filled($customer['address'] ?? null) ? mb_substr(trim($customer['address']), 0, 255) : null,
                // WhatsApp ou équipe avec un numéro : le quartier est attendu pendant hold_minutes
                'expires_at' => ! $complete && $phone !== null ? now()->addMinutes($session->hold_minutes) : null,
            ]);
        });

        if ($reservation->wasRecentlyCreated && $reservation->status === 'reserved' && $reservation->customer_phone !== null
            && ($reservation->shipping_place_id !== null || $reservation->shipping_address !== null)) {
            $this->order(collect([$reservation]));
        }

        return $reservation->fresh(['item', 'order']);
    }

    /**
     * Réservation depuis la page du live : plusieurs articles, et plusieurs pièces d'un même article, en une fois.
     * Une seule commande pour tout ce qui est disponible ; le reste passe en liste d'attente.
     *
     * @param  array<int, int>  $lines  live_item_id => pièces
     * @param  array{name?: ?string, phone?: ?string, place_id?: ?int, address?: ?string}  $customer
     * @return Collection<int, LiveReservation>
     */
    public function reserveLines(LiveSession $session, array $lines, array $customer, string $source): Collection
    {
        if ($session->status !== 'live') {
            throw new BusinessRuleException('Ce live n\'est pas en cours.');
        }
        $items = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->whereIn('id', array_keys($lines))->orderBy('position')->get();
        if ($items->count() !== count($lines)) {
            throw new BusinessRuleException('Un des articles n\'est plus proposé dans ce live : rechargez la page.');
        }
        $phone = PhoneNumber::normalize($customer['phone'] ?? null);
        $placeId = isset($customer['place_id']) ? $this->places->forStore($session->store)->firstWhere('id', (int) $customer['place_id'])?->id : null;
        $complete = $phone !== null && ($placeId !== null || filled($customer['address'] ?? null));

        $created = DB::transaction(function () use ($session, $items, $lines, $customer, $source, $phone, $placeId, $complete) {
            LiveItem::forStore($session->store_id)->whereIn('id', $items->pluck('id'))->lockForUpdate()->get();
            $created = collect();
            foreach ($items as $item) {
                for ($i = 0; $i < $lines[$item->id]; $i++) {
                    $created->push(LiveReservation::create([
                        'store_id' => $session->store_id,
                        'live_session_id' => $session->id,
                        'live_item_id' => $item->id,
                        'source' => $source,
                        'status' => $this->remaining($item) > 0 ? 'reserved' : 'waiting',
                        'customer_name' => filled($customer['name'] ?? null) ? mb_substr(trim($customer['name']), 0, 120) : null,
                        'customer_phone' => $phone,
                        'shipping_place_id' => $placeId,
                        'shipping_address' => filled($customer['address'] ?? null) ? mb_substr(trim($customer['address']), 0, 255) : null,
                        'expires_at' => ! $complete && $phone !== null ? now()->addMinutes($session->hold_minutes) : null,
                    ]));
                }
            }

            return $created;
        });

        $held = $created->where('status', 'reserved');
        if ($complete && $held->isNotEmpty()) {
            $this->order($held);
        }

        return LiveReservation::with('item', 'order')->whereIn('id', $created->pluck('id'))->orderBy('id')->get();
    }

    /**
     * Quartier donné par un client qui a des pièces gardées : une commande pour toutes ses réservations (null si le
     * message ne nomme aucun quartier livré par la boutique).
     */
    public function complete(Store $store, string $phone, string $text): ?Order
    {
        $pending = LiveReservation::forStore($store->id)->with('item.session')->where('customer_phone', $phone)->where('status', 'reserved')
            ->whereNull('order_id')->get();
        if ($pending->isEmpty()) {
            return null;
        }
        // Seulement un message qui nomme un quartier livré : une question (« c'est combien ? ») reste à l'équipe
        $place = $this->matcher->search($text, $this->places->forStore($store), 1)->first();
        if ($place === null) {
            return null;
        }
        $pending->each->update(['shipping_place_id' => $place?->id, 'shipping_address' => mb_substr(trim($text), 0, 255), 'expires_at' => null]);

        return $this->order($pending);
    }

    /**
     * Réservations sans quartier arrivées à échéance : pièces libérées pour la liste d'attente.
     */
    public function expire(): int
    {
        $due = LiveReservation::withoutGlobalScopes()->with('item.session')->where('status', 'reserved')->whereNull('order_id')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->get();
        foreach ($due as $reservation) {
            $reservation->update(['status' => 'expired']);
            $this->promote($reservation->item);
        }

        return $due->count();
    }

    public function cancel(LiveReservation $reservation): LiveReservation
    {
        if (! in_array($reservation->status, ['reserved', 'waiting'], true)) {
            throw new BusinessRuleException('Cette réservation est déjà devenue une commande : annulez la commande.');
        }
        $reservation->update(['status' => 'cancelled']);
        $this->promote($reservation->item);

        return $reservation;
    }

    /**
     * Pièce libérée : la première personne de la liste d'attente est servie (commande si elle a donné son quartier,
     * sinon pièce gardée et quartier demandé par WhatsApp).
     */
    public function promote(LiveItem $item): void
    {
        while ($this->remaining($item) > 0) {
            $next = LiveReservation::forStore($item->store_id)->where('live_item_id', $item->id)->where('status', 'waiting')->oldest('id')->first();
            if ($next === null) {
                return;
            }
            $complete = $next->shipping_place_id !== null || $next->shipping_address !== null;
            $next->update(['status' => 'reserved', 'expires_at' => $complete ? null : now()->addMinutes($item->session->hold_minutes)]);
            if ($complete && $next->customer_phone !== null) {
                $this->order(collect([$next]));
            } elseif ($next->customer_phone !== null) {
                $this->tell($item->store_id, $next->customer_phone, "Bonne nouvelle : la {$item->code} ({$this->label($item)}) s'est libérée, elle est à vous ! "
                    .'Répondez avec votre quartier (et un repère) pour la livraison.');
            }
        }
    }

    /**
     * Message WhatsApp reçu : code(s) du live en cours, capture d'écran d'un article (lue à part, ReadLiveCapture),
     * ou quartier attendu. Vrai si le message a été traité.
     */
    public function fromWhatsApp(Conversation $conversation, ConversationMessage $message): bool
    {
        if ($conversation->channel !== 'whatsapp' || blank($message->body)) {
            return false;
        }
        $session = self::current($conversation->store_id);
        if ($message->type === 'image') {
            if ($session === null) {
                return false;
            }
            // Lecture de la photo (téléchargement, IA) hors de la notification de WhatsApp
            ReadLiveCapture::dispatch($message->id)->afterCommit();

            return true;
        }
        if ($message->type !== 'text') {
            return false;
        }
        $items = $session ? $this->codesIn($session, $message->body) : collect();
        if ($items->isNotEmpty()) {
            $this->reserveFor($conversation, $items);

            return true;
        }

        $order = $this->complete($conversation->store, PhoneNumber::normalize($conversation->contact), $message->body);
        if ($order !== null) {
            $this->reply($conversation, "Merci ! Commande {$order->reference()} enregistrée ({$order->items->map(fn ($i) => $i->title)->implode(', ')}), "
                .'livraison : '.($order->shipping_address ?? 'à préciser').'. Nous vous appelons pour confirmer.');

            return true;
        }

        return false;
    }

    /**
     * Capture d'écran envoyée pendant le live : le code de l'article montré à l'écran (A3) est lu sur la photo,
     * d'abord dans la légende, sinon par l'IA. Sans code lisible, on demande au client de l'écrire.
     */
    public function readCapture(ConversationMessage $message): void
    {
        $conversation = Conversation::forStore($message->store_id)->find($message->conversation_id);
        $session = self::current($message->store_id);
        if ($conversation === null || $session === null) {
            return;
        }
        $items = $this->codesIn($session, preg_replace('/^📷 Photo( : )?/u', '', (string) $message->body));
        if ($items->isEmpty()) {
            $code = $this->codeOnPhoto($conversation, $message, $session);
            $items = $code !== null ? $session->items()->with('session', 'variant.product')->where('code', $code)->get() : collect();
        }
        if ($items->isEmpty()) {
            $codes = $session->items->pluck('code')->take(3)->implode(', ');
            $this->reply($conversation, "Nous n'avons pas trouvé le code de l'article sur votre capture. Écrivez-nous le code affiché pendant le live (par exemple {$codes}).");

            return;
        }
        $this->reserveFor($conversation, $items->take(1));
    }

    /**
     * @param  Collection<int, LiveItem>  $items
     */
    private function reserveFor(Conversation $conversation, Collection $items): void
    {
        $phone = PhoneNumber::normalize($conversation->contact);
        $lines = $items->map(function (LiveItem $item) use ($phone, $conversation) {
            $r = $this->reserve($item, ['name' => $conversation->contact_name, 'phone' => $phone], 'whatsapp');

            return $r->status === 'waiting'
                ? "⏳ {$item->code} ({$this->label($item)}) : déjà partie, vous êtes n°{$this->position($r)} sur la liste d'attente."
                : "✅ {$item->code} ({$this->label($item)}, ".Money::format($item->price).') : réservée pour vous.';
        });
        $held = LiveReservation::forStore($conversation->store_id)->where('customer_phone', $phone)->where('status', 'reserved')->whereNull('order_id')->exists();
        $this->reply($conversation, $lines->implode("\n").($held ? "\nPour la livraison, répondez avec votre quartier (et un repère)." : ''));
    }

    /**
     * Code du live lu sur la photo par l'IA (compté comme une action de l'assistant) ; null si illisible ou indisponible.
     */
    private function codeOnPhoto(Conversation $conversation, ConversationMessage $message, LiveSession $session): ?string
    {
        $bytes = $conversation->via_link
            ? (($link = app(Inbox::class)->linkFor($conversation)) && $message->provider_message_id ? app(WhatsAppBridge::class)->image($link, $message->provider_message_id) : null)
            : (($account = WhatsappAccount::forStore($message->store_id)->first()) && $message->media_id ? app(WhatsAppCloud::class)->image($account, $message->media_id) : null);
        if ($bytes === null) {
            return null;
        }
        $codes = $session->items->map(fn (LiveItem $i) => "{$i->code} : {$this->label($i)}")->implode("\n");
        try {
            $assistant = app(Assistant::class);
            $result = $assistant->run($conversation->store, null, 'live_capture',
                "Tu aides une boutique qui vend en live. Le client a envoyé une capture d'écran du live. Pendant le live, chaque article "
                .'est montré avec un code court (une lettre et un chiffre, par exemple A3) écrit sur une carte, un écran ou en surimpression. '
                ."Trouve ce code sur l'image. Codes de ce live :\n{$codes}\nRéponds code = null si aucun de ces codes n'est lisible ; n'invente rien.",
                [$assistant->imageFrom($bytes), ['type' => 'text', 'text' => 'Quel code d\'article voit-on sur cette capture ?']],
                ['type' => 'object', 'properties' => ['code' => ['type' => ['string', 'null']]], 'required' => ['code'], 'additionalProperties' => false],
                200);
        } catch (BusinessRuleException|QuotaExceededException $e) {
            Log::info('Live : capture non lue', ['message' => $message->id, 'error' => $e->getMessage()]);

            return null;
        }
        $code = strtoupper(trim((string) ($result['code'] ?? '')));

        return $session->items->contains('code', $code) ? $code : null;
    }

    /**
     * État du live pour l'écran de l'équipe et la page publique.
     *
     * @return array<string, mixed>
     */
    public function state(LiveSession $session, bool $team = true): array
    {
        $session->loadMissing('items.variant.product.images');
        $reservations = $team ? LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->with('item:id,code', 'order:id,number,store_id')
            ->latest('id')->limit(200)->get() : collect();
        $counts = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)
            ->selectRaw('live_item_id, status, count(*) as n')->groupBy('live_item_id', 'status')->get();

        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status,
            'hold_minutes' => $session->hold_minutes,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'items' => $session->items->map(fn (LiveItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'variant_id' => $item->variant_id,
                'title' => $this->label($item),
                'image' => $item->variant?->product?->images->first()?->urls()['thumb'] ?? null,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'remaining' => $this->remaining($item),
                'waiting' => (int) $counts->where('live_item_id', $item->id)->where('status', 'waiting')->sum('n'),
            ])->values()->all(),
            'reservations' => $reservations->map(fn (LiveReservation $r) => [
                'id' => $r->id,
                'code' => $r->item?->code,
                'source' => $r->source,
                'status' => $r->status,
                'customer_name' => $r->customer_name,
                'customer_phone' => $r->customer_phone,
                'shipping_address' => $r->shipping_address,
                'order' => $r->order ? ['id' => $r->order->id, 'reference' => $r->order->reference()] : null,
                'expires_at' => $r->expires_at?->toIso8601String(),
                'created_at' => $r->created_at->toIso8601String(),
            ])->values()->all(),
            'summary' => $team ? $this->summary($session) : null,
            // WhatsApp qui répond aux clients pendant le live : official (Meta), link (relié par QR code) ou null
            'whatsapp' => $team ? (WhatsappAccount::forStore($session->store_id)->exists() ? 'official' : (WhatsappLink::connectedFor($session->store_id) ? 'link' : null)) : null,
        ];
    }

    /**
     * Bilan : réservations, commandes créées et montant, articles vendus.
     *
     * @return array<string, int>
     */
    public function summary(LiveSession $session): array
    {
        $all = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->with('item:id,price')->get();
        $ordered = $all->where('status', 'ordered');

        return [
            'reservations' => $all->whereNotIn('status', ['cancelled'])->count(),
            'ordered' => $ordered->count(),
            'orders' => $ordered->pluck('order_id')->unique()->count(),
            'amount' => (int) $ordered->sum(fn (LiveReservation $r) => $r->item?->price ?? 0),
            'pending' => $all->where('status', 'reserved')->count(),
            'waiting' => $all->where('status', 'waiting')->count(),
            'expired' => $all->where('status', 'expired')->count(),
        ];
    }

    /**
     * @param  Collection<int, LiveReservation>  $reservations  même client
     */
    private function order(Collection $reservations): ?Order
    {
        $first = $reservations->first();
        $session = $first->session;
        $store = Store::find($first->store_id);
        $items = LiveItem::forStore($store->id)->whereIn('id', $reservations->pluck('live_item_id'))->get()->keyBy('id');
        $byItem = $reservations->groupBy('live_item_id');
        try {
            $order = $this->desk->create($store, [
                'source' => 'live',
                'customer_name' => $first->customer_name,
                'customer_phone' => $first->customer_phone,
                'delivery_method' => 'delivery',
                'shipping_place_id' => $first->shipping_place_id,
                'shipping_address' => $first->shipping_address,
                'internal_note' => "Live « {$session->title} » : ".$byItem->map(fn ($rs, $id) => $items->get($id)?->code.($rs->count() > 1 ? ' ×'.$rs->count() : ''))->implode(', '),
                // Une ligne par article, une pièce par réservation
                'items' => $byItem->map(fn ($rs, $id) => [
                    'variant_id' => $items->get($id)->variant_id, 'quantity' => $rs->count(), 'unit_price' => $items->get($id)->price,
                ])->values()->all(),
            ], null, 'customer');
        } catch (BusinessRuleException|QuotaExceededException $e) {
            // Commande impossible (stock, quota) : la réservation reste, l'équipe la traite à la main
            Log::info('Live : commande non créée', ['reservations' => $reservations->pluck('id')->all(), 'error' => $e->getMessage()]);

            return null;
        }
        LiveReservation::whereIn('id', $reservations->pluck('id'))->update(['status' => 'ordered', 'order_id' => $order->id, 'expires_at' => null]);

        return $order->load('items');
    }

    /**
     * @return Collection<int, LiveItem>
     */
    private function codesIn(LiveSession $session, string $text): Collection
    {
        preg_match_all('/(?<![A-Za-z0-9])([A-Za-z])\s?(\d{1,2})(?![0-9])/u', $text, $m, PREG_SET_ORDER);
        $codes = collect($m)->map(fn ($x) => strtoupper($x[1]).(int) $x[2])->unique();

        return $codes->isEmpty() ? collect() : $session->items()->with('session', 'variant.product')->whereIn('code', $codes)->get();
    }

    private function position(LiveReservation $reservation): int
    {
        return LiveReservation::forStore($reservation->store_id)->where('live_item_id', $reservation->live_item_id)->where('status', 'waiting')
            ->where('id', '<=', $reservation->id)->count();
    }

    private function label(LiveItem $item): string
    {
        return $item->variant?->fullTitle() ?? 'Article';
    }

    private function reply(Conversation $conversation, string $text): void
    {
        try {
            app(Inbox::class)->replyAutomatically($conversation, $text);
        } catch (Throwable $e) {
            Log::info('Live : réponse WhatsApp non envoyée', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    private function tell(int $storeId, string $phone, string $text): void
    {
        $conversation = Conversation::forStore($storeId)->where('channel', 'whatsapp')->where('contact', $phone)->first();
        if ($conversation !== null) {
            $this->reply($conversation, $text);
        }
    }

    /**
     * A1…A9, B1…B9, …
     */
    public static function code(int $index): string
    {
        return chr(65 + intdiv($index, 9) % 26).($index % 9 + 1);
    }
}
