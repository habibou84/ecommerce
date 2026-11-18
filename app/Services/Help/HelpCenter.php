<?php

namespace App\Services\Help;

use App\Models\HelpArticle;
use App\Models\HelpFeedback;
use App\Models\HelpMiss;
use App\Models\User;
use App\Support\HelpDefaults;
use App\Support\StartSteps;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Centre d'aide : fiches publiées, recherche par les mots du marchand (sans accents, pluriels ignorés), avis
 * « utile / pas utile » et recherches restées sans réponse (les fiches à écrire). La recherche sert aussi au
 * support sur WhatsApp : une réponse vient d'une fiche, jamais d'une invention.
 */
class HelpCenter
{
    /** Mots trop courants pour chercher */
    private const STOP = ['comment', 'faire', 'pour', 'avec', 'dans', 'mon', 'mes', 'une', 'des', 'les', 'que', 'qui', 'est', 'sur', 'pas',
        'veux', 'peux', 'peut', 'quoi', 'svp', 'bonjour', 'bonsoir', 'merci', 'aide', 'besoin', 'vous', 'nous', 'par', 'aux', 'ton', 'tes', 'son', 'ses',
        'ou', 'et', 'de', 'du', 'la', 'le', 'un', 'je', 'tu', 'il', 'ma', 'ta', 'ca', 'ce', 'en', 'au', 'ne', 'se', 'si', 'on', 'ai', 'as', 'a', 'y', 'j', 'l', 'd', 'c', 'n', 'm', 's', 'qu'];

    /**
     * Fiches de départ manquantes (une fiche déjà là, même modifiée ou dépubliée, n'est jamais touchée).
     */
    public function installDefaults(): int
    {
        $known = HelpArticle::pluck('slug')->all();
        $added = 0;
        foreach (HelpDefaults::all() as $row) {
            if (! in_array($row['slug'], $known, true)) {
                HelpArticle::create($row);
                $added++;
            }
        }

        return $added;
    }

    /**
     * Fiches publiées, dans l'ordre des rubriques.
     *
     * @return Collection<int, HelpArticle>
     */
    public function published(): Collection
    {
        $order = array_flip(array_keys(HelpArticle::CATEGORIES));

        return HelpArticle::where('is_published', true)->orderBy('sort')->orderBy('id')->get()
            ->sortBy(fn (HelpArticle $a) => $order[$a->category] ?? 99)->values();
    }

    /**
     * Fiches qui répondent le mieux à la question, la meilleure d'abord. Une question sans réponse est notée.
     *
     * @return Collection<int, HelpArticle>
     */
    public function search(string $question, int $limit = 6, bool $record = true): Collection
    {
        $words = self::words($question);
        if ($words === []) {
            return collect();
        }
        $found = $this->published()->map(function (HelpArticle $a) use ($words) {
            $fields = [
                [self::plain($a->title), 5], [self::plain((string) $a->keywords), 4], [self::plain($a->summary), 2],
                [self::plain(implode(' ', $a->steps ?? []).' '.$a->body), 1],
            ];
            $score = 0;
            $matched = 0;
            foreach ($words as $word) {
                $best = 0;
                foreach ($fields as [$text, $weight]) {
                    if (str_contains($text, $word)) {
                        $best = max($best, $weight);
                    }
                }
                $score += $best;
                $matched += $best > 0 ? 1 : 0;
            }

            // La moitié des mots au moins doit se retrouver dans la fiche
            return ['article' => $a, 'score' => $matched * 2 >= count($words) ? $score : 0];
        })->filter(fn (array $r) => $r['score'] > 0)->sortByDesc('score')->take($limit)->pluck('article')->values();

        if ($record && $found->isEmpty()) {
            $this->miss($question);
        }

        return $found;
    }

    /**
     * Avis d'un marchand sur une fiche (il peut changer d'avis).
     */
    public function vote(HelpArticle $article, User $user, bool $helpful): void
    {
        HelpFeedback::updateOrCreate(['help_article_id' => $article->id, 'user_id' => $user->id], ['helpful' => $helpful]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(HelpArticle $a, bool $full = true): array
    {
        $base = [
            'slug' => $a->slug,
            'category' => $a->category,
            'title' => $a->title,
            'summary' => $a->summary,
            'has_video' => $a->videoUrl() !== null,
        ];
        if (! $full) {
            return $base;
        }
        // Vidéo d'une étape essentielle : toujours sa dernière version (adresse, image et durée à jour)
        $step = $a->video_path === null && $a->video_url !== null
            ? collect(StartSteps::all())->first(fn (array $s) => strtok($s['video'], '?') === strtok($a->video_url, '?'))
            : null;

        return $base + [
            'steps' => $a->steps ?? [],
            'body' => $a->body,
            'video' => $a->videoUrl() === null ? null : [
                'url' => $step['video'] ?? $a->videoUrl(),
                'poster' => $step['poster'] ?? $a->poster_url,
                'seconds' => $step['seconds'] ?? $a->video_seconds,
                // Fichier lu dans la page ; un lien (YouTube, TikTok…) s'ouvre à côté
                'file' => (bool) preg_match('/\.(mp4|webm)(\?.*)?$/i', $a->videoUrl()),
            ],
            'images' => $a->imageUrls(),
            'screen' => $a->screen,
            'screen_label' => $a->screen_label ?: 'Ouvrir l\'écran',
        ];
    }

    private function miss(string $question): void
    {
        $query = Str::limit(trim(preg_replace('/\s+/', ' ', mb_strtolower($question))), 117, '');
        if (mb_strlen($query) < 3) {
            return;
        }
        $miss = HelpMiss::firstOrNew(['query' => $query]);
        $miss->fill(['hits' => $miss->exists ? $miss->hits + 1 : 1, 'last_at' => now()])->save();
    }

    /**
     * Mots utiles d'une question : sans accents, sans mots courants, sans la marque du pluriel.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        return collect(explode(' ', self::plain($text)))
            ->reject(fn (string $w) => $w === '' || in_array($w, self::STOP, true) || mb_strlen($w) < 2)
            ->map(fn (string $w) => mb_strlen($w) > 3 ? rtrim($w, 'sx') : $w)
            ->unique()->values()->all();
    }

    private static function plain(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
    }
}
