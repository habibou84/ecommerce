<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\HelpFeedback;
use App\Services\Help\HelpCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aide dans l'application : les fiches par rubrique, la recherche, une fiche (vidéo, images, écran à ouvrir) et
 * l'avis du marchand.
 */
class HelpController extends Controller
{
    public function __construct(private readonly HelpCenter $help) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $q = trim((string) ($data['q'] ?? ''));

        return response()->json(['data' => [
            'categories' => HelpArticle::CATEGORIES,
            'articles' => $this->help->published()->map(fn (HelpArticle $a) => $this->help->present($a, false))->values(),
            'results' => $q === '' ? null : $this->help->search($q)->map(fn (HelpArticle $a) => $this->help->present($a, false))->values(),
        ]]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $article = HelpArticle::where('slug', $slug)->where('is_published', true)->firstOrFail();
        $article->increment('views');
        $vote = HelpFeedback::where('help_article_id', $article->id)->where('user_id', $request->user()->id)->value('helpful');

        return response()->json(['data' => $this->help->present($article) + ['my_vote' => $vote]]);
    }

    public function vote(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        $article = HelpArticle::where('slug', $slug)->where('is_published', true)->firstOrFail();
        $this->help->vote($article, $request->user(), $data['helpful']);

        return response()->json(['data' => ['my_vote' => $data['helpful']]]);
    }
}
