<?php

namespace App\Http\Controllers\Api\V1\Couriers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierPayRule;
use App\Models\CourierProfile;
use App\Models\DeliveryZone;
use App\Models\Store;
use App\Services\Accounts\StoreRoles;
use App\Services\Couriers\CourierAccess;
use App\Services\Couriers\CourierNetwork;
use App\Services\Couriers\CourierPayroll;
use App\Services\Couriers\CourierRoster;
use App\Services\Couriers\SharedCouriers;
use App\Support\Files;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Livreurs de la boutique et règles de rémunération.
 */
class CourierController extends Controller
{
    public function __construct(private readonly CourierRoster $roster) {}

    public function index(CourierPayroll $payroll): JsonResponse
    {
        $couriers = Courier::with(['places.parent', 'payRule:id,name'])
            ->withCount(['shipments as active_missions' => fn ($q) => $q->whereIn('status', ['awaiting_pickup', 'in_transit', 'out_for_delivery', 'failed', 'rescheduled', 'returning'])])
            ->orderByRaw("status IN ('inactive', 'declined')")->orderBy('name')->get();

        return response()->json([
            'data' => $couriers->map(fn (Courier $c) => $this->present($c) + ['unpaid' => $payroll->unpaid($c), 'cash_held' => $c->cashHeld()]),
            // Zones de prix : montants des règles de paie « selon la zone »
            'zones' => DeliveryZone::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'pay_rules' => CourierPayRule::orderBy('name')->get(),
            'vehicles' => Courier::VEHICLES,
            'settings' => $this->settingsOf($this->currentStore()),
        ]);
    }

    /**
     * Réglages des livreurs : code du client à la livraison, position pendant la tournée.
     */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['require_delivery_code' => ['sometimes', 'boolean'], 'courier_tracking' => ['sometimes', 'boolean'], 'couriers_require_id' => ['sometimes', 'boolean']]);
        $store = $this->currentStore();
        $store->forceFill($data)->save();
        // Position désactivée : on n'en garde aucune
        if (! $store->courier_tracking) {
            Courier::where('store_id', $store->id)->update(['last_latitude' => null, 'last_longitude' => null, 'last_located_at' => null]);
        }

        return response()->json(['data' => $this->settingsOf($store)]);
    }

    private function settingsOf(Store $store): array
    {
        return ['require_delivery_code' => (bool) $store->require_delivery_code, 'courier_tracking' => (bool) $store->courier_tracking,
            // Pièce d'identité exigée : un livreur inscrit ne peut accepter la demande qu'après l'avoir ajoutée
            'couriers_require_id' => (bool) $store->couriers_require_id];
    }

    public function store(Request $request, SharedCouriers $shared, StoreRoles $roles): JsonResponse
    {
        $data = $this->validated($request);
        $store = $this->currentStore();

        // Autres boutiques de l'adresse pour lesquelles il travaillera aussi (celles où l'on gère les livreurs)
        $others = $store->addressStores()->whereIn('id', array_map('intval', $request->input('store_ids', [])))->where('id', '!=', $store->id)
            ->filter(fn ($s) => $roles->can($request->user(), $s, Permission::CouriersManage))
            ->values();

        $extras = $others->map(fn ($s) => ['store_id' => $s->id, 'role' => Role::Courier->value])->all();
        [$courier, $link] = $this->roster->add($store, $request->user(), $data, $extras);

        // Déjà membre (compte actif) : partagé tout de suite ; sinon en acceptant l'invitation
        if ($courier->user_id !== null) {
            $others->each(fn ($s) => $shared->share($courier->setRelation('store', $store), $s));
        }

        return response()->json(['data' => $this->present($courier->load(['places.parent', 'payRule:id,name'])), 'invitation_url' => $link,
            // Livreur déjà inscrit : il doit accepter la demande dans son application
            'requested' => $courier->status === 'requested'], 201);
    }

    /**
     * Annuaire des livreurs inscrits (lien commun) qui livrent dans un lieu, pas encore reliés à la boutique.
     */
    public function directory(Request $request, CourierNetwork $network): JsonResponse
    {
        $placeId = $request->validate(['place_id' => ['nullable', 'integer']])['place_id'] ?? null;

        return response()->json(['data' => $network->directory($this->currentStore(), $placeId)->map(fn (CourierProfile $p) => [
            'id' => $p->id,
            'name' => $p->shortName(),
            'vehicle' => Courier::VEHICLES[$p->vehicle_type] ?? $p->vehicle_type,
            'photo_url' => $p->photo_path ? "/api/v1/courier-profiles/{$p->id}/photo" : null,
            'has_id_document' => $p->hasIdDocument(),
            'places' => $p->places->map(fn ($pl) => $pl->name)->values(),
            'since' => $p->created_at->toDateString(),
        ])->values(), 'require_id' => (bool) $this->currentStore()->couriers_require_id]);
    }

    /** Demande à un livreur de l'annuaire */
    public function requestFromDirectory(Request $request, int $profile, CourierNetwork $network): JsonResponse
    {
        $store = $this->currentStore();
        $data = $request->validate([
            'pay_rule_id' => ['nullable', 'integer', Rule::exists('courier_pay_rules', 'id')->where('store_id', $store->id)],
            'cash_limit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $model = CourierProfile::where('listed', true)->with('user')->findOrFail($profile);
        $courier = $network->request($store, $request->user(), $model->user, $data);

        return response()->json(['data' => $this->present($courier->load(['places.parent', 'payRule:id,name'])), 'requested' => true], 201);
    }

    /** Demande pas encore acceptée (ou refusée) : retirée */
    public function cancelRequest(int $courier, CourierNetwork $network): JsonResponse
    {
        $network->cancel(Courier::findOrFail($courier));

        return response()->json(['message' => 'Demande retirée.']);
    }

    /** Pièce d'identité d'un livreur qui livre pour la boutique */
    public function idDocument(int $courier): StreamedResponse
    {
        $model = Courier::where('status', 'active')->findOrFail($courier);
        $profile = CourierProfile::where('user_id', $model->user_id)->whereNotNull('id_document_path')->firstOrFail();

        return Files::private()->response($profile->id_document_path, null, ['Cache-Control' => 'private, no-store']);
    }

    /** Photo d'un livreur : de l'annuaire, ou relié à la boutique */
    public function profilePhoto(int $profile): StreamedResponse
    {
        $model = CourierProfile::whereNotNull('photo_path')->findOrFail($profile);
        abort_unless($model->listed || Courier::where('user_id', $model->user_id)->exists(), 404);

        return Files::private()->response($model->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function update(Request $request, int $courier): JsonResponse
    {
        $model = Courier::findOrFail($courier);
        $data = $this->validated($request, partial: true);
        $this->roster->update($model, $data);

        return response()->json(['data' => $this->present($model->load(['places.parent', 'payRule:id,name']))]);
    }

    public function reinvite(Request $request, int $courier): JsonResponse
    {
        return response()->json(['invitation_url' => $this->roster->reinvite(Courier::findOrFail($courier), $request->user())]);
    }

    /**
     * Message WhatsApp prérempli avec ses courses du jour et un lien d'accès (sans mot de passe, un seul usage).
     */
    public function accessLink(Request $request, int $courier, CourierAccess $access): JsonResponse
    {
        return response()->json(['data' => $access->message(Courier::findOrFail($courier), $request->user())]);
    }

    /** Téléphone perdu, livreur parti : liens annulés et téléphones déconnectés */
    public function cutAccess(int $courier, CourierAccess $access): JsonResponse
    {
        $access->cut(Courier::findOrFail($courier));

        return response()->json(['message' => 'Accès coupé : le livreur devra recevoir un nouveau lien pour se reconnecter.']);
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
            'place_ids' => ['sometimes', 'array', 'max:100'],
            'place_ids.*' => ['integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['integer'],
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'cash_limit' => 'plafond']);
    }

    private function validatedRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(CourierPayRule::TYPES)],
            'amount' => ['required', 'integer', 'min:0', 'max:10000000'],
            'zone_amounts' => ['nullable', 'array'],
            'zone_amounts.*' => ['integer', 'min:0', 'max:10000000'],
            'expedition_amount' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'failed_attempt_amount' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ], [], ['name' => 'nom', 'amount' => 'montant']) + ['expedition_amount' => null, 'failed_attempt_amount' => 0];
    }

    private function present(Courier $c): array
    {
        $profile = $c->user_id ? CourierProfile::where('user_id', $c->user_id)->first() : null;
        $waiting = in_array($c->status, ['requested', 'declined'], true);

        return [
            // Livreur inscrit au lien commun : photo, pièce d'identité fournie ou non, date de la demande
            'profile' => $profile ? [
                'photo_url' => $profile->photo_path ? "/api/v1/courier-profiles/{$profile->id}/photo" : null,
                'has_id_document' => $profile->hasIdDocument(),
                'id_document_url' => $profile->hasIdDocument() && $c->status === 'active' ? "/api/v1/couriers/{$c->id}/id-document" : null,
            ] : null,
            'requested_at' => $c->requested_at?->toIso8601String(),
            'answered_at' => $c->answered_at?->toIso8601String(),
            'id' => $c->id,
            'name' => $c->name,
            // Numéro visible une fois la demande acceptée
            'phone' => $waiting ? null : $c->phone,
            'phone_display' => $waiting ? null : PhoneNumber::display($c->phone),
            'vehicle_type' => $c->vehicle_type,
            'status' => $c->status,
            'is_available' => $c->is_available,
            'cash_limit' => $c->cash_limit,
            'pay_rule' => $c->payRule ? ['id' => $c->payRule->id, 'name' => $c->payRule->name] : null,
            // Lieux couverts (aucun : partout)
            'places' => $c->places->map(fn ($p) => ['id' => $p->id, 'label' => $p->label()])->values(),
            'active_missions' => $c->active_missions ?? 0,
            // Dernière position pendant la tournée (si la boutique l'a activé), de moins de douze heures
            'location' => $c->last_located_at?->gt(now()->subHours(12)) && $this->currentStore()->courier_tracking
                ? ['latitude' => $c->last_latitude, 'longitude' => $c->last_longitude, 'at' => $c->last_located_at->toIso8601String()] : null,
            'note' => $c->note,
        ];
    }
}
