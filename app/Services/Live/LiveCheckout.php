<?php

namespace App\Services\Live;

use App\Enums\ConfirmationOutcome;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\DeliveryZone;
use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use App\Services\Orders\OrderDesk;
use App\Services\Places\Places;
use App\Services\WhatsApp\Inbox;
use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Commande du live sur WhatsApp, en touchant plutôt qu'en écrivant (numéro officiel seulement) : photo de l'article
 * réservé, commune puis quartier choisis dans une liste avec le prix de la livraison, récapitulatif en image avec le
 * total et « Je confirme » (la commande passe confirmée, sans appel), relance avant de libérer une pièce.
 * Les touches de la cliente reviennent avec un identifiant « live:… ». Sans numéro officiel : messages en texte.
 */
class LiveCheckout
{
    /** Lignes d'une liste WhatsApp : 10 au plus, la dernière pour « Autre… » */
    private const ROWS = 9;

    public function __construct(
        private readonly Inbox $inbox,
        private readonly Places $places,
        private readonly OrderDesk $desk,
        private readonly LiveImages $images,
    ) {}

    public function official(Conversation $conversation): bool
    {
        return $this->inbox->officialFor($conversation) !== null;
    }

    /**
     * Photo de l'article avec la légende ; faux si pas de photo ou pas de numéro officiel (l'appelant écrit le texte).
     */
    public function itemPhoto(Conversation $conversation, LiveItem $item, string $caption): bool
    {
        $url = $this->images->item($item);

        return $url !== null && $this->rich($conversation, $caption, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendImage($account, $to, $url, $caption));
    }

    /**
     * Liste des communes livrées, avec le prix de la livraison sous chacune.
     */
    public function askCommune(Conversation $conversation, string $text): bool
    {
        $store = Store::findOrFail($conversation->store_id);
        $rows = $this->communes($store)->map(fn (array $c) => [
            'id' => 'live:commune:'.$c['place']->id, 'title' => $c['place']->name, 'description' => $this->feeLabel($c['fee'], $c['from'], $c['eta']),
        ])->push(['id' => 'live:other', 'title' => 'Autre commune…', 'description' => 'Écrivez-la simplement'])->values()->all();

        return $this->rich($conversation, $text.' (liste des communes)', fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $text, 'Choisir ma commune', 'Communes livrées', $rows));
    }

    /**
     * Touche de la cliente (liste ou bouton) : vrai si elle concerne le live.
     */
    public function onReply(Conversation $conversation, string $payload): bool
    {
        if (! str_starts_with($payload, 'live:')) {
            return false;
        }
        $store = Store::findOrFail($conversation->store_id);
        [, $action, $id] = array_pad(explode(':', $payload, 3), 3, null);
        $place = in_array($action, ['commune', 'place'], true) && ctype_digit((string) $id)
            ? Place::visibleTo($store->id)->with('parent.parent')->find((int) $id) : null;

        match (true) {
            $action === 'commune' && $place !== null => $this->quartiers($store, $place)->isNotEmpty()
                ? $this->askQuartier($conversation, $store, $place)
                : $this->deliverTo($conversation, $place),
            $action === 'place' && $place !== null => $this->deliverTo($conversation, $place),
            $action === 'confirm' => $this->confirm($conversation, (int) $id),
            $action === 'address' => $this->askCommune($conversation, 'Où faut-il livrer votre commande ?'),
            $action === 'add' => $this->text($conversation, 'Envoyez le code de l\'article (par exemple A4) : il s\'ajoute à votre commande.'),
            default => $this->text($conversation, 'Écrivez votre commune et votre quartier, par exemple « Cocody Angré ».'),
        };

        return true;
    }

    /**
     * Récapitulatif en image (miniatures, prix, livraison, total) avec les boutons ; commande déjà confirmée : le
     * nouveau total seulement.
     */
    public function recap(Conversation $conversation, Order $order): bool
    {
        $order->refresh()->load('items');
        $store = Store::findOrFail($order->store_id);
        $image = $this->tryImage(fn () => $this->images->recap($order, $store->name));
        $lines = $order->items->map(fn ($i) => '• '.$i->title.($i->quantity > 1 ? " × {$i->quantity}" : '').' : '.Money::format((int) $i->total))->implode("\n");
        $place = $order->shipping_place_id ? Place::with('parent')->find($order->shipping_place_id)?->label() : null;
        $details = ($image ? '' : $lines."\n").'Livraison'.($place ? " ({$place})" : '').' : '.($order->shipping_fee > 0 ? Money::format((int) $order->shipping_fee) : 'offerte')
            ."\n*Total à payer : ".Money::format((int) $order->total).'*';

        if ($order->status !== OrderStatus::PendingConfirmation) {
            $text = "✅ Commande n° {$order->reference()} mise à jour.\n".$details;

            return $image
                ? $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendImage($account, $to, $image, $text))
                : $this->text($conversation, $text);
        }
        $text = $details."\nPaiement à la livraison. On confirme ?";

        return $this->rich($conversation, "Commande n° {$order->reference()} : ".Money::format((int) $order->total).' (à confirmer)', fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendButtons($account, $to, $text, [
            "live:confirm:{$order->id}" => '✅ Je confirme',
            "live:address:{$order->id}" => 'Changer l\'adresse',
            'live:add' => 'Ajouter un article',
        ], $image));
    }

    /**
     * Communes livrées par la boutique (les plus demandées d'abord), avec les frais et le délai de leur zone.
     *
     * @return Collection<int, array{place: Place, fee: int, from: bool, eta: ?string}>
     */
    public function communes(Store $store): Collection
    {
        $zones = DeliveryZone::forStore($store->id)->where('is_active', true)->with('places')->get();
        $communes = collect();
        foreach ($zones as $zone) {
            foreach ($zone->places as $place) {
                $targets = match ($place->type) {
                    'commune' => collect([$place]),
                    'neighborhood' => collect([$place->parent_id ? Place::find($place->parent_id) : null])->filter(),
                    default => Place::visibleTo($store->id)->where('parent_id', $place->id)->where('type', 'commune')->get(),
                };
                foreach ($targets as $commune) {
                    $known = $communes->get($commune->id);
                    // Quartier seulement : le prix vaut « dès » pour la commune
                    $partial = $place->type === 'neighborhood';
                    if ($known === null || $zone->fee < $known['fee']) {
                        $communes->put($commune->id, ['place' => $commune, 'fee' => (int) $zone->fee, 'from' => $partial || ($known['from'] ?? false), 'eta' => $zone->eta_label]);
                    }
                }
            }
        }
        $popular = $this->popularity($store->id, $communes->keys()->all(), true);

        return $communes->sortBy([fn ($a, $b) => ($popular[$b['place']->id] ?? 0) <=> ($popular[$a['place']->id] ?? 0), fn ($a, $b) => $a['fee'] <=> $b['fee'], fn ($a, $b) => strcoll($a['place']->name, $b['place']->name)])
            ->take(self::ROWS)->values();
    }

    /**
     * Quartiers livrés d'une commune (les plus demandés d'abord), avec leurs frais.
     *
     * @return Collection<int, array{place: Place, fee: ?int, eta: ?string}>
     */
    public function quartiers(Store $store, Place $commune): Collection
    {
        $children = Place::visibleTo($store->id)->with('parent.parent')->where('parent_id', $commune->id)->where('type', 'neighborhood')->get()
            ->map(fn (Place $p) => ['place' => $p, 'zone' => $this->places->zoneFor($store, $p)])->filter(fn ($q) => $q['zone'] !== null);
        $popular = $this->popularity(null, $children->pluck('place.id')->all(), false);

        return $children->sortBy([fn ($a, $b) => ($popular[$b['place']->id] ?? 0) <=> ($popular[$a['place']->id] ?? 0), fn ($a, $b) => strcoll($a['place']->name, $b['place']->name)])
            ->take(self::ROWS)->map(fn ($q) => ['place' => $q['place'], 'fee' => (int) $q['zone']->fee, 'eta' => $q['zone']->eta_label])->values();
    }

    private function askQuartier(Conversation $conversation, Store $store, Place $commune): bool
    {
        $quartiers = $this->quartiers($store, $commune);
        $fees = $quartiers->pluck('fee')->unique();
        $rows = $quartiers->map(fn (array $q) => array_filter([
            'id' => 'live:place:'.$q['place']->id, 'title' => $q['place']->name,
            'description' => $fees->count() > 1 ? $this->feeLabel($q['fee'], false, null) : null,
        ]))->push(['id' => 'live:other', 'title' => 'Autre quartier…', 'description' => 'Écrivez-le simplement'])->values()->all();
        $text = $commune->name.($fees->count() === 1 ? ', livraison '.Money::format((int) $fees->first()) : '').'. Dans quel quartier ?';

        return $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $text, 'Choisir mon quartier', $commune->name, $rows));
    }

    /**
     * Lieu choisi : les pièces gardées deviennent une commande, ou la commande du live change d'adresse ; puis le
     * récapitulatif.
     */
    private function deliverTo(Conversation $conversation, Place $place): bool
    {
        $phone = PhoneNumber::normalize($conversation->contact);
        $pending = LiveReservation::forStore($conversation->store_id)->with('item.session')->where('customer_phone', $phone)
            ->where('status', 'reserved')->whereNull('order_id')->get();
        $order = $pending->isNotEmpty() ? app(LiveSales::class)->deliverTo($pending, $place) : null;
        if ($order === null && $pending->isEmpty() && ($order = $this->liveOrder($conversation->store_id, $phone)) !== null) {
            try {
                $order = $this->desk->update($order, ['shipping_place_id' => $place->id, 'shipping_address' => $place->label()], null);
            } catch (Throwable $e) {
                Log::info('Live : adresse non changée', ['order' => $order->id, 'error' => $e->getMessage()]);

                return $this->text($conversation, 'L\'adresse n\'a pas pu être changée : la boutique va vous contacter.');
            }
        }

        return $order !== null
            ? $this->recap($conversation, $order)
            : $this->text($conversation, $pending->isEmpty() ? 'Rien à livrer pour l\'instant : envoyez le code d\'un article du live (par exemple A3).' : 'Merci ! La boutique vous appelle pour confirmer la livraison.');
    }

    private function confirm(Conversation $conversation, int $orderId): bool
    {
        $phone = PhoneNumber::normalize($conversation->contact);
        $order = Order::forStore($conversation->store_id)->whereKey($orderId)->where('customer_phone', $phone)->first();
        if ($order === null) {
            return $this->text($conversation, 'Cette commande n\'existe plus : envoyez le code d\'un article pour recommencer.');
        }
        if ($order->status === OrderStatus::PendingConfirmation) {
            $order = $this->desk->recordConfirmation($order, ConfirmationOutcome::Confirmed, 'whatsapp', 'Confirmée par la cliente sur WhatsApp (live)', null, null);
        }
        if ($order->status === OrderStatus::Cancelled) {
            return $this->text($conversation, "La commande n° {$order->reference()} a été annulée : écrivez-nous si c'est une erreur.");
        }
        $place = $order->shipping_place_id ? Place::with('parent')->find($order->shipping_place_id)?->label() : null;
        $first = trim(strtok((string) $order->customer_name, ' ') ?: '');

        return $this->text($conversation, '🎉 Merci'.($first !== '' ? " {$first}" : '')." ! Commande n° {$order->reference()} confirmée.\n"
            .'📦 Total : '.Money::format((int) $order->total)."\n"
            .'🛵 Livraison'.($place ? " à {$place}" : '').' : le livreur vous appelle avant de passer.');
    }

    /**
     * Commande du live encore modifiable de cette cliente (à confirmer ou confirmée, pas encore partie).
     */
    public function liveOrder(int $storeId, ?string $phone): ?Order
    {
        if ($phone === null) {
            return null;
        }

        return Order::forStore($storeId)->where('customer_phone', $phone)
            ->whereIn('status', [OrderStatus::PendingConfirmation->value, OrderStatus::Confirmed->value])
            ->whereIn('id', LiveReservation::forStore($storeId)->where('customer_phone', $phone)->whereNotNull('order_id')->select('order_id'))
            ->where('created_at', '>=', now()->subDays(2))->latest('id')->first();
    }

    /**
     * Demandes passées par lieu (commune : quartiers compris), pour mettre les plus courants en haut de la liste.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function popularity(?int $storeId, array $ids, bool $withChildren): array
    {
        if ($ids === []) {
            return [];
        }
        $query = Order::withoutGlobalScopes()->when($storeId, fn ($q) => $q->where('orders.store_id', $storeId))->whereNotNull('shipping_place_id');
        if (! $withChildren) {
            return $query->whereIn('shipping_place_id', $ids)->selectRaw('shipping_place_id, count(*) as n')->groupBy('shipping_place_id')->pluck('n', 'shipping_place_id')->all();
        }

        return $query->join('places', 'places.id', '=', 'orders.shipping_place_id')
            ->selectRaw('CASE WHEN places.id IN ('.implode(',', array_map('intval', $ids)).') THEN places.id ELSE places.parent_id END as commune, count(*) as n')
            ->where(fn ($q) => $q->whereIn('places.id', $ids)->orWhereIn('places.parent_id', $ids))
            ->groupBy('commune')->pluck('n', 'commune')->all();
    }

    private function feeLabel(int $fee, bool $from, ?string $eta): string
    {
        $price = $fee > 0 ? ($from ? 'dès ' : '').Money::format($fee) : 'offerte';

        return 'Livraison '.$price.($eta ? ' · '.mb_strtolower($eta) : '');
    }

    /**
     * @param  callable(WhatsAppCloud, mixed, string): string  $send
     */
    private function rich(Conversation $conversation, string $preview, callable $send): bool
    {
        try {
            return $this->inbox->replyRich($conversation, $preview, $send) !== null;
        } catch (Throwable $e) {
            Log::info('Live : message WhatsApp non envoyé', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function text(Conversation $conversation, string $text): bool
    {
        try {
            $this->inbox->replyAutomatically($conversation, $text);

            return true;
        } catch (Throwable $e) {
            Log::info('Live : réponse WhatsApp non envoyée', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function tryImage(callable $make): ?string
    {
        try {
            return $make();
        } catch (Throwable $e) {
            Log::info('Live : récapitulatif en image non fait', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
