<?php

namespace App\Services\WhatsApp;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\QuotaExceededException;
use App\Models\Collection as ProductCollection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\Place;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\VolumePrice;
use App\Services\Live\LiveCheckout;
use App\Services\Live\LiveImages;
use App\Services\Live\LiveSales;
use App\Services\Orders\OrderDesk;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use App\Services\Promotions\Pricing;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Modèles de la boutique montrés sur WhatsApp, hors live : le client écrit « modèles » (ou « catalogue », « vous avez
 * quoi ? ») et reçoit les catégories, puis les photos 5 par 5 (nom, prix, tailles en stock, prix de gros, packs), et
 * commande en touchant : « Je prends » → taille → nombre de pièces (vente en gros) → commune et quartier (comme au
 * live) → récapitulatif → « Je confirme ». Numéro relié par QR code : les mêmes étapes avec des numéros à taper.
 *
 * Les touches reviennent avec un identifiant « models:… ». Ces commandes sont des commandes WhatsApp de la boutique,
 * hors live : une fois confirmées, chacune compte 1 commande du solde (retirée le soir). L'assistant se tait 6 h après
 * une réponse de l'équipe dans la conversation, depuis l'application ou depuis le téléphone de la boutique.
 */
class ModelsOnWhatsApp
{
    /** Photos envoyées à la fois */
    public const PER_PAGE = 5;

    /** Une liste des catégories au plus toutes les 2 minutes par client (demandée par un mot) */
    private const MENU_SECONDS = 120;

    /** Silence de l'assistant après une réponse de l'équipe */
    private const PAUSE_HOURS = 6;

    private const WORDS = ['modele', 'modeles', 'catalogue', 'vous avez quoi', 'vous avez quel', 'qu est ce que vous avez', 'quest ce que vous avez',
        'vos articles', 'vos produits', 'les photos', 'vos photos', 'voir les articles', 'voir vos articles', 'nouveautes', 'nouveaute'];

    /** Mots seuls compris comme une demande des modèles quand aucun live n'est en cours */
    private const LIST_WORDS = ['live', 'le live', 'liste', 'la liste', 'articles', 'les articles', 'catalogue', 'le catalogue', 'menu', 'produits', 'les produits', 'photos'];

    public function __construct(
        private readonly Inbox $inbox,
        private readonly StorefrontCatalog $catalog,
        private readonly Pricing $pricing,
        private readonly OrderDesk $desk,
    ) {}

    /**
     * Message qui demande les modèles : « modèles », « vous avez quoi ? », « catalogue »… (court message seulement).
     */
    public static function asks(string $text): bool
    {
        $words = Str::of(Str::ascii(mb_strtolower($text)))->replaceMatches('/[^a-z ]+/', ' ')->squish()->value();
        if ($words === '' || str_word_count($words) > 12) {
            return false;
        }
        if (in_array($words, self::LIST_WORDS, true)) {
            return true;
        }

        return collect(self::WORDS)->contains(fn (string $w) => preg_match('/(?<![a-z])'.preg_quote($w, '/').'(?![a-z])/', $words) === 1);
    }

    /**
     * L'assistant peut répondre : réglage de la boutique allumé, conversation WhatsApp, et personne de l'équipe n'a
     * répondu dans les 6 dernières heures.
     */
    public function enabled(Conversation $conversation): bool
    {
        $store = $conversation->store ?? Store::find($conversation->store_id);
        if ($store === null || ! $store->whatsapp_models || $conversation->channel !== 'whatsapp') {
            return false;
        }

        return ConversationMessage::forStore($conversation->store_id)->where('conversation_id', $conversation->id)->where('direction', 'out')
            ->whereNotIn('type', ['auto', 'template'])->where('created_at', '>=', now()->subHours(self::PAUSE_HOURS))->doesntExist();
    }

    /**
     * Message texte reçu : demande des modèles, ou réponse tapée à une question en cours (numéro, taille, quantité).
     * Vrai si le message a été traité.
     */
    public function fromText(Conversation $conversation, string $text): bool
    {
        if (! $this->enabled($conversation)) {
            return false;
        }
        if (self::asks($text)) {
            return Cache::add('models-menu:'.$conversation->id, 1, self::MENU_SECONDS) ? $this->categories($conversation) : true;
        }

        return $this->answer($conversation, $text);
    }

    /**
     * Touche du client (liste ou bouton) : « models:cat:c12:1 », « models:take:5 », « models:var:9 », « models:qty:9:6 ».
     */
    public function onReply(Conversation $conversation, string $payload): bool
    {
        $parts = explode(':', $payload);
        if (($parts[0] ?? '') !== 'models') {
            return false;
        }

        return match ($parts[1] ?? '') {
            'menu' => $this->categories($conversation),
            'cat' => $this->items($conversation, (string) ($parts[2] ?? 'all'), (int) ($parts[3] ?? 0)),
            'take' => $this->take($conversation, (int) ($parts[2] ?? 0)),
            'var' => $this->chooseVariant($conversation, (int) ($parts[2] ?? 0)),
            'qty' => $this->add($conversation, (int) ($parts[2] ?? 0), (int) ($parts[3] ?? 1)),
            default => $this->categories($conversation),
        };
    }

    /**
     * Catégories de la boutique (collections avec des articles en vente), plus « Packs » et « Nouveautés ».
     */
    public function categories(Conversation $conversation): bool
    {
        $store = $this->store($conversation);
        $rows = $this->categoryRows($store);
        if ($rows === []) {
            return $this->text($conversation, 'Nos modèles arrivent bientôt 🙏 Écrivez-nous ce que vous cherchez, on vous répond ici.');
        }
        if (count($rows) === 1) {
            return $this->items($conversation, $rows[0]['key'], 0);
        }
        // Dernière ligne : les prix de livraison (pour voir, sans commander)
        if (count($rows) < 10) {
            $rows[] = ['key' => 'fees', 'title' => '🛵 Prix de livraison', 'description' => 'Le prix pour votre commune'];
        }
        $intro = 'Bonjour 👋 Voici nos catégories. Touchez pour voir les photos :';
        $this->remember($conversation, ['step' => 'categories', 'options' => array_combine(range(1, count($rows)), array_column($rows, 'key'))]);
        $official = $this->rich($conversation, $intro.' (catégories)', fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $intro, 'Voir les catégories', 'Catégories',
            array_map(fn (array $r) => ['id' => $r['key'] === 'fees' ? 'fees:menu' : 'models:cat:'.$r['key'].':0', 'title' => $r['title'], 'description' => $r['description']], $rows)));
        if ($official) {
            return true;
        }
        $lines = collect($rows)->map(fn (array $r, int $i) => '*'.($i + 1).'* '.$r['title'].' · '.$r['description'])->implode("\n");

        return $this->text($conversation, "Bonjour 👋 Voici nos catégories :\n\n{$lines}\n\n👉 Écrivez *le numéro* de la catégorie.");
    }

    /**
     * @return list<array{key: string, title: string, description: string}>
     */
    private function categoryRows(Store $store): array
    {
        $rows = [];
        $base = $this->catalog->products($store);
        $collections = ProductCollection::forStore($store->id)->orderBy('sort_order')->orderBy('title')->get();
        foreach ($collections as $collection) {
            $products = (clone $base)->whereHas('collections', fn ($q) => $q->whereKey($collection->id))->with('variants:id,product_id,price')->get(['id']);
            if ($products->isEmpty()) {
                continue;
            }
            $from = (int) $products->flatMap(fn (Product $p) => $p->variants->pluck('price'))->min();
            $rows[] = ['key' => 'c'.$collection->id, 'title' => $collection->title, 'description' => $products->count().' modèle'.($products->count() > 1 ? 's' : '').' · dès '.Money::format($from)];
            if (count($rows) >= 8) {
                break;
            }
        }
        $packs = (clone $base)->where('is_bundle', true)->count();
        if ($packs > 0) {
            $rows[] = ['key' => 'packs', 'title' => '🎁 Packs', 'description' => $packs.' pack'.($packs > 1 ? 's' : '')];
        }
        $new = (clone $base)->where('published_at', '>=', now()->subDays(14))->count();
        if ($new > 0 && count($rows) < 10) {
            $rows[] = ['key' => 'new', 'title' => '✨ Nouveautés', 'description' => 'Arrivés ces deux dernières semaines'];
        }
        // Pas de catégorie : tous les modèles
        if ($collections->isEmpty() || $rows === [] || ($packs + $new > 0 && count($rows) === ($packs > 0 ? 1 : 0) + ($new > 0 ? 1 : 0))) {
            $all = (clone $base)->count();
            if ($all > 0) {
                array_unshift($rows, ['key' => 'all', 'title' => '👗 Tous nos modèles', 'description' => $all.' modèle'.($all > 1 ? 's' : '')]);
            }
        }

        return array_slice($rows, 0, 10);
    }

    /**
     * Articles d'une catégorie : en stock, les plus récents d'abord.
     */
    private function query(Store $store, string $key): Builder
    {
        $query = $this->catalog->products($store);

        return match (true) {
            str_starts_with($key, 'c') && ctype_digit(substr($key, 1)) => $query->whereHas('collections', fn ($q) => $q->whereKey((int) substr($key, 1))),
            $key === 'packs' => $query->where('is_bundle', true),
            $key === 'new' => $query->where('published_at', '>=', now()->subDays(14)),
            default => $query,
        };
    }

    /**
     * Photos d'une catégorie, 5 par 5 : nom, prix, tailles en stock, prix de gros et packs ; « Je prends » sous chacune.
     */
    public function items(Conversation $conversation, string $key, int $page): bool
    {
        $store = $this->store($conversation);
        $products = $this->query($store, $key)->with(['images', 'variants' => fn ($q) => $q->where('is_active', true)->with(['image', 'option1', 'option2', 'option3', 'product'])])
            ->orderByDesc('published_at')->orderByDesc('id')->get();
        $availability = $this->catalog->availability($store, $products->flatMap->variants->pluck('id')->all());
        $products = $products->filter(fn (Product $p) => $this->inStock($p, $availability)->isNotEmpty())->values();
        $title = $this->categoryTitle($store, $key);
        if ($products->isEmpty()) {
            return $this->text($conversation, "Plus rien en stock dans « {$title} » pour l'instant 🙏 Écrivez *modèles* pour voir les autres catégories.");
        }
        $page = max(0, min($page, intdiv($products->count() - 1, self::PER_PAGE)));
        $shown = $products->slice($page * self::PER_PAGE, self::PER_PAGE)->values();
        $official = $this->inbox->officialFor($conversation) !== null;
        $options = [];
        foreach ($shown as $n => $product) {
            $number = $n + 1;
            $options[$number] = $product->id;
            $caption = $this->caption($store, $product, $availability);
            $image = rescue(fn () => ($v = $product->variants->first(fn (ProductVariant $v) => $v->image_id) ?? $product->variants->first()) ? app(LiveImages::class)->variant($v) : null, null, false);
            if ($official) {
                $sent = $this->rich($conversation, '📷 '.$product->title.' (modèle)', fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendButtons($account, $to, $caption,
                    ['models:take:'.$product->id => '🛍️ Je prends'], $image));
                if ($sent) {
                    continue;
                }
            }
            $caption .= "\n👉 Pour le prendre, écrivez *{$number}*";
            app(LiveCheckout::class)->picture($conversation, $image, $caption) || $this->text($conversation, $caption);
        }
        $from = $page * self::PER_PAGE + 1;
        $to = $page * self::PER_PAGE + $shown->count();
        $more = $to < $products->count();
        $this->remember($conversation, ['step' => 'items', 'key' => $key, 'page' => $page, 'more' => $more, 'options' => $options]);
        $footer = "{$title} : {$from} à {$to} sur {$products->count()}";
        $buttons = ($more ? ['models:cat:'.$key.':'.($page + 1) => 'Voir la suite'] : []) + ['models:menu' => 'Autres catégories'];
        if ($official && $this->rich($conversation, $footer, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendButtons($account, $to, $footer, $buttons))) {
            return true;
        }

        return $this->text($conversation, $footer."\n\n👉 Écrivez *le numéro* du modèle que vous voulez".($more ? "\n➡️ *+* pour la suite" : '')."\n↩️ *0* pour les catégories");
    }

    private function categoryTitle(Store $store, string $key): string
    {
        return match (true) {
            str_starts_with($key, 'c') => ProductCollection::forStore($store->id)->whereKey((int) substr($key, 1))->value('title') ?? 'Modèles',
            $key === 'packs' => 'Packs',
            $key === 'new' => 'Nouveautés',
            default => 'Nos modèles',
        };
    }

    /**
     * Légende d'une photo : nom, prix (ancien prix barré), tailles en stock, prix de gros, lot, packs au choix.
     *
     * @param  array<int, int>  $availability
     */
    private function caption(Store $store, Product $product, array $availability): string
    {
        $left = $this->inStock($product, $availability);
        $context = $this->pricing->context($store->id, [$product->id]);
        $prices = $left->map(fn (ProductVariant $v) => $this->pricing->unit($context, $v));
        $price = (int) $prices->min('price');
        $compare = $prices->firstWhere('price', $price)['compare_at'] ?? null;
        $sizes = $product->has_variants ? $left->map(fn (ProductVariant $v) => $v->title)->unique()->implode(', ') : '';
        $tiers = VolumePrice::forStore($store->id)->where('product_id', $product->id)->orderBy('min_quantity')->get();
        $lines = ['*'.$product->title.'* · '.($left->pluck('price')->unique()->count() > 1 ? 'dès ' : '').Money::format($price).($compare ? ' ~'.Money::format((int) $compare).'~' : '')];
        foreach ($tiers as $tier) {
            $lines[] = '💰 Dès '.$tier->min_quantity.' : '.Money::format((int) round($tier->price / $tier->min_quantity)).' la pièce';
        }
        if ($product->lotLabel()) {
            $lines[] = '📦 Se vend '.$product->lotLabel();
        }
        foreach ($this->pricing->packsFor($store->id, $product->id) as $pack) {
            $lines[] = "🎁 {$pack['title']} : {$pack['quantity']} au choix pour ".Money::format($pack['price']);
        }
        if ($sizes !== '') {
            $lines[] = ($product->is_bundle ? 'Choix : ' : 'Tailles : ').$sizes;
        }

        return implode("\n", $lines);
    }

    /**
     * Variantes encore vendables d'un produit.
     *
     * @param  array<int, int>  $availability
     * @return Collection<int, ProductVariant>
     */
    private function inStock(Product $product, array $availability): Collection
    {
        return $product->variants->filter(function (ProductVariant $v) use ($product, $availability) {
            $v->setRelation('product', $product);
            $left = $this->catalog->sellable($v, $availability);

            return $left === null || $left > 0;
        })->values();
    }

    /**
     * « Je prends » : la taille (s'il y en a plusieurs en stock), sinon le nombre de pièces ou l'ajout.
     */
    public function take(Conversation $conversation, int $productId): bool
    {
        $store = $this->store($conversation);
        $product = $this->catalog->products($store)->with(['variants' => fn ($q) => $q->where('is_active', true)->with(['option1', 'option2', 'option3'])])->find($productId);
        if ($product === null) {
            return $this->text($conversation, "Ce modèle n'est plus en vente 🙏 Écrivez *modèles* pour voir les autres.");
        }
        $left = $this->inStock($product, $this->catalog->availability($store, $product->variants->pluck('id')->all()));
        if ($left->isEmpty()) {
            return $this->text($conversation, 'Ce modèle vient de partir 😔 Écrivez *modèles* pour voir les autres.');
        }
        if ($left->count() === 1) {
            return $this->chooseVariant($conversation, $left->first()->id);
        }
        $values = $left->take(10)->values();
        $text = "Super choix 😍 *{$product->title}*\n".($product->is_bundle ? 'Quel choix ?' : 'Quelle taille ?');
        $this->remember($conversation, ['step' => 'variant', 'product' => $product->id, 'options' => array_combine(range(1, $values->count()), $values->pluck('id')->all())]);
        $ids = $values->mapWithKeys(fn (ProductVariant $v) => ['models:var:'.$v->id => Str::limit($v->title, 20, '')])->all();
        $sent = $values->count() <= 3
            ? $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendButtons($account, $to, $text, $ids))
            : $this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $text, 'Choisir', 'Choix',
                collect($ids)->map(fn (string $title, string $id) => ['id' => $id, 'title' => $title])->values()->all()));
        if ($sent) {
            return true;
        }

        return $this->text($conversation, $text."\n\n".$values->map(fn (ProductVariant $v, int $i) => '*'.($i + 1).'* '.$v->title)->implode("\n")."\n\n👉 Écrivez *le numéro* (ou la taille).");
    }

    /**
     * Taille choisie : nombre de pièces demandé pour un article vendu en gros, sinon une pièce ajoutée.
     */
    public function chooseVariant(Conversation $conversation, int $variantId): bool
    {
        $variant = ProductVariant::forStore($conversation->store_id)->with('product')->find($variantId);
        if ($variant === null || $variant->product === null) {
            return $this->text($conversation, "Ce modèle n'est plus en vente 🙏 Écrivez *modèles* pour voir les autres.");
        }
        $product = $variant->product;
        $tiers = VolumePrice::forStore($product->store_id)->where('product_id', $product->id)->orderBy('min_quantity')->pluck('min_quantity')->all();
        if ($tiers === [] && ! $product->sale_lot && ! $product->min_order_quantity) {
            return $this->add($conversation, $variant->id, 1);
        }
        // Vente en gros : la plus petite quantité permise, puis les paliers de prix de gros (3 boutons au plus)
        $choices = collect([$product->sellableQuantity(1), ...array_map(fn (int $q) => $product->sellableQuantity($q), $tiers)])->unique()->sort()->take(3)->values();
        $text = 'Combien de pièces ?'.($product->lotLabel() ? "\n📦 Se vend ".$product->lotLabel() : '');
        $this->remember($conversation, ['step' => 'quantity', 'variant' => $variant->id]);
        $ids = $choices->mapWithKeys(fn (int $q) => ["models:qty:{$variant->id}:{$q}" => $q.(in_array($q, $tiers, true) || $q > 1 && $tiers !== [] && $q >= min($tiers) ? ' (gros)' : '')])->all();
        if ($this->rich($conversation, $text, fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendButtons($account, $to, $text."\n_Ou écrivez le nombre (ex. 12)_", $ids))) {
            return true;
        }

        return $this->text($conversation, $text."\n\n👉 Écrivez *le nombre de pièces* (ex. ".$choices->last().').');
    }

    /**
     * Article choisi : ajouté à la commande WhatsApp encore à confirmer, ou gardé en attendant l'adresse.
     */
    public function add(Conversation $conversation, int $variantId, int $quantity): bool
    {
        $store = $this->store($conversation);
        $variant = ProductVariant::forStore($store->id)->with('product')->find($variantId);
        if ($variant === null || $variant->product === null) {
            return $this->text($conversation, "Ce modèle n'est plus en vente 🙏 Écrivez *modèles* pour voir les autres.");
        }
        $product = $variant->product;
        $asked = max(1, min(999, $quantity));
        $quantity = $product->sellableQuantity($asked);
        $left = $this->catalog->sellable($variant, $this->catalog->availability($store, [$variant->id]));
        if ($left !== null && $left < $quantity) {
            return $this->text($conversation, $left > 0
                ? "Il n'en reste que {$left} 😔 Écrivez le nombre de pièces voulu (".$left.' au plus).'
                : 'Ce modèle vient de partir 😔 Écrivez *modèles* pour voir les autres.');
        }
        $this->forget($conversation);
        $note = $quantity !== $asked ? "📦 Se vend {$product->lotLabel()} : {$quantity} pièces.\n" : '';
        $phone = PhoneNumber::normalize($conversation->contact);
        $open = self::openOrder($store->id, $phone);
        if ($open !== null) {
            try {
                $order = $this->desk->addItems($open, [['variant_id' => $variant->id, 'quantity' => $quantity]], 'WhatsApp : '.$variant->fullTitle());
            } catch (BusinessRuleException $e) {
                return $this->text($conversation, $e->getMessage());
            }
            $note === '' || $this->text($conversation, trim($note));

            return app(LiveCheckout::class)->recap($conversation, $order);
        }
        $lines = Cache::get(self::linesKey($conversation), []);
        $lines[$variant->id] = $quantity;
        Cache::put(self::linesKey($conversation), $lines, now()->addDay());
        $this->text($conversation, $note."✅ *C'est noté !*\n".$variant->fullTitle().($quantity > 1 ? " × {$quantity}" : '').' · '.Money::format((int) $variant->price * $quantity)
            ."\n_Le prix final (gros, pack) est sur le récapitulatif._");
        app(LiveSales::class)->askAddress($conversation, "📍 *DERNIÈRE ÉTAPE : L'ADRESSE*");

        return true;
    }

    /**
     * Réponse tapée à la question en cours (numéro relié par QR code, ou client qui écrit au lieu de toucher).
     */
    private function answer(Conversation $conversation, string $text): bool
    {
        $state = Cache::get(self::stateKey($conversation));
        if (! is_array($state)) {
            return false;
        }
        $clean = Str::of(Str::ascii(mb_strtolower(trim($text))))->replaceMatches('/[^a-z0-9+ ]+/', ' ')->squish()->value();
        $number = ctype_digit($clean) ? (int) $clean : null;

        return match ($state['step'] ?? null) {
            'categories' => $number !== null && isset($state['options'][$number]) && ($state['options'][$number] === 'fees'
                ? app(DeliveryFeesOnWhatsApp::class)->list($conversation)
                : $this->items($conversation, $state['options'][$number], 0)),
            'items' => match (true) {
                $clean === '+' || $clean === 'suite' => $state['more'] && $this->items($conversation, $state['key'], $state['page'] + 1),
                $number === 0 => $this->categories($conversation),
                $number !== null && isset($state['options'][$number]) => $this->take($conversation, $state['options'][$number]),
                default => false,
            },
            'variant' => $this->typedVariant($conversation, $state, $clean, $number),
            'quantity' => $number !== null && $number > 0 && $this->add($conversation, (int) $state['variant'], $number),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function typedVariant(Conversation $conversation, array $state, string $clean, ?int $number): bool
    {
        if ($number !== null && isset($state['options'][$number])) {
            return $this->chooseVariant($conversation, (int) $state['options'][$number]);
        }
        $variants = ProductVariant::forStore($conversation->store_id)->whereIn('id', array_values($state['options'] ?? []))->get();
        $match = $variants->first(fn (ProductVariant $v) => Str::of(Str::ascii(mb_strtolower($v->title)))->replaceMatches('/[^a-z0-9+ ]+/', ' ')->squish()->value() === $clean);

        return $match !== null && $this->chooseVariant($conversation, $match->id);
    }

    /**
     * Lieu de livraison choisi (liste ou texte) avec des articles en attente : la commande WhatsApp est créée.
     */
    public function deliverTo(Conversation $conversation, Place $place, ?string $address = null): ?Order
    {
        $lines = Cache::get(self::linesKey($conversation), []);
        if ($lines === []) {
            return null;
        }
        $store = $this->store($conversation);
        $phone = PhoneNumber::normalize($conversation->contact);
        try {
            $order = $this->desk->create($store, [
                'source' => 'whatsapp',
                'customer_name' => $conversation->contact_name,
                'customer_phone' => $phone,
                'delivery_method' => 'delivery',
                'shipping_place_id' => $place->id,
                'shipping_address' => $address ?? $place->label(),
                'internal_note' => 'WhatsApp : commandé depuis les modèles',
                'credit_channel' => 'models',
                'items' => collect($lines)->map(fn (int $q, int $id) => ['variant_id' => $id, 'quantity' => $q])->values()->all(),
            ], null, 'customer');
        } catch (BusinessRuleException|QuotaExceededException $e) {
            Log::info('Modèles WhatsApp : commande non créée', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
            $this->text($conversation, 'Merci ! La boutique vous contacte pour finaliser la commande 🙏');

            return null;
        }
        Cache::forget(self::linesKey($conversation));
        Cache::put(self::orderKey($store->id, $phone), $order->id, now()->addDays(2));

        return $order;
    }

    /**
     * Quartier écrit par un client qui a des articles en attente : la commande, ou null si aucun lieu n'est reconnu.
     */
    public function complete(Conversation $conversation, string $text): ?Order
    {
        if (Cache::get(self::linesKey($conversation), []) === [] || (str_contains($text, '?') && str_word_count(Str::ascii($text)) > 2)) {
            return null;
        }
        $store = $this->store($conversation);
        $place = app(PlaceMatcher::class)->search($text, app(Places::class)->forStore($store), 1)->first();

        return $place === null ? null : $this->deliverTo($conversation, $place, mb_substr(trim($text), 0, 255));
    }

    /** Articles en attente de l'adresse */
    public function waiting(Conversation $conversation): bool
    {
        return Cache::get(self::linesKey($conversation), []) !== [];
    }

    /**
     * Commande passée depuis les modèles, encore à confirmer ou pas encore partie (2 jours au plus).
     */
    public static function openOrder(int $storeId, ?string $phone): ?Order
    {
        $id = $phone === null ? null : Cache::get(self::orderKey($storeId, $phone));

        return $id === null ? null : Order::forStore($storeId)->whereKey($id)->where('customer_phone', $phone)
            ->whereIn('status', [OrderStatus::PendingConfirmation->value, OrderStatus::Confirmed->value])->first();
    }

    private static function stateKey(Conversation $conversation): string
    {
        return 'models-state:'.$conversation->id;
    }

    private static function linesKey(Conversation $conversation): string
    {
        return 'models-lines:'.$conversation->id;
    }

    private static function orderKey(int $storeId, string $phone): string
    {
        return "models-order:{$storeId}:{$phone}";
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function remember(Conversation $conversation, array $state): void
    {
        Cache::put(self::stateKey($conversation), $state, now()->addHours(12));
    }

    private function forget(Conversation $conversation): void
    {
        Cache::forget(self::stateKey($conversation));
    }

    private function store(Conversation $conversation): Store
    {
        return $conversation->store ?? Store::findOrFail($conversation->store_id);
    }

    private function rich(Conversation $conversation, string $preview, callable $send): bool
    {
        try {
            return $this->inbox->replyRich($conversation, $preview, $send) !== null;
        } catch (Throwable $e) {
            Log::info('Modèles WhatsApp : message non envoyé', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function text(Conversation $conversation, string $text): bool
    {
        try {
            $this->inbox->replyAutomatically($conversation, $text);

            return true;
        } catch (Throwable $e) {
            Log::info('Modèles WhatsApp : réponse non envoyée', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
