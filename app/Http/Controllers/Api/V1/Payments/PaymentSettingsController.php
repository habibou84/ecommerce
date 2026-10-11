<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Models\PaymentRoute;
use App\Services\Billing\PlanLimits;
use App\Services\Payments\FeeSimulator;
use App\Services\Payments\PaymentDesk;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestion › Paiements : comptes du marchand chez les prestataires, routage par moyen de paiement (avec secours),
 * paiement en ligne sur la boutique, simulateur et rapport des frais.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(private readonly PaymentDesk $payments) {}

    public function show(): JsonResponse
    {
        $store = $this->currentStore();
        $routes = PaymentRoute::orderBy('priority')->get()->groupBy('method')->map(fn ($r) => $r->pluck('payment_account_id')->values());

        return response()->json(['data' => [
            'online_payment' => $store->online_payment,
            'deposit_percent' => $store->deposit_percent,
            'accounts' => PaymentAccount::orderBy('id')->get()->map(fn ($a) => $this->presentAccount($a)),
            'routes' => (object) $routes->all(),
            'methods' => PaymentDesk::METHODS,
            'available_methods' => array_keys($this->payments->availableMethods($store)),
            'providers' => collect(PaymentAccount::PROVIDERS)->map(fn ($name, $code) => [
                'code' => $code,
                'name' => $name,
                'fields' => $this->payments->gateway($code)->credentialFields(),
                'methods' => $this->payments->gateway($code)->methods(),
            ])->values(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'online_payment' => ['required', Rule::in(['off', 'optional', 'deposit', 'required'])],
            'deposit_percent' => ['nullable', 'integer', 'between:5,90', 'required_if:online_payment,deposit'],
        ], [], ['deposit_percent' => 'acompte']);
        $this->currentStore()->forceFill($data)->save();

        return $this->show();
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $data = $this->validatedAccount($request);
        app(PlanLimits::class)->ensureCanAdd($this->currentStore(), 'payment_accounts');
        $account = PaymentAccount::create($data + ['store_id' => $this->currentStore()->id, 'status' => 'active']);

        // Premier compte : il devient le prestataire de tous les moyens qu'il couvre
        foreach ($this->payments->gateway($account->provider)->methods() as $method) {
            if (! PaymentRoute::where('method', $method)->exists()) {
                PaymentRoute::create(['store_id' => $account->store_id, 'method' => $method, 'payment_account_id' => $account->id, 'priority' => 1]);
            }
        }

        return response()->json(['data' => $this->presentAccount($account)], 201);
    }

    public function updateAccount(Request $request, int $account): JsonResponse
    {
        $model = PaymentAccount::findOrFail($account);
        $data = $this->validatedAccount($request, $model);
        if (isset($data['credentials'])) {
            // Champs laissés vides : la valeur enregistrée est gardée
            $data['credentials'] = array_merge($model->credentials ?? [], array_filter($data['credentials'], fn ($v) => filled($v)));
        }
        $model->update($data);

        return response()->json(['data' => $this->presentAccount($model)]);
    }

    public function destroyAccount(int $account): JsonResponse
    {
        PaymentAccount::findOrFail($account)->delete();

        return response()->json(['message' => 'Compte supprimé.']);
    }

    /**
     * Routage : pour chaque moyen, la liste ordonnée des comptes (le premier, puis les secours).
     */
    public function routes(Request $request): JsonResponse
    {
        $data = $request->validate(['routes' => ['present', 'array'], 'routes.*' => ['array'], 'routes.*.*' => ['integer']]);
        $store = $this->currentStore();
        $accounts = PaymentAccount::get()->keyBy('id');

        DB::transaction(function () use ($data, $store, $accounts) {
            PaymentRoute::query()->delete();
            foreach ($data['routes'] as $method => $ids) {
                abort_unless(array_key_exists($method, PaymentDesk::METHODS), 422, 'Moyen de paiement inconnu.');
                foreach (array_values(array_unique($ids)) as $i => $id) {
                    $account = $accounts->get($id);
                    abort_unless($account && in_array($method, $this->payments->gateway($account->provider)->methods(), true), 422,
                        'Ce prestataire ne propose pas '.PaymentDesk::METHODS[$method].'.');
                    PaymentRoute::create(['store_id' => $store->id, 'method' => $method, 'payment_account_id' => $id, 'priority' => $i + 1]);
                }
            }
        });

        return $this->show();
    }

    public function simulator(FeeSimulator $simulator): JsonResponse
    {
        return response()->json(['data' => $simulator->simulate($this->currentStore())]);
    }

    public function report(FeeSimulator $simulator): JsonResponse
    {
        return response()->json(['data' => $simulator->report($this->currentStore())]);
    }

    private function validatedAccount(Request $request, ?PaymentAccount $account = null): array
    {
        $provider = $account?->provider ?? $request->input('provider');
        $fields = array_key_exists((string) $provider, PaymentAccount::PROVIDERS) ? array_keys($this->payments->gateway($provider)->credentialFields()) : [];
        $required = $account ? 'nullable' : 'required';

        return $request->validate([
            'provider' => [$account ? 'prohibited' : 'required', Rule::in(array_keys(PaymentAccount::PROVIDERS))],
            'label' => ['nullable', 'string', 'max:60'],
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'credentials' => [$account ? 'sometimes' : 'required', 'array'],
            ...collect($fields)->mapWithKeys(fn ($f) => ["credentials.{$f}" => [$required, 'string', 'max:255']])->all(),
            'fee_percent' => ['nullable', 'numeric', 'between:0,20'],
            'fee_fixed' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ], [], ['fee_percent' => 'taux de frais']);
    }

    private function presentAccount(PaymentAccount $a): array
    {
        $fields = $this->payments->gateway($a->provider)->credentialFields();

        return [
            'id' => $a->id,
            'provider' => $a->provider,
            'name' => $a->name(),
            'label' => $a->label,
            'mode' => $a->mode,
            'status' => $a->status,
            'fee_percent' => $a->fee_percent,
            'fee_fixed' => $a->fee_fixed,
            'last_error' => $a->last_error,
            'methods' => $this->payments->gateway($a->provider)->methods(),
            // Identifiants jamais renvoyés : seulement les 4 derniers caractères
            'credentials_hint' => collect($fields)->mapWithKeys(fn ($label, $key) => [$key => ($v = $a->credential($key)) ? '…'.substr($v, -4) : null]),
            'webhook_url' => Tenancy::apiUrl('/webhooks/payments/'.$a->webhook_uuid),
        ];
    }
}
