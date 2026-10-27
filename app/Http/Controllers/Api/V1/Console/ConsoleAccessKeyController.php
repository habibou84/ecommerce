<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\AccessKeyBatch;
use App\Models\AdminAction;
use App\Models\Plan;
use App\Services\Billing\AccessKeys;
use App\Services\Billing\PremiumCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console › Clés d'accès : lots de cartes à QR code (Membre Premium à vie ou durée), suivi, cartes à imprimer,
 * annulation d'une clé, formule des cartes Membre Premium offertes par les entreprises de livraison. Chaque
 * changement est journalisé.
 */
class ConsoleAccessKeyController extends Controller
{
    public function __construct(private readonly AccessKeys $keys, private readonly PremiumCards $premium) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'kinds' => AccessKeyBatch::KINDS,
            'plans' => Plan::where('code', '!=', 'free')->orderBy('sort')->get(['code', 'name']),
            'idle_days' => AccessKeys::IDLE_DAYS,
            // Formule des cartes offertes par les entreprises de Jibiat
            'premium' => $this->premium->settings(),
            'batches' => AccessKeyBatch::with('plan')->latest('id')->get()->map(fn (AccessKeyBatch $b) => $this->keys->presentBatch($b))->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(AccessKeyBatch::KINDS))],
            'plan' => ['required', Rule::exists('plans', 'code')->whereNot('code', 'free')],
            'months' => ['nullable', 'required_if:kind,timed', 'integer', 'min:1', 'max:36'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'distributor_name' => ['nullable', 'string', 'max:120'],
            'distributor_phone' => ['nullable', new PhoneRule],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['kind' => 'type de clé', 'plan' => 'offre', 'months' => 'durée', 'quantity' => 'nombre de clés', 'distributor_phone' => 'téléphone du distributeur']);
        $data['distributor_phone'] = filled($data['distributor_phone'] ?? null) ? PhoneNumber::normalize($data['distributor_phone']) : null;
        $batch = $this->keys->createBatch($data, $request->user());
        $this->log($request, 'access_keys_created', ['batch' => $batch->id, 'kind' => $batch->kind, 'plan' => $data['plan'], 'quantity' => $batch->quantity]);

        return response()->json(['data' => $this->keys->presentBatch($batch->load('plan'), true)], 201);
    }

    public function show(int $batch): JsonResponse
    {
        return response()->json(['data' => $this->keys->presentBatch(AccessKeyBatch::with('plan')->findOrFail($batch), true)]);
    }

    public function cards(Request $request, int $batch): Response
    {
        $model = AccessKeyBatch::with('plan')->findOrFail($batch);
        $this->log($request, 'access_keys_printed', ['batch' => $model->id]);

        return $this->keys->cards($model);
    }

    public function revoke(Request $request, int $key): JsonResponse
    {
        $model = AccessKey::with('batch.plan')->findOrFail($key);
        if ($model->revoked_at === null) {
            $this->keys->revoke($model);
            $this->log($request, 'access_key_revoked', ['key' => $model->id, 'batch' => $model->batch_id, 'number' => $model->number], $model->store_id);
        }

        return $this->show($model->batch_id);
    }

    public function premium(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'code')->whereNot('code', 'free')],
            'free_months' => ['required', 'integer', 'min:0', 'max:36'],
            'discount_rate' => ['required', 'numeric', 'min:0', 'max:0.9'],
            'active_days' => ['required', 'integer', 'min:7', 'max:365'],
        ], [], ['plan' => 'offre', 'free_months' => 'mois offerts', 'discount_rate' => 'réduction', 'active_days' => 'jours sans livraison']);
        $this->premium->updateSettings($data);
        $this->log($request, 'premium_cards_settings', $data);

        return $this->index();
    }

    private function log(Request $request, string $action, array $data, ?int $storeId = null): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => $storeId, 'action' => $action, 'data' => $data, 'ip' => $request->ip()]);
    }
}
