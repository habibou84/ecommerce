<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\LiveItem;
use App\Models\LiveScreen;
use App\Models\LiveSession;
use App\Services\Live\LiveImages;
use App\Services\Live\LiveSales;
use App\Services\Live\LiveScreens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Live en images : écrans du live (télé, tablette, ordinateur) reliés par un code, mode d'affichage choisi par la
 * vendeuse, cartes des articles à enregistrer dans la galerie du téléphone.
 */
class LiveScreenController extends Controller
{
    public function __construct(private readonly LiveScreens $screens) {}

    // ── Écran (sans compte) ──

    public function create(): JsonResponse
    {
        $screen = $this->screens->create();

        return response()->json(['data' => ['token' => $screen->token, 'code' => $screen->code]], 201);
    }

    public function show(string $token): JsonResponse
    {
        $screen = LiveScreen::where('token', $token)->firstOrFail();
        if ($screen->store_id === null) {
            return response()->json(['data' => ['paired' => false, 'code' => $this->screens->pendingCode($screen)]]);
        }

        return response()->json(['data' => ['paired' => true] + $this->screens->display($screen)]);
    }

    // ── Boutique ──

    public function index(): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => LiveScreen::where('store_id', $store->id)->latest('id')->get()->map(fn (LiveScreen $s) => $this->present($s))]);
    }

    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:4'], 'name' => ['nullable', 'string', 'max:60']], [], ['code' => 'code de l\'écran']);

        return response()->json(['data' => $this->present($this->screens->pair($this->currentStore(), $data['code'], $data['name'] ?? null))], 201);
    }

    /**
     * Lien direct de l'écran, à coller dans TikTok LIVE Studio, OBS ou PRISM (page web par-dessus la vidéo).
     */
    public function link(): JsonResponse
    {
        return response()->json(['data' => $this->present($this->screens->link($this->currentStore()))]);
    }

    public function destroy(int $screen): JsonResponse
    {
        LiveScreen::where('store_id', $this->currentStore()->id)->findOrFail($screen)->delete();

        return response()->json(['data' => null]);
    }

    /**
     * Ce que montrent les écrans : l'article choisi, le défilement des articles ou « Comment commander ».
     */
    public function mode(Request $request, int $session, LiveSales $live): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(LiveScreens::MODES)]]);
        $model = LiveSession::findOrFail($session);
        $model->update(['display_mode' => $data['mode']]);

        return response()->json(['data' => $live->state($model->fresh())]);
    }

    /**
     * Affichage du live tel que le voient les écrans (mode présentation de l'application).
     */
    public function display(int $session): JsonResponse
    {
        return response()->json(['data' => [
            'store' => $this->screens->storeInfo($this->currentStore()),
            'live' => $this->screens->live(LiveSession::findOrFail($session)),
        ]]);
    }

    /**
     * Cartes du live : une image par article (et sa version « VENDU »), plus « Comment commander ».
     */
    public function cards(Request $request, int $session, LiveImages $images, LiveSales $live): JsonResponse
    {
        $options = $request->validate(['was' => ['sometimes', 'boolean'], 'stock' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->buildCards(LiveSession::findOrFail($session), $images, $live, (bool) ($options['was'] ?? true), (bool) ($options['stock'] ?? true))]);
    }

    /**
     * Toutes les cartes dans un fichier ZIP (ordinateur, ou téléphone sans partage de fichiers).
     */
    public function zip(Request $request, int $session, LiveImages $images, LiveSales $live): BinaryFileResponse
    {
        $options = $request->validate(['was' => ['sometimes', 'boolean'], 'stock' => ['sometimes', 'boolean'], 'sold' => ['sometimes', 'boolean']]);
        $model = LiveSession::findOrFail($session);
        $cards = $this->buildCards($model, $images, $live, (bool) ($options['was'] ?? true), (bool) ($options['stock'] ?? true));

        $file = tempnam(sys_get_temp_dir(), 'cartes');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        foreach ($cards['items'] as $i => $card) {
            $zip->addFromString(sprintf('%02d-%s.jpg', $i + 1, $card['code']), $images->contents($card['path']));
            if (! empty($options['sold'])) {
                $zip->addFromString(sprintf('%02d-%s-vendu.jpg', $i + 1, $card['code']), $images->contents($card['sold_path']));
            }
        }
        $zip->addFromString('00-comment-commander.jpg', $images->contents($cards['how']['path']));
        $zip->close();

        return response()->download($file, 'cartes-du-live-'.$model->id.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Une carte servie par l'application (même adresse que la page : partage vers la galerie sans blocage du
     * navigateur).
     */
    public function file(Request $request, LiveImages $images): Response
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:200']]);
        abort_unless(preg_match('#^live/'.$this->currentStore()->id.'/(card|how)-[A-Za-z0-9-]+\.jpg$#', $data['path']) === 1, 404);

        return response($images->contents($data['path']), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * @return array{items: list<array<string, mixed>>, how: array<string, string>}
     */
    private function buildCards(LiveSession $session, LiveImages $images, LiveSales $live, bool $was, bool $stock): array
    {
        $display = $this->screens->live($session);
        $session->loadMissing('items.variant.image', 'items.variant.product.images');
        $models = $session->items->keyBy('id');
        $store = $this->currentStore();

        $items = collect($display['items'])->map(function (array $item) use ($images, $models, $store, $was, $stock) {
            /** @var LiveItem $model */
            $model = $models->get($item['id']);
            $card = [
                'code' => $item['code'], 'title' => $item['title'], 'variant' => $item['variant'], 'price' => $item['price'],
                'was' => $was ? $item['was'] : null, 'remaining' => $stock ? $item['remaining'] : null,
                'image' => $model?->variant?->image ?? $model?->variant?->product?->images->first(),
            ];
            $path = $images->card($store->id, $card);
            $sold = $images->card($store->id, $card, true);

            return ['id' => $item['id'], 'code' => $item['code'], 'title' => $item['title'], 'remaining' => $item['remaining'],
                'url' => $images->url($path), 'sold_url' => $images->url($sold), 'path' => $path, 'sold_path' => $sold];
        })->values();

        $info = $this->screens->storeInfo($store);
        $how = $images->howCard($store->id, $store->name, $info['whatsapp'], $info['whatsapp_url']);

        return ['items' => $items->all(), 'how' => ['url' => $images->url($how), 'path' => $how]];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LiveScreen $screen): array
    {
        return [
            'id' => $screen->id, 'name' => $screen->name, 'online' => $screen->isOnline(), 'is_link' => $screen->name === LiveScreens::LINK_NAME,
            'last_seen_at' => $screen->last_seen_at?->toIso8601String(),
            // Lien direct (ordinateur, PRISM) : à coller une fois dans le logiciel de live
            'url' => LiveScreens::url($screen),
        ];
    }
}
