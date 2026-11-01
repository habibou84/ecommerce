<?php

namespace App\Services\Live;

use App\Exceptions\BusinessRuleException;
use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\LiveScreen;
use App\Models\LiveSession;
use App\Models\Place;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Support\Tenancy;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;

/**
 * Écran du live : une télé, une tablette ou un ordinateur ouvre jibiat-marchand.com/ecran, reçoit un code à
 * 4 chiffres, la vendeuse le tape dans l'application ; l'écran affiche ensuite l'article montré, les pièces
 * restantes, « VENDU », « Comment commander » et les dernières commandes (commune seulement, jamais de nom ni de
 * numéro). Le même affichage sert au mode présentation (partage d'écran) et à TikTok LIVE Studio.
 */
class LiveScreens
{
    /** Un code non utilisé est renouvelé au bout de ce délai */
    private const CODE_MINUTES = 30;

    public const MODES = ['product', 'scroll', 'how'];

    public function create(): LiveScreen
    {
        return LiveScreen::create(['token' => Str::random(48), 'code' => $this->freeCode()]);
    }

    /**
     * Écran pas encore relié : son code, renouvelé s'il est trop ancien.
     */
    public function pendingCode(LiveScreen $screen): string
    {
        if ($screen->code === null || $screen->updated_at->lt(now()->subMinutes(self::CODE_MINUTES))) {
            $screen->forceFill(['code' => $this->freeCode()])->save();
        }

        return $screen->code;
    }

    public function pair(Store $store, string $code, ?string $name): LiveScreen
    {
        $screen = LiveScreen::whereNull('store_id')->where('code', $code)->where('updated_at', '>=', now()->subMinutes(self::CODE_MINUTES))->first();
        if ($screen === null) {
            throw new BusinessRuleException('Code inconnu ou trop ancien : rouvrez la page de l\'écran pour en avoir un nouveau.', 'code');
        }
        $screen->forceFill(['store_id' => $store->id, 'code' => null, 'paired_at' => now(), 'name' => $this->name($store, $name)])->save();
        // Même écran relié à nouveau (même nom) : l'ancienne ligne, éteinte, est remplacée
        $this->offline($store)->where('id', '!=', $screen->id)->where('name', $screen->name)->delete();

        return $screen;
    }

    /** Un écran éteint depuis plus longtemps est retiré tout seul */
    public const FORGET_DAYS = 7;

    /**
     * Ménage : écrans éteints depuis plus de 7 jours (ou reliés sans jamais s'allumer). Le lien direct est gardé.
     */
    public function forgetStale(Store $store): void
    {
        $limit = now()->subDays(self::FORGET_DAYS);
        $this->offline($store)->where(fn ($q) => $q->where('last_seen_at', '<', $limit)
            ->orWhere(fn ($q) => $q->whereNull('last_seen_at')->where('paired_at', '<', $limit)))->delete();
    }

    /**
     * Écrans (télé, tablette) de la boutique qui ne sont pas allumés en ce moment.
     *
     * @return Builder<LiveScreen>
     */
    private function offline(Store $store)
    {
        return LiveScreen::where('store_id', $store->id)->where('name', '!=', self::LINK_NAME)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(30)));
    }

    /**
     * Lien direct (ordinateur, PRISM, TikTok LIVE Studio) : un écran déjà relié, sans code.
     */
    public const LINK_NAME = 'Ordinateur ou PRISM (lien)';

    /**
     * Lien de la boutique, le même à chaque fois (déjà collé dans LIVE Studio ou PRISM, il reste valable).
     */
    public function link(Store $store): LiveScreen
    {
        return LiveScreen::where('store_id', $store->id)->where('name', self::LINK_NAME)->first()
            ?? LiveScreen::create(['store_id' => $store->id, 'token' => Str::random(48), 'paired_at' => now(), 'name' => self::LINK_NAME]);
    }

    public static function url(LiveScreen $screen): string
    {
        return Tenancy::platformUrl('/ecran/'.$screen->token);
    }

    /**
     * Affichage de l'écran : boutique et live en cours (ou null entre deux lives).
     *
     * @return array<string, mixed>
     */
    public function display(LiveScreen $screen): array
    {
        if ($screen->last_seen_at === null || $screen->last_seen_at->lt(now()->subSeconds(15))) {
            $screen->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
        $store = $screen->store;
        $session = LiveSales::current($store->id);

        return ['store' => $this->storeInfo($store), 'live' => $session ? $this->sharedLive($session) : null,
            // « Tester » touché dans l'application : l'écran se signale en grand
            'test' => Cache::has(self::testKey($screen->id))];
    }

    /**
     * Le même calcul pour tous les écrans d'un live (100 écrans = 1 calcul) : refait dès qu'une pièce est prise,
     * qu'un article change ou que la vendeuse change ce qui est montré (deux petites requêtes pour le savoir).
     *
     * @return array<string, mixed>
     */
    private function sharedLive(LiveSession $session): array
    {
        $holding = "'".implode("','", LiveReservation::HOLDING)."'";
        $reservations = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)
            ->selectRaw("count(*) as n, max(updated_at) as u, sum(case when status in ({$holding}) then 1 else 0 end) as h, sum(case when status = 'ordered' then 1 else 0 end) as o")
            ->toBase()->first();
        $items = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)
            ->selectRaw('count(*) as n, max(updated_at) as u, count(flash_price) as f')->where(fn ($q) => $q->whereNull('flash_ends_at')->orWhere('flash_ends_at', '>', now()))
            ->toBase()->first();
        $all = LiveItem::forStore($session->store_id)->where('live_session_id', $session->id)->count();
        $version = md5(implode('|', [$session->updated_at?->getTimestamp(), $session->display_mode, $session->featured_item_id,
            $reservations->n ?? 0, $reservations->u ?? '', $reservations->h ?? 0, $reservations->o ?? 0, $all, $items->n ?? 0, $items->u ?? '', $items->f ?? 0]));

        return Cache::remember('live-screen-view:'.$session->id.':'.$version, now()->addSeconds(10), fn () => $this->live($session));
    }

    public const TEST_SECONDS = 10;

    public static function testKey(int $screenId): string
    {
        return 'live-screen-test:'.$screenId;
    }

    /**
     * Live pour l'affichage : articles (grande photo, prix, pièces restantes), article montré, mode, dernières
     * commandes.
     *
     * @return array<string, mixed>
     */
    public function live(LiveSession $session): array
    {
        $session->loadMissing('items.variant.product.images', 'items.variant.image', 'items.variant.option1.option', 'items.variant.option2.option', 'items.variant.option3.option');
        $sales = app(LiveSales::class);
        $waiting = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->where('status', 'waiting')
            ->selectRaw('live_item_id, count(*) as n')->groupBy('live_item_id')->pluck('n', 'live_item_id');

        // Un produit = un code : ses variantes (tailles, couleurs) sont regroupées sur une seule carte
        $groups = $session->items->sortBy('position')->groupBy('code');
        $featured = $session->featured_item_id !== null ? $session->items->firstWhere('id', $session->featured_item_id)?->code : null;

        return [
            'id' => $session->id,
            'title' => $session->title,
            'mode' => $session->display_mode ?: 'product',
            // Article montré : la carte de son produit (premier article du code)
            'featured_item_id' => $featured !== null ? $groups->get($featured)?->first()?->id : null,
            'items' => $groups->map(function ($group) use ($sales, $waiting) {
                /** @var LiveItem $item */
                $item = $group->first();
                $variant = $item->variant;
                $image = ($group->count() === 1 ? $variant?->image : null) ?? $variant?->product?->images->first() ?? $variant?->image;
                $remaining = $group->mapWithKeys(fn (LiveItem $i) => [$i->id => $sales->remaining($i)]);
                $price = (int) $group->min(fn (LiveItem $i) => $i->currentPrice());
                $compare = (int) $group->max(fn (LiveItem $i) => max((int) ($i->variant?->compare_at_price ?? 0), (int) ($i->variant?->price ?? 0), $i->flashActive() ? $i->price : 0));
                $flash = $group->first(fn (LiveItem $i) => $i->flashActive());

                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'title' => $variant?->product?->title ?? 'Article',
                    // Produit sans tailles ni couleurs choisies à part : la variante sous le nom
                    'variant' => $group->count() === 1 && $variant && ! $variant->is_default ? $variant->title : null,
                    'price' => $price,
                    'from' => $group->map(fn (LiveItem $i) => $i->currentPrice())->unique()->count() > 1,
                    // Ancien prix barré : prix habituel (ou prix barré du catalogue, ou prix du live pendant la vente
                    // flash) plus élevé que le prix à payer
                    'was' => $compare > $price ? $compare : null,
                    // Vente flash : fin du chrono
                    'flash_ends_at' => $flash?->flash_ends_at->toIso8601String(),
                    'quantity' => (int) $group->sum('quantity'),
                    'remaining' => (int) $remaining->sum(),
                    'waiting' => (int) $group->sum(fn (LiveItem $i) => $waiting[$i->id] ?? 0),
                    'image' => $image ? ($image->urls()['large'] ?? $image->urls()['medium'] ?? null) : null,
                    // Tailles, couleurs… et ce qui reste de chacune (écran : pastilles)
                    'options' => $group->count() > 1 ? $this->options($group, $remaining->all()) : [],
                ];
            })->values()->all(),
            'orders' => LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->where('status', 'ordered')->distinct()->count('order_id'),
            'ticker' => $this->ticker($session),
        ];
    }

    /**
     * Options d'un produit du live : [{ name: « Taille », values: [{ value: « M », remaining: 2 }…] }…].
     *
     * @param  Collection<int, LiveItem>  $group
     * @param  array<int, int>  $remaining  article => pièces restantes
     * @return list<array{name: string, values: list<array{value: string, remaining: int}>}>
     */
    private function options($group, array $remaining): array
    {
        $first = $group->first();
        $names = $first->optionNames();

        return collect($names)->map(fn (string $name, int $position) => [
            'name' => $name,
            // Dans l'ordre du catalogue (S, M, L), pas dans l'ordre d'ajout au live
            'values' => $group->groupBy(fn (LiveItem $i) => $i->optionValues()[$position] ?? '')->filter(fn ($items, $value) => $value !== '')
                ->sortBy(fn ($items) => $items->first()->variant?->{'option'.($position + 1)}?->position ?? 0)
                ->map(fn ($items, $value) => ['value' => (string) $value, 'remaining' => (int) $items->sum(fn (LiveItem $i) => $remaining[$i->id] ?? 0)])
                ->values()->all(),
        ])->filter(fn (array $option) => count($option['values']) > 1)->values()->all();
    }

    /**
     * Dernières pièces prises : « Cocody vient de prendre A1 », jamais de nom ni de numéro.
     *
     * @return list<array{id: int, text: string}>
     */
    private function ticker(LiveSession $session): array
    {
        $recent = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->whereIn('status', LiveReservation::HOLDING)
            ->with('item:id,code,variant_id', 'item.variant:id,title,is_default', 'order:id,shipping_place_id')->latest('id')->limit(5)->get();
        $placeIds = $recent->map(fn (LiveReservation $r) => $r->order?->shipping_place_id ?? $r->shipping_place_id)->filter()->unique();
        $places = Place::with('parent')->whereIn('id', $placeIds)->get()->keyBy('id');

        return $recent->map(function (LiveReservation $r) use ($places) {
            $place = $places->get($r->order?->shipping_place_id ?? $r->shipping_place_id);
            $commune = $place ? ($place->type === 'neighborhood' && $place->parent ? $place->parent->name : $place->name) : null;

            $variant = $r->item?->variant;
            $detail = $variant && ! $variant->is_default ? ' ('.str_replace(' / ', ', ', (string) $variant->title).')' : '';

            return ['id' => $r->id, 'text' => ($commune ?? 'Une cliente').' vient de prendre '.$r->item?->code.$detail];
        })->values()->all();
    }

    /**
     * Nom et WhatsApp de la boutique affichés à l'écran.
     *
     * @return array{name: string, link: string, link_label: string, link_qr: string, whatsapp: ?string, whatsapp_url: ?string, qr: ?string}
     */
    public function storeInfo(Store $store): array
    {
        // Le numéro qui reçoit vraiment les codes pendant le live : WhatsApp officiel relié à Meta, sinon le WhatsApp
        // relié par QR code, sinon le numéro des réglages de la boutique
        // (un identifiant WhatsApp masqué, plus long qu'un numéro, est écarté)
        $normalized = collect([
            WhatsappAccount::forStore($store->id)->value('display_phone'),
            WhatsappLink::connectedFor($store->id)?->phone,
            $store->whatsapp_phone,
            $store->phone,
            // Un compte WhatsApp jamais passé aux 10 chiffres donne l'ancien numéro (+225 95 32 32 72 au lieu de
            // +225 05 95 32 32 72) : il est remis au format d'aujourd'hui
        ])->map(fn ($phone) => $phone ? PhoneNumber::modernize($phone) : null)
            ->first(fn ($phone) => $phone !== null && strlen($phone) <= 14);

        $url = $normalized ? 'https://wa.me/'.ltrim($normalized, '+') : null;
        // Page du live : pour commander sur le site plutôt que sur WhatsApp
        $link = $store->url('/live');

        return [
            'name' => $store->name,
            'link' => $link,
            'link_label' => preg_replace('#^https?://#', '', $link),
            'link_qr' => Cache::remember('live-qr:'.md5($link), now()->addDay(), fn () => (new QRCode(new QROptions([
                'outputType' => QROutputInterface::GDIMAGE_PNG, 'scale' => 8, 'imageTransparent' => false,
            ])))->render($link)),
            'whatsapp' => $normalized ? self::fullNumber($normalized) : null,
            'whatsapp_url' => $url,
            // QR code qui ouvre la conversation WhatsApp (image en ligne, gardée une journée)
            'qr' => $url ? Cache::remember('live-qr:'.md5($url), now()->addDay(), fn () => (new QRCode(new QROptions([
                'outputType' => QROutputInterface::GDIMAGE_PNG, 'scale' => 8, 'imageTransparent' => false,
            ])))->render($url)) : null,
        ];
    }

    /** Numéro complet, indicatif compris : +2250700000001 → « +225 07 00 00 00 01 » */
    public static function fullNumber(string $normalized): string
    {
        if (strlen($normalized) === 14 && str_starts_with($normalized, '+'.PhoneNumber::DEFAULT_COUNTRY_CODE)) {
            return '+'.PhoneNumber::DEFAULT_COUNTRY_CODE.' '.PhoneNumber::display($normalized);
        }

        return $normalized;
    }

    private function freeCode(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (LiveScreen::whereNull('store_id')->where('code', $code)->where('updated_at', '>=', now()->subMinutes(self::CODE_MINUTES))->doesntExist()) {
                return $code;
            }
        }

        throw new BusinessRuleException('Trop d\'écrans en attente : réessayez dans un instant.');
    }

    private function name(Store $store, ?string $name): string
    {
        $name = trim((string) $name);

        if ($name !== '') {
            return Str::limit($name, 60, '');
        }
        // Sans nom : « Écran », ou « Écran 2 »… seulement si un écran allumé porte déjà ce nom (sinon il le remplace)
        $taken = LiveScreen::where('store_id', $store->id)->where('last_seen_at', '>=', now()->subSeconds(30))->pluck('name')->all();
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? 'Écran' : 'Écran '.$n;
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }
}
