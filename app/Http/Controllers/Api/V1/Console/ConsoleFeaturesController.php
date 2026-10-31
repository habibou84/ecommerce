<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Store;
use App\Services\Platform\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console › Fonctions : chaque fonction masquée, en test (boutiques choisies) ou active ; chaque changement est noté
 * dans le journal.
 */
class ConsoleFeaturesController extends Controller
{
    public function __construct(private readonly Features $features) {}

    public function index(): JsonResponse
    {
        $tests = $this->features->testStores();

        return response()->json(['data' => [
            'features' => collect(Features::FEATURES)->map(fn ($f, $key) => [
                'key' => $key, 'group' => $f['group'], 'label' => $f['label'], 'description' => $f['description'], 'state' => $this->features->state($key),
            ])->values(),
            'test_stores' => Store::whereIn('id', $tests)->orderBy('name')->get(['id', 'name', 'slug'])->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug]),
        ]]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(Features::FEATURES[$key]), 404);
        $state = $request->validate(['state' => ['required', Rule::in(Features::STATES)]])['state'];
        $before = $this->features->state($key);
        $this->features->set($key, $state);
        $this->log($request, 'feature.'.$key, ['from' => $before, 'to' => $state]);

        return $this->index();
    }

    public function testStores(Request $request): JsonResponse
    {
        $ids = $request->validate(['store_ids' => ['present', 'array', 'max:50'], 'store_ids.*' => ['integer']])['store_ids'];
        $this->features->setTestStores($ids);
        $this->log($request, 'feature.test_stores', ['stores' => $this->features->testStores()]);

        return $this->index();
    }

    private function log(Request $request, string $action, array $data): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => $action, 'data' => $data, 'ip' => $request->ip()]);
    }
}
