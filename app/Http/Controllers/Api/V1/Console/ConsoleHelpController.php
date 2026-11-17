<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\HelpArticle;
use App\Models\HelpMiss;
use App\Services\Help\HelpCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Console › Centre d'aide : les fiches lues par les marchands (et par le support sur WhatsApp), leurs images et leur
 * vidéo, les avis reçus et les recherches restées sans réponse. Chaque changement est journalisé.
 */
class ConsoleHelpController extends Controller
{
    public function __construct(private readonly HelpCenter $help) {}

    public function index(Request $request): JsonResponse
    {
        $articles = HelpArticle::withCount([
            'feedback as helpful_count' => fn ($q) => $q->where('helpful', true),
            'feedback as unhelpful_count' => fn ($q) => $q->where('helpful', false),
        ])->orderBy('sort')->orderBy('id')->get();

        return response()->json(['data' => [
            'categories' => HelpArticle::CATEGORIES,
            'max_images' => HelpArticle::MAX_IMAGES,
            'articles' => $articles->map(fn (HelpArticle $a) => [
                'id' => $a->id,
                'video_url' => $a->video_url,
                'video_uploaded' => $a->video_path !== null,
                'poster_url' => $a->poster_url,
                'video_seconds' => $a->video_seconds,
                'keywords' => $a->keywords,
                'sort' => $a->sort,
                'is_published' => $a->is_published,
                'views' => $a->views,
                'helpful' => (int) $a->helpful_count,
                'unhelpful' => (int) $a->unhelpful_count,
                'updated_at' => $a->updated_at,
            ] + $this->help->present($a))->values(),
            'misses' => HelpMiss::orderByDesc('hits')->orderByDesc('last_at')->limit(50)->get(['id', 'query', 'hits', 'last_at']),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $slug = $base = Str::limit(Str::slug($data['title']), 70, '') ?: 'fiche';
        for ($i = 2; HelpArticle::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }
        $article = HelpArticle::create($data + ['slug' => $slug]);
        $this->log($request, 'help_article_added', ['id' => $article->id, 'title' => $article->title]);

        return $this->index($request);
    }

    public function update(Request $request, int $article): JsonResponse
    {
        $model = HelpArticle::findOrFail($article);
        $model->update($this->validated($request));
        $this->log($request, 'help_article_updated', ['id' => $model->id, 'title' => $model->title]);

        return $this->index($request);
    }

    public function destroy(Request $request, int $article): JsonResponse
    {
        $model = HelpArticle::findOrFail($article);
        Storage::disk(HelpArticle::disk())->delete(array_filter([...($model->images ?? []), $model->video_path]));
        $model->delete();
        $this->log($request, 'help_article_deleted', ['id' => $article, 'title' => $model->title]);

        return $this->index($request);
    }

    public function image(Request $request, int $article): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096']], [], ['image' => 'image']);
        $model = HelpArticle::findOrFail($article);
        abort_if(count($model->images ?? []) >= HelpArticle::MAX_IMAGES, 422, 'Une fiche porte au plus '.HelpArticle::MAX_IMAGES.' images.');
        $path = $request->file('image')->store('aide', ['disk' => HelpArticle::disk(), 'visibility' => 'public']);
        $model->forceFill(['images' => [...($model->images ?? []), $path]])->save();

        return $this->index($request);
    }

    public function removeImage(Request $request, int $article, int $index): JsonResponse
    {
        $model = HelpArticle::findOrFail($article);
        $images = $model->images ?? [];
        abort_unless(isset($images[$index]), 404);
        Storage::disk(HelpArticle::disk())->delete($images[$index]);
        unset($images[$index]);
        $model->forceFill(['images' => array_values($images)])->save();

        return $this->index($request);
    }

    public function video(Request $request, int $article): JsonResponse
    {
        $request->validate(['video' => ['required', 'file', 'mimetypes:video/mp4', 'max:30720']], ['video.max' => 'La vidéo ne doit pas dépasser 30 Mo.', 'video.mimetypes' => 'La vidéo doit être un fichier MP4.'], ['video' => 'vidéo']);
        $model = HelpArticle::findOrFail($article);
        if ($model->video_path) {
            Storage::disk(HelpArticle::disk())->delete($model->video_path);
        }
        $model->forceFill(['video_path' => $request->file('video')->store('aide', ['disk' => HelpArticle::disk(), 'visibility' => 'public'])])->save();
        $this->log($request, 'help_article_video', ['id' => $model->id, 'title' => $model->title]);

        return $this->index($request);
    }

    public function removeVideo(Request $request, int $article): JsonResponse
    {
        $model = HelpArticle::findOrFail($article);
        if ($model->video_path) {
            Storage::disk(HelpArticle::disk())->delete($model->video_path);
            $model->forceFill(['video_path' => null])->save();
        }

        return $this->index($request);
    }

    /**
     * Recherche sans réponse traitée (fiche écrite, ou question sans intérêt).
     */
    public function dismissMiss(Request $request, int $miss): JsonResponse
    {
        HelpMiss::whereKey($miss)->delete();

        return $this->index($request);
    }

    /**
     * Remet les fiches de départ qui manquent (une fiche supprimée par erreur).
     */
    public function defaults(Request $request): JsonResponse
    {
        $added = $this->help->installDefaults();
        $this->log($request, 'help_defaults_installed', ['added' => $added]);

        return $this->index($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(array_keys(HelpArticle::CATEGORIES))],
            'summary' => ['required', 'string', 'max:600'],
            'steps' => ['nullable', 'array', 'max:12'],
            'steps.*' => ['required', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:5000'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'video_url' => ['nullable', 'string', 'max:255', 'regex:#^(/[\w\-./?=&%]+|https://\S+)$#'],
            'poster_url' => ['nullable', 'string', 'max:255', 'regex:#^(/[\w\-./?=&%]+|https://\S+)$#'],
            'video_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'screen' => ['nullable', 'string', 'max:160', 'regex:#^/gestion(/[\w\-/]*)?(\?[\w\-=&]*)?$#'],
            'screen_label' => ['nullable', 'string', 'max:60'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_published' => ['required', 'boolean'],
        ], [
            'video_url.regex' => 'L\'adresse de la vidéo commence par / (fichier du site) ou par https://.',
            'poster_url.regex' => 'L\'adresse de l\'image de la vidéo commence par / ou par https://.',
            'screen.regex' => 'L\'écran à ouvrir est une adresse de la gestion, par exemple /gestion/catalogue.',
        ], ['title' => 'question', 'category' => 'rubrique', 'summary' => 'réponse courte', 'steps' => 'étapes', 'steps.*' => 'étape', 'body' => 'détails',
            'keywords' => 'mots-clés', 'video_url' => 'vidéo', 'screen' => 'écran à ouvrir', 'screen_label' => 'texte du bouton']);

        return $data + ['sort' => 0];
    }

    private function log(Request $request, string $action, array $data): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => $action, 'data' => $data, 'ip' => $request->ip()]);
    }
}
