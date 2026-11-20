<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\PlaceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Quartiers manquants signalés par les boutiques : la plateforme les ajoute à la liste commune (habibou84/socle), qui
 * arrive ensuite dans toutes les boutiques et chez les entreprises de Jibiat, puis marque le signalement.
 */
class ConsolePlaceReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reports = PlaceReport::query()->with(['store:id,name', 'parent.parent'])
            ->when(! $request->boolean('all'), fn ($q) => $q->where('status', 'pending'))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $reports->map(fn (PlaceReport $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'parent' => $r->parent?->label(),
            'status' => $r->status,
            'store' => $r->store?->name,
            'created_at' => $r->created_at,
        ])]);
    }

    public function update(Request $request, PlaceReport $placeReport): JsonResponse
    {
        $placeReport->update($request->validate(['status' => ['required', Rule::in(PlaceReport::STATUSES)]]));

        return response()->json(['data' => ['id' => $placeReport->id, 'status' => $placeReport->status]]);
    }
}
