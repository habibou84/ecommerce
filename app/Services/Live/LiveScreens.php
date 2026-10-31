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

        return $screen;
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

        return ['store' => $this->storeInfo($store), 'live' => $session ? $this->live($session) : null];
    }

    /**
     * Live pour l'affichage : articles (grande photo, prix, pièces restantes), article montré, mode, dernières
     * commandes.
     *
     * @return array<string, mixed>
     */
    public function live(LiveSession $session): array
    {
        $session->loadMissing('items.variant.product.images', 'items.variant.image');
        $sales = app(LiveSales::class);
        $waiting = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->where('status', 'waiting')
            ->selectRaw('live_item_id, count(*) as n')->groupBy('live_item_id')->pluck('n', 'live_item_id');

        return [
            'id' => $session->id,
            'title' => $session->title,
            'mode' => $session->display_mode ?: 'product',
            'featured_item_id' => $session->featured_item_id,
            'items' => $session->items->map(function (LiveItem $item) use ($sales, $waiting) {
                $variant = $item->variant;
                $image = $variant?->image ?? $variant?->product?->images->first();
                $compare = (int) ($variant?->compare_at_price ?? 0);
                $regular = (int) ($variant?->price ?? 0);

                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'title' => $variant?->product?->title ?? 'Article',
                    'variant' => $variant && ! $variant->is_default ? $variant->title : null,
                    'price' => (int) $item->price,
                    // Ancien prix barré : prix habituel (ou prix barré du catalogue) plus élevé que le prix du live
                    'was' => max($compare, $regular) > $item->price ? max($compare, $regular) : null,
                    'quantity' => (int) $item->quantity,
                    'remaining' => $sales->remaining($item),
                    'waiting' => (int) ($waiting[$item->id] ?? 0),
                    'image' => $image ? ($image->urls()['large'] ?? $image->urls()['medium'] ?? null) : null,
                ];
            })->values()->all(),
            'orders' => LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->where('status', 'ordered')->distinct()->count('order_id'),
            'ticker' => $this->ticker($session),
        ];
    }

    /**
     * Dernières pièces prises : « Cocody vient de prendre A1 », jamais de nom ni de numéro.
     *
     * @return list<array{id: int, text: string}>
     */
    private function ticker(LiveSession $session): array
    {
        $recent = LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->whereIn('status', LiveReservation::HOLDING)
            ->with('item:id,code', 'order:id,shipping_place_id')->latest('id')->limit(5)->get();
        $placeIds = $recent->map(fn (LiveReservation $r) => $r->order?->shipping_place_id ?? $r->shipping_place_id)->filter()->unique();
        $places = Place::with('parent')->whereIn('id', $placeIds)->get()->keyBy('id');

        return $recent->map(function (LiveReservation $r) use ($places) {
            $place = $places->get($r->order?->shipping_place_id ?? $r->shipping_place_id);
            $commune = $place ? ($place->type === 'neighborhood' && $place->parent ? $place->parent->name : $place->name) : null;

            return ['id' => $r->id, 'text' => ($commune ?? 'Une cliente').' vient de prendre '.$r->item?->code];
        })->values()->all();
    }

    /**
     * Nom et WhatsApp de la boutique affichés à l'écran.
     *
     * @return array{name: string, whatsapp: ?string, whatsapp_url: ?string, qr: ?string}
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
        ])->map(fn ($phone) => $phone ? PhoneNumber::normalize($phone) : null)
            ->first(fn ($phone) => $phone !== null && strlen($phone) <= 14);

        $url = $normalized ? 'https://wa.me/'.ltrim($normalized, '+') : null;

        return [
            'name' => $store->name,
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

        return $name !== '' ? Str::limit($name, 60, '') : 'Écran '.(LiveScreen::where('store_id', $store->id)->count() + 1);
    }
}
