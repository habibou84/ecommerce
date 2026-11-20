<?php

namespace App\Services\Places;

use App\Models\Place;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Position (centre) des lieux du référentiel, cherchée dans OpenStreetMap (Nominatim). Sert à placer sur la carte du
 * livreur les adresses sans point GPS. Une recherche par seconde au plus, comme le demande OpenStreetMap ; un lieu
 * introuvable n'est recherché de nouveau qu'au bout d'un mois.
 */
class PlaceLocator
{
    /** Côte d'Ivoire : les résultats hors de ce cadre sont écartés */
    private const BOUNDS = ['south' => 4.2, 'north' => 10.8, 'west' => -8.7, 'east' => -2.4];

    /**
     * @return array{located: int, missed: int}
     */
    public function locateMissing(int $limit = 50, int $pauseMs = 1100): array
    {
        $places = Place::whereNull('latitude')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('located_at')->orWhere('located_at', '<', now()->subMonth()))
            ->with('parent.parent')->orderByRaw("CASE type WHEN 'city' THEN 0 WHEN 'commune' THEN 1 ELSE 2 END")->orderBy('id')
            ->limit($limit)->get();
        $located = 0;
        foreach ($places as $i => $place) {
            if ($i > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $point = $this->search($place);
            $place->forceFill(['located_at' => now()] + ($point ? ['latitude' => $point[0], 'longitude' => $point[1]] : []))->save();
            $located += $point ? 1 : 0;
        }

        return ['located' => $located, 'missed' => $places->count() - $located];
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    public function search(Place $place): ?array
    {
        // « Angré, Cocody, Abidjan, Côte d'Ivoire »
        $names = collect([$place, $place->parent, $place->parent?->parent])->filter()->pluck('name')->push('Côte d\'Ivoire')->unique();
        try {
            $response = Http::timeout(15)->withUserAgent((string) config('services.maps.user_agent'))->acceptJson()
                ->get((string) config('services.maps.geocoder_url'), [
                    'q' => $names->implode(', '), 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'ci', 'accept-language' => 'fr',
                ]);
        } catch (Throwable) {
            return null;
        }
        $hit = $response->successful() ? ($response->json()[0] ?? null) : null;
        if (! is_array($hit) || ! isset($hit['lat'], $hit['lon'])) {
            return null;
        }
        [$lat, $lng] = [(float) $hit['lat'], (float) $hit['lon']];
        $inside = $lat >= self::BOUNDS['south'] && $lat <= self::BOUNDS['north'] && $lng >= self::BOUNDS['west'] && $lng <= self::BOUNDS['east'];

        return $inside ? [round($lat, 6), round($lng, 6)] : null;
    }

    /**
     * Point d'un lieu pour la carte : le sien, sinon celui du lieu parent (quartier inconnu → centre de la commune).
     *
     * @return array{0: float, 1: float}|null
     */
    public static function pointOf(?Place $place): ?array
    {
        for ($p = $place, $depth = 0; $p !== null && $depth < 3; $p = $p->parent, $depth++) {
            if ($p->latitude !== null && $p->longitude !== null) {
                return [(float) $p->latitude, (float) $p->longitude];
            }
        }

        return null;
    }
}
