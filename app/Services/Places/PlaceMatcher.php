<?php

namespace App\Services\Places;

use App\Models\Place;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Retrouve les lieux cités dans un texte libre (« Yop », « Cocody Angré », « 2 plateaux vallon »…), par leur nom
 * ou une de leurs variantes d'écriture. Repris du ZoneMatcher de Delivery App.
 */
class PlaceMatcher
{
    /**
     * Lieux cités, du plus précis au moins précis : le quartier l'emporte sur sa commune, le nom long sur le court.
     *
     * @param  Collection<int, Place>  $places
     * @return Collection<int, Place>
     */
    public function inText(string $text, Collection $places): Collection
    {
        $haystack = ' '.self::normalize($text).' ';

        $positions = [];
        $found = $places->filter(function (Place $place) use ($haystack, &$positions) {
            foreach ($this->names($place) as $name) {
                if ($name !== '' && ($position = strpos($haystack, " {$name} ")) !== false) {
                    $positions[$place->id] = $position;

                    return true;
                }
            }

            return false;
        })
            // Quartier avant commune ; entre deux quartiers, le dernier cité (« 2 plateaux vallon » : Vallon)
            ->sortByDesc(fn (Place $p) => [$p->type === 'neighborhood' ? 1 : 0, $positions[$p->id], mb_strlen($p->name)])->values();

        // Un quartier trouvé rend sa commune (et sa ville) redondantes
        $ancestors = $found->flatMap(fn (Place $p) => array_filter([$p->parent_id, $p->parent?->parent_id]));

        return $found->reject(fn (Place $p) => $ancestors->contains($p->id))->values();
    }

    /**
     * Réponse courte (champ « quartier » d'un formulaire) : lieux cités, sinon début ou partie de nom.
     *
     * @param  Collection<int, Place>  $places
     * @return Collection<int, Place>
     */
    public function search(string $text, Collection $places, int $limit = 10): Collection
    {
        $found = $this->inText($text, $places);
        $needle = self::normalize($text);

        if (mb_strlen($needle) >= 2) {
            $partial = $places->filter(fn (Place $p) => collect($this->names($p))->contains(fn ($n) => str_contains($n, $needle)))
                ->sortBy(fn (Place $p) => [str_starts_with(self::normalize($p->name), $needle) ? 0 : 1, mb_strlen($p->name)]);
            $found = $found->concat($partial)->unique('id');
        }

        // Rien trouvé : une petite faute de frappe dans une réponse courte (« Yopugon », « Koumasi »)
        if ($found->isEmpty()) {
            $found = $this->nearest($text, $places);
        }

        return $found->take($limit)->values();
    }

    /**
     * Lieux dont le nom diffère d'une ou deux lettres d'un mot (ou de deux mots qui se suivent) d'une réponse courte :
     * 1 lettre pour un nom de 5 à 7 lettres, 2 à partir de 8 ; même première lettre ; jamais dans une question ou un
     * long message (on ne devine pas un lieu au milieu d'une phrase).
     *
     * @param  Collection<int, Place>  $places
     * @return Collection<int, Place>
     */
    public function nearest(string $text, Collection $places): Collection
    {
        $words = array_values(array_filter(explode(' ', self::normalize($text))));
        if ($words === [] || count($words) > 4 || str_contains($text, '?')) {
            return collect();
        }
        $candidates = $words;
        for ($i = 0; $i < count($words) - 1; $i++) {
            $candidates[] = $words[$i].' '.$words[$i + 1];
        }
        $scored = $places->map(function (Place $place) use ($candidates) {
            $best = null;
            foreach ($this->names($place) as $name) {
                $length = mb_strlen($name);
                if ($length < 5) {
                    continue;
                }
                $allowed = $length >= 8 ? 2 : 1;
                foreach ($candidates as $word) {
                    if ($word[0] !== $name[0] || abs(mb_strlen($word) - $length) > $allowed) {
                        continue;
                    }
                    $distance = levenshtein($word, $name);
                    if ($distance <= $allowed && ($best === null || $distance < $best)) {
                        $best = $distance;
                    }
                }
            }

            return $best === null ? null : ['place' => $place, 'distance' => $best];
        })->filter();

        // Le plus proche, et à égalité le quartier avant la commune
        return $scored->sortBy(fn (array $s) => [$s['distance'], $s['place']->type === 'neighborhood' ? 0 : 1, mb_strlen($s['place']->name)])
            ->pluck('place')->values();
    }

    /**
     * @return list<string>
     */
    private function names(Place $place): array
    {
        return array_map(self::normalize(...), array_merge([$place->name], $place->aliases ?? []));
    }

    public static function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
