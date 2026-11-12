<?php

namespace App\Services\Live;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\QuotaExceededException;
use App\Jobs\ReadLiveCapture;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\LiveSession;
use App\Models\Order;
use App\Models\Place;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\VolumePrice;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Services\Ai\Assistant;
use App\Services\Billing\Credits;
use App\Services\Orders\OrderDesk;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\Promotions\Pricing;
use App\Services\WhatsApp\DeliveryFeesOnWhatsApp;
use App\Services\WhatsApp\Inbox;
use App\Services\WhatsApp\ModelsOnWhatsApp;
use App\Services\WhatsApp\WhatsAppBridge;
use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
    /** Commandes gardées actives après « Terminer le live » (heures) */
    public const KEEP_HOURS = [24, 48];

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
            $session ??= new LiveSession(['store_id' => $store->id, 'status' => 'draft', 'created_by' => $user?->id,
                'channel' => ($data['channel'] ?? null) === LiveSession::CHANNEL_WHATSAPP ? LiveSession::CHANNEL_WHATSAPP : 'live']);
            // Articles changés : la photo à épingler est à refaire (étape 3 de « Préparer un live »)
            $session->pin_ready_at = null;
            $session->fill([
                'title' => $data['title'], 'hold_minutes' => $data['hold_minutes'] ?? $session->hold_minutes ?? 120,
                'stream_url' => array_key_exists('stream_url', $data) ? ($data['stream_url'] ?: null) : $session->stream_url,
            ])->save();

            // Codes réattribués dans l'ordre de la liste : codes provisoires d'abord, pour ne pas croiser l'index unique
            LiveItem::forStore($store->id)->where('live_session_id', $session->id)->get()
                ->each(fn (LiveItem $item) => $item->forceFill(['code' => '#'.$item->id])->saveQuietly());
            $kept = [];
            $codes = self::codes(array_values($data['items']), $variants);
            foreach (array_values($data['items']) as $i => $line) {
                $variant = $variants->get($line['variant_id']);
                $item = LiveItem::forStore($store->id)->where('live_session_id', $session->id)->where('variant_id', $variant->id)->first()
                    ?? new LiveItem(['store_id' => $store->id, 'live_session_id' => $session->id, 'variant_id' => $variant->id]);
                $item->fill([
                    'code' => $codes[$variant->product_id],
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
        $variants = ProductVariant::forStore($session->store_id)->with('product.images')->whereIn('id', collect($lines)->pluck('variant_id'))->get()->keyBy('id');
        foreach (array_values($lines) as $i => $line) {
            $product = $variants->get($line['variant_id'])?->product;
            if ($product !== null && $product->images->isEmpty()) {
                throw new BusinessRuleException("Ajoute une photo à « {$product->title} » avant de le mettre en live.", "items.{$i}.variant_id");
            }
        }

        $touched = DB::transaction(function () use ($session, $lines, $variants) {
            LiveSession::forStore($session->store_id)->lockForUpdate()->find($session->id);
            $existing = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->get();
            if ($existing->count() + count($lines) > 60) {
                throw new BusinessRuleException('60 articles au plus dans un live.');
            }
            $existing->loadMissing('variant');
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
                    // Autre variante d'un produit déjà dans le live : même code ; sinon la pancarte choisie, ou le code libre suivant
                    $code = $existing->first(fn (LiveItem $other) => $other->variant?->product_id === $variant->product_id)?->code;
                    if ($code === null && ($sign = self::sign($line['code'] ?? null)) !== null) {
                        if (in_array($sign, $used, true)) {
                            throw new BusinessRuleException("La pancarte {$sign} est déjà utilisée dans ce live. Choisissez une autre pancarte.", "items.{$i}.code");
                        }
                        $code = $sign;
                    }
                    // Pack : pancarte P1, P2…
                    for ($n = 1; $code === null && $variant->product->is_bundle && $n < 100; $n++) {
                        if (! in_array('P'.$n, $used, true)) {
                            $code = 'P'.$n;
                        }
                    }
                    while ($code === null && (in_array($candidate = self::code($index), $used, true) || str_starts_with($candidate, 'P'))) {
                        $index++;
                    }
                    $code ??= $candidate;
                    $used[] = $code;
                    $item = LiveItem::create([
                        'store_id' => $session->store_id, 'live_session_id' => $session->id, 'variant_id' => $variant->id,
                        'code' => $code, 'price' => $line['price'] ?? $variant->price, 'quantity' => $line['quantity'], 'position' => ++$position,
                    ]);
                    $item->setRelation('variant', $variant);
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

    public function start(LiveSession $session, ?int $openHours = null): LiveSession
    {
        $whatsapp = $session->isWhatsApp();
        if ($session->status === 'ended') {
            throw new BusinessRuleException($whatsapp ? 'Ces commandes WhatsApp sont fermées : rouvre-les avec ces articles.' : 'Ce live est terminé.');
        }
        if ($session->items()->doesntExist()) {
            throw new BusinessRuleException($whatsapp ? 'Ajoute au moins un article avant d\'ouvrir les commandes.' : 'Ajoutez au moins un article avant de lancer le live.');
        }
        $running = LiveSession::forStore($session->store_id)->where('status', 'live')->whereKeyNot($session->id)->get();
        // Un seul code A1 à la fois : lancer un live ferme les commandes WhatsApp ; l'inverse attend la fin du live
        foreach ($running as $other) {
            if ($other->isWhatsApp() && ! $whatsapp) {
                $this->end($other);

                continue;
            }
            throw new BusinessRuleException($other->isWhatsApp() ? 'Les commandes WhatsApp sont déjà ouvertes : ferme-les d\'abord.'
                : ($whatsapp ? 'Un live est en cours : ouvre les commandes WhatsApp après le live.' : 'Un autre live est en cours : terminez-le d\'abord.'));
        }
        // Un seul live à la fois : les commandes encore actives d'un live terminé s'arrêtent (et il est compté)
        LiveSession::forStore($session->store_id)->where('status', 'ended')->where('orders_until', '>', now())->whereKeyNot($session->id)
            ->get()->each(fn (LiveSession $old) => $this->stopOrders($old));
        // Les clientes commandent sur la photo : un article sans photo ne passe pas en live
        $missing = $session->items()->with('variant.product.images')->orderBy('position')->get()
            ->filter(fn (LiveItem $item) => $item->variant?->product !== null && $item->variant->product->images->isEmpty())
            ->unique(fn (LiveItem $item) => $item->variant->product_id)
            ->map(fn (LiveItem $item) => "{$item->code} {$item->variant->product->title}");
        if ($missing->isNotEmpty()) {
            throw new BusinessRuleException('Ajoute une photo avant '.($whatsapp ? 'd\'ouvrir les commandes' : 'le live').' : '.$missing->implode(', ').'.');
        }
        $session->update(['status' => 'live', 'started_at' => $session->started_at ?? now()]
            // Commandes WhatsApp : ouvertes 24 h, 3 jours, 7 jours, ou jusqu'à « Fermer »
            + ($whatsapp ? ['orders_until' => in_array($openHours, LiveSession::WHATSAPP_HOURS, true) ? now()->addHours($openHours) : null] : []));

        return $session;
    }

    /**
     * Fin du live : plus de nouvelles réservations ; les clients déjà servis peuvent encore donner leur quartier.
     */
    public function end(LiveSession $session, ?int $keepHours = null): LiveSession
    {
        $keep = ! $session->isWhatsApp() && in_array($keepHours, self::KEEP_HOURS, true) && $session->status === 'live';
        $session->update(['status' => 'ended', 'ended_at' => $session->ended_at ?? now(), 'orders_until' => $keep ? now()->addHours($keepHours) : null]);
        if ($keep) {
            // Commandes encore actives : le live sera compté quand elles s'arrêtent (une seule fois)
            return $session->refresh();
        }
        // Commandes payantes : les commandes du live retirées du solde
        app(Credits::class)->bill($session);

        return $session->refresh();
    }

    /**
     * Commandes après le live arrêtées (bouton, heure passée ou nouveau live) : plus de réservations, live compté.
     */
    public function stopOrders(LiveSession $session): LiveSession
    {
        if ($session->status !== 'ended') {
            return $this->end($session);
        }
        if ($session->orders_until !== null && $session->orders_until->isFuture()) {
            $session->update(['orders_until' => now()]);
        }
        app(Credits::class)->bill($session);

        return $session->refresh();
    }

    /** Commandes nées des codes après « Terminer le live » */
    public function ordersAfter(LiveSession $session): int
    {
        return LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->whereNotNull('order_id')
            ->whereHas('order', fn ($q) => $q->where('created_at', '>', $session->ended_at))->distinct()->count('order_id');
    }

    /**
     * Live qui prend les commandes : en cours, sinon terminé avec commandes encore actives.
     */
    public static function ordering(int $storeId): ?LiveSession
    {
        $current = self::current($storeId);
        // Commandes WhatsApp en pause (crédit vide) ou arrivées à leur heure de fermeture : plus de nouvelles commandes
        if ($current !== null && $current->takesOrders()) {
            return $current;
        }

        return LiveSession::forStore($storeId)->where('status', 'ended')->where('channel', 'live')->where('orders_until', '>', now())->latest('ended_at')->first();
    }

    /**
     * Article montré en ce moment (mis en avant sur la page du live), ou plus aucun.
     */
    public function feature(LiveSession $session, ?int $itemId): LiveSession
    {
        if ($session->status === 'ended') {
            throw new BusinessRuleException('Ce live est terminé.');
        }
        $item = $itemId !== null ? LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->find($itemId) : null;
        if ($itemId !== null && $item === null) {
            throw new BusinessRuleException('Cet article n\'est pas dans le live.', 'item_id');
        }
        if ($item?->hidden()) {
            throw new BusinessRuleException('C\'est l\'article mystère : révèle-le avec « Article mystère » pour le montrer.', 'item_id');
        }
        // Article choisi : les écrans du live le montrent (fin du défilement ou de « Comment commander »)
        $session->update(['featured_item_id' => $itemId] + ($itemId !== null ? ['display_mode' => 'product'] : []));

        return $session;
    }

    /**
     * Article mystère : caché des clientes (écrans, liste envoyée, étiquette) jusqu'à sa révélation pendant le live,
     * avec jusqu'à 3 indices donnés un par un. Toutes les tailles et couleurs du même code suivent.
     *
     * @param  list<string>  $hints
     */
    public function mystery(LiveSession $session, string $code, bool $mystery, array $hints = []): LiveSession
    {
        if ($session->status === 'ended') {
            throw new BusinessRuleException('Ce live est terminé.');
        }
        $items = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->where('code', $code)->get();
        if ($items->isEmpty()) {
            throw new BusinessRuleException('Cet article n\'est pas dans le live.', 'code');
        }
        if ($mystery && $items->contains(fn (LiveItem $i) => $i->revealed_at !== null)) {
            throw new BusinessRuleException('Cet article a déjà été révélé.', 'code');
        }
        $hints = array_values(array_slice(array_filter(array_map(fn ($h) => trim((string) $h), $hints)), 0, 3));
        LiveItem::forStore($session->store_id)->whereIn('id', $items->pluck('id'))->update(['mystery' => $mystery, 'mystery_hints' => $mystery && $hints ? json_encode($hints, JSON_UNESCAPED_UNICODE) : null]);
        // Article montré devenu mystère : l'écran ne doit plus le montrer
        if ($mystery && $items->contains('id', $session->featured_item_id)) {
            $session->update(['featured_item_id' => null]);
        }
        $session->touch();

        return $session->fresh();
    }

    /**
     * Refaire un live : nouveau live en préparation avec les mêmes articles, dans le même ordre (prix et pièces
     * repris ; les pièces sont ramenées au stock disponible, au moins une).
     */
    public function duplicate(LiveSession $session, ?User $user): LiveSession
    {
        $session->loadMissing('items.variant.levels');
        $lines = $session->items->filter(fn (LiveItem $i) => $i->variant !== null)->map(fn (LiveItem $i) => [
            'variant_id' => $i->variant_id,
            'code' => $i->code, // mêmes pancartes que le live d'origine
            'price' => $i->price,
            'quantity' => max(1, min($i->quantity, (int) $i->variant->levels->sum(fn ($l) => $l->available()) ?: $i->quantity)),
        ])->values()->all();
        if ($lines === []) {
            throw new BusinessRuleException('Les articles de ce live n\'existent plus dans votre catalogue.');
        }
        $store = Store::findOrFail($session->store_id);

        return $this->save($store, null, [
            'title' => ($session->isWhatsApp() ? 'Commandes WhatsApp du ' : 'Live du ').now()->locale('fr')->isoFormat('dddd D MMM'), 'hold_minutes' => $session->hold_minutes,
            'stream_url' => $session->stream_url, 'items' => $lines, 'channel' => $session->channel,
        ], $user);
    }

    /**
     * Vente flash : prix live sur un article pendant quelques minutes ; l'article est montré sur les écrans.
     * Sans prix : la vente flash s'arrête.
     */
    public function flash(LiveSession $session, LiveItem $item, ?int $price, int $minutes = 5): LiveItem
    {
        if ($session->status !== 'live') {
            throw new BusinessRuleException('La vente flash se lance pendant le live.');
        }
        // Toutes les variantes du produit (même code) : tailles et couleurs au même prix live
        $group = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->where('code', $item->code);
        if ($price === null) {
            $group->update(['flash_price' => null, 'flash_ends_at' => null]);

            return $item;
        }
        $lowest = (int) (clone $group)->min('price');
        if ($price >= $lowest) {
            throw new BusinessRuleException('Le prix live doit être plus bas que '.Money::format($lowest).'.', 'price');
        }
        $group->update(['flash_price' => $price, 'flash_ends_at' => now()->addMinutes(max(1, min(60, $minutes))), 'updated_at' => now()]);
        $this->feature($session, $item->id);

        return $item;
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
     * Encore une pièce en stock dans la boutique pour faire la commande : stock disponible de la variante, moins les
     * pièces déjà gardées sans commande. Sinon la cliente passe en liste d'attente, comme pour un article épuisé
     * dans le live (vente en rupture, précommande ou stock non suivi : toujours en stock).
     */
    private function inStock(LiveItem $item): bool
    {
        $variant = ProductVariant::with('product', 'levels')->find($item->variant_id);
        $product = $variant?->product;
        if ($variant === null || $product === null || $product->allow_backorder || $product->preorder_enabled || ! $product->track_inventory) {
            return true;
        }
        $available = (int) $variant->levels->sum(fn ($level) => $level->available());
        $held = LiveReservation::forStore($item->store_id)->where('status', 'reserved')->whereNull('order_id')
            ->whereHas('item', fn ($q) => $q->where('variant_id', $item->variant_id))->count();

        return $available - $held > 0;
    }

    /**
     * Pièces restantes de plusieurs articles en une seule requête (écran du live, affichage, bilans) : id => restantes.
     *
     * @param  Collection<int, LiveItem>  $items
     * @return array<int, int>
     */
    public function remainingFor(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $held = LiveReservation::forStore($items->first()->store_id)->whereIn('live_item_id', $items->pluck('id'))->whereIn('status', LiveReservation::HOLDING)
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereHas('order', fn ($o) => $o->where('status', '!=', 'cancelled')))
            ->selectRaw('live_item_id, count(*) as n')->groupBy('live_item_id')->pluck('n', 'live_item_id');

        return $items->mapWithKeys(fn (LiveItem $item) => [$item->id => max(0, $item->quantity - (int) ($held[$item->id] ?? 0))])->all();
    }

    /**
     * Réservation d'un article. Avec le quartier (page du live, équipe) : commande créée tout de suite.
     *
     * @param  array{name?: ?string, phone?: ?string, place_id?: ?int, address?: ?string}  $customer
     */
    public function reserve(LiveItem $item, array $customer, string $source): LiveReservation
    {
        $session = $item->session;
        if (! $session->takesOrders()) {
            throw new BusinessRuleException($session->isWhatsApp() ? 'Les commandes WhatsApp ne sont pas ouvertes.' : 'Ce live n\'est pas en cours.');
        }
        $phone = PhoneNumber::normalize($customer['phone'] ?? null);
        if ($source === 'page' && $phone !== null) {
            $this->pageLimits($session, collect([$item]), [$item->id => 1], $phone);
        }
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
                // Prix du moment (prix flash pendant le chrono) : gardé même si le chrono finit avant la commande
                'unit_price' => $item->currentPrice(),
                'source' => $source,
                'status' => $this->remaining($item) > 0 && $this->inStock($item) ? 'reserved' : 'waiting',
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

    /** Pièces de l'article déjà demandées par ce numéro (gardées, en attente, ou dans la commande pas encore partie) */
    private function piecesAsked(LiveItem $item, string $phone): int
    {
        $mine = fn (array $statuses) => LiveReservation::forStore($item->store_id)->where('live_item_id', $item->id)
            ->where('customer_phone', $phone)->whereIn('status', $statuses);

        return $mine(['reserved', 'waiting'])->count()
            + $mine(['ordered'])->whereHas('order', fn ($o) => $o->whereIn('status', [OrderStatus::PendingConfirmation->value, OrderStatus::Confirmed->value]))->count();
    }

    public const PAGE_MAX_PER_PHONE_HOUR = 5;

    /**
     * Page publique du live : un même numéro ne réserve pas plus que le maximum par article (comme sur WhatsApp), ni
     * en série (fausses réservations qui bloquent le stock des vraies clientes pendant le direct).
     *
     * @param  Collection<int, LiveItem>  $items
     * @param  array<int, int>  $lines
     * @return array<int, int>
     */
    private function pageLimits(LiveSession $session, Collection $items, array $lines, string $phone): array
    {
        $key = "live-page:{$session->store_id}:{$phone}";
        if (RateLimiter::tooManyAttempts($key, self::PAGE_MAX_PER_PHONE_HOUR)) {
            throw new BusinessRuleException('Trop de réservations avec ce numéro en peu de temps. Écrivez à la boutique pour ajouter des articles.');
        }
        $allowed = [];
        foreach ($items as $item) {
            // Pièces en plus de ce que ce numéro a déjà demandé, sans dépasser le maximum par article
            $missing = min($lines[$item->id], self::maxPieces($item) - $this->piecesAsked($item, $phone));
            if ($missing > 0) {
                $allowed[$item->id] = $missing;
            }
        }
        if ($allowed === []) {
            throw new BusinessRuleException('Vous avez déjà réservé ces articles : la boutique vous appelle pour confirmer.');
        }
        RateLimiter::hit($key, 3600);

        return $allowed;
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
        if (! $session->takesOrders()) {
            throw new BusinessRuleException($session->isWhatsApp() ? 'Les commandes WhatsApp ne sont pas ouvertes.' : 'Ce live n\'est pas en cours.');
        }
        $items = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->whereIn('id', array_keys($lines))->orderBy('position')->get();
        if ($items->count() !== count($lines)) {
            throw new BusinessRuleException('Un des articles n\'est plus proposé dans ce live : rechargez la page.');
        }
        $phone = PhoneNumber::normalize($customer['phone'] ?? null);
        if ($source === 'page' && $phone !== null) {
            $lines = $this->pageLimits($session, $items, $lines, $phone);
            $items = $items->filter(fn (LiveItem $item) => isset($lines[$item->id]))->values();
        }
        $placeId = isset($customer['place_id']) ? $this->places->forStore($session->store)->firstWhere('id', (int) $customer['place_id'])?->id : null;
        // Page du live : un quartier choisi mais pas livré (gardé d'une ancienne visite) ne doit pas passer inaperçu
        if ($source === 'page' && isset($customer['place_id']) && $placeId === null && blank($customer['address'] ?? null)) {
            throw new BusinessRuleException('Ce quartier n\'est pas livré par la boutique : choisissez-le dans la liste.', 'place_id');
        }
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
                        'unit_price' => $item->currentPrice(),
                        'source' => $source,
                        'status' => $this->remaining($item) > 0 && $this->inStock($item) ? 'reserved' : 'waiting',
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
        // Seulement un message qui nomme un quartier livré : une question (« c'est combien ? », « vous livrez à
        // Bingerville ? ») reste à l'équipe ; « Yopougon ? » tout court reste une réponse
        if (str_contains($text, '?') && str_word_count(Str::ascii($text)) > 2) {
            return null;
        }
        $place = $this->matcher->search($text, $this->places->forStore($store), 1)->first();
        if ($place === null) {
            return null;
        }

        return $this->deliverTo($pending, $place, mb_substr(trim($text), 0, 255));
    }

    /**
     * Lieu de livraison connu (tapé ou choisi dans la liste WhatsApp) : les pièces gardées deviennent une commande.
     *
     * @param  Collection<int, LiveReservation>  $pending
     */
    public function deliverTo(Collection $pending, Place $place, ?string $address = null): ?Order
    {
        $pending->each->update(['shipping_place_id' => $place->id, 'shipping_address' => $address ?? $place->label(), 'expires_at' => null]);

        return $this->order($pending);
    }

    /**
     * Réservations sans quartier arrivées à échéance : pièces libérées pour la liste d'attente.
     */
    public function expire(): int
    {
        $this->remind();
        $due = LiveReservation::withoutGlobalScopes()->with('item.session')->where('status', 'reserved')->whereNull('order_id')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->get();
        foreach ($due as $reservation) {
            $reservation->update(['status' => 'expired']);
            $this->promote($reservation->item);
        }

        return $due->count();
    }

    /**
     * Pièce gardée sans quartier : un rappel un quart d'heure avant de la libérer (liste des communes sur le WhatsApp
     * officiel), une seule fois.
     */
    public function remind(): int
    {
        $due = LiveReservation::withoutGlobalScopes()->with('item:id,code')->where('status', 'reserved')->whereNull('order_id')->whereNull('reminded_at')
            ->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addMinutes(15)])->where('created_at', '<=', now()->subMinutes(5))->get();
        foreach ($due->groupBy(fn (LiveReservation $r) => $r->store_id.'|'.$r->customer_phone) as $group) {
            $first = $group->first();
            LiveReservation::withoutGlobalScopes()->whereIn('id', $group->pluck('id'))->update(['reminded_at' => now()]);
            $conversation = Conversation::forStore($first->store_id)->where('channel', 'whatsapp')->where('contact', $first->customer_phone)->first();
            if ($conversation === null) {
                continue;
            }
            $codes = $group->pluck('item.code')->filter()->unique()->implode(', ');
            $minutes = max(1, (int) ceil(now()->diffInMinutes($group->min('expires_at'))));
            $this->askAddress($conversation, "⏰ *Votre {$codes} est gardé encore {$minutes} minute".($minutes > 1 ? 's' : '').'*'."\nDonnez votre adresse pour le garder.");
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
    public function fromWhatsApp(Conversation $conversation, ConversationMessage $message, ?string $payload = null): bool
    {
        if ($conversation->channel !== 'whatsapp' || blank($message->body)) {
            return false;
        }
        // Liste ou bouton touchés pendant la commande du live (WhatsApp officiel)
        if ($payload !== null && str_starts_with($payload, 'live:')) {
            return app(LiveCheckout::class)->onReply($conversation, $payload);
        }
        // Modèles de la boutique : catégorie, « Je prends », taille, quantité touchées
        if ($payload !== null && str_starts_with($payload, 'models:')) {
            return app(ModelsOnWhatsApp::class)->onReply($conversation, $payload);
        }
        // Prix de livraison : liste des communes, une commune touchée (pour voir, sans changer l'adresse)
        if ($payload !== null && str_starts_with($payload, 'fees:')) {
            return app(DeliveryFeesOnWhatsApp::class)->onReply($conversation, $payload);
        }
        // Capture du reçu de paiement d'une expédition payée avant l'envoi (lien Wave de la boutique)
        if (app(LiveCheckout::class)->receipt($conversation, $message)) {
            return true;
        }
        $session = self::ordering($conversation->store_id);
        if ($message->type === 'image') {
            if ($session === null) {
                return false;
            }
            // Lecture de la photo (téléchargement, IA) hors de la notification de WhatsApp. Trois par 10 minutes et par
            // client : des dizaines d'images envoyées pendant le live ne vident pas les actions IA de la boutique
            $key = 'live-capture:'.$conversation->id;
            if (RateLimiter::tooManyAttempts($key, 3)) {
                return true; // la photo reste dans la boîte Messages, l'équipe la voit
            }
            RateLimiter::hit($key, 600);
            ReadLiveCapture::dispatch($message->id)->afterCommit();

            return true;
        }
        if ($message->type !== 'text') {
            return false;
        }
        // Réponse au récapitulatif en texte (WhatsApp relié par QR code) : 1 / OUI, 2 / AJOUTER, 3 / ADRESSE, 4 / ANNULER
        if (app(LiveCheckout::class)->answer($conversation, (string) $message->body)) {
            return true;
        }
        $models = app(ModelsOnWhatsApp::class);
        // « LIVE » (ou « liste », « articles », « catalogue ») : tous les articles encore disponibles du live ; sans live,
        // les modèles de la boutique (catégories puis photos)
        if (self::asksForList((string) $message->body) && ($session !== null || ! $models->enabled($conversation))) {
            $this->sendList($conversation, $session);

            return true;
        }
        // Codes du live (« A1 L rouge ») ; sinon réponse à la question « Quelle taille ? »
        if ($session !== null && ($this->takeCodes($conversation, $session, (string) $message->body) || $this->chooseOption($conversation, (string) $message->body))) {
            return true;
        }
        // Modèles de la boutique : « modèles », « vous avez quoi ? », ou réponse tapée (numéro, taille, quantité)
        if ($models->fromText($conversation, (string) $message->body)) {
            return true;
        }

        $order = $this->complete($conversation->store, PhoneNumber::normalize($conversation->contact), $message->body)
            ?? $models->complete($conversation, (string) $message->body);
        // Récapitulatif : prix de la livraison, total, et confirmer / ajouter / changer l'adresse / annuler
        if ($order !== null) {
            app(LiveCheckout::class)->recap($conversation, $order);

            return true;
        }
        // « livraison », « c'est combien la livraison à Yop ? » : les prix de livraison
        if (app(DeliveryFeesOnWhatsApp::class)->fromText($conversation, (string) $message->body)) {
            return true;
        }
        // Code d'un live terminé (commandes arrêtées) : réponse polie avec le lien de la boutique
        if ($session === null && $this->endedLiveCode($conversation, (string) $message->body)) {
            return true;
        }

        return $this->placeNotFound($conversation, (string) $message->body);
    }

    /**
     * Code d'un article d'un live terminé depuis moins de 7 jours, commandes arrêtées : on le dit au client avec le
     * lien de la boutique (une fois par heure et par conversation) ; le message reste dans Messages pour l'équipe.
     */
    private function endedLiveCode(Conversation $conversation, string $text): bool
    {
        $codes = array_keys(self::quantities($text));
        if ($codes === [] || str_word_count(Str::ascii($text)) > 6) {
            return false;
        }
        // Live terminé, ou commandes WhatsApp fermées ou en pause
        $last = self::current($conversation->store_id)
            ?? LiveSession::forStore($conversation->store_id)->where('status', 'ended')->where('ended_at', '>=', now()->subDays(7))->latest('ended_at')->first();
        if ($last === null || $last->items()->whereIn('code', $codes)->doesntExist()) {
            return false;
        }
        if (Cache::add('live-ended-code:'.$conversation->id, 1, 3600)) {
            $this->reply($conversation, ($last->isWhatsApp() ? 'Les commandes sont fermées pour le moment 🙏' : 'Ce live est terminé 🙏')
                .' Tu peux commander sur notre boutique : '.rtrim($conversation->store->url('/'), '/')."\nOu écris-nous ici, on te répond.");
        }

        return true;
    }

    /** Une seule relance « quartier pas reconnu » par cliente toutes les 10 minutes */
    private const PLACE_ASK_SECONDS = 600;

    /**
     * Pièce gardée en attente du quartier, et une réponse courte où aucun lieu n'est reconnu (« près du marché ») :
     * on le dit à la cliente au lieu de se taire (liste des communes avec le WhatsApp officiel). Une question
     * (« c'est combien la livraison ? ») ou un long message restent à l'équipe. La vendeuse voit « quartier à vérifier ».
     */
    private function placeNotFound(Conversation $conversation, string $text): bool
    {
        if (str_contains($text, '?') || str_word_count(Str::ascii($text)) > 8) {
            return false;
        }
        $waiting = LiveReservation::forStore($conversation->store_id)->where('customer_phone', PhoneNumber::normalize($conversation->contact))
            ->where('status', 'reserved')->whereNull('order_id')->exists()
            || app(ModelsOnWhatsApp::class)->waiting($conversation);
        if (! $waiting) {
            return false;
        }
        if (Cache::add('live-place-ask:'.$conversation->id, 1, self::PLACE_ASK_SECONDS)) {
            $this->askAddress($conversation, '⚠️ *Quartier non reconnu*', 'Yopougon Siporex');
        }

        return true;
    }

    /**
     * Quartier choisi par l'équipe pour une cliente dont la réponse n'a pas été reconnue : ses pièces gardées de ce
     * live deviennent une commande.
     */
    public function placeFor(LiveSession $session, LiveReservation $reservation, Place $place, ?string $address = null): ?Order
    {
        $pending = LiveReservation::forStore($session->store_id)->with('item.session')->where('live_session_id', $session->id)
            ->where('customer_phone', $reservation->customer_phone)->where('status', 'reserved')->whereNull('order_id')->get();
        if ($pending->isEmpty()) {
            throw new BusinessRuleException('Cette cliente n\'a plus de pièce gardée dans ce live.');
        }

        $order = $this->deliverTo($pending, $place, $address);
        if ($order === null) {
            throw new BusinessRuleException('Commande pas créée : '.(Cache::get('live-order-error:'.$pending->first()->id) ?? 'réessayez dans un instant.'));
        }

        return $order;
    }

    /**
     * Capture d'écran envoyée pendant le live : le code de l'article montré à l'écran (A3) est lu sur la photo,
     * d'abord dans la légende, sinon par l'IA. Sans code lisible, on demande au client de l'écrire.
     */
    public function readCapture(ConversationMessage $message): void
    {
        $conversation = Conversation::forStore($message->store_id)->find($message->conversation_id);
        $session = self::ordering($message->store_id);
        if ($conversation === null || $session === null) {
            return;
        }
        $caption = preg_replace('/^📷 Photo( : )?/u', '', (string) $message->body);
        if ($this->takeCodes($conversation, $session, $caption, true)) {
            return;
        }
        $code = $this->codeOnPhoto($conversation, $message, $session);
        if ($code !== null && $this->takeCodes($conversation, $session, $code.' '.$caption, true)) {
            return;
        }
        $codes = $session->items->reject(fn (LiveItem $i) => $i->hidden())->pluck('code')->unique()->take(3)->implode(', ');
        $this->reply($conversation, "Nous n'avons pas trouvé le code de l'article sur votre capture. Écrivez-nous le code affiché pendant le live (par exemple {$codes}).");
    }

    /**
     * Codes du live dans un message. Un produit avec des tailles ou des couleurs a un seul code : la variante est lue
     * dans les mots qui suivent le code (« A1 L rouge ») ; s'il en manque, la cliente reçoit la question
     * (« Quelle taille ? » avec les tailles qui restent). Vrai si le message contenait un code du live.
     */
    private function takeCodes(Conversation $conversation, LiveSession $session, string $text, bool $first = false): bool
    {
        $quantities = self::quantities($text);
        if ($quantities === []) {
            return false;
        }
        $groups = $session->items()->with(self::VARIANT_RELATIONS)->whereIn('code', array_keys($quantities))->orderBy('position')->get()->groupBy('code');
        if ($groups->isEmpty()) {
            return false;
        }
        $segments = self::segments($text, $groups->keys()->all());
        $items = collect();
        $wanted = [];
        $ask = null;
        $notes = [];
        foreach (array_keys($quantities) as $code) {
            $group = $groups->get($code);
            if ($group === null) {
                continue;
            }
            $product = $group->first()->variant?->product;
            // Tailles mélangées : « A4 6 M 6 L »
            $mix = $this->mixedSizes($group, $segments[$code] ?? '');
            if ($mix !== null) {
                foreach ($mix as [$item, $quantity]) {
                    $items->push($item);
                    $wanted[$item->id] = $quantity;
                }
                $total = array_sum(array_column($mix, 1));
                if ($product !== null && $product->sellableQuantity($total) > $total) {
                    $notes[] = "📦 {$code} se vend {$product->lotLabel()} : ajoutez encore ".($product->sellableQuantity($total) - $total).' pièce(s) (ex. '.$code.' 2 '.($mix[0][0]->optionValues()[0] ?? 'M').').';
                }
                if ($first) {
                    break;
                }

                continue;
            }
            // Vente par lot ou avec un minimum : quantité arrondie au lot au-dessus (jamais refusée)
            $quantity = $product?->sellableQuantity($quantities[$code]) ?? $quantities[$code];
            if ($quantity !== $quantities[$code]) {
                $notes[] = "📦 {$code} se vend {$product->lotLabel()} : je vous en mets {$quantity}.\nPour changer, écrivez par exemple {$code} x".($quantity * 2).'.';
            }
            [$item, $chosen] = $this->pickVariant($group, $this->chosenIn($group, $segments[$code] ?? ''));
            if ($item !== null) {
                $items->push($item);
                $wanted[$item->id] = $quantity;
            } elseif ($ask === null) {
                $ask = ['code' => $code, 'quantity' => $quantity, 'chosen' => $chosen];
            }
            if ($first) {
                break;
            }
        }
        if ($notes !== []) {
            $this->reply($conversation, implode("\n", $notes));
        }
        if ($items->isNotEmpty()) {
            $this->reserveFor($conversation, $items, $wanted);
        }
        if ($ask !== null) {
            $this->askOption($conversation, $session, $ask);
        }

        return true;
    }

    private const VARIANT_RELATIONS = ['session', 'variant.product', 'variant.option1.option', 'variant.option2.option', 'variant.option3.option'];

    /** Temps laissé pour répondre à « Quelle taille ? » */
    private const ASK_MINUTES = 30;

    private static function askKey(int $conversationId): string
    {
        return 'live-ask:'.$conversationId;
    }

    /**
     * Variante voulue d'après les valeurs choisies (position de l'option => valeur) : l'article si une seule convient
     * (en priorité parmi celles qui restent), sinon null et les choix gardés.
     *
     * @param  Collection<int, LiveItem>  $group
     * @param  array<int, string>  $chosen
     * @return array{0: ?LiveItem, 1: array<int, string>}
     */
    private function pickVariant(Collection $group, array $chosen): array
    {
        if ($group->count() === 1) {
            return [$group->first(), $chosen];
        }
        $fits = fn (LiveItem $item) => collect($chosen)->every(fn ($value, $position) => ($item->optionValues()[$position] ?? null) === $value);
        $candidates = $group->filter($fits)->values();
        if ($candidates->isEmpty()) {
            $chosen = [];
            $candidates = $group->values();
        }
        $left = $candidates->filter(fn (LiveItem $item) => $this->remaining($item) > 0)->values();
        $pool = $left->isNotEmpty() ? $left : $candidates;

        return [$pool->count() === 1 ? $pool->first() : null, $chosen];
    }

    /**
     * Tailles mélangées dans un même code (« 6 M 6 L », « 2 noir 4 rouge ») : pièces par variante, ou null si le texte
     * n'en donne pas (ou si une autre option manque encore).
     *
     * @param  Collection<int, LiveItem>  $group
     * @return ?list<array{0: LiveItem, 1: int}>
     */
    private function mixedSizes(Collection $group, string $segment): ?array
    {
        if ($group->count() < 2 || trim($segment) === '') {
            return null;
        }
        $positions = (int) $group->map(fn (LiveItem $item) => count($item->optionValues()))->max();
        $text = self::normalize($segment);
        for ($position = 0; $position < $positions; $position++) {
            $values = $group->map(fn (LiveItem $item) => $item->optionValues()[$position] ?? null)->filter()->unique()->values();
            if ($values->count() < 2) {
                continue;
            }
            $pairs = [];
            foreach ($values as $value) {
                $v = preg_quote(trim(self::normalize($value)), '/');
                if ($v !== '' && preg_match_all('/(?<![0-9a-z])(\d{1,2})\s*(?:x|×|\*)?\s*'.$v.'(?![a-z0-9])/u', $text, $m)) {
                    $pairs[$value] = array_sum(array_map('intval', $m[1]));
                }
            }
            $pairs = array_filter($pairs);
            if ($pairs === []) {
                return null;
            }
            // Les autres options nommées une seule fois s'appliquent à toutes les pièces (« 3 M 3 L rouge »)
            $others = array_diff_key($this->chosenIn($group, $segment), [$position => true]);
            $out = [];
            foreach ($pairs as $value => $quantity) {
                [$item] = $this->pickVariant($group, $others + [$position => $value]);
                if ($item === null) {
                    return null;
                }
                $out[] = [$item, min(99, $quantity)];
            }

            return $out;
        }

        return null;
    }

    /**
     * Valeurs nommées dans le texte qui suit le code (« L », « rouge ») : une seule par option, sinon on demandera.
     *
     * @param  Collection<int, LiveItem>  $group
     * @return array<int, string>
     */
    private function chosenIn(Collection $group, string $segment): array
    {
        $chosen = [];
        $positions = $group->map(fn (LiveItem $item) => count($item->optionValues()))->max();
        for ($position = 0; $position < $positions; $position++) {
            $values = $group->map(fn (LiveItem $item) => $item->optionValues()[$position] ?? null)->filter()->unique()->values();
            $named = $values->filter(fn (string $value) => self::mentions($segment, $value))->values();
            if ($named->count() === 1) {
                $chosen[$position] = $named->first();
            }
        }

        return $chosen;
    }

    private static function normalize(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }

    private static function mentions(string $text, string $value): bool
    {
        $value = trim(self::normalize($value));

        return $value !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($value, '/').'(?![a-z0-9])/u', self::normalize($text)) === 1;
    }

    /**
     * Question sur l'option qui manque (« Quelle taille ? ») avec seulement ce qui reste : boutons ou liste sur le
     * WhatsApp officiel, texte sinon. La réponse est attendue 30 minutes.
     *
     * @param  array{code: string, quantity: int, chosen: array<int, string>}  $ask
     */
    private function askOption(Conversation $conversation, LiveSession $session, array $ask): void
    {
        $group = $session->items()->with(self::VARIANT_RELATIONS)->where('code', $ask['code'])->orderBy('position')->get();
        $fits = fn (LiveItem $item) => collect($ask['chosen'])->every(fn ($value, $position) => ($item->optionValues()[$position] ?? null) === $value);
        $candidates = $group->filter($fits)->values();
        $left = $candidates->filter(fn (LiveItem $item) => $this->remaining($item) > 0)->values();
        $pool = $left->isNotEmpty() ? $left : $candidates;
        $positions = (int) $pool->map(fn (LiveItem $item) => count($item->optionValues()))->max();
        $position = collect(range(0, max(0, $positions - 1)))
            ->first(fn (int $p) => $pool->map(fn (LiveItem $item) => $item->optionValues()[$p] ?? null)->unique()->count() > 1) ?? 0;
        $values = $pool->map(fn (LiveItem $item) => $item->optionValues()[$position] ?? null)->filter()->unique()->values();
        $gone = $candidates->map(fn (LiveItem $item) => $item->optionValues()[$position] ?? null)->filter()->unique()->diff($values)->values();
        $first = $group->first();
        $name = Str::lower($first->optionNames()[$position] ?? 'choix');

        Cache::put(self::askKey($conversation->id), ['session' => $session->id] + $ask + ['position' => $position, 'values' => $values->all()], now()->addMinutes(self::ASK_MINUTES));

        $text = "{$ask['code']} ".($ask['quantity'] > 1 ? "× {$ask['quantity']} " : '').($first->variant?->product?->title ?? 'Article').', '.Money::format($first->currentPrice()).".\n*Quelle {$name} ?*"
            .($gone->isNotEmpty() ? ' ('.($gone->count() > 1 ? 'déjà parties : ' : 'déjà partie : ').$gone->implode(', ').')' : '')
            .($left->isEmpty() ? "\nTout est parti : vous serez sur la liste d'attente." : '')
            .($ask['quantity'] > 1 && $values->count() > 1 ? "\n_Pour mélanger, écrivez par exemple : ".intdiv($ask['quantity'], 2).' '.$values[0].' '.($ask['quantity'] - intdiv($ask['quantity'], 2)).' '.$values[1].'_' : '');
        if (! app(LiveCheckout::class)->askOption($conversation, $text, $values->all())) {
            $this->reply($conversation, $text."\nRépondez : ".$values->join(', ', ' ou ').'.');
        }
    }

    /**
     * Réponse à « Quelle taille ? » (bouton touché ou mot écrit) : la question suivante, ou la pièce gardée.
     * Faux si aucune question n'attend de réponse, ou si le message n'y répond pas (il suit alors son cours).
     */
    public function chooseOption(Conversation $conversation, string $answer): bool
    {
        $ask = Cache::get(self::askKey($conversation->id));
        $session = $ask ? LiveSession::forStore($conversation->store_id)->find($ask['session']) : null;
        if ($session === null || ! $session->takesOrders()) {
            return false;
        }
        $group = $session->items()->with(self::VARIANT_RELATIONS)->where('code', $ask['code'])->orderBy('position')->get();
        $mix = $this->mixedSizes($group, $answer);
        if ($mix !== null) {
            Cache::forget(self::askKey($conversation->id));
            $this->reserveFor($conversation, collect(array_column($mix, 0)), collect($mix)->mapWithKeys(fn ($m) => [$m[0]->id => $m[1]])->all());

            return true;
        }
        $value = collect($ask['values'])->first(fn (string $v) => self::normalize(trim($answer)) === self::normalize($v))
            ?? collect($ask['values'])->first(fn (string $v) => self::mentions($answer, $v));
        if ($value === null) {
            // Réponse courte qui ne correspond à rien : on rappelle les choix ; un message plus long suit son cours
            if (mb_strlen(trim($answer)) > 15) {
                return false;
            }
            $this->reply($conversation, 'Répondez avec un de ces choix : '.collect($ask['values'])->join(', ', ' ou ').'.');

            return true;
        }
        $chosen = $ask['chosen'];
        $chosen[$ask['position']] = $value;
        $group = $session->items()->with(self::VARIANT_RELATIONS)->where('code', $ask['code'])->orderBy('position')->get();
        [$item, $chosen] = $this->pickVariant($group, $chosen);
        if ($item === null) {
            $this->askOption($conversation, $session, ['code' => $ask['code'], 'quantity' => $ask['quantity'], 'chosen' => $chosen]);

            return true;
        }
        Cache::forget(self::askKey($conversation->id));
        $this->reserveFor($conversation, collect([$item]), [$item->id => $ask['quantity']]);

        return true;
    }

    /**
     * Texte qui suit chaque code jusqu'au code suivant (« A1 L rouge + A2 » : A1 => « L rouge + », A2 => « »).
     *
     * @return array<string, string>
     */
    private static function segments(string $text, ?array $codes = null): array
    {
        preg_match_all(self::CODE_PATTERN, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        // Seulement les codes du live : « A1 6 M 6 L » ne coupe pas sur « M 6 »
        if ($codes !== null) {
            $m = array_values(array_filter($m, fn ($x) => in_array(strtoupper($x[2][0]).(int) $x[3][0], $codes, true)));
        }
        $out = [];
        foreach ($m as $i => $x) {
            $code = strtoupper($x[2][0]).(int) $x[3][0];
            $start = $x[0][1] + strlen($x[0][0]);
            $end = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($text);
            $out[$code] = ($out[$code] ?? '').' '.substr($text, $start, $end - $start);
        }

        return $out;
    }

    /**
     * @param  Collection<int, LiveItem>  $items
     */
    /**
     * Codes reçus par WhatsApp : pièces voulues par article (« A2 x3 » = 3 pièces en tout ; renvoyer le même message
     * ne réserve rien de plus, tant que la commande n'est pas confirmée). Client qui a déjà une commande du live à confirmer : ajouté à cette commande, sans
     * redemander le quartier.
     *
     * @param  Collection<int, LiveItem>  $items
     * @param  array<int, int>  $quantities  article => pièces voulues (1 par défaut)
     */
    private function reserveFor(Conversation $conversation, Collection $items, array $quantities = []): void
    {
        $phone = PhoneNumber::normalize($conversation->contact);
        $session = $items->first()->session;
        $open = $this->openOrder($session, $phone);
        $mine = fn (LiveItem $item, array $statuses) => LiveReservation::forStore($session->store_id)->where('live_item_id', $item->id)
            ->where('customer_phone', $phone)->whereIn('status', $statuses);
        $lines = [];
        foreach ($items as $item) {
            $missing = min(self::maxPieces($item), $quantities[$item->id] ?? 1) - $this->piecesAsked($item, $phone);
            if ($missing > 0) {
                $lines[$item->id] = $missing;
            }
        }
        $customer = ['name' => $conversation->contact_name, 'phone' => $phone]
            + ($open ? ['place_id' => $open->shipping_place_id, 'address' => $open->shipping_address] : []);
        $created = $lines === [] ? collect() : $this->reserveLines($session, $lines, $customer, 'whatsapp');

        // Ce qu'il faut dire pour chaque article (gardé, ou sur la liste d'attente)
        $said = $items->mapWithKeys(function (LiveItem $item) use ($mine) {
            $held = $mine($item, LiveReservation::HOLDING)->count();
            $waiting = $mine($item, ['waiting'])->oldest('id')->get();
            $label = "{$item->code} ({$this->label($item)}";

            $text = collect([
                $held > 0 ? "✅ {$label}, ".Money::format($item->currentPrice()).')'.($held > 1 ? " × {$held} : réservées" : ' : réservée').' pour vous.' : null,
                $waiting->isEmpty() ? null : ($held > 0
                    ? "⏳ {$item->code} : {$waiting->count()} de plus sur la liste d'attente (il n'en restait pas assez)."
                    : "⏳ {$label}) : déjà partie, vous êtes n°{$this->position($waiting->first())} sur la liste d'attente."),
            ])->filter()->implode("\n");

            return [$item->id => $text];
        });
        $text = $said->filter()->implode("\n");
        $added = $created->firstWhere('status', 'ordered')?->order;
        $held = LiveReservation::forStore($conversation->store_id)->where('customer_phone', $phone)->where('status', 'reserved')->whereNull('order_id')->exists();
        $checkout = app(LiveCheckout::class);
        // Commande créée ou complétée : le récapitulatif (en image), qui montre déjà tous les articles
        if ($added !== null) {
            $wait = collect(explode("\n", $text))->filter(fn (string $l) => str_starts_with($l, '⏳'))->implode("\n");
            $wait === '' || $this->reply($conversation, $wait);
            $checkout->recap($conversation, $added);

            return;
        }
        // Un court texte pour tous les articles demandés (gardés ou en liste d'attente) ; la question de l'adresse part
        // ensuite, seule, pour qu'on la voie bien
        $kept = $items->filter(fn (LiveItem $item) => $mine($item, LiveReservation::HOLDING)->exists());
        $waits = collect(explode("\n", $text))->filter(fn (string $l) => str_starts_with($l, '⏳'));
        $caption = trim(($kept->isEmpty() ? '' : "✅ *C'est réservé pour vous !*\n"
            .$kept->map(fn (LiveItem $item) => $item->code.' '.($item->variant?->product?->title ?? 'Article').($item->optionValues() !== [] ? " ({$item->variant->title})" : '')
                .(($n = $mine($item, LiveReservation::HOLDING)->count()) > 1 ? " × {$n}" : '').' · '.Money::format($item->currentPrice()))->implode("\n")
            .(($gros = $this->wholesaleNote($session->store_id, $kept, $kept->mapWithKeys(fn (LiveItem $item) => [$item->id => $mine($item, LiveReservation::HOLDING)->count()])->all())) ? "\n".$gros : '')
            .($held ? "\n⏱️ Gardé *".self::duration((int) ($session->hold_minutes ?: 120)).'* pour vous' : '')."\n")
            .$waits->implode("\n"));
        // Pendant la commande : du texte seul (une image arrive plus tard qu'un texte et tomberait après la question de
        // l'adresse) ; les photos viennent avec le récapitulatif, puis en grand après « Je confirme »
        $text === '' || $this->reply($conversation, $caption !== '' ? $caption : $text);
        if ($held) {
            $this->askAddress($conversation, "📍 *DERNIÈRE ÉTAPE : L'ADRESSE*");
        }
    }

    /**
     * Question de l'adresse, en message à part : liste des communes (WhatsApp officiel) ou consigne à taper avec un
     * exemple (WhatsApp relié par QR code, ou liste impossible).
     */
    public function askAddress(Conversation $conversation, string $title, string $example = 'Cocody Angré 8e tranche'): void
    {
        app(LiveCheckout::class)->askCommune($conversation, $title."\n\n👇 Touchez *« Choisir ma commune »* ci-dessous")
            || $this->reply($conversation, $title."\n\n✍️ Écrivez *votre commune + votre quartier*\n_Exemple : {$example}_");
    }

    /** « 30 minutes », « 2 h », « 1 h 30 » */
    private static function duration(int $minutes): string
    {
        return $minutes < 60 ? "{$minutes} minutes" : intdiv($minutes, 60).' h'.($minutes % 60 > 0 ? ' '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '');
    }

    /**
     * Pièces qu'un client peut demander d'un coup : 10 pour un article vendu à la pièce, 99 pour un article vendu en
     * gros (prix de gros, lot ou minimum), un pack au choix compris.
     */
    private static function maxPieces(LiveItem $item): int
    {
        $product = $item->variant?->product;
        if ($product === null) {
            return 10;
        }
        $gros = $product->sale_lot || $product->min_order_quantity
            || VolumePrice::forStore($product->store_id)->where('product_id', $product->id)->exists();

        return $gros ? 99 : 10;
    }

    /**
     * Commande du live encore à confirmer de ce client : les articles suivants s'y ajoutent (une seule livraison).
     */
    private function openOrder(LiveSession $session, ?string $phone): ?Order
    {
        if ($phone === null) {
            return null;
        }

        return Order::forStore($session->store_id)->whereIn('status', [OrderStatus::PendingConfirmation->value, OrderStatus::Confirmed->value])
            ->whereIn('id', LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)
                ->where('customer_phone', $phone)->whereNotNull('order_id')->select('order_id'))
            ->latest('id')->first();
    }

    /** Pancarte : une lettre puis un nombre de 1 à 99 (A1, B12), reconnue par CODE_PATTERN dans les messages */
    private const SIGN_PATTERN = '/^[A-Z][1-9][0-9]?$/';

    private const CODE_PATTERN = '/(?<![A-Za-z0-9])(?:(\d{1,2})\s*[x×*]?\s*)?([A-Za-z])\s?(\d{1,2})(?![0-9])(?:\s*[x×*]\s*(\d{1,2})(?![0-9])|\s+(\d{1,2})\s*(?:pi[eè]ces?|pcs?|fois|exemplaires?)(?![A-Za-z]))?/iu';

    /**
     * Pièces voulues par code dans un message : « A2 x3 », « A2×3 », « 3 A2 », « 3xA2 », « A2 3 pièces » (99 au plus ;
     * 10 au plus réservées pour un article vendu à la pièce, voir maxPieces).
     *
     * @return array<string, int>
     */
    public static function quantities(string $text): array
    {
        preg_match_all(self::CODE_PATTERN, $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $code = strtoupper($x[2]).(int) $x[3];
            $qty = (int) (($x[4] ?? '') !== '' ? $x[4] : (($x[5] ?? '') !== '' ? $x[5] : (($x[1] ?? '') !== '' ? $x[1] : 1)));
            $out[$code] = max($out[$code] ?? 0, min(99, max(1, $qty)));
        }

        return $out;
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
        $session->loadMissing('items.variant.product.images', 'items.variant.option1.option', 'items.variant.option2.option', 'items.variant.option3.option');
        $reservations = $team ? LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->with('item:id,code', 'order:id,number,store_id,status,payment_proof_at')
            ->latest('id')->limit(200)->get() : collect();
        $counts = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)
            ->selectRaw('live_item_id, status, count(*) as n')->groupBy('live_item_id', 'status')->get();
        $left = $this->remainingFor($session->items);
        $errors = $team ? Cache::many($reservations->where('status', 'reserved')->whereNull('order_id')->map(fn (LiveReservation $r) => 'live-order-error:'.$r->id)->values()->all()) : [];

        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status,
            'hold_minutes' => $session->hold_minutes,
            'stream_url' => $session->stream_url,
            'featured_item_id' => $session->featured_item_id,
            'display_mode' => $session->display_mode ?: 'product',
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            // « Terminer le live » en gardant les commandes actives : jusqu'à quand, et combien de commandes depuis la fin
            'orders_until' => $session->extended() ? $session->orders_until->toIso8601String() : null,
            // Commandes WhatsApp sans live : heure de fermeture (null : jusqu'à « Fermer »), en pause si crédit vide
            'channel' => $session->channel,
            'closes_at' => $session->isWhatsApp() && $session->status === 'live' ? $session->orders_until?->toIso8601String() : null,
            'paused' => $session->isWhatsApp() && $session->status === 'live' && app(Credits::class)->paused($session),
            'orders_after' => $team && $session->status === 'ended' && $session->orders_until !== null ? $this->ordersAfter($session) : null,
            // « Préparer un live » : photo à épingler publiée (étape 3)
            'pin_ready' => $session->pin_ready_at !== null,
            // Solde : commandes prises, solde après le live (équipe seulement)
            'billing' => $team ? app(Credits::class)->progress($session) : null,
            // Page publique du live : l'article mystère n'apparaît qu'une fois révélé
            'items' => $session->items->reject(fn (LiveItem $item) => ! $team && $item->hidden())->map(fn (LiveItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'variant_id' => $item->variant_id,
                'product_id' => $item->variant?->product_id,
                'title' => $this->label($item),
                // Produit et variante séparés : une carte par produit (même code), les tailles et couleurs à choisir
                'product' => $item->variant?->product?->title ?? 'Article',
                'variant' => $item->optionValues() !== [] ? $item->variant->title : null,
                'options' => $item->optionValues(),
                'option_names' => $item->optionNames(),
                'image' => $item->variant?->product?->images->first()?->urls()['thumb'] ?? null,
                'price' => $item->currentPrice(),
                // Vente flash en cours : prix habituel du live et fin du chrono
                'flash' => $item->flashActive() ? ['was' => $item->price, 'ends_at' => $item->flash_ends_at->toIso8601String()] : null,
                'quantity' => $item->quantity,
                'remaining' => $left[$item->id],
                'waiting' => (int) $counts->where('live_item_id', $item->id)->where('status', 'waiting')->sum('n'),
                // Article mystère : caché des clientes jusqu'à sa révélation (indices pour l'équipe)
                'mystery' => $item->mystery,
                'revealed' => $item->revealed_at !== null,
                'hints' => $team ? array_values((array) $item->mystery_hints) : [],
            ])->values()->all(),
            'reservations' => $reservations->map(fn (LiveReservation $r) => [
                'id' => $r->id,
                'item_id' => $r->live_item_id,
                'code' => $r->item?->code,
                'unit_price' => $r->unit_price,
                'source' => $r->source,
                'status' => $r->status,
                'customer_name' => $r->customer_name,
                'customer_phone' => $r->customer_phone,
                'shipping_address' => $r->shipping_address,
                // Quartier connu mais commande pas créée (stock, quota…) : la raison, pour réessayer
                'shipping_place_id' => $r->shipping_place_id,
                'order_error' => $r->status === 'reserved' && $r->order_id === null ? ($errors['live-order-error:'.$r->id] ?? null) : null,
                'order' => $r->order ? ['id' => $r->order->id, 'reference' => $r->order->reference(), 'status' => $r->order->status->value,
                    // Reçu de paiement envoyé par la cliente, à vérifier par la boutique
                    'proof' => $r->order->payment_proof_at !== null] : null,
                'expires_at' => $r->expires_at?->toIso8601String(),
                'created_at' => $r->created_at->toIso8601String(),
            ])->values()->all(),
            // Messages WhatsApp reçus pendant le live (écran de la vendeuse) : code lu, capture, question à répondre
            'messages' => $team && $session->started_at !== null ? $this->messages($session, $reservations) : [],
            'summary' => $team ? $this->summary($session) : null,
            // Bilan : montant du live terminé juste avant, pour comparer
            'previous_amount' => $team && $session->status === 'ended' ? $this->previousAmount($session) : null,
            // WhatsApp qui répond aux clients pendant le live : official (Meta), link (relié par QR code) ou null
            'whatsapp' => $team ? self::whatsapp($session->store_id) : null,
            'whatsapp_phone' => $team ? self::whatsappNumber($session->store_id)['phone'] : null,
        ];
    }

    /**
     * Messages reçus sur WhatsApp depuis le début du live, du plus récent au plus ancien (40 au plus), pour l'écran de
     * la vendeuse : le code du live lu dans le message (ou sur la capture, d'après la réservation qui l'a suivie), et
     * « à répondre » quand c'est un texte sans code resté sans réponse (question de la cliente).
     *
     * @param  Collection<int, LiveReservation>  $reservations
     * @return list<array<string, mixed>>
     */
    private function messages(LiveSession $session, Collection $reservations): array
    {
        $until = $session->ended_at ?? now();
        $inbound = ConversationMessage::forStore($session->store_id)->where('direction', 'in')
            ->whereBetween('created_at', [$session->started_at, $until])
            ->whereHas('conversation', fn ($q) => $q->where('channel', 'whatsapp'))
            ->with('conversation:id,contact,contact_name')->latest('id')->limit(40)->get();
        if ($inbound->isEmpty()) {
            return [];
        }
        // Dernière réponse envoyée dans chaque conversation : un message reçu avant elle a eu sa réponse
        $answered = ConversationMessage::forStore($session->store_id)->where('direction', 'out')
            ->whereIn('conversation_id', $inbound->pluck('conversation_id')->unique())
            ->selectRaw('conversation_id, max(id) as last_id')->groupBy('conversation_id')->pluck('last_id', 'conversation_id');
        $codes = $session->items->pluck('code')->unique()->all();

        return $inbound->map(function (ConversationMessage $m) use ($answered, $codes, $reservations) {
            $read = array_values(array_intersect(array_keys(self::quantities((string) $m->body)), $codes));
            if ($read === [] && $m->type === 'image') {
                // Capture lue : la réservation créée juste après, pour cette cliente
                $code = $reservations->filter(fn (LiveReservation $r) => $r->customer_phone === $m->conversation?->contact && $r->created_at->gte($m->created_at))
                    ->sortBy('id')->first()?->item?->code;
                $read = $code ? [$code] : [];
            }

            return [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'name' => $m->conversation?->contact_name ?: PhoneNumber::display((string) $m->conversation?->contact),
                'phone' => $m->conversation?->contact,
                'type' => $m->type,
                'body' => Str::limit((string) $m->body, 160),
                'codes' => $read,
                // « LIVE » : la liste des articles a été envoyée, rien à répondre
                'list' => $m->type === 'text' && self::asksForList((string) $m->body),
                // Réponse sans code d'une cliente dont la pièce attend encore son quartier : à vérifier par la vendeuse
                'place_check' => $read === [] && $m->type === 'text' && ! self::asksForList((string) $m->body)
                    && $reservations->contains(fn (LiveReservation $r) => $r->status === 'reserved' && $r->customer_phone === $m->conversation?->contact && $r->created_at->lte($m->created_at)),
                'reservation_id' => $reservations->first(fn (LiveReservation $r) => $r->status === 'reserved' && $r->customer_phone === $m->conversation?->contact)?->id,
                'answered' => ($answered[$m->conversation_id] ?? 0) > $m->id,
                'created_at' => $m->created_at->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * WhatsApp qui lit les messages des clients pendant le live : official (Meta), link (relié par QR code) ou null.
     */
    public static function whatsapp(int $storeId): ?string
    {
        return WhatsappAccount::forStore($storeId)->exists() ? 'official' : (WhatsappLink::connectedFor($storeId) ? 'link' : null);
    }

    /**
     * Numéro WhatsApp (Meta) relié à la boutique, et s'il est en mode sans carte, pour l'afficher dans le live.
     *
     * @return array{phone: ?string, no_card: bool}
     */
    public static function whatsappNumber(int $storeId): array
    {
        $account = WhatsappAccount::forStore($storeId)->first();

        return ['phone' => $account?->display_phone, 'no_card' => (bool) $account?->withoutCard()];
    }

    /**
     * Bilans de plusieurs lives (liste des lives) en quelques requêtes, quel que soit le nombre de lives : id => bilan,
     * mêmes chiffres que summary().
     *
     * @param  Collection<int, LiveSession>  $sessions
     * @return array<int, array<string, int>>
     */
    public function summaries(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }
        $storeId = $sessions->first()->store_id;
        $ids = $sessions->pluck('id');
        $rows = LiveReservation::forStore($storeId)->whereIn('live_session_id', $ids)
            ->selectRaw('live_session_id, status, count(*) as n, count(distinct order_id) as orders, sum(unit_price) as amount')
            ->groupBy('live_session_id', 'status')->get();
        $items = LiveItem::forStore($storeId)->whereIn('live_session_id', $ids)->get();
        // Pièce sans prix gardé : le prix de l'article du live
        $unpriced = LiveReservation::forStore($storeId)->whereIn('live_session_id', $ids)->where('status', 'ordered')->whereNull('unit_price')
            ->selectRaw('live_session_id, live_item_id, count(*) as n')->groupBy('live_session_id', 'live_item_id')->get();
        $left = $this->remainingFor($items);

        return $sessions->mapWithKeys(function (LiveSession $session) use ($rows, $items, $unpriced, $left) {
            $mine = $rows->where('live_session_id', $session->id);
            $count = fn (string $status) => (int) $mine->where('status', $status)->sum('n');
            $ordered = $mine->firstWhere('status', 'ordered');
            $extra = $unpriced->where('live_session_id', $session->id)
                ->sum(fn ($r) => (int) $r->n * (int) ($items->firstWhere('id', $r->live_item_id)?->price ?? 0));

            return [$session->id => [
                'reservations' => (int) $mine->where('status', '!=', 'cancelled')->sum('n'),
                'ordered' => $count('ordered'),
                'orders' => (int) ($ordered->orders ?? 0),
                'amount' => (int) ($ordered->amount ?? 0) + (int) $extra,
                'pending' => $count('reserved'),
                'waiting' => $count('waiting'),
                'expired' => $count('expired'),
                'sold_out' => $items->where('live_session_id', $session->id)->filter(fn (LiveItem $item) => ($left[$item->id] ?? 1) === 0)->count(),
            ]];
        })->all();
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
            'amount' => (int) $ordered->sum(fn (LiveReservation $r) => $r->unit_price ?? $r->item?->price ?? 0),
            'pending' => $all->where('status', 'reserved')->count(),
            'waiting' => $all->where('status', 'waiting')->count(),
            'expired' => $all->where('status', 'expired')->count(),
            // Articles dont toutes les pièces sont parties
            'sold_out' => count(array_filter($this->remainingFor($session->items()->get()), fn (int $left) => $left === 0)),
        ];
    }

    private function previousAmount(LiveSession $session): ?int
    {
        $previous = LiveSession::forStore($session->store_id)->where('status', 'ended')->where('id', '<', $session->id)->latest('id')->first();

        return $previous ? $this->summary($previous)['amount'] : null;
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
        // Une ligne par article et par prix (une pièce prise pendant la vente flash garde son prix flash)
        $lines = $reservations->groupBy(fn (LiveReservation $r) => $r->live_item_id.':'.($r->unit_price ?? $items->get($r->live_item_id)->price))
            ->map(fn ($rs) => [
                'variant_id' => $items->get($rs->first()->live_item_id)->variant_id, 'quantity' => $rs->count(),
                'unit_price' => $rs->first()->unit_price ?? $items->get($rs->first()->live_item_id)->price,
            ])->values()->all();
        $lines = $this->wholesale($store->id, $lines);
        $codes = $byItem->map(fn ($rs, $id) => $items->get($id)?->code.($rs->count() > 1 ? ' ×'.$rs->count() : ''))->implode(', ');
        $open = $this->openOrder($session, $first->customer_phone);
        try {
            $order = $open !== null
                ? $this->desk->addItems($open, $lines, "live : {$codes}")
                : $this->desk->create($store, [
                    'source' => 'live',
                    'customer_name' => $first->customer_name,
                    'customer_phone' => $first->customer_phone,
                    'delivery_method' => 'delivery',
                    'shipping_place_id' => $first->shipping_place_id,
                    'shipping_address' => $first->shipping_address,
                    'internal_note' => "Live « {$session->title} » : {$codes}",
                    // Une ligne par article, une pièce par réservation
                    'items' => $lines,
                ], null, 'customer');
        } catch (BusinessRuleException|QuotaExceededException $e) {
            // Commande impossible (stock, quota…) : la réservation reste avec son quartier ; l'équipe voit pourquoi
            // dans l'application et peut réessayer
            Log::info('Live : commande non créée', ['reservations' => $reservations->pluck('id')->all(), 'error' => $e->getMessage()]);
            Cache::putMany($reservations->mapWithKeys(fn (LiveReservation $r) => ['live-order-error:'.$r->id => $e->getMessage()])->all(), now()->addDays(3));

            return null;
        }
        Cache::deleteMultiple($reservations->map(fn (LiveReservation $r) => 'live-order-error:'.$r->id)->all());
        LiveReservation::whereIn('id', $reservations->pluck('id'))->update(['status' => 'ordered', 'order_id' => $order->id, 'expires_at' => null]);

        return $order->load('items');
    }

    /**
     * Prix de gros et packs au choix en live : quand le prix calculé pour la quantité (« 6 pour 30 000 F », « 3 robes
     * au choix pour 25 000 F ») est plus bas que le prix du live, c'est lui qui compte (la ligne ne garde pas de prix
     * forcé). Le prix du live (vente flash comprise) reste sinon.
     *
     * @param  list<array{variant_id: int, quantity: int, unit_price: int}>  $lines
     * @return list<array{variant_id: int, quantity: int, unit_price?: int}>
     */
    private function wholesale(int $storeId, array $lines): array
    {
        $variants = ProductVariant::forStore($storeId)->with('product')->whereIn('id', array_column($lines, 'variant_id'))->get()->keyBy('id');
        $catalog = array_values(array_filter(array_map(fn ($l) => $variants->has($l['variant_id']) ? ['variant' => $variants->get($l['variant_id']), 'quantity' => $l['quantity']] : null, $lines)));
        if (count($catalog) !== count($lines)) {
            return $lines;
        }
        $prices = app(Pricing::class)->lines($storeId, $catalog);

        return array_map(function (array $line, array $price) {
            if ($price['volume'] && $price['total'] < $line['unit_price'] * $line['quantity']) {
                unset($line['unit_price']);
            }

            return $line;
        }, $lines, $prices);
    }

    /**
     * « 💰 Prix de gros : 54 000 F au lieu de 72 000 F » pour les pièces gardées d'un client, ou null sans remise.
     *
     * @param  Collection<int, LiveItem>  $items  articles avec le nombre de pièces gardées en clé « held »
     * @param  array<int, int>  $held  article du live => pièces gardées
     */
    private function wholesaleNote(int $storeId, Collection $items, array $held): ?string
    {
        $lines = $items->filter(fn (LiveItem $item) => ($held[$item->id] ?? 0) > 0 && $item->variant !== null)->values();
        if ($lines->isEmpty()) {
            return null;
        }
        $prices = app(Pricing::class)->lines($storeId, $lines->map(fn (LiveItem $item) => ['variant' => $item->variant, 'quantity' => $held[$item->id]])->all());
        $live = (int) $lines->sum(fn (LiveItem $item) => $item->currentPrice() * $held[$item->id]);
        $best = 0;
        foreach ($prices as $i => $p) {
            $best += min($p['total'], $lines[$i]->currentPrice() * $held[$lines[$i]->id]);
        }
        if (! collect($prices)->contains('volume', true) || $best >= $live) {
            return null;
        }

        return '💰 *Prix de gros : '.Money::format($best).'* au lieu de '.Money::format($live);
    }

    private function position(LiveReservation $reservation): int
    {
        return LiveReservation::forStore($reservation->store_id)->where('live_item_id', $reservation->live_item_id)->where('status', 'waiting')
            ->where('id', '<=', $reservation->id)->count();
    }

    /** Mots qui demandent la liste des articles du live (le message entier, sans chiffre : jamais une pancarte) */
    private const LIST_WORDS = ['live', 'le live', 'liste', 'la liste', 'articles', 'les articles', 'catalogue', 'le catalogue'];

    /** Une même cliente reçoit la liste au plus une fois toutes les 2 minutes */
    private const LIST_SECONDS = 120;

    /**
     * Le message demande la liste des articles : « LIVE », « liste », « Articles ? », « catalogue stp »…
     */
    public static function asksForList(string $text): bool
    {
        $words = Str::of(Str::ascii(mb_strtolower($text)))->replaceMatches('/[^a-z ]+/', ' ')->squish()
            ->replaceMatches('/\s+(stp|svp|s il vous plait|s il te plait|please|merci)$/', '')->value();

        return in_array($words, self::LIST_WORDS, true);
    }

    /**
     * Réponse à « LIVE » : les articles encore disponibles du live en cours (code, nom, tailles qui restent, prix),
     * comment répondre, et le lien de la page du live avec les photos. Sans live : le lien de la boutique.
     */
    private function sendList(Conversation $conversation, ?LiveSession $session): void
    {
        if (! Cache::add('live-list:'.$conversation->id, 1, self::LIST_SECONDS)) {
            return;
        }
        $store = $conversation->store ?? Store::findOrFail($conversation->store_id);
        if ($session === null) {
            $this->reply($conversation, "Il n'y a pas de live en ce moment. Retrouvez tous nos articles sur notre boutique : ".$store->url('/'));

            return;
        }
        $groups = $session->items()->shown()->with([...self::VARIANT_RELATIONS, 'variant.image', 'variant.product.images'])->orderBy('position')->get()->groupBy('code')
            ->map(function (Collection $group, string $code) {
                $left = $group->filter(fn (LiveItem $i) => $this->remaining($i) > 0);
                if ($left->isEmpty()) {
                    return null;
                }
                $first = $group->first();
                $sizes = $left->filter(fn (LiveItem $i) => $i->optionValues() !== [])->map(fn (LiveItem $i) => $i->variant->title)->unique()->implode(', ');

                return ['code' => $code, 'title' => $first->variant?->product?->title ?? 'Article', 'sizes' => $sizes,
                    'price' => (int) $left->min(fn (LiveItem $i) => $i->currentPrice()), 'image' => $first->variant?->image ?? $first->variant?->product?->images->first()];
            })->filter()->values();
        if ($groups->isEmpty()) {
            $this->reply($conversation, "Tout est parti pour l'instant !".($session->isWhatsApp() ? '' : ' De nouveaux articles arrivent pendant le live.').' Nos autres articles : '.$store->url('/'));

            return;
        }
        $shown = $groups->take(self::LIST_ON_IMAGE);
        $more = $groups->count() - $shown->count();
        // Exemple pris sur un vrai article : « A2 » sans taille, « A1 M » avec des tailles
        $sample = fn (array $g) => $g['code'].($g['sizes'] !== '' ? ' '.trim(explode(',', $g['sizes'])[0]) : '');
        $example = $sample($shown->first());
        $several = $shown->count() > 1 ? $sample($shown->get(1)) : $example;
        $anySizes = $shown->contains(fn (array $g) => $g['sizes'] !== '');
        // Une seule image pour toute la liste (miniature, code, nom, tailles, prix), puis un court texte avec le lien
        $image = rescue(fn () => app(LiveImages::class)->board($store->id, 'Encore disponibles · '.$store->name, $shown->map(fn (array $g) => [
            'code' => $g['code'], 'title' => $g['title'], 'detail' => $g['sizes'] !== '' ? 'Tailles : '.$g['sizes'] : 'Taille unique', 'price' => $g['price'], 'image' => $g['image'],
        ])->all(), 'Répondez avec le code (ex. '.$example.')', $groups->count().' article'.($groups->count() > 1 ? 's' : '').($more > 0 ? " · + {$more} sur la page des articles" : '')), report: false);
        $howTo = "👉 Répondez avec *le code* de l'article (ex. *{$example}*).".($anySizes ? " S'il y a des tailles, on vous demande la vôtre." : '')
            .($several !== $example ? " Plusieurs articles : *{$example}, {$several}*." : '')."\n📷 Toutes les photos : ".$store->url('/live');
        if (app(LiveCheckout::class)->picture($conversation, $image, '🔴 Encore disponibles en ce moment')) {
            $this->howTo($conversation, $howTo);

            return;
        }
        $text = $groups->take(30)->map(fn (array $g) => "*{$g['code']}* · {$g['title']}".($g['sizes'] !== '' ? " ({$g['sizes']})" : '').' · '.Money::format($g['price']));
        $this->reply($conversation, "🔴 *Encore disponibles en ce moment :*\n\n".$text->implode("\n\n")
            .($groups->count() > 30 ? "\n\n… et ".($groups->count() - 30).' autres sur la page des articles.' : '')."\n\n".$howTo);
    }

    /**
     * Comment commander, avec le bouton « 🛵 Prix de livraison » sur le WhatsApp officiel (sinon : écrire « livraison »).
     */
    private function howTo(Conversation $conversation, string $text): void
    {
        $sent = rescue(fn () => app(Inbox::class)->replyRich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendButtons($account, $to, $text,
            ['fees:menu' => '🛵 Prix de livraison'])), null, false);
        if ($sent === null) {
            $this->reply($conversation, $text."\n🛵 Prix de livraison : écrivez *livraison*.");
        }
    }

    /** Articles sur l'image de la liste « LIVE » (au-delà : le nombre, et la page du live) */
    private const LIST_ON_IMAGE = 12;

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
    /**
     * Pancartes du live : un code par produit (ses variantes le partagent). Le code choisi par le marchand (la pancarte
     * qu'il montrera à la caméra) est gardé ; les autres produits prennent, dans l'ordre de la liste, le premier code
     * libre (A1…A9, B1…). Deux produits ne peuvent pas avoir la même pancarte.
     *
     * @param  list<array{variant_id: int, code?: ?string}>  $lines
     * @param  Collection<int, ProductVariant>  $variants  par identifiant
     * @return array<int, string> code par produit
     */
    private static function codes(array $lines, Collection $variants): array
    {
        $codes = [];
        $owners = [];
        foreach ($lines as $i => $line) {
            $variant = $variants->get($line['variant_id']);
            if ($variant === null || $variant->product === null) {
                throw new BusinessRuleException('Un article du live n\'existe plus dans votre catalogue.', "items.{$i}.variant_id");
            }
            $code = self::sign($line['code'] ?? null);
            if ($code === null || isset($codes[$variant->product_id])) {
                continue;
            }
            if (isset($owners[$code])) {
                throw new BusinessRuleException("La pancarte {$code} est déjà sur « {$owners[$code]} ». Choisissez une autre pancarte.", "items.{$i}.code");
            }
            $codes[$variant->product_id] = $code;
            $owners[$code] = $variant->product->title;
        }
        $index = 0;
        $pack = 1;
        foreach ($lines as $line) {
            $variant = $variants->get($line['variant_id']);
            $productId = $variant->product_id;
            if (isset($codes[$productId])) {
                continue;
            }
            // Packs : pancartes P1, P2… (les autres articles A1, A2…)
            if ($variant->product?->is_bundle) {
                while (isset($owners['P'.$pack]) && $pack < 99) {
                    $pack++;
                }
                if (! isset($owners['P'.$pack])) {
                    $codes[$productId] = 'P'.$pack;
                    $owners['P'.$pack] = true;

                    continue;
                }
            }
            while (isset($owners[$candidate = self::code($index)]) || str_starts_with($candidate, 'P')) {
                $index++;
            }
            $codes[$productId] = $candidate;
            $owners[$candidate] = true;
        }

        return $codes;
    }

    /**
     * Pancarte écrite par le marchand (« a12 ») mise en forme (A12) ; null si vide.
     */
    public static function sign(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }
        if (preg_match(self::SIGN_PATTERN, $code) !== 1) {
            throw new BusinessRuleException('Une pancarte, c\'est une lettre puis un nombre de 1 à 99 (A1, B12…).');
        }

        return $code;
    }

    /**
     * Changer la pancarte d'un produit (toutes ses variantes) : avant le live, ou pendant tant que personne ne l'a
     * réservé (le code annoncé ne doit pas changer sous les yeux des clientes).
     */
    public function changeCode(LiveSession $session, LiveItem $item, string $code): LiveSession
    {
        if ($session->status === 'ended') {
            throw new BusinessRuleException('Ce live est terminé.');
        }
        $code = self::sign($code) ?? throw new BusinessRuleException('Choisissez une pancarte.');

        DB::transaction(function () use ($session, $item, $code) {
            LiveSession::forStore($session->store_id)->lockForUpdate()->find($session->id);
            $group = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->where('code', $item->code)->get();
            if ($code === $item->code) {
                return;
            }
            $taken = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->where('code', $code)->with('variant.product')->first();
            if ($taken !== null) {
                throw new BusinessRuleException("La pancarte {$code} est déjà sur « {$this->label($taken)} ». Choisissez une autre pancarte.", 'code');
            }
            if (LiveReservation::forStore($session->store_id)->whereIn('live_item_id', $group->pluck('id'))->exists()) {
                throw new BusinessRuleException("La {$item->code} est déjà réservée : sa pancarte ne peut plus changer pendant ce live.", 'code');
            }
            LiveItem::forStore($session->store_id)->whereIn('id', $group->pluck('id'))->update(['code' => $code]);
        });

        return $session->fresh();
    }

    public static function code(int $index): string
    {
        return chr(65 + intdiv($index, 9) % 26).($index % 9 + 1);
    }
}
