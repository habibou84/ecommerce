<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\CaptionWriter;
use App\Services\Social\MetaSocial;
use App\Services\Social\SocialConnections;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialStats;
use App\Services\Social\TikTokSocial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Réseaux sociaux de la boutique : comptes connectés, publication des produits (textes proposés par l'IA,
 * programmation) et ce que rapporte chaque réseau.
 */
class SocialController extends Controller
{
    public function index(Request $request, SocialStats $stats): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', Rule::in([7, 30, 90])]]);
        $accounts = SocialAccount::query()->get()->keyBy('network');

        return response()->json([
            'accounts' => collect(SocialAccount::NETWORKS)->map(fn ($label, $network) => [
                'network' => $network,
                'label' => $label,
                'connected' => $accounts->has($network),
                'name' => $accounts[$network]->name ?? null,
                'error' => $accounts[$network]->last_error ?? null,
            ])->values(),
            'available' => ['meta' => MetaSocial::enabled(), 'tiktok' => TikTokSocial::enabled()],
            'stats' => $stats->report($this->currentStore(), (int) ($data['days'] ?? 30)),
        ]);
    }

    /**
     * Adresse de la fenêtre de connexion (Facebook et Instagram ensemble, ou TikTok).
     */
    public function connect(Request $request, string $provider, SocialConnections $connections): JsonResponse
    {
        return response()->json(['url' => $connections->authorizeUrl($this->currentStore(), $request->user(), $provider)]);
    }

    public function pages(Request $request, string $key, SocialConnections $connections): JsonResponse
    {
        return response()->json(['data' => $connections->pendingPages($this->currentStore(), $request->user(), $key)]);
    }

    public function confirmTikTok(Request $request, string $key, SocialConnections $connections): JsonResponse
    {
        $account = $connections->confirmTikTok($this->currentStore(), $request->user(), $key);

        return response()->json(['data' => ['network' => $account->network, 'name' => $account->name]]);
    }

    public function choosePage(Request $request, string $key, SocialConnections $connections): JsonResponse
    {
        $data = $request->validate(['page_id' => ['required', 'string', 'max:64']]);
        $accounts = $connections->choosePage($this->currentStore(), $request->user(), $key, $data['page_id']);

        return response()->json(['data' => array_map(fn (SocialAccount $a) => ['network' => $a->network, 'name' => $a->name], $accounts)]);
    }

    public function disconnect(string $network): JsonResponse
    {
        SocialAccount::query()->where('network', $network)->delete();

        return response()->json(['message' => SocialAccount::NETWORKS[$network].' déconnecté.']);
    }

    /**
     * Trois textes proposés par l'assistant IA pour un produit.
     */
    public function captions(Request $request, int $product, CaptionWriter $writer): JsonResponse
    {
        $data = $request->validate(['tone' => ['nullable', Rule::in(['simple', 'nouchi'])]]);

        return response()->json(['data' => $writer->propose($this->currentStore(), Product::findOrFail($product), $request->user(), $data['tone'] ?? 'simple')]);
    }

    public function publish(Request $request, int $product, SocialPublisher $publisher): JsonResponse
    {
        $data = $request->validate([
            'caption' => ['required', 'string', 'max:2000'],
            'networks' => ['required', 'array', 'min:1'],
            'networks.*' => [Rule::in(array_keys(SocialAccount::NETWORKS))],
            'scheduled_at' => ['nullable', 'date'],
        ], [], ['caption' => 'texte', 'networks' => 'réseaux']);
        $store = $this->currentStore();
        $post = $publisher->create($store, $request->user(), Product::findOrFail($product), $data['caption'], $data['networks'],
            isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at'], $store->timezone) : null);

        return response()->json(['data' => $this->present($post->fresh())], 201);
    }

    /**
     * Republie sur les réseaux en échec.
     */
    public function retry(int $post, SocialPublisher $publisher): JsonResponse
    {
        return response()->json(['data' => $this->present($publisher->publish(SocialPost::findOrFail($post)))]);
    }

    public function cancel(int $post): JsonResponse
    {
        SocialPost::query()->where('status', 'scheduled')->findOrFail($post)->delete();

        return response()->json(['message' => 'Publication programmée annulée.']);
    }

    private function present(SocialPost $post): array
    {
        return [
            'id' => $post->id,
            'status' => $post->status,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'results' => $post->results ?? [],
        ];
    }
}
