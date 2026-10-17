<?php

namespace App\Http\Controllers\Api\V1\Couriers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierPayRule;
use App\Models\DeliveryZone;
use App\Services\Accounts\StoreRoles;
use App\Services\Couriers\CourierPayroll;
use App\Services\Couriers\CourierRoster;
use App\Services\Couriers\SharedCouriers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Livreurs de la boutique et règles de rémunération.
 */
class CourierController extends Controller
{
    public function __construct(private readonly CourierRoster $roster) {}

    public function index(CourierPayroll $payroll): JsonResponse
    {
        $couriers = Courier::with(['zones:id,name', 'payRule:id,name'])
            ->withCount(['shipments as active_missions' => fn ($q) => $q->whereIn('status', ['awaiting_pickup', 'in_transit', 'out_for_delivery', 'failed', 'rescheduled', 'returning'])])
            ->orderByRaw("status = 'inactive'")->orderBy('name')->get();

        return response()->json([
            'data' => $couriers->map(fn (Courier $c) => $this->present($c) + ['unpaid' => $payroll->unpaid($c), 'cash_held' => $c->cashHeld()]),
            'zones' => DeliveryZone::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'pay_rules' => CourierPayRule::orderBy('name')->get(),
            'vehicles' => Courier::VEHICLES,
        ]);
    }

    public function store(Request $request, SharedCouriers $shared, StoreRoles $roles): JsonResponse
    {
        $data = $this->validated($request);
        $store = $this->currentStore();

        // Autres boutiques de l'adresse pour lesquelles il travaillera aussi (celles où l'on gère les livreurs)
        $others = $store->addressStores()->whereIn('id', array_map('intval', $request->input('store_ids', [])))->where('id', '!=', $store->id)
            ->filter(fn ($s) => $s->owner_id === $request->user()->id || in_array(Permission::CouriersManage->value, $roles->permissionsOf($request->user(), $s), true))
            ->values();

        $extras = $others->map(fn ($s) => ['store_id' => $s->id, 'role' => Role::Courier->value])->all();
        [$courier, $link] = $this->roster->add($store, $request->user(), $data, $extras);

        // Déjà membre (compte actif) : partagé tout de suite ; sinon en acceptant l'invitation
        if ($courier->user_id !== null) {
            $others->each(fn ($s) => $shared->share($courier->setRelation('store', $store), $s));
        }

        return response()->json(['data' => $this->present($courier->load(['zones:id,name', 'payRule:id,name'])), 'invitation_url' => $link], 201);
    }

    public function update(Request $request, int $courier): JsonResponse
    {
        $model = Courier::findOrFail($courier);
        $data = $this->validated($request, partial: true);
        $this->roster->update($model, $data);

        return response()->json(['data' => $this->present($model->load(['zones:id,name', 'payRule:id,name']))]);
    }

    public function reinvite(Request $request, int $courier): JsonResponse
    {
        return response()->json(['invitation_url' => $this->roster->reinvite(Courier::findOrFail($courier), $request->user())]);
    }

    public function deactivate(int $courier): JsonResponse
    {
        $this->roster->deactivate(Courier::findOrFail($courier));

        return response()->json(['message' => 'Livreur désactivé.']);
    }

    public function storeRule(Request $request): JsonResponse
    {
        return response()->json(['data' => CourierPayRule::create($this->validatedRule($request) + ['store_id' => $this->currentStore()->id])], 201);
    }

    public function updateRule(Request $request, int $rule): JsonResponse
    {
        $model = CourierPayRule::findOrFail($rule);
        $model->update($this->validatedRule($request));

        return response()->json(['data' => $model]);
    }

    public function destroyRule(int $rule): JsonResponse
    {
        $model = CourierPayRule::findOrFail($rule);
        Courier::where('pay_rule_id', $model->id)->update(['pay_rule_id' => null]);
        $model->delete();

        return response()->json(['message' => 'Règle supprimée.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $store = $this->currentStore();
        if ($request->has('phone') && is_string($request->input('phone')) && PhoneNumber::isValid($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }

        return $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'phone' => [$partial ? 'prohibited' : 'required', new PhoneRule],
            'vehicle_type' => ['sometimes', Rule::in(array_keys(Courier::VEHICLES))],
            'pay_rule_id' => ['sometimes', 'nullable', 'integer', Rule::exists('courier_pay_rules', 'id')->where('store_id', $store->id)],
            'cash_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'is_available' => ['sometimes', 'boolean'],
            'zone_ids' => ['sometimes', 'array'],
            'zone_ids.*' => ['integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['integer'],
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'cash_limit' => 'plafond']);
    }

    private function validatedRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['per_delivery', 'per_zone'])],
            'amount' => ['required', 'integer', 'min:0', 'max:10000000'],
            'zone_amounts' => ['nullable', 'array'],
            'zone_amounts.*' => ['integer', 'min:0', 'max:10000000'],
            'failed_attempt_amount' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ], [], ['name' => 'nom', 'amount' => 'montant']) + ['failed_attempt_amount' => 0];
    }

    private function present(Courier $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'phone_display' => PhoneNumber::display($c->phone),
            'vehicle_type' => $c->vehicle_type,
            'status' => $c->status,
            'is_available' => $c->is_available,
            'cash_limit' => $c->cash_limit,
            'pay_rule' => $c->payRule ? ['id' => $c->payRule->id, 'name' => $c->payRule->name] : null,
            'zones' => $c->zones->map(fn ($z) => ['id' => $z->id, 'name' => $z->name])->values(),
            'active_missions' => $c->active_missions ?? 0,
            'note' => $c->note,
        ];
    }
}
