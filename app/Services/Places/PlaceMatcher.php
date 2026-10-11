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

        return $found->take($limit)->values();
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
