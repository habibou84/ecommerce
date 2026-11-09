<?php

namespace App\Services\Live;

use App\Enums\CancelReason;
use App\Enums\ConfirmationOutcome;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DeliveryZone;
use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\Order;
use App\Models\Place;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Notifications\OrderAlertNotice;
use App\Services\Accounts\TeamNotifier;
use App\Services\Orders\OrderDesk;
use App\Services\Payments\PaymentDesk;
use App\Services\Places\DeliveryRates;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\WhatsApp\Inbox;
use App\Services\WhatsApp\ModelsOnWhatsApp;
use App\Services\WhatsApp\WhatsAppBridge;
use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * Liste des communes livrées, avec le prix de la livraison sous chacune.
     */
    public function askCommune(Conversation $conversation, string $text): bool
    {
        $store = Store::findOrFail($conversation->store_id);
        $rows = $this->communes($store)->map(fn (array $c) => [
            'id' => 'live:commune:'.$c['place']->id, 'title' => $c['place']->name, 'description' => $this->feeLabel($c['fee'], $c['from'], $c['eta']),
        ])->push(['id' => 'live:other', 'title' => 'Autre commune…', 'description' => 'Écrivez-la simplement']);
        if ($this->expeditions($store)) {
            $from = $this->cities($store)->min('fee') ?? (int) $store->expedition_fee;
            $rows->push(['id' => 'live:cities', 'title' => '🚚 Autre ville', 'description' => 'Expédition dès '.Money::format((int) $from)]);
        }
        $rows = $rows->values()->all();

        return $this->rich($conversation, $text.' (liste des communes)', fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $text, 'Choisir ma commune', 'Communes livrées', $rows));
    }

    /**
     * « Quelle taille ? » : boutons (3 choix au plus) ou liste (10 au plus) ; faux sans numéro officiel.
     *
     * @param  list<string>  $values
     */
    public function askOption(Conversation $conversation, string $text, array $values): bool
    {
        if ($values === [] || count($values) > 10 || ! $this->official($conversation)) {
            return false;
        }
        $ids = collect($values)->mapWithKeys(fn (string $value) => ['live:opt:'.mb_substr($value, 0, 180) => mb_substr($value, 0, 20)])->all();

        return count($values) <= 3
            ? $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendButtons($account, $to, $text, $ids))
            : $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $text, 'Choisir', 'Choix', collect($ids)
                ->map(fn (string $title, string $id) => ['id' => $id, 'title' => mb_substr($title, 0, 24)])->values()->all()));
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
        // Taille, couleur… touchée en réponse à « Quelle taille ? »
        if ($action === 'opt') {
            app(LiveSales::class)->chooseOption($conversation, (string) $id);

            return true;
        }
        $place = in_array($action, ['commune', 'place'], true) && ctype_digit((string) $id)
            ? Place::visibleTo($store->id)->with('parent.parent')->find((int) $id) : null;

        match (true) {
            $action === 'commune' && $place !== null => $this->quartiers($store, $place)->isNotEmpty()
                ? $this->askQuartier($conversation, $store, $place)
                : $this->deliverTo($conversation, $place),
            $action === 'place' && $place !== null => $this->deliverTo($conversation, $place),
            $action === 'confirm' => $this->confirm($conversation, (int) $id),
            $action === 'cities' => $this->askCity($conversation, $store),
            $action === 'othercity' => $this->text($conversation, "🚚 *VOTRE VILLE*\n\n✍️ Écrivez *votre ville*\n_Exemple : Bouaké_"),
            $action === 'address' => $this->askCommune($conversation, "📍 *NOUVELLE ADRESSE*\n\n👇 Touchez *« Choisir ma commune »* ci-dessous"),
            $action === 'add' && $this->modelsInstead($conversation, $store) => true,
            $action === 'add' => $this->text($conversation, "➕ *AJOUTER UN ARTICLE*\n\n✍️ Envoyez *le code de l'article*\n_Exemple : A4, ou plusieurs : A4 + A5_"),
            default => $this->text($conversation, "📍 *VOTRE ADRESSE*\n\n✍️ Écrivez *votre commune + votre quartier*\n_Exemple : Cocody Angré 8e tranche_"),
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
        $delivery = ($order->isExpedition() ? '🚚 Expédition' : '🛵 Livraison').($place ? " ({$place})" : '').' : '.($order->shipping_fee > 0 ? Money::format((int) $order->shipping_fee) : 'offerte');
        // Expédition payée avant l'envoi (réglage de la boutique) : on le dit à la place de « paiement à la livraison »
        $payment = $this->prepaid($order) ? "💳 *À payer avant l'envoi* : le colis part dès que vous avez payé" : '_Paiement à la livraison_';
        // Prix de gros, pack au choix, prix barré : ce que le client économise
        $saving = (int) $order->items->sum(fn ($i) => $i->compare_at_price && $i->compare_at_price * $i->quantity > $i->total ? $i->compare_at_price * $i->quantity - $i->total : 0);
        $total = '💰 *Total à payer : '.Money::format((int) $order->total).'*'.($saving > 0 ? "\n🎁 Vous économisez ".Money::format($saving) : '');
        $title = "🧾 *COMMANDE N° {$order->reference()}*";

        // WhatsApp relié par QR code : pas de boutons, le récapitulatif en texte et des réponses à taper
        // Un seul message (la photo porte tout le récapitulatif en légende) : rien ne peut arriver dans le désordre
        if (! $this->official($conversation)) {
            $details = $title."\n".($image ? '' : $lines."\n").$delivery."\n".$total;
            if ($order->status !== OrderStatus::PendingConfirmation) {
                $text = "✅ *COMMANDE MISE À JOUR*\n".$details;

                return $this->picture($conversation, $image, $text) || $this->text($conversation, "✅ *COMMANDE MISE À JOUR*\n".$title."\n".$lines."\n".$delivery."\n".$total);
            }
            Cache::put(self::menuKey($conversation), $order->id, now()->addDay());
            $menu = "\n{$payment}\n━━━━━━━━━━━━\n👉 *RÉPONDEZ AVEC UN CHIFFRE :*\n\n"
                ."*1* ✅ Je confirme\n*2* ➕ Ajouter un article\n*3* 📍 Changer l'adresse\n*4* ❌ Annuler";

            return $this->picture($conversation, $image, $details."\n📷 _Vérifiez les photos avant de confirmer_".$menu)
                || $this->text($conversation, $title."\n".$lines."\n".$delivery."\n".$total.$menu);
        }
        $details = $title."\n".($image ? '' : $lines."\n").$delivery."\n".$total.($image ? "\n📷 _Vérifiez les photos avant de confirmer_" : '');
        if ($order->status !== OrderStatus::PendingConfirmation) {
            $text = "✅ *COMMANDE MISE À JOUR*\n".$details;

            return $image
                ? $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendImage($account, $to, $image, $text))
                : $this->text($conversation, $text);
        }
        $text = $details."\n{$payment}\n\n👇 *Touchez « Je confirme » pour valider*";

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
        $home = app(DeliveryRates::class)->home($store);
        $zones = DeliveryZone::forStore($store->id)->where('is_active', true)->with('places.parent')->get();
        $targets = collect();
        $cheaperInside = []; // commune → plus petit prix d'un de ses quartiers à part
        foreach ($zones as $zone) {
            foreach ($zone->places as $place) {
                $found = match ($place->type) {
                    'commune' => collect([$place]),
                    'neighborhood' => collect([$place->parent])->filter(),
                    default => Place::visibleTo($store->id)->with('parent')->where('parent_id', $place->id)->where('type', 'commune')->get(),
                };
                foreach ($found as $commune) {
                    // Seulement la ville de la boutique : les autres villes sont des expéditions
                    if ($home !== null && $commune->parent_id !== $home->id) {
                        continue;
                    }
                    $targets->put($commune->id, $commune);
                    if ($place->type === 'neighborhood') {
                        $cheaperInside[$commune->id] = min($cheaperInside[$commune->id] ?? PHP_INT_MAX, (int) $zone->fee);
                    }
                }
            }
        }
        // Le prix le plus précis l'emporte (commune, sinon ville) ; « dès » si un quartier à part coûte moins
        $communes = $targets->map(function (Place $commune) use ($store, $cheaperInside) {
            $zone = $this->places->zoneFor($store, $commune);

            return $zone === null ? null : ['place' => $commune, 'fee' => (int) $zone->fee,
                'from' => ($cheaperInside[$commune->id] ?? PHP_INT_MAX) < (int) $zone->fee, 'eta' => $zone->eta_label];
        })->filter();
        $popular = $this->popularity($store->id, $communes->keys()->all(), true);

        return $communes->sortBy([fn ($a, $b) => ($popular[$b['place']->id] ?? 0) <=> ($popular[$a['place']->id] ?? 0), fn ($a, $b) => strcoll($a['place']->name, $b['place']->name)])
            ->take($this->expeditions($store) ? self::ROWS - 1 : self::ROWS)->values();
    }

    /** Expéditions vers les autres villes proposées : un prix est réglé dans les tarifs */
    private function expeditions(Store $store): bool
    {
        return $store->expedition_fee !== null;
    }

    /**
     * Autres villes (expédition), les plus demandées d'abord, avec leur prix (celui de la ville, sinon celui de toutes
     * les villes).
     *
     * @return Collection<int, array{place: Place, fee: int}>
     */
    public function cities(Store $store): Collection
    {
        $rates = app(DeliveryRates::class);

        return collect($rates->cities($store))->take(self::ROWS)
            ->map(fn (array $c) => ['place' => Place::find($c['id']), 'fee' => (int) ($c['fee'] ?? $store->expedition_fee ?? 0)])
            ->filter(fn (array $c) => $c['place'] !== null)->values();
    }

    private function askCity(Conversation $conversation, Store $store): bool
    {
        $rows = $this->cities($store)->map(fn (array $c) => [
            'id' => 'live:place:'.$c['place']->id, 'title' => $c['place']->name, 'description' => 'Expédition '.Money::format($c['fee']),
        ])->push(['id' => 'live:othercity', 'title' => 'Autre ville…', 'description' => 'Écrivez-la simplement'])->values()->all();
        $text = "🚚 *QUELLE VILLE ?*\n\n👇 Touchez *« Choisir ma ville »*";

        return $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $text, 'Choisir ma ville', 'Villes', $rows));
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
        $text = "📍 *{$commune->name}*".($fees->count() === 1 ? ' · livraison '.Money::format((int) $fees->first()) : '')."\n\n👇 Touchez *« Choisir mon quartier »*";

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
        // Articles choisis dans les modèles (hors live) en attente de l'adresse
        $models = app(ModelsOnWhatsApp::class);
        if ($order === null && $pending->isEmpty() && $models->waiting($conversation)) {
            $order = $models->deliverTo($conversation, $place);

            return $order !== null && $this->recap($conversation, $order);
        }
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

    /**
     * « Ajouter un article » sans live en cours : les catégories des modèles plutôt qu'un code à envoyer.
     */
    private function modelsInstead(Conversation $conversation, Store $store): bool
    {
        $models = app(ModelsOnWhatsApp::class);

        return LiveSales::ordering($store->id) === null && $models->enabled($conversation) && $models->categories($conversation);
    }

    private static function menuKey(Conversation $conversation): string
    {
        return 'live-menu:'.$conversation->id;
    }

    /** Réponses comprises après le récapitulatif en texte (WhatsApp relié par QR code) */
    private const ANSWERS = [
        'confirm' => ['1', 'oui', 'ok', 'okay', 'd accord', 'daccord', 'je confirme', 'confirme', 'confirmer', 'c est bon', 'cest bon', 'oui je confirme'],
        'add' => ['2', 'ajouter', 'ajout', 'ajouter un article'],
        'address' => ['3', 'adresse', 'changer l adresse', 'changer adresse', 'changer mon adresse'],
        'cancel' => ['4', 'annuler', 'annule', 'annulation', 'annuler la commande'],
    ];

    /**
     * Réponse à taper au récapitulatif (WhatsApp relié par QR code) : 1 ou OUI confirme, 2 ou AJOUTER, 3 ou ADRESSE,
     * 4 ou ANNULER (à redire une fois pour annuler). Après ADRESSE, le lieu écrit change l'adresse et les frais. Vrai si
     * le message a été compris comme une de ces réponses.
     */
    public function answer(Conversation $conversation, string $text): bool
    {
        if ($this->official($conversation)) {
            return false;
        }
        $phone = PhoneNumber::normalize($conversation->contact);
        $addressKey = 'live-address:'.$conversation->id;
        if (Cache::has($addressKey)) {
            $place = app(PlaceMatcher::class)->search($text, $this->places->forStore(Store::findOrFail($conversation->store_id)), 1)->first();
            if ($place !== null) {
                Cache::forget($addressKey);

                return $this->deliverTo($conversation, $place);
            }
        }
        if (! Cache::has(self::menuKey($conversation))) {
            return false;
        }
        $words = Str::of(Str::ascii(mb_strtolower($text)))->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->value();
        $action = collect(self::ANSWERS)->search(fn (array $answers) => in_array($words, $answers, true));
        if ($action === false) {
            return false;
        }
        $order = $this->liveOrder($conversation->store_id, $phone);
        if ($order === null) {
            Cache::forget(self::menuKey($conversation));

            return $this->text($conversation, 'Cette commande est déjà en route ou terminée : appelez la boutique pour toute question.');
        }
        $cancelKey = 'live-cancel:'.$conversation->id;

        return match ($action) {
            'confirm' => $this->confirm($conversation, $order->id),
            'add' => $this->modelsInstead($conversation, Store::findOrFail($conversation->store_id))
                || $this->text($conversation, "➕ *AJOUTER UN ARTICLE*\n\n✍️ Envoyez *le code de l'article*\n_Exemple : A4_"),
            'address' => Cache::put($addressKey, $order->id, now()->addHour())
                && $this->text($conversation, "📍 *NOUVELLE ADRESSE*\n\n✍️ Écrivez *votre commune + votre quartier*\n_Exemple : Yopougon Siporex_"),
            'cancel' => Cache::pull($cancelKey) === $order->id
                ? $this->cancel($conversation, $order)
                : Cache::put($cancelKey, $order->id, now()->addMinutes(10))
                    && $this->text($conversation, "⚠️ *VOUS VOULEZ ANNULER ?*\n\nPour annuler la commande n° {$order->reference()}, répondez *4* ou *ANNULER* encore une fois."),
        };
    }

    /**
     * Annulation demandée par la cliente : la commande est annulée, les pièces reviennent au live et la liste d'attente
     * est servie.
     */
    private function cancel(Conversation $conversation, Order $order): bool
    {
        try {
            $this->desk->cancel($order, CancelReason::CustomerRequest, 'Annulée par la cliente sur WhatsApp (live)', null);
        } catch (Throwable $e) {
            Log::info('Live : commande non annulée', ['order' => $order->id, 'error' => $e->getMessage()]);

            return $this->text($conversation, 'La commande n\'a pas pu être annulée : la boutique va vous contacter.');
        }
        Cache::forget(self::menuKey($conversation));
        $reservations = LiveReservation::forStore($order->store_id)->with('item')->where('order_id', $order->id)->get();
        $reservations->each->update(['status' => 'cancelled']);
        $reservations->pluck('item')->filter()->unique('id')->each(fn (LiveItem $item) => app(LiveSales::class)->promote($item));

        return $this->text($conversation, "❌ *COMMANDE ANNULÉE*\n\nLa commande n° {$order->reference()} est annulée. Merci et à bientôt sur nos lives !");
    }

    private function confirm(Conversation $conversation, int $orderId): bool
    {
        $phone = PhoneNumber::normalize($conversation->contact);
        $order = Order::forStore($conversation->store_id)->whereKey($orderId)->where('customer_phone', $phone)->first();
        if ($order === null) {
            return $this->text($conversation, 'Cette commande n\'existe plus : envoyez le code d\'un article pour recommencer.');
        }
        $first = $order->status === OrderStatus::PendingConfirmation;
        if ($first) {
            $order = $this->desk->recordConfirmation($order, ConfirmationOutcome::Confirmed, 'whatsapp', 'Confirmée par la cliente sur WhatsApp (live)', null, null);
        }
        if ($order->status === OrderStatus::Cancelled) {
            return $this->text($conversation, "La commande n° {$order->reference()} a été annulée : écrivez-nous si c'est une erreur.");
        }
        Cache::forget(self::menuKey($conversation));
        $place = $order->shipping_place_id ? Place::with('parent')->find($order->shipping_place_id)?->label() : null;
        $name = trim(strtok((string) $order->customer_name, ' ') ?: '');

        $thanks = "🎉 *COMMANDE CONFIRMÉE !*\n\nMerci".($name !== '' ? " {$name}" : '')." 🙏\n🧾 N° {$order->reference()}\n";
        $sent = $this->text($conversation, $this->prepaid($order)
            // Expédition payée avant l'envoi : le montant, comment payer, puis le colis part à la gare
            ? $thanks.'💳 *'.Money::format($order->expeditionPrepayDue())." à payer maintenant, avant l'envoi*\n".$this->howToPay($order)
                ."\n🚚 ".($place ? "{$place} : v" : 'V').'otre colis part à la gare dès que le paiement est reçu.'
            : $thanks.'💰 *'.Money::format((int) $order->total)."* à payer à la livraison\n"
                .'🛵 '.($place ? "{$place} : l" : 'L').'e livreur vous appelle avant de passer.');
        // Expédition payée avant l'envoi : les photos attendent la validation du paiement, pour que le message
        // « payez puis envoyez le reçu » reste le dernier et ne soit pas noyé sous les photos
        if ($first && ! $this->prepaid($order)) {
            $this->photos($conversation, $order);
        }

        return $sent;
    }

    /** Expédition vers une autre ville que la boutique fait payer avant l'envoi, avec encore quelque chose à payer */
    private function prepaid(Order $order): bool
    {
        return $order->isExpedition() && $order->expedition_prepay && $order->expeditionPrepayDue() > 0;
    }

    /**
     * Comment payer avant l'envoi : le lien de paiement en ligne de la commande quand la boutique en a un, sinon la
     * boutique envoie son numéro Wave ou Orange Money.
     */
    private function howToPay(Order $order): string
    {
        $store = Store::findOrFail($order->store_id);
        try {
            $link = app(PaymentDesk::class)->createLink($order, $order->expeditionPrepayDue(), 'Commande '.$order->reference().' (avant l\'envoi)', null);

            return '👉 Payez ici : '.$store->url('/payer/'.$link->token);
        } catch (Throwable) {
            // Pas de paiement en ligne : le lien Wave de la boutique, puis la capture du reçu à envoyer ici
            return filled($store->wave_link)
                ? '👉 Payez avec Wave : '.self::waveLink($store->wave_link, $order->expeditionPrepayDue())."\n📸 Puis envoyez ici la *capture du reçu Wave*."
                : '👉 La boutique vous envoie tout de suite son numéro Wave ou Orange Money pour payer.';
        }
    }

    /**
     * Lien Wave de la boutique avec le montant à payer déjà rempli (paramètre amount de Wave) : la cliente n'a
     * plus qu'à valider.
     */
    public static function waveLink(string $link, int $amount): string
    {
        $link = (string) preg_replace('/([?&])amount=[^&]*&?/', '$1', trim($link));
        $link = rtrim($link, '?&');

        return $link.(str_contains($link, '?') ? '&' : '?').'amount='.$amount;
    }

    /**
     * Photo envoyée par une cliente dont l'expédition attend d'être payée : c'est le reçu de paiement. Il est
     * noté sur la commande (la boutique le voit et valide le paiement) et la cliente est remerciée. Vrai si traité.
     */
    public function receipt(Conversation $conversation, ConversationMessage $message): bool
    {
        if ($message->type !== 'image') {
            return false;
        }
        $order = $this->liveOrder($conversation->store_id, PhoneNumber::normalize($conversation->contact));
        if ($order === null || $order->status !== OrderStatus::Confirmed || ! $this->prepaid($order)) {
            return false;
        }
        $order->forceFill(['payment_proof_at' => now(), 'payment_proof_message_id' => $message->id])->save();
        $this->desk->log($order, 'payment_proof', ['message_id' => $message->id], null);
        $this->text($conversation, "🙏 *Merci, reçu bien reçu !*\nLa boutique vérifie le paiement de la commande n° {$order->reference()} et vous prévient dès que le colis part.");
        // Le marchand est prévenu tout de suite sur son téléphone : un toucher ouvre la commande pour valider
        rescue(fn () => app(TeamNotifier::class)->notify(Store::findOrFail($order->store_id), Permission::OrdersManage->value, new OrderAlertNotice(
            $order->store_id, 'payment_proof', '💳 Reçu Wave à vérifier',
            trim(($order->customer_name ?: 'Une cliente').' · N° '.$order->reference().' · '.Money::format($order->expeditionPrepayDue()).' à recevoir'),
            '/gestion/commandes/'.$order->id,
        )), report: false);

        return true;
    }

    /**
     * « Je ne le vois pas » : la boutique ne trouve pas le paiement dans son Wave. Le reçu est retiré de la commande
     * (elle attend un nouveau reçu) et la cliente est invitée à vérifier puis à renvoyer la capture.
     */
    public function paymentRejected(Order $order): void
    {
        $order->forceFill(['payment_proof_at' => null, 'payment_proof_message_id' => null])->save();
        $this->desk->log($order, 'payment_proof_rejected', [], auth()->user());
        if (blank($order->customer_phone)) {
            return;
        }
        $conversation = Conversation::forStore($order->store_id)->where('channel', 'whatsapp')->where('contact', $order->customer_phone)->first();
        if ($conversation !== null) {
            $this->text($conversation, "⚠️ *Nous ne voyons pas encore votre paiement* pour la commande n° {$order->reference()} (".Money::format($order->expeditionPrepayDue()).").\n👉 Vérifiez dans Wave puis renvoyez la capture ici.");
        }
    }

    /**
     * Photo du reçu envoyée par la cliente, en octets : lue une fois chez WhatsApp puis gardée (les liens de Meta
     * expirent) ; null si introuvable.
     */
    public function proofImage(Order $order): ?string
    {
        if ($order->payment_proof_message_id === null) {
            return null;
        }
        $path = "payment-proofs/{$order->store_id}/{$order->payment_proof_message_id}.jpg";
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->get($path);
        }
        $message = ConversationMessage::forStore($order->store_id)->with('conversation')->find($order->payment_proof_message_id);
        if ($message === null || $message->conversation === null) {
            return null;
        }
        $bytes = rescue(fn () => $message->conversation->via_link
            ? (($link = app(Inbox::class)->linkFor($message->conversation)) && $message->provider_message_id ? app(WhatsAppBridge::class)->image($link, $message->provider_message_id) : null)
            : (($account = WhatsappAccount::forStore($order->store_id)->first()) && $message->media_id ? app(WhatsAppCloud::class)->image($account, $message->media_id) : null), null, false);
        if (is_string($bytes) && $bytes !== '') {
            Storage::disk('local')->put($path, $bytes);

            return $bytes;
        }

        return null;
    }

    /**
     * Paiement validé par la boutique pour une expédition payée avant l'envoi : la cliente est prévenue.
     */
    public function paymentValidated(Order $order): void
    {
        if (! $order->isExpedition() || ! $order->expedition_prepay || $order->expeditionPrepayDue() > 0 || blank($order->customer_phone)) {
            return;
        }
        $conversation = Conversation::forStore($order->store_id)->where('channel', 'whatsapp')->where('contact', $order->customer_phone)->first();
        if ($conversation !== null) {
            $this->text($conversation, "✅ *Paiement reçu, merci !*\nVotre commande n° {$order->reference()} part à la gare. Nous vous envoyons le ticket dès le dépôt.");
            $this->photos($conversation, $order);
        }
    }

    /** Au-delà, une seule image avec toutes les miniatures */
    private const BIG_PHOTOS = 4;

    /**
     * Après « Je confirme » : « Voici les photos de votre commande », la vraie photo de chaque article en grand avec
     * son code, sa taille et son prix (4 au plus ; au-delà une seule image avec toutes les miniatures).
     */
    public function photos(Conversation $conversation, Order $order): void
    {
        $items = LiveReservation::forStore($order->store_id)->where('order_id', $order->id)->whereNot('status', 'cancelled')
            ->with(['item.variant.image', 'item.variant.product.images', 'item.variant.option1.option', 'item.variant.option2.option', 'item.variant.option3.option'])
            ->get()->filter(fn (LiveReservation $r) => $r->item !== null)->groupBy('live_item_id')
            ->map(fn (Collection $rs) => ['item' => $rs->first()->item, 'count' => $rs->count()])->values();
        if ($items->isEmpty()) {
            return;
        }
        $intro = "📷 *Voici les photos de votre commande N° {$order->reference()}*";
        $line = fn (array $row) => $row['item']->code.' · '.($row['item']->variant?->product?->title ?? 'Article')
            .($row['item']->optionValues() !== [] ? ' · Taille '.$row['item']->variant->title : '')
            .($row['count'] > 1 ? " × {$row['count']}" : '').' · '.Money::format($row['item']->currentPrice());

        if ($items->count() > self::BIG_PHOTOS) {
            $board = $this->tryImage(fn () => $this->images->board($order->store_id, 'Votre commande N° '.$order->reference(), $items->map(fn (array $row) => [
                'code' => $row['item']->code, 'title' => $row['item']->variant?->product?->title ?? 'Article',
                'detail' => trim(($row['item']->optionValues() !== [] ? 'Taille '.$row['item']->variant->title : '').($row['count'] > 1 ? " × {$row['count']}" : '')),
                'price' => $row['item']->currentPrice(), 'image' => $row['item']->variant?->image ?? $row['item']->variant?->product?->images->first(),
            ])->all()));
            $this->picture($conversation, $board, $intro);

            return;
        }
        foreach ($items as $i => $row) {
            $photo = $this->tryImage(fn () => $this->images->item($row['item']));
            $this->picture($conversation, $photo, ($i === 0 ? $intro."\n" : '').$line($row));
        }
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
            ->where('created_at', '>=', now()->subDays(2))->latest('id')->first()
            // Commande passée depuis les modèles de la boutique (hors live)
            ?? ModelsOnWhatsApp::openOrder($storeId, $phone);
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

    /**
     * Une image avec sa légende (WhatsApp officiel ou relié par QR code) ; faux sans image ou si l'envoi échoue
     * (l'appelant écrit alors le texte seul).
     */
    public function picture(Conversation $conversation, ?string $image, string $caption): bool
    {
        if ($image === null) {
            return false;
        }
        try {
            $this->inbox->replyImageAutomatically($conversation, $image, $caption);

            return true;
        } catch (Throwable $e) {
            Log::info('Live : image WhatsApp non envoyée', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

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
