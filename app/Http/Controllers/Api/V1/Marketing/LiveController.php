<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\LiveSession;
use App\Models\ProductVariant;
use App\Services\Catalog\ImageStore;
use App\Services\Live\LiveImages;
use App\Services\Live\LiveSales;
use App\Services\Places\Places;
use App\Services\Storefront\ThemeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Vente en live : préparation (articles, codes, prix, pièces), écran du live (réservations en direct, mode
 * assistant) et bilan.
 */
class LiveController extends Controller
{
    /** Pancarte : une lettre puis un nombre de 1 à 99 (a12 accepté, mis en majuscule par LiveSales::sign) */
    private const SIGN = '/^[A-Za-z][1-9][0-9]?$/';

    private const SIGN_MESSAGES = [
        'items.*.code.regex' => 'Une pancarte, c\'est une lettre puis un nombre de 1 à 99 (A1, B12…).',
        'code.regex' => 'Une pancarte, c\'est une lettre puis un nombre de 1 à 99 (A1, B12…).',
    ];

    public function __construct(private readonly LiveSales $live) {}

    public function index(): JsonResponse
    {
        $store = $this->currentStore();
        $sessions = LiveSession::forStore($store->id)->withCount('items')->latest('id')->limit(30)->get();

        return response()->json([
            'data' => $sessions->map(fn (LiveSession $s) => [
                'id' => $s->id, 'title' => $s->title, 'status' => $s->status, 'items_count' => $s->items_count, 'pin_ready' => $s->pin_ready_at !== null,
                'started_at' => $s->started_at?->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(),
                'minutes' => $s->started_at ? (int) $s->started_at->diffInMinutes($s->ended_at ?? now()) : null,
                'summary' => $s->status === 'draft' ? null : $this->live->summary($s),
            ])->values(),
            'meta' => ['page_url' => $store->url('/live'), 'whatsapp' => LiveSales::whatsapp($store->id), ...$this->numberMeta($store->id)],
        ]);
    }

    /**
     * Numéro WhatsApp relié (affiché dans « Préparer un live ») et mode sans carte.
     *
     * @return array<string, mixed>
     */
    private function numberMeta(int $storeId): array
    {
        $number = LiveSales::whatsappNumber($storeId);

        return ['whatsapp_phone' => $number['phone'], 'whatsapp_no_card' => $number['no_card']];
    }

    /**
     * Marque de la boutique pour les pancartes : nom, logo et couleur principale de la boutique en ligne.
     */
    public function brand(ThemeSettings $themes, ImageStore $images): JsonResponse
    {
        $store = $this->currentStore();
        $color = rescue(fn () => $themes->resolve($store)['settings']['primary_color'] ?? null, null, false);

        return response()->json(['data' => [
            'name' => $store->name,
            'logo' => $store->logo_path ? $images->urls($store->logo_path)['medium'] : null,
            'color' => is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : '#b91c1c',
        ]]);
    }

    /**
     * Articles à présenter : recherche dans le catalogue, avec prix et stock disponible.
     */
    public function variants(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) $request->query('search'));
        $variants = ProductVariant::query()->whereHas('product')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('title', 'ilike', "%{$search}%"))
                ->orWhere('sku', 'ilike', "%{$search}%")))
            ->with(['product:id,title', 'product.images', 'levels'])->orderBy('product_id')->orderBy('position')->limit(60)->get();

        return response()->json(['data' => $variants->map(fn (ProductVariant $v) => [
            'id' => $v->id,
            'title' => $v->fullTitle(),
            // Produit et variante : « Tout le produit » ajoute toutes ses tailles et couleurs (un seul code)
            'product_id' => $v->product_id,
            'product' => $v->product?->title,
            'variant' => $v->is_default ? null : $v->title,
            'price' => $v->price,
            'available' => (int) $v->levels->sum(fn ($l) => $l->available()),
            'image' => $v->product?->images->first()?->urls()['thumb'] ?? null,
        ])->values()]);
    }

    public function show(int $session): JsonResponse
    {
        return $this->state($this->session($session));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->state($this->live->save($this->currentStore(), null, $this->validated($request), $request->user()), 201);
    }

    public function update(Request $request, int $session): JsonResponse
    {
        return $this->state($this->live->save($this->currentStore(), $this->session($session), $this->validated($request), $request->user()));
    }

    /**
     * Articles ajoutés pendant le live : codes déjà annoncés inchangés.
     */
    public function addItems(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.code' => ['nullable', 'string', 'regex:'.self::SIGN],
        ], self::SIGN_MESSAGES);
        $live = $this->session($session);
        $added = $this->live->addItems($live, $data['items']);

        return response()->json(['data' => $this->live->state($live->fresh()), 'meta' => ['codes' => $added->pluck('code')->unique()->values()]]);
    }

    /**
     * Pancarte d'un produit du live (toutes ses variantes) : celle que le marchand montre à la caméra.
     */
    public function code(Request $request, int $session, int $item): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:'.self::SIGN]], self::SIGN_MESSAGES);
        $live = $this->session($session);
        $liveItem = LiveItem::forStore($live->store_id)->where('live_session_id', $live->id)->findOrFail($item);

        return $this->state($this->live->changeCode($live, $liveItem, $data['code']));
    }

    /**
     * Article montré en ce moment : mis en avant sur la page du live (item_id null : plus aucun).
     */
    /**
     * Photos à épingler du live : les photos des articles et leur code, en hauteur et carrées, 12 articles par photo.
     */
    public function pin(int $session, LiveImages $images): JsonResponse
    {
        $model = LiveSession::with('items.variant.product.images', 'items.variant.image')->findOrFail($session);
        $store = $this->currentStore();
        // Un produit = un code (ses tailles et couleurs le partagent) : la photo du produit, comme sur l'écran du live.
        // Les articles finis n'y sont plus (sauf si tout est fini)
        $groups = $model->items->groupBy('code');
        $left = $groups->filter(fn ($group) => $group->sum(fn (LiveItem $item) => $this->live->remaining($item)) > 0);
        $tiles = ($left->isEmpty() ? $groups : $left)->map(function ($group, $code) {
            $variant = $group->first()->variant;
            $image = ($group->count() === 1 ? $variant?->image : null) ?? $variant?->product?->images->first() ?? $variant?->image;

            return ['code' => (string) $code, 'image' => $image];
        })->values();
        $pages = $tiles->chunk(LiveImages::PIN_PER_IMAGE)->map(fn ($chunk) => $chunk->values()->all())->values();
        $build = fn (bool $square) => $pages->map(function (array $chunk) use ($images, $store, $square) {
            $path = $images->pin($store->id, $chunk, $square);

            return ['path' => $path, 'url' => $images->url($path), 'codes' => array_column($chunk, 'code')];
        })->all();

        return response()->json(['data' => ['portrait' => $build(false), 'square' => $build(true)]]);
    }

    /**
     * Une photo à épingler servie par l'application (même adresse que la page : téléchargement et partage sans blocage).
     */
    public function pinFile(Request $request, LiveImages $images): Response
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:200']]);
        abort_unless(preg_match('#^live/'.$this->currentStore()->id.'/pin-(haut|carre)-[a-f0-9]{16}\.jpg$#', $data['path']) === 1, 404);

        return response($images->contents($data['path']), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * « Comment ça marche » vu une fois de plus par la personne (affiché en entier aux 2 premières visites).
     */
    public function introSeen(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['live_intro_views' => min(99, (int) $user->live_intro_views + 1)])->save();

        return response()->json(['data' => ['live_intro_views' => $user->live_intro_views]]);
    }

    /**
     * « Préparer un live », étape 3 : la photo à épingler est publiée (« C'est fait »).
     */
    public function pinReady(int $session): JsonResponse
    {
        $live = $this->session($session);
        $live->forceFill(['pin_ready_at' => now()])->save();

        return $this->state($live);
    }

    /**
     * « Lives passés » : chiffres de tous les lives terminés (lives, commandes, montant, commandes par live), le
     * produit le plus commandé et l'heure de début qui rapporte le plus en moyenne.
     */
    public function stats(): JsonResponse
    {
        $store = $this->currentStore();
        $ended = LiveSession::forStore($store->id)->where('status', 'ended')->whereNotNull('started_at')->latest('id')->limit(200)->get();
        $summaries = $ended->mapWithKeys(fn (LiveSession $s) => [$s->id => $this->live->summary($s)]);
        $orders = (int) $summaries->sum('orders');

        $best = LiveReservation::forStore($store->id)->whereIn('live_session_id', $ended->pluck('id'))->where('status', 'ordered')
            ->with('item.variant.product:id,title')->get()
            ->groupBy(fn (LiveReservation $r) => $r->item?->variant?->product_id)
            ->filter(fn ($group, $product) => $product !== '' && $product !== null)
            ->sortByDesc(fn ($group) => $group->count())->first();

        // Heure de début (heure d'Abidjan) au meilleur montant moyen
        $hour = $ended->groupBy(fn (LiveSession $s) => (int) $s->started_at->copy()->setTimezone('Africa/Abidjan')->format('G'))
            ->map(fn ($group) => $group->avg(fn (LiveSession $s) => $summaries[$s->id]['amount']))
            ->sortDesc()->keys()->first();

        return response()->json(['data' => [
            'lives' => $ended->count(),
            'orders' => $orders,
            'amount' => (int) $summaries->sum('amount'),
            'orders_per_live' => $ended->count() ? round($orders / $ended->count(), 1) : 0,
            'best_product' => $best ? ['title' => $best->first()->item?->variant?->product?->title, 'orders' => $best->count()] : null,
            'best_hour' => $hour,
        ]]);
    }

    public function feature(Request $request, int $session): JsonResponse
    {
        $data = $request->validate(['item_id' => ['nullable', 'integer']]);

        return $this->state($this->live->feature($this->session($session), $data['item_id'] ?? null));
    }

    /**
     * Vente flash : prix live sur un article pendant quelques minutes (sans prix : arrêter).
     */
    public function flash(Request $request, int $session, int $item): JsonResponse
    {
        $data = $request->validate([
            'price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
        ], [], ['price' => 'prix live', 'minutes' => 'durée']);
        $model = $this->session($session);
        $line = LiveItem::forStore($model->store_id)->where('live_session_id', $model->id)->findOrFail($item);
        $this->live->flash($model, $line, $data['price'] ?? null, $data['minutes'] ?? 5);

        return $this->state($model->fresh());
    }

    /**
     * Refaire ce live : nouveau live en préparation avec les mêmes articles.
     */
    public function duplicate(Request $request, int $session): JsonResponse
    {
        return $this->state($this->live->duplicate($this->session($session), $request->user()), 201);
    }

    public function start(int $session): JsonResponse
    {
        return $this->state($this->live->start($this->session($session)));
    }

    public function end(int $session): JsonResponse
    {
        return $this->state($this->live->end($this->session($session)));
    }

    /**
     * Mode assistant : réservation saisie par l'équipe (pseudo ou numéro, code ; quartier si on l'a).
     */
    public function reserve(Request $request, int $session): JsonResponse
    {
        $live = $this->session($session);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'],
            'name' => ['nullable', 'required_without:phone', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'place_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $item = LiveItem::forStore($live->store_id)->where('live_session_id', $live->id)->where('code', strtoupper(trim($data['code'])))->first();
        if ($item === null) {
            return response()->json(['message' => "Aucun article {$data['code']} dans ce live.", 'errors' => ['code' => ["Aucun article {$data['code']} dans ce live."]]], 422);
        }
        $this->live->reserve($item, $data, 'manual');

        return $this->state($live->fresh());
    }

    /**
     * Quartier choisi par l'équipe (réponse de la cliente pas reconnue) : ses pièces gardées deviennent une commande.
     */
    public function place(Request $request, Places $places, int $session, int $reservation): JsonResponse
    {
        $data = $request->validate(['place_id' => ['required', 'integer'], 'address' => ['nullable', 'string', 'max:255']]);
        $live = $this->session($session);
        $place = $places->forStore($this->currentStore())->firstWhere('id', $data['place_id']) ?? abort(422, 'Ce quartier n\'est pas livré par la boutique.');
        $this->live->placeFor($live, LiveReservation::forStore($live->store_id)->where('live_session_id', $live->id)->findOrFail($reservation), $place, $data['address'] ?? null);

        return $this->state($live->fresh());
    }

    public function cancel(int $session, int $reservation): JsonResponse
    {
        $live = $this->session($session);
        $this->live->cancel(LiveReservation::forStore($live->store_id)->where('live_session_id', $live->id)->findOrFail($reservation));

        return $this->state($live->fresh());
    }

    private function session(int $id): LiveSession
    {
        return LiveSession::forStore($this->currentStore()->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'hold_minutes' => ['nullable', 'integer', 'between:10,1440'],
            'stream_url' => ['nullable', 'url:https', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.code' => ['nullable', 'string', 'regex:'.self::SIGN],
        ], self::SIGN_MESSAGES);
    }

    private function state(LiveSession $session, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->live->state($session)], $status);
    }
}
