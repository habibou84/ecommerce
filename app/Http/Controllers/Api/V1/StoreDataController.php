<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExportStoreData;
use App\Models\StoreExport;
use App\Services\Accounts\StoreClosure;
use App\Services\Accounts\StoreDataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Données de la boutique : export complet (archive CSV) et fermeture, réservés au propriétaire.
 */
class StoreDataController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureOwner($request);

        return response()->json(['data' => StoreExport::latest()->limit(5)->get()->map(fn (StoreExport $e) => [
            'id' => $e->id,
            'status' => $e->status,
            'size' => $e->size,
            'created_at' => $e->created_at->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
            'error' => $e->error,
        ])]);
    }

    public function export(Request $request): JsonResponse
    {
        $this->ensureOwner($request);
        abort_if(StoreExport::where('status', 'pending')->exists(), 422, 'Un export est déjà en préparation.');
        abort_if(StoreExport::where('created_at', '>=', now()->subDay())->count() >= 3, 422, 'Trois exports par jour au plus : réessayez demain.');

        $export = StoreExport::create(['store_id' => $this->currentStore()->id, 'user_id' => $request->user()->id]);
        ExportStoreData::dispatch($export->id)->afterCommit();

        return response()->json(['data' => ['id' => $export->id, 'status' => 'pending']], 202);
    }

    public function download(Request $request, int $export, StoreDataExport $exports): BinaryFileResponse
    {
        $this->ensureOwner($request);
        $model = StoreExport::where('status', 'ready')->where('expires_at', '>', now())->findOrFail($export);
        $path = $exports->absolutePath($model);
        abort_unless(is_file($path), 404);

        return response()->download($path, 'donnees-'.$this->currentStore()->slug.'-'.$model->created_at->format('Y-m-d').'.zip');
    }

    public function close(Request $request, StoreClosure $closure): JsonResponse
    {
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:120'], 'reason' => ['nullable', 'string', 'max:255']], [], ['confirmation' => 'nom de l\'espace marchand']);
        $store = $closure->close($this->currentStore(), $request->user(), $data['confirmation'], $data['reason'] ?? null);

        return response()->json(['data' => ['status' => $store->status->value, 'closed_at' => $store->closed_at->toIso8601String()]]);
    }

    private function ensureOwner(Request $request): void
    {
        abort_unless($this->currentStore()->owner_id === $request->user()->id, 403, 'Réservé au propriétaire de l\'espace marchand.');
    }
}
