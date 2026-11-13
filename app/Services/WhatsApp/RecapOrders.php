<?php

namespace App\Services\WhatsApp;

use App\Enums\CancelReason;
use App\Enums\ConfirmationOutcome;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\QuotaExceededException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\SupportConversation;
use App\Models\User;
use App\Notifications\RecapNotice;
use App\Services\Accounts\StoreRoles;
use App\Services\Accounts\TeamNotifier;
use App\Services\Ai\Assistant;
use App\Services\Billing\Credits;
use App\Services\Live\LiveCheckout;
use App\Services\Live\LiveSales;
use App\Services\Orders\OrderDesk;
use App\Services\Orders\OrderDrafter;
use App\Services\Orders\OrderMessageParser;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\Storefront\StorefrontCatalog;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;
use RuntimeException;
use Throwable;

/**
 * Commande prise dans une conversation WhatsApp normale, sans recopier : le marchand discute depuis son téléphone,
 * puis écrit « récap » (seul, ou suivi de précisions : « récap 2 robes M Angré demain », prioritaires). L'application
 * relit la conversation (règles, puis IA comprise dans le prix de la commande), demande au client ce qui manque (taille,
 * commune et quartier), crée la commande « à confirmer » et lui envoie le récapitulatif à boutons du live
 * (« Je confirme », changer l'adresse, ajouter un article).
 *
 * La commande est confirmée par le client (bouton), ou par la boutique : « confirmé » écrit dans la discussion, bouton
 * « Confirmer » de la notification sur le numéro de Jibiat Marchand, ou depuis l'application. La boutique est prévenue
 * à chaque étape (notification, et numéro de Jibiat Marchand quand WhatsApp le permet).
 *
 * Payante : une fois confirmée, la commande compte 1 commande du solde (retirée le soir) ; solde vide, un nouveau
 * récap n'envoie rien au client. Un nouveau « récap » met à jour la commande encore à confirmer.
 */
class RecapOrders
{
    /** Récaps au plus par conversation et par jour (l'IA est comprise dans le prix de la commande) */
    public const PER_DAY = 5;

    /** Relance du client sans réponse, puis annulation (stock rendu) */
    public const REMIND_HOURS = 2;

    public const EXPIRE_HOURS = 24;

    /** Mots du marchand qui confirment la commande à sa place (« ok » seul est trop courant) */
    private const CONFIRM_WORDS = ['confirme', 'confirmee', 'ok confirme', 'ok confirmee', 'c est confirme', 'cest confirme', 'commande confirmee',
        'c est confirme merci', 'confirme merci', 'confirmee merci'];

    /** Messages relus pour reconstituer la commande */
    private const HISTORY = 40;

    public function __construct(
        private readonly Inbox $inbox,
        private readonly OrderDesk $desk,
        private readonly OrderMessageParser $parser,
        private readonly Credits $credits,
        private readonly StorefrontCatalog $catalog,
    ) {}

    /**
     * Texte après « récap » quand le message commence par ce mot (sinon null) : « récap », « Récap : 2 robes M ».
     */
    public static function recapText(string $text): ?string
    {
        if (preg_match('/^[\s*_~#]*r[ée]cap(?:itulatif)?(?![\p{L}\p{N}])[\s*_~:.,;!\-–—]*(.*)$/isu', trim($text), $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /** « confirmé », « Confirmée ✅ », « ok confirmé » */
    public static function confirms(string $text): bool
    {
        return in_array(self::words($text), self::CONFIRM_WORDS, true);
    }

    private static function words(string $text): string
    {
        return Str::of(Str::ascii(mb_strtolower($text)))->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->value();
    }

    /**
     * Message du marchand écrit depuis le téléphone de la boutique : « récap » ou « confirmé ». Vrai si compris.
     */
    public function fromMerchant(Conversation $conversation, string $text): bool
    {
        if ($conversation->channel !== 'whatsapp') {
            return false;
        }
        $rest = self::recapText($text);
        if ($rest !== null) {
            return $this->recap($conversation, $rest);
        }

        return self::confirms($text) && $this->confirmFromChat($conversation);
    }

    /**
     * « récap » : la commande est reconstituée, ce qui manque est demandé au client, puis le récapitulatif part.
     */
    public function recap(Conversation $conversation, string $precision = ''): bool
    {
        $store = $this->store($conversation);
        $phone = PhoneNumber::normalize($conversation->contact);
        $name = $this->customerName($conversation);
        if ($phone === null) {
            return false;
        }
        if ($this->inbox->linkFor($conversation) === null && $this->inbox->officialFor($conversation) === null) {
            $this->tell($store, null, 'Récap non envoyé', "{$name} n'a pas écrit depuis plus de 24 h : WhatsApp n'autorise plus l'envoi du récap. Il partira quand il vous réécrira.");

            return true;
        }
        if (! $this->credits->canRecap($store)) {
            $this->tell($store, null, 'Récap non envoyé : solde à zéro', "Ton solde de commandes est à zéro : recharge pour envoyer le récap à {$name}. Rien n'a été envoyé au client.", '/gestion/solde');

            return true;
        }
        $counter = 'recap-count:'.$conversation->id.':'.now()->toDateString();
        Cache::add($counter, 0, now()->endOfDay());
        if (Cache::increment($counter) > self::PER_DAY) {
            $this->tell($store, null, 'Récap non envoyé', 'Déjà '.self::PER_DAY." récaps aujourd'hui avec {$name} : finissez la commande depuis l'application.");

            return true;
        }

        $draft = $this->read($store, $conversation, $precision);
        if ($draft['items'] === []) {
            $this->tell($store, null, 'Récap : aucun article reconnu', "Je n'ai reconnu aucun article dans la discussion avec {$name}. Rien n'a été envoyé au client. Écrivez par exemple : « récap 2 robes wax M Angré ».");

            return true;
        }
        Cache::put(self::stateKey($conversation), $draft, now()->addHours(12));

        return $this->proceed($conversation);
    }

    /**
     * Ce que dit la conversation : articles (précisions du marchand d'abord), lieu, adresse, nom.
     *
     * @return array{items: list<array{product_id: int, title: string, variant_id: ?int, quantity: int}>, place: ?int, address: ?string, name: ?string}
     */
    private function read(Store $store, Conversation $conversation, string $precision): array
    {
        $own = $precision !== '' ? $this->parser->parse($store, $precision) : null;
        $messages = ConversationMessage::forStore($store->id)->where('conversation_id', $conversation->id)
            ->whereNotIn('type', ['auto', 'template'])->where('sent_at', '>=', now()->subDay())
            ->latest('sent_at')->latest('id')->limit(self::HISTORY)->get(['direction', 'body'])->reverse()
            ->filter(fn (ConversationMessage $m) => self::recapText((string) $m->body) === null);
        $transcript = $messages->map(fn (ConversationMessage $m) => ($m->direction === 'in' ? 'Client : ' : 'Boutique : ').$m->body)->implode("\n");
        $said = $this->parser->parse($store, $messages->pluck('body')->implode("\n"));

        $items = $own['items'] ?? [];
        $ai = null;
        if ($items === [] && app(Assistant::class)->enabled($store)) {
            try {
                $ai = app(OrderDrafter::class)->draft($store, null, $transcript, [], 'whatsapp_recap');
                $items = $ai['items'] ?? [];
            } catch (Throwable $e) {
                Log::info('Récap WhatsApp : IA indisponible, lecture par règles', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
            }
        }
        if ($items === []) {
            $items = $said['items'];
        }
        $place = $own['place']['id'] ?? $ai['place']['id'] ?? $said['place']['id'] ?? null;

        return [
            'items' => collect($items)->filter(fn ($i) => ! empty($i['product_id']))->map(fn ($i) => [
                'product_id' => (int) $i['product_id'],
                'title' => (string) $i['title'],
                'variant_id' => isset($i['variant_id']) ? (int) $i['variant_id'] : null,
                'quantity' => max(1, min(999, (int) ($i['quantity'] ?? 1))),
            ])->unique(fn ($i) => $i['product_id'].':'.$i['variant_id'])->values()->all(),
            'place' => $place,
            'address' => $own['address'] ?? $ai['address'] ?? $said['address'] ?? null,
            'name' => $own['customer_name'] ?? $ai['customer_name'] ?? null,
        ];
    }

    /**
     * Étape suivante : taille manquante demandée au client, sinon la commande et le récapitulatif (ou la commune).
     */
    private function proceed(Conversation $conversation): bool
    {
        $state = Cache::get(self::stateKey($conversation));
        if (! is_array($state)) {
            return false;
        }
        foreach ($state['items'] as $index => $item) {
            if ($item['variant_id'] === null) {
                return $this->askVariant($conversation, $state, $index);
            }
        }
        Cache::forget(self::stateKey($conversation));
        $order = $this->place($conversation, $state);
        if ($order === null) {
            return true;
        }
        $name = $this->customerName($conversation);
        if ($order->shipping_place_id === null && $order->delivery_method === 'delivery') {
            Cache::put(self::placeKey($conversation), $order->id, now()->addDay());
            app(LiveSales::class)->askAddress($conversation, '📍 *OÙ VOUS LIVRER ?*');
            $this->tell($this->store($conversation), $order, "Récap pour {$name}", "Commande {$order->reference()} créée, à confirmer : on demande sa commune et son quartier, puis le récap part avec « Je confirme ».");

            return true;
        }
        app(LiveCheckout::class)->recap($conversation, $order);
        $this->announce($order, $name);

        return true;
    }

    /**
     * Commande à confirmer créée, ou mise à jour si un récap précédent attend encore la confirmation.
     *
     * @param  array<string, mixed>  $state
     */
    private function place(Conversation $conversation, array $state): ?Order
    {
        $store = $this->store($conversation);
        $phone = PhoneNumber::normalize($conversation->contact);
        $place = $state['place'] ? Place::visibleTo($store->id)->with('parent.parent')->find($state['place']) : null;
        $lines = collect($state['items'])->map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity']])->values()->all();
        $where = $place ? ['shipping_place_id' => $place->id, 'shipping_address' => mb_substr((string) ($state['address'] ?: $place->label()), 0, 255)] : [];
        $open = self::openOrder($store->id, $phone, pendingOnly: true);
        try {
            $order = $open !== null
                ? $this->desk->update($open, ['items' => $lines] + $where, null)
                : $this->desk->create($store, [
                    'source' => 'whatsapp',
                    'customer_name' => $state['name'] ?: $conversation->contact_name,
                    'customer_phone' => $phone,
                    'delivery_method' => 'delivery',
                    'internal_note' => 'WhatsApp : récap envoyé par la boutique',
                    'credit_channel' => 'recap',
                    'items' => $lines,
                ] + $where, null, 'user');
        } catch (BusinessRuleException|QuotaExceededException $e) {
            $this->tell($store, null, 'Récap non envoyé', 'La commande de '.$this->customerName($conversation)." n'a pas pu être créée : {$e->getMessage()} Rien n'a été envoyé au client.");

            return null;
        }
        Cache::put(self::orderKey($store->id, $phone), $order->id, now()->addDays(2));
        $conversation->forceFill(['order_id' => $order->id])->save();

        return $order;
    }

    /**
     * « Quelle taille ? » pour un article cité sans sa taille : boutons ou liste (numéro officiel), sinon des numéros.
     *
     * @param  array<string, mixed>  $state
     */
    private function askVariant(Conversation $conversation, array $state, int $index): bool
    {
        $store = $this->store($conversation);
        $item = $state['items'][$index];
        $product = Product::forStore($store->id)->with(['variants' => fn ($q) => $q->where('is_active', true)])->find($item['product_id']);
        $availability = $product ? $this->catalog->availability($store, $product->variants->pluck('id')->all()) : [];
        $left = $product === null ? collect() : $product->variants->filter(function (ProductVariant $v) use ($product, $availability) {
            $v->setRelation('product', $product);
            $n = $this->catalog->sellable($v, $availability);

            return $n === null || $n > 0;
        })->values();
        if ($left->isEmpty()) {
            Cache::forget(self::stateKey($conversation));
            $this->tell($store, null, 'Récap non envoyé', "{$item['title']} n'est plus en stock : rien n'a été envoyé à ".$this->customerName($conversation).'.');

            return true;
        }
        if ($left->count() === 1) {
            return $this->chooseVariant($conversation, $index, $left->first()->id);
        }
        $values = $left->take(10)->values();
        $state['asking'] = ['index' => $index, 'options' => array_combine(range(1, $values->count()), $values->pluck('id')->all())];
        Cache::put(self::stateKey($conversation), $state, now()->addHours(12));
        $text = "🧾 *VOTRE COMMANDE*\n\n*{$product->title}* : ".($product->is_bundle ? 'quel choix ?' : 'quelle taille ?');
        $ids = $values->mapWithKeys(fn (ProductVariant $v) => ["recap:var:{$index}:{$v->id}" => Str::limit($v->title, 20, '')])->all();
        $sent = $this->inbox->officialFor($conversation) !== null && rescue(fn () => $this->inbox->replyRich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $values->count() <= 3
            ? $cloud->sendButtons($account, $to, $text, $ids)
            : $cloud->sendList($account, $to, $text, 'Choisir', 'Choix', collect($ids)->map(fn (string $title, string $id) => ['id' => $id, 'title' => $title])->values()->all())), null, false) !== null;
        if (! $sent) {
            $this->text($conversation, $text."\n\n".$values->map(fn (ProductVariant $v, int $i) => '*'.($i + 1).'* '.$v->title)->implode("\n")."\n\n👉 Écrivez *le numéro* (ou la taille).");
        }

        return true;
    }

    private function chooseVariant(Conversation $conversation, int $index, int $variantId): bool
    {
        $state = Cache::get(self::stateKey($conversation));
        if (! is_array($state) || ! isset($state['items'][$index])) {
            return false;
        }
        $variant = ProductVariant::forStore($conversation->store_id)->whereKey($variantId)->where('product_id', $state['items'][$index]['product_id'])->first();
        if ($variant === null) {
            return false;
        }
        $state['items'][$index]['variant_id'] = $variant->id;
        unset($state['asking']);
        Cache::put(self::stateKey($conversation), $state, now()->addHours(12));

        return $this->proceed($conversation);
    }

    /**
     * Taille touchée par le client : « recap:var:0:12 ».
     */
    public function onReply(Conversation $conversation, string $payload): bool
    {
        $parts = explode(':', $payload);
        if (($parts[0] ?? '') !== 'recap' || ($parts[1] ?? '') !== 'var' || ! ctype_digit($parts[2] ?? '') || ! ctype_digit($parts[3] ?? '')) {
            return false;
        }

        return $this->chooseVariant($conversation, (int) $parts[2], (int) $parts[3]) || true;
    }

    /**
     * Taille tapée par le client (numéro de la liste ou valeur, « M »). Vrai si comprise.
     */
    public function answer(Conversation $conversation, string $text): bool
    {
        $state = Cache::get(self::stateKey($conversation));
        if (! is_array($state) || ! isset($state['asking'])) {
            return false;
        }
        $clean = self::words($text);
        $options = $state['asking']['options'];
        if (ctype_digit($clean) && isset($options[(int) $clean])) {
            return $this->chooseVariant($conversation, $state['asking']['index'], (int) $options[(int) $clean]);
        }
        $match = ProductVariant::forStore($conversation->store_id)->whereIn('id', array_values($options))->get()
            ->first(fn (ProductVariant $v) => self::words($v->title) === $clean);

        return $match !== null && $this->chooseVariant($conversation, $state['asking']['index'], $match->id);
    }

    /**
     * Commune et quartier écrits par le client quand le récap attend son adresse : la commande, ou null.
     */
    public function complete(Conversation $conversation, string $text): ?Order
    {
        $id = Cache::get(self::placeKey($conversation));
        if ($id === null || (str_contains($text, '?') && str_word_count(Str::ascii($text)) > 2)) {
            return null;
        }
        $store = $this->store($conversation);
        $place = app(PlaceMatcher::class)->search($text, app(Places::class)->forStore($store), 1)->first();
        $order = $place === null ? null : Order::forStore($store->id)->whereKey($id)->where('status', OrderStatus::PendingConfirmation->value)->first();
        if ($order === null) {
            return null;
        }
        try {
            $order = $this->desk->update($order, ['shipping_place_id' => $place->id, 'shipping_address' => mb_substr(trim($text), 0, 255)], null);
        } catch (Throwable $e) {
            Log::info('Récap WhatsApp : adresse non enregistrée', ['order' => $order->id, 'error' => $e->getMessage()]);

            return null;
        }
        Cache::forget(self::placeKey($conversation));

        return $order;
    }

    /**
     * « confirmé » écrit par le marchand après le récap : la commande à confirmer de ce client passe confirmée.
     */
    private function confirmFromChat(Conversation $conversation): bool
    {
        $order = self::openOrder($conversation->store_id, PhoneNumber::normalize($conversation->contact), pendingOnly: true);

        return $order !== null && $this->confirmByMerchant($order, $conversation, 'dans la discussion');
    }

    /**
     * Confirmation par la boutique, sans le bouton du client : notée comme telle, puis le client reçoit « commande
     * confirmée » avec ce qu'il reste à payer.
     */
    public function confirmByMerchant(Order $order, ?Conversation $conversation, string $how, ?User $user = null): bool
    {
        if ($order->status !== OrderStatus::PendingConfirmation) {
            return false;
        }
        $order = $this->desk->recordConfirmation($order, ConfirmationOutcome::Confirmed, 'whatsapp',
            "Confirmée par la boutique ({$how}), sans confirmation du client", null, $user);
        if ($order->status !== OrderStatus::Confirmed) {
            return true;
        }
        if ($conversation !== null) {
            Cache::forget(self::placeKey($conversation));
            app(LiveCheckout::class)->confirm($conversation, $order->id);
        }
        $this->confirmed($order, 'la boutique');

        return true;
    }

    /**
     * Commande récap confirmée (par le client ou la boutique) : la boutique est prévenue, avec ce qui sera compté.
     */
    public function confirmed(Order $order, string $by): void
    {
        $order = $order->fresh() ?? $order;
        $store = Store::findOrFail($order->store_id);
        $counted = $order->credit_due ? ' · 1 commande comptée (reste '.max(0, (int) $store->order_credits - $this->countedToday($store)).')' : '';
        $this->tell($store, $order, '✅ Commande '.$order->reference().' confirmée', 'Confirmée par '.$by.' : '.Money::format((int) $order->total).$counted.'.', null, false);
    }

    /** Commandes confirmées pas encore retirées du solde (le soir) */
    private function countedToday(Store $store): int
    {
        return Order::forStore($store->id)->where('credit_due', true)->whereNull('credit_billed_at')->count();
    }

    /**
     * Bouton touché par le marchand sur le numéro de Jibiat Marchand : « recap:ok:{boutique}:{commande} ».
     */
    public function fromOwner(string $phone, string $payload): bool
    {
        if (preg_match('/^recap:ok:(\d+):(\d+)$/', $payload, $m) !== 1) {
            return false;
        }
        $store = Store::find((int) $m[1]);
        $user = $store === null ? null : User::where('phone', PhoneNumber::normalize($phone) ?? $phone)->first();
        if ($store === null || $user === null || ! in_array(Permission::OrdersConfirm->value, app(StoreRoles::class)->permissionsOf($user, $store), true)) {
            return true;
        }
        app(Tenancy::class)->setStore($store);
        $order = Order::forStore($store->id)->find((int) $m[2]);
        if ($order === null) {
            return true;
        }
        $conversation = Conversation::forStore($store->id)->where('channel', 'whatsapp')->where('contact', $order->customer_phone)->first();
        $done = $order->status === OrderStatus::PendingConfirmation && $this->confirmByMerchant($order, $conversation, 'notification WhatsApp', $user);
        if (! $done) {
            rescue(fn () => app(PlatformWhatsApp::class)->sendAutomatic($phone, "La commande {$order->reference()} n'est plus à confirmer ({$order->status->label()})."), report: false);
        }

        return true;
    }

    /**
     * Récaps envoyés sans réponse : relance au bout de 2 h (une fois), annulation au bout de 24 h (stock rendu, rien
     * de compté). Renvoie le nombre de commandes relancées ou annulées.
     */
    public function remind(): int
    {
        $count = 0;
        $orders = Order::withoutGlobalScopes()->with('store')->where('credit_channel', 'recap')->where('status', OrderStatus::PendingConfirmation->value)
            ->where('created_at', '<', now()->subHours(self::REMIND_HOURS))->where('created_at', '>=', now()->subDays(3))->get();
        foreach ($orders as $order) {
            if ($order->store === null) {
                continue;
            }
            app(Tenancy::class)->setStore($order->store);
            $conversation = Conversation::forStore($order->store_id)->where('channel', 'whatsapp')->where('contact', $order->customer_phone)->first();
            if ($order->created_at->lt(now()->subHours(self::EXPIRE_HOURS))) {
                try {
                    $this->desk->cancel($order, CancelReason::Unreachable, 'Annulée automatiquement : récap pas confirmé en '.self::EXPIRE_HOURS.' h (stock rendu, rien de compté).', null);
                    $count++;
                } catch (Throwable $e) {
                    Log::info('Récap WhatsApp : commande non annulée', ['order' => $order->id, 'error' => $e->getMessage()]);
                }

                continue;
            }
            if ($conversation === null || ! Cache::add('recap-reminded:'.$order->id, 1, now()->addDays(3))) {
                continue;
            }
            $this->text($conversation, "⏰ *VOTRE COMMANDE VOUS ATTEND*\n\nLa commande n° {$order->reference()} n'est pas encore confirmée.");
            app(LiveCheckout::class)->recap($conversation, $order);
            $count++;
        }

        return $count;
    }

    /**
     * Récap envoyé : la boutique le sait, avec un bouton « Confirmer » (le client a dit oui à l'écrit).
     */
    private function announce(Order $order, string $name): void
    {
        $this->tell($this->store(null, $order->store_id), $order, "Récap envoyé à {$name}", "Commande {$order->reference()} : ".Money::format((int) $order->total)
            .', à confirmer. Le client touche « Je confirme », ou écrivez « confirmé » dans la discussion.', null, true);
    }

    /**
     * Prévenir la boutique : notification (application, téléphone) et numéro de Jibiat Marchand au propriétaire, quand
     * WhatsApp le permet (il a écrit à ce numéro depuis moins de 24 h, ou numéro relié par QR code).
     */
    private function tell(Store $store, ?Order $order, string $title, string $body, ?string $url = null, bool $confirmButton = false): void
    {
        rescue(fn () => app(TeamNotifier::class)->notify($store, Permission::OrdersConfirm->value,
            new RecapNotice($store->id, $title, $body, $url ?? ($order ? '/gestion/commandes/'.$order->id : '/gestion/messages'))), report: false);
        $phone = $store->owner?->phone;
        $platform = app(PlatformWhatsApp::class);
        if (blank($phone) || ! $platform->connected()) {
            return;
        }
        $open = $platform->isQr() || SupportConversation::where('contact', $phone)->first()?->canReply();
        if (! $open) {
            return;
        }
        $text = "🧾 *{$title}*\n{$body}";
        try {
            $confirmButton && $order !== null && ! $platform->isQr()
                ? $platform->sendChoices($phone, $text, ["recap:ok:{$store->id}:{$order->id}" => '✅ Confirmer'])
                : $platform->sendAutomatic($phone, $text, false);
        } catch (RuntimeException $e) {
            Log::info('Récap WhatsApp : boutique non prévenue sur WhatsApp', ['store' => $store->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Commande récap de ce client encore modifiable (2 jours au plus) ; à confirmer seulement si demandé.
     */
    public static function openOrder(int $storeId, ?string $phone, bool $pendingOnly = false): ?Order
    {
        $id = $phone === null ? null : Cache::get(self::orderKey($storeId, $phone));

        return $id === null ? null : Order::forStore($storeId)->whereKey($id)->where('customer_phone', $phone)
            ->whereIn('status', $pendingOnly ? [OrderStatus::PendingConfirmation->value] : [OrderStatus::PendingConfirmation->value, OrderStatus::Confirmed->value])->first();
    }

    private function customerName(Conversation $conversation): string
    {
        return $conversation->contact_name ?: PhoneNumber::display($conversation->contact);
    }

    private static function stateKey(Conversation $conversation): string
    {
        return 'recap-state:'.$conversation->id;
    }

    private static function placeKey(Conversation $conversation): string
    {
        return 'recap-place:'.$conversation->id;
    }

    private static function orderKey(int $storeId, string $phone): string
    {
        return "recap-order:{$storeId}:{$phone}";
    }

    private function store(?Conversation $conversation, ?int $storeId = null): Store
    {
        return $conversation?->store ?? Store::findOrFail($storeId ?? $conversation?->store_id);
    }

    private function text(Conversation $conversation, string $text): void
    {
        try {
            $this->inbox->replyAutomatically($conversation, $text);
        } catch (Throwable $e) {
            Log::info('Récap WhatsApp : message non envoyé', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }
}
