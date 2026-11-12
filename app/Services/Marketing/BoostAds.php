<?php

namespace App\Services\Marketing;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Boost;
use App\Models\Collection;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Models\User;
use App\Notifications\BoostNotice;
use App\Services\Accounts\TeamNotifier;
use App\Services\Billing\Credits;
use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use RuntimeException;

/**
 * Assistant Boost : la pub Facebook et Instagram créée depuis l'application, sur le compte publicitaire du marchand
 * (payée à Meta par sa carte). Un produit (une photo), plusieurs (carrousel de 2 à 10) ou le catalogue (tout ou une
 * collection : Meta choisit les produits pour chacun). Public : communes ou villes livrées (cercle autour de chacune)
 * ou tout le pays, âge, femmes ou hommes. Chaque lien porte utm_content « b{id} » : les commandes venues de la pub sont
 * comptées et retirées du solde de la boutique (1 commande chacune, réglable dans la console). Conseils : pause si
 * la pub coûte plus que la marge, prolonger si elle rapporte.
 */
class BoostAds
{
    /**
     * Centres des communes d'Abidjan et des grandes villes (pour cibler un cercle autour), rayon en km.
     *
     * @var array<string, array{0: float, 1: float, 2: int}>
     */
    public const LOCATIONS = [
        'abidjan' => [5.3600, -4.0083, 20], 'abobo' => [5.4180, -4.0200, 4], 'adjame' => [5.3670, -4.0200, 3],
        'attecoube' => [5.3370, -4.0430, 3], 'cocody' => [5.3600, -3.9800, 5], 'koumassi' => [5.2970, -3.9480, 3],
        'marcory' => [5.3030, -3.9830, 3], 'plateau' => [5.3230, -4.0190, 2], 'port-bouet' => [5.2560, -3.9260, 4],
        'treichville' => [5.2950, -4.0080, 2], 'yopougon' => [5.3450, -4.0800, 5], 'bingerville' => [5.3550, -3.8850, 5],
        'songon' => [5.3200, -4.2600, 6], 'anyama' => [5.4940, -4.0520, 5], 'grand-bassam' => [5.2110, -3.7390, 6],
        'bouake' => [7.6900, -5.0300, 12], 'yamoussoukro' => [6.8200, -5.2770, 12], 'san-pedro' => [4.7480, -6.6360, 10],
        'daloa' => [6.8770, -6.4500, 10], 'korhogo' => [9.4580, -5.6290, 10], 'man' => [7.4120, -7.5540, 10],
        'gagnoa' => [6.1310, -5.9510, 10], 'divo' => [5.8370, -5.3570, 10], 'abengourou' => [6.7300, -3.4960, 10],
        'soubre' => [5.7850, -6.6080, 10], 'agboville' => [5.9280, -4.2130, 10], 'dabou' => [5.3250, -4.3770, 8],
        'bondoukou' => [8.0400, -2.8000, 10], 'odienne' => [9.5100, -7.5690, 10], 'seguela' => [7.9610, -6.6730, 10],
        'aboisso' => [5.4670, -3.2070, 8],
    ];

    /**
     * Devises des comptes publicitaires : FCFA pour une unité, et unités par devise (budgets envoyés à Meta en
     * centimes pour l'euro et le dollar). Taux du dollar approché.
     *
     * @var array<string, array{0: float, 1: int}>
     */
    public const CURRENCIES = ['XOF' => [1, 1], 'XAF' => [1, 1], 'EUR' => [655.957, 100], 'USD' => [600, 100]];

    public const BUDGETS = [1000, 2500, 5000];

    public const DAYS = [3, 7, 14];

    public function __construct(
        private readonly WhatsAppCloud $graph,
        private readonly MetaAds $meta,
        private readonly Credits $credits,
        private readonly TeamNotifier $notifier,
    ) {}

    private function token(Store $store): string
    {
        $account = $this->meta->account($store);
        if (! $account || $account->status !== 'active' || $account->token_expires_at?->isPast()) {
            throw new BusinessRuleException('Connecte d\'abord ton compte Facebook (Suivi publicitaire › Brancher le pixel et le catalogue).');
        }

        return (string) $account->access_token;
    }

    /**
     * Ce qu'il faut pour lancer un boost : compte publicitaire avec une carte, page Facebook, pixel, catalogue ;
     * et les choix de l'assistant (lieux livrés, collections, prix).
     *
     * @return array<string, mixed>
     */
    public function setup(Store $store): array
    {
        $account = $this->meta->account($store);
        $connected = $account !== null && $account->status === 'active' && ! $account->token_expires_at?->isPast();
        $adAccounts = $pages = [];
        $error = null;
        if ($connected) {
            try {
                $adAccounts = $this->adAccounts($store);
                $pages = $this->pages($store);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        $s = $this->credits->settings();

        return [
            'connected' => $connected,
            'error' => $error,
            'ad_accounts' => $adAccounts,
            'pages' => $pages,
            'pixel_id' => $store->marketingId('meta_pixel_id'),
            'catalog_id' => $store->marketingId('meta_catalog_id'),
            'zones' => $this->zones($store),
            'collections' => Collection::query()->withCount('products')->orderBy('title')->get()
                ->filter(fn ($c) => $c->products_count > 0)->map(fn ($c) => ['id' => $c->id, 'name' => $c->title, 'products' => $c->products_count])->values()->all(),
            'catalog_products' => Product::where('status', 'active')->count(),
            'budgets' => self::BUDGETS,
            'days' => self::DAYS,
            // Solde : commandes venues des boosts retirées du solde (sauf illimité ou commandes gratuites)
            'fee' => ['counted' => $this->credits->counting($store), 'weight' => $s['boost_weight'], 'orders' => (int) $store->order_credits],
        ];
    }

    /**
     * Comptes publicitaires du compte Business relié (et ceux de la personne), avec leur devise et leur carte.
     *
     * @return list<array{id: string, name: string, currency: string, usable: bool, card: bool}>
     */
    public function adAccounts(Store $store): array
    {
        $token = $this->token($store);
        $business = (string) ($store->marketing['meta_business_id'] ?? '');
        $rows = [];
        foreach (array_filter([$business ? $business.'/owned_ad_accounts' : null, $business ? $business.'/client_ad_accounts' : null, 'me/adaccounts']) as $edge) {
            try {
                $rows = array_merge($rows, $this->graph->graph('GET', $edge, $token, ['fields' => 'id,name,account_status,currency,funding_source', 'limit' => 50])['data'] ?? []);
            } catch (RuntimeException) {
                // suivant
            }
        }

        return collect($rows)->unique('id')->map(fn ($a) => [
            'id' => (string) $a['id'],
            'name' => (string) ($a['name'] ?? $a['id']),
            'currency' => (string) ($a['currency'] ?? ''),
            'usable' => (int) ($a['account_status'] ?? 0) === 1 && isset(self::CURRENCIES[$a['currency'] ?? '']),
            'card' => ! empty($a['funding_source']),
        ])->sortByDesc(fn ($a) => ($a['usable'] ? 2 : 0) + ($a['card'] ? 1 : 0))->values()->all();
    }

    /**
     * Pages Facebook utilisables pour la pub : la page déjà reliée à la boutique d'abord.
     *
     * @return list<array{id: string, name: string}>
     */
    public function pages(Store $store): array
    {
        $token = $this->token($store);
        $business = (string) ($store->marketing['meta_business_id'] ?? '');
        $rows = [];
        $linked = SocialAccount::forStore($store->id)->where('network', 'facebook')->where('status', 'active')->first();
        if ($linked) {
            $rows[] = ['id' => (string) $linked->external_id, 'name' => (string) $linked->name];
        }
        foreach (array_filter([$business ? $business.'/owned_pages' : null, $business ? $business.'/client_pages' : null, 'me/accounts']) as $edge) {
            try {
                $rows = array_merge($rows, $this->graph->graph('GET', $edge, $token, ['fields' => 'id,name', 'limit' => 50])['data'] ?? []);
            } catch (RuntimeException) {
                // suivant
            }
        }

        return collect($rows)->unique('id')->map(fn ($p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? $p['id'])])->values()->all();
    }

    /**
     * Lieux proposés : les communes et villes livrées dont on connaît le centre (sinon Abidjan et ses communes).
     *
     * @return list<array{key: string, name: string, city: bool}>
     */
    public function zones(Store $store): array
    {
        $keys = [];
        $places = DeliveryZone::forStore($store->id)->where('is_active', true)->with('places.parent.parent')->get()->flatMap->places;
        foreach ($places as $place) {
            for ($p = $place; $p !== null; $p = $p->parent) {
                $key = Str::slug($p->name);
                if (isset(self::LOCATIONS[$key])) {
                    $keys[$key] = $p->name;
                    break;
                }
            }
        }
        if ($keys === []) {
            $keys = ['abidjan' => 'Abidjan', 'abobo' => 'Abobo', 'adjame' => 'Adjamé', 'cocody' => 'Cocody', 'koumassi' => 'Koumassi',
                'marcory' => 'Marcory', 'plateau' => 'Plateau', 'port-bouet' => 'Port-Bouët', 'treichville' => 'Treichville', 'yopougon' => 'Yopougon'];
        }
        // Une commune livrée d'Abidjan : on propose aussi tout Abidjan
        if (array_intersect(array_keys($keys), ['abobo', 'adjame', 'attecoube', 'cocody', 'koumassi', 'marcory', 'plateau', 'port-bouet', 'treichville', 'yopougon']) && ! isset($keys['abidjan'])) {
            $keys = ['abidjan' => 'Abidjan'] + $keys;
        }

        return collect($keys)->map(fn ($name, $key) => ['key' => $key, 'name' => $key === 'abidjan' ? 'Abidjan (toutes communes)' : $name, 'city' => self::LOCATIONS[$key][2] >= 8])
            ->sortBy(fn ($z) => ($z['key'] === 'abidjan' ? '0' : '1').$z['name'])->values()->all();
    }

    /**
     * Public de la pub pour Meta : cercles autour des lieux choisis (ou tout le pays), âge, sexe.
     *
     * @param  list<string>  $zones
     * @return array<string, mixed>
     */
    public function targeting(Store $store, array $zones, int $ageMin, int $ageMax, string $gender): array
    {
        $circles = collect($zones)->filter(fn ($k) => isset(self::LOCATIONS[$k]))->map(fn ($k) => [
            'latitude' => self::LOCATIONS[$k][0], 'longitude' => self::LOCATIONS[$k][1], 'radius' => self::LOCATIONS[$k][2], 'distance_unit' => 'kilometer',
        ])->values()->all();
        $targeting = [
            'geo_locations' => $circles !== [] ? ['custom_locations' => $circles] : ['countries' => [$store->country ?: 'CI']],
            'age_min' => $ageMin,
            'age_max' => $ageMax,
            'targeting_automation' => ['advantage_audience' => 0],
        ];
        if ($gender !== 'all') {
            $targeting['genders'] = [$gender === 'men' ? 1 : 2];
        }

        return $targeting;
    }

    /**
     * Taille du public estimée par Meta (personnes), ou null si Meta ne répond pas.
     *
     * @return array{lower: int, upper: int}|null
     */
    public function audience(Store $store, string $adAccount, array $targeting): ?array
    {
        return Cache::remember('boost-audience:'.md5($adAccount.json_encode($targeting)), now()->addHours(6), function () use ($store, $adAccount, $targeting) {
            try {
                $row = $this->graph->graph('GET', $adAccount.'/delivery_estimate', $this->token($store), [
                    'targeting_spec' => json_encode($targeting), 'optimization_goal' => 'LINK_CLICKS',
                ])['data'][0] ?? [];
            } catch (RuntimeException|BusinessRuleException) {
                return null;
            }
            $lower = (int) ($row['estimate_mau_lower_bound'] ?? $row['estimate_dau'] ?? 0);
            $upper = (int) ($row['estimate_mau_upper_bound'] ?? $lower);

            return $upper > 0 ? ['lower' => $lower, 'upper' => $upper] : null;
        });
    }

    /**
     * Crée la pub chez Meta et la lance : campagne, ensemble (budget, durée, public), visuel (photo, carrousel ou
     * catalogue), pub. En cas d'échec, ce qui a été créé est supprimé et le boost est marqué « échoué ».
     *
     * @param  array<string, mixed>  $data
     */
    public function launch(Store $store, User $user, array $data): Boost
    {
        $token = $this->token($store);
        if ($this->credits->counting($store)) {
            $this->credits->welcome($store);
            if ((int) $store->order_credits <= 0) {
                throw new BusinessRuleException('Ton solde de commandes est vide : recharge-le pour lancer un boost.', 'payment');
            }
        }
        $account = collect($this->adAccounts($store))->firstWhere('id', $data['ad_account_id']);
        if (! $account || ! $account['usable']) {
            throw new BusinessRuleException('Ce compte publicitaire ne peut pas être utilisé (compte inactif ou devise non prise en charge).');
        }
        if (! collect($this->pages($store))->contains('id', $data['page_id'])) {
            throw new BusinessRuleException('Choisis une page Facebook de ton compte Business.');
        }
        $pixel = $store->marketingId('meta_pixel_id');
        $catalog = $store->marketingId('meta_catalog_id');
        if ($data['kind'] === 'catalog' && (! $catalog || ! $pixel)) {
            throw new BusinessRuleException('Pour booster le catalogue, branche d\'abord le pixel et le catalogue (Suivi publicitaire).');
        }

        $products = collect();
        if ($data['kind'] !== 'catalog') {
            $products = Product::with('images', 'variants')->whereIn('id', $data['product_ids'])->where('status', 'active')->get()
                ->sortBy(fn (Product $p) => array_search($p->id, $data['product_ids']))->values();
            if ($products->count() !== count($data['product_ids']) || $products->contains(fn (Product $p) => $p->images->isEmpty())) {
                throw new BusinessRuleException('Chaque produit boosté doit être en ligne et avoir une photo.');
            }
        }
        $collection = $data['kind'] === 'catalog' && ! empty($data['collection_id']) ? Collection::findOrFail($data['collection_id']) : null;

        $name = match ($data['kind']) {
            'product' => $products->first()->title,
            'products' => $products->count().' produits : '.$products->pluck('title')->take(3)->implode(', '),
            default => 'Catalogue · '.($collection?->title ?? 'tout'),
        };
        $boost = Boost::create([
            'store_id' => $store->id, 'kind' => $data['kind'], 'product_ids' => $products->pluck('id')->all() ?: null, 'collection_id' => $collection?->id,
            'name' => Str::limit($name, 180, '…'), 'daily_budget' => $data['daily_budget'], 'days' => $data['days'], 'zones' => $data['zones'] ?: null,
            'age_min' => $data['age_min'], 'age_max' => $data['age_max'], 'gender' => $data['gender'], 'message' => $data['message'],
            'status' => 'review', 'ad_account_id' => $account['id'], 'currency' => $account['currency'], 'page_id' => $data['page_id'], 'ends_at' => now()->addDays($data['days']),
            'created_by' => $user->id,
        ]);

        try {
            $this->createAd($store, $boost, $token, $products, $pixel, $catalog, $collection);
        } catch (RuntimeException $e) {
            if ($boost->campaign_id) {
                try {
                    $this->graph->graph('POST', $boost->campaign_id, $token, ['status' => 'DELETED']);
                } catch (RuntimeException) {
                    // déjà supprimée ou injoignable
                }
            }
            $boost->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 480)]);
            throw new BusinessRuleException('Meta n\'a pas créé la pub : '.$e->getMessage());
        }

        return $boost->fresh();
    }

    private function createAd(Store $store, Boost $boost, string $token, $products, ?string $pixel, ?string $catalog, ?Collection $collection): void
    {
        $act = $boost->ad_account_id;
        $tags = ['utm_source' => 'facebook', 'utm_medium' => 'boost', 'utm_content' => $boost->tag()];
        $link = fn (Product $p) => $store->url('/produits/'.$p->slug).'?'.http_build_query($tags + ['utm_campaign' => 'boost-'.$p->id]);
        $sales = $pixel !== null;

        // Catalogue : un ensemble de produits (en stock ; une collection ou tout)
        if ($boost->kind === 'catalog') {
            $filter = ['availability' => ['eq' => 'in stock']];
            if ($collection) {
                $ids = $collection->products()->with('variants')->get()->flatMap(fn (Product $p) => $p->variants->map(fn ($v) => CatalogFeed::itemId($v->sku, $v->id)))->values()->all();
                $filter = ['and' => [$filter, ['retailer_id' => ['is_any' => $ids]]]];
            }
            $set = (string) ($this->graph->graph('POST', $catalog.'/product_sets', $token, [
                'name' => Str::limit('Boost '.$boost->id.' · '.($collection?->title ?? 'tout le catalogue'), 90, ''), 'filter' => json_encode($filter),
            ])['id'] ?? '');
            $boost->update(['product_set_id' => $set]);
        }

        $campaign = ['name' => 'Boost · '.$boost->name, 'objective' => $sales ? 'OUTCOME_SALES' : 'OUTCOME_TRAFFIC', 'status' => 'PAUSED',
            'special_ad_categories' => [], 'is_adset_budget_sharing_enabled' => false];
        if ($boost->kind === 'catalog') {
            $campaign['promoted_object'] = ['product_catalog_id' => $catalog];
        }
        $boost->update(['campaign_id' => (string) ($this->graph->graph('POST', $act.'/campaigns', $token, $campaign)['id'] ?? '')]);

        [$rate, $minor] = self::CURRENCIES[$boost->currency];
        $adset = [
            'name' => 'Boost '.$boost->id, 'campaign_id' => $boost->campaign_id, 'status' => 'ACTIVE',
            'daily_budget' => max(1, (int) round($boost->daily_budget / $rate * $minor)),
            'billing_event' => 'IMPRESSIONS', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'optimization_goal' => $sales ? 'OFFSITE_CONVERSIONS' : 'LINK_CLICKS',
            'start_time' => now()->toIso8601String(), 'end_time' => $boost->ends_at->toIso8601String(),
            'targeting' => $this->targeting($store, $boost->zones ?? [], $boost->age_min, $boost->age_max, $boost->gender),
        ];
        if ($boost->kind === 'catalog') {
            $adset['promoted_object'] = ['product_set_id' => $boost->product_set_id, 'custom_event_type' => 'PURCHASE'];
        } elseif ($sales) {
            $adset['promoted_object'] = ['pixel_id' => $pixel, 'custom_event_type' => 'PURCHASE'];
        }
        $boost->update(['adset_id' => (string) ($this->graph->graph('POST', $act.'/adsets', $token, $adset)['id'] ?? '')]);

        $shop = ['type' => 'SHOP_NOW'];
        $creative = ['name' => 'Boost '.$boost->id];
        if ($boost->kind === 'catalog') {
            $creative['product_set_id'] = $boost->product_set_id;
            $creative['url_tags'] = http_build_query($tags + ['utm_campaign' => 'boost-catalogue']);
            $creative['object_story_spec'] = ['page_id' => $boost->page_id, 'template_data' => [
                'link' => $store->url('/'), 'message' => $boost->message, 'name' => '{{product.name}}', 'description' => '{{product.price}}',
                'call_to_action' => $shop, 'multi_share_end_card' => false,
            ]];
        } elseif ($boost->kind === 'product') {
            $p = $products->first();
            $creative['object_story_spec'] = ['page_id' => $boost->page_id, 'link_data' => [
                'link' => $link($p), 'message' => $boost->message, 'picture' => $p->images->first()->urls()['large'], 'name' => $p->title,
                'description' => Money::format((int) $p->variants->min('price')), 'call_to_action' => $shop + ['value' => ['link' => $link($p)]],
            ]];
        } else {
            $creative['object_story_spec'] = ['page_id' => $boost->page_id, 'link_data' => [
                'link' => $store->url('/').'?'.http_build_query($tags + ['utm_campaign' => 'boost-selection']), 'message' => $boost->message,
                'multi_share_optimized' => true, 'multi_share_end_card' => false,
                'child_attachments' => $products->map(fn (Product $p) => [
                    'link' => $link($p), 'picture' => $p->images->first()->urls()['large'], 'name' => Str::limit($p->title, 40, ''),
                    'description' => Money::format((int) $p->variants->min('price')), 'call_to_action' => $shop + ['value' => ['link' => $link($p)]],
                ])->all(),
            ]];
        }
        $creativeId = (string) ($this->graph->graph('POST', $act.'/adcreatives', $token, $creative)['id'] ?? '');
        $boost->update(['ad_id' => (string) ($this->graph->graph('POST', $act.'/ads', $token, [
            'name' => 'Boost '.$boost->id, 'adset_id' => $boost->adset_id, 'creative' => ['creative_id' => $creativeId], 'status' => 'ACTIVE',
        ])['id'] ?? '')]);
        $this->graph->graph('POST', $boost->campaign_id, $token, ['status' => 'ACTIVE']);
    }

    /** Pause, reprise ou arrêt définitif */
    public function setStatus(Boost $boost, string $action): Boost
    {
        if (! in_array($boost->status, Boost::LIVE, true)) {
            throw new BusinessRuleException('Ce boost est terminé.');
        }
        $token = $this->token($boost->store);
        try {
            $this->graph->graph('POST', $boost->campaign_id, $token, ['status' => $action === 'resume' ? 'ACTIVE' : 'PAUSED']);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage());
        }
        $boost->update(['status' => match ($action) {
            'resume' => 'active', 'pause' => 'paused', default => 'ended'
        }]);

        return $this->sync($boost);
    }

    /** Prolonger : la fin recule du nombre de jours (et repart de maintenant si le boost était fini) */
    public function extend(Boost $boost, int $days): Boost
    {
        if (! in_array($boost->status, [...Boost::LIVE, 'ended'], true)) {
            throw new BusinessRuleException('Ce boost ne peut pas être prolongé.');
        }
        $token = $this->token($boost->store);
        $end = ($boost->ends_at->isPast() ? now() : $boost->ends_at)->copy()->addDays($days);
        try {
            $this->graph->graph('POST', $boost->adset_id, $token, ['end_time' => $end->toIso8601String()]);
            if ($boost->status !== 'active' && $boost->status !== 'review') {
                $this->graph->graph('POST', $boost->campaign_id, $token, ['status' => 'ACTIVE']);
            }
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage());
        }
        $boost->update(['ends_at' => $end, 'days' => $boost->days + $days, 'status' => $boost->status === 'review' ? 'review' : 'active']);

        return $this->sync($boost);
    }

    /**
     * Relit chez Meta l'état de la pub et ses chiffres, compte les commandes venues de la pub et retire le prix de
     * l'assistant du crédit pour les nouvelles commandes. Prévient l'équipe quand la pub commence ou est refusée.
     */
    public function sync(Boost $boost): Boost
    {
        $store = $boost->store;
        $before = $boost->status;
        if ($boost->campaign_id && $boost->status !== 'failed') {
            try {
                $token = $this->token($store);
                [$rate] = self::CURRENCIES[$boost->currency] ?? [1];
                $stats = $this->graph->graph('GET', $boost->campaign_id.'/insights', $token, ['fields' => 'spend,clicks,impressions', 'date_preset' => 'maximum'])['data'][0] ?? [];
                $boost->fill(['spend' => (int) round((float) ($stats['spend'] ?? 0) * $rate), 'clicks' => (int) ($stats['clicks'] ?? 0), 'impressions' => (int) ($stats['impressions'] ?? 0)]);
                if (in_array($boost->status, Boost::LIVE, true) && $boost->ad_id) {
                    $ad = $this->graph->graph('GET', $boost->ad_id, $token, ['fields' => 'effective_status,ad_review_feedback']);
                    $boost->status = match (true) {
                        $boost->ends_at->isPast() => 'ended',
                        ($ad['effective_status'] ?? '') === 'DISAPPROVED' => 'refused',
                        in_array($ad['effective_status'] ?? '', ['PENDING_REVIEW', 'IN_PROCESS'], true) => 'review',
                        in_array($ad['effective_status'] ?? '', ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'], true) => 'paused',
                        ($ad['effective_status'] ?? '') === 'ACTIVE' => 'active',
                        default => $boost->status,
                    };
                    if ($boost->status === 'refused') {
                        $boost->error = Str::limit(collect($ad['ad_review_feedback']['global'] ?? [])->implode(' '), 480) ?: 'Pub refusée par Meta.';
                    }
                }
            } catch (RuntimeException|BusinessRuleException) {
                // Meta injoignable : on garde les derniers chiffres
            }
        }

        // Commandes venues de la pub (sans les annulées) ; les nouvelles sont retirées du solde
        $orders = Order::forStore($store->id)->where('utm->utm_content', $boost->tag())
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value]);
        $boost->orders = (clone $orders)->count();
        $boost->sales = (int) (clone $orders)->sum('total');
        $new = max(0, $boost->orders - $boost->billed_orders);
        DB::transaction(function () use ($boost, $store, $new) {
            if ($new > 0) {
                $used = $this->credits->counting($store) ? $new * $this->credits->settings()['boost_weight'] : 0;
                $this->credits->debit($store, $used);
                $boost->billed_orders += $new;
                $boost->credits_used += $used;
            }
            $boost->synced_at = now();
            $boost->save();
        });

        if ($before !== $boost->status) {
            match ($boost->status) {
                'active' => $this->notify($store, 'Ton boost a commencé', '« '.$boost->name.' » est en ligne sur Facebook et Instagram.'),
                'refused' => $this->notify($store, 'Boost refusé par Meta', '« '.$boost->name.' » : '.$boost->error),
                'ended' => $this->notify($store, 'Boost terminé', '« '.$boost->name.' » : '.$boost->orders.' commande'.($boost->orders > 1 ? 's' : '').' pour '.Money::format($boost->spend).' de pub.'),
                default => null,
            };
        }

        return $boost;
    }

    private function notify(Store $store, string $title, string $body): void
    {
        $this->notifier->notify($store, Permission::StoreManage->value, new BoostNotice($store->id, $title, $body));
    }

    /**
     * Marge par commande des produits boostés (prix moins prix d'achat), ou null si le prix d'achat n'est pas saisi.
     * Catalogue : la marge moyenne des produits en ligne.
     */
    public function margin(Boost $boost): ?int
    {
        $products = Product::with('variants')->where('status', 'active')
            ->when($boost->kind !== 'catalog', fn ($q) => $q->whereIn('id', $boost->product_ids ?? []))->limit(200)->get();
        $margins = $products->map(function (Product $p) {
            $v = $p->variants->filter(fn ($v) => $v->is_active && (int) $v->cost_price > 0)->sortBy('price')->first();

            return $v ? (int) $v->price - (int) $v->cost_price : null;
        })->filter(fn ($m) => $m !== null);

        return $margins->isEmpty() ? null : (int) round($margins->avg());
    }

    /**
     * Conseil sur un boost en cours : pause si la pub coûte plus que la marge par commande (ou aucune commande après
     * deux jours de budget), prolonger si elle coûte moins de 60 % de la marge. Sans prix d'achat : demander de le
     * saisir. Rien tant que la pub n'a pas assez tourné.
     *
     * @return array{kind: string, margin: ?int}|null
     */
    public function advice(Boost $boost): ?array
    {
        if (! in_array($boost->status, ['active', 'paused'], true) || ($boost->orders < 2 && $boost->spend < 2 * $boost->daily_budget)) {
            return null;
        }
        $margin = $this->margin($boost);
        if ($boost->orders === 0) {
            return ['kind' => 'stop', 'margin' => $margin];
        }
        if ($margin === null) {
            return ['kind' => 'cost', 'margin' => null];
        }
        $cost = $boost->spend / $boost->orders;

        return match (true) {
            $cost > $margin => ['kind' => 'stop', 'margin' => $margin],
            $cost <= 0.6 * $margin && $boost->status === 'active' => ['kind' => 'go', 'margin' => $margin],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function present(Boost $boost): array
    {
        return [
            'id' => $boost->id, 'kind' => $boost->kind, 'name' => $boost->name, 'status' => $boost->status, 'error' => $boost->error,
            'product_ids' => $boost->product_ids ?? [], 'collection_id' => $boost->collection_id,
            // Pour relancer le même boost : produits avec leur photo
            'products' => $boost->product_ids ? Product::with('images', 'variants')->whereIn('id', $boost->product_ids)->get()->map(fn (Product $p) => [
                'id' => $p->id, 'title' => $p->title, 'image' => $p->images->first()?->urls()['medium'] ?? null, 'price' => (int) $p->variants->min('price'), 'sold' => 0,
            ])->values()->all() : [],
            'daily_budget' => $boost->daily_budget, 'days' => $boost->days, 'zones' => $boost->zones ?? [], 'age_min' => $boost->age_min,
            'age_max' => $boost->age_max, 'gender' => $boost->gender, 'message' => $boost->message,
            'ad_account_id' => $boost->ad_account_id, 'page_id' => $boost->page_id,
            'budget_total' => $boost->daily_budget * $boost->days, 'spend' => $boost->spend, 'clicks' => $boost->clicks,
            'impressions' => $boost->impressions, 'orders' => $boost->orders, 'sales' => $boost->sales, 'credits_used' => $boost->credits_used,
            'cost_per_order' => $boost->orders > 0 ? (int) round($boost->spend / $boost->orders) : null,
            'advice' => $this->advice($boost),
            'day' => min($boost->days, (int) floor($boost->created_at->diffInDays(now())) + 1),
            'ends_at' => $boost->ends_at->toIso8601String(), 'created_at' => $boost->created_at->toIso8601String(),
        ];
    }
}
