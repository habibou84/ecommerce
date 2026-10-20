<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\CarrierPayout;
use App\Models\CarrierZone;
use App\Models\CarrierZoneMapping;
use App\Services\Places\Places;
use App\Services\Shipping\CarrierConnections;
use App\Services\Shipping\CarrierEvents;
use App\Services\Shipping\CarrierStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Entreprises de livraison de la boutique : connexion (clé API Delivery App ou transporteur sans API), zones,
 * réglages, reversements.
 */
class CarrierController extends Controller
{
    public function __construct(private readonly CarrierConnections $connections) {}

    public function index(): JsonResponse
    {
        $connections = CarrierConnection::where('status', '!=', 'disabled')->orderBy('name')->get();

        return response()->json(['data' => $connections->map(fn ($c) => $this->present($c))]);
    }

    public function connectDeliveryApp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'max:100'],
        ], [], ['address' => 'adresse', 'api_key' => 'clé API']);

        $connection = $this->connections->connectDeliveryApp($this->currentStore(), $data['address'], $data['api_key']);

        return response()->json(['data' => $this->present($connection)], 201);
    }

    public function connectManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', new PhoneRule],
        ], [], ['name' => 'nom', 'phone' => 'téléphone']);

        $connection = $this->connections->connectManual($this->currentStore(), $data['name'], $data['phone'] ?? null);

        return response()->json(['data' => $this->present($connection)], 201);
    }

    public function update(Request $request, int $carrier): JsonResponse
    {
        $connection = CarrierConnection::findOrFail($carrier);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'fee_payer' => ['sometimes', Rule::in(['store', 'customer'])],
            'pickup_location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('stock_locations', 'id')->where('store_id', $connection->store_id)],
            // Clé sans la portée webhooks:write : adresse déclarée à la main chez le transporteur, secret collé ici
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $settings = $connection->settings ?? [];
        foreach (['fee_payer', 'pickup_location_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
            }
        }
        $connection->forceFill(array_filter([
            'name' => $data['name'] ?? null,
            'settings' => $settings,
            'webhook_secret' => $data['webhook_secret'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return response()->json(['data' => $this->present($connection)]);
    }

    public function destroy(int $carrier): JsonResponse
    {
        $this->connections->disconnect(CarrierConnection::findOrFail($carrier));

        return response()->json(['message' => 'Entreprise de livraison déconnectée.']);
    }

    /**
     * Zones du transporteur et correspondance avec les lieux de la boutique (communes, puis quartiers).
     */
    public function zones(int $carrier, Places $places): JsonResponse
    {
        $connection = CarrierConnection::findOrFail($carrier);
        $mappings = CarrierZoneMapping::where('connection_id', $connection->id)->get()->keyBy('place_id');

        $rows = $places->forStore($this->currentStore())
            ->filter(fn ($p) => in_array($p->type, ['city', 'commune', 'neighborhood'], true))
            ->sortBy(fn ($p) => [$p->type === 'neighborhood' ? 1 : 0, $p->label()])
            ->map(fn ($p) => [
                'place_id' => $p->id,
                'label' => $p->label(),
                'type' => $p->type,
                'carrier_zone_id' => $mappings->get($p->id)?->carrier_zone_id,
                'matched_by' => $mappings->get($p->id)?->matched_by,
            ])->values();

        return response()->json(['data' => [
            'zones' => CarrierZone::where('connection_id', $connection->id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (CarrierZone $z) => ['id' => $z->id, 'label' => $z->label(), 'name' => $z->name, 'fee' => $z->fee, 'is_shipping' => $z->is_shipping]),
            'places' => $rows,
        ]]);
    }

    public function updateZones(Request $request, int $carrier): JsonResponse
    {
        $connection = CarrierConnection::findOrFail($carrier);
        $data = $request->validate([
            'mappings' => ['present', 'array', 'max:500'],
            'mappings.*.place_id' => ['required', 'integer'],
            'mappings.*.carrier_zone_id' => ['nullable', 'integer'],
        ]);
        $this->connections->updateMappings($connection, $data['mappings']);

        return response()->json(['message' => 'Correspondances enregistrées.']);
    }

    /**
     * Zone ajoutée à la grille d'une entreprise sans API (« Cocody », 1 500 F), puis reliée aux quartiers.
     */
    public function storeZone(Request $request, int $carrier): JsonResponse
    {
        $connection = CarrierConnection::findOrFail($carrier);
        abort_if($connection->isApi(), 422, 'Les zones de cette entreprise viennent de son application : synchronisez-les.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'fee' => ['required', 'integer', 'min:0', 'max:10000000'],
        ], [], ['name' => 'nom de la zone', 'fee' => 'tarif']);

        $zone = CarrierZone::create([
            'connection_id' => $connection->id, 'remote_id' => 'manual-'.Str::uuid(), 'name' => $data['name'], 'fee' => $data['fee'],
            'is_shipping' => false, 'is_active' => true, 'synced_at' => now(),
        ]);
        $this->connections->autoMap($connection);

        return response()->json(['data' => ['id' => $zone->id, 'label' => $zone->label(), 'name' => $zone->name, 'fee' => $zone->fee]], 201);
    }

    /**
     * Tarif d'une zone (et son nom, pour une entreprise sans API). Entreprise avec API : prix de secours si son devis
     * ne répond pas.
     */
    public function updateZone(Request $request, int $zone): JsonResponse
    {
        $model = CarrierZone::findOrFail($zone);
        $connection = CarrierConnection::findOrFail($model->connection_id);
        $data = $request->validate([
            'name' => [$connection->isApi() ? 'prohibited' : 'sometimes', 'required', 'string', 'max:120'],
            'fee' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ], [], ['name' => 'nom de la zone', 'fee' => 'tarif']);
        $model->fill($data)->save();
        if (isset($data['name'])) {
            $this->connections->autoMap($connection);
        }

        return response()->json(['data' => ['id' => $model->id, 'label' => $model->label(), 'name' => $model->name, 'fee' => $model->fee]]);
    }

    public function destroyZone(int $zone): JsonResponse
    {
        $model = CarrierZone::findOrFail($zone);
        $connection = CarrierConnection::findOrFail($model->connection_id);
        abort_if($connection->isApi(), 422, 'Les zones de cette entreprise viennent de son application.');
        CarrierZoneMapping::where('carrier_zone_id', $model->id)->delete();
        $model->delete();

        return response()->json(['message' => 'Zone supprimée.']);
    }

    /**
     * Mise à jour des zones et des courses depuis le transporteur.
     */
    public function sync(int $carrier, CarrierEvents $events): JsonResponse
    {
        $connection = CarrierConnection::findOrFail($carrier);
        abort_unless($connection->isApi(), 422, 'Cette entreprise de livraison n\'a pas d\'API.');

        $this->connections->syncZones($connection);
        $updated = $events->sync($connection);

        return response()->json(['data' => $this->present($connection->refresh()), 'updated' => $updated]);
    }

    /**
     * Stock chez le transporteur : entrepôts, produits rapprochés par SKU, écarts avec la boutique.
     */
    public function stock(int $carrier, CarrierStock $stock): JsonResponse
    {
        return response()->json(['data' => $stock->sync(CarrierConnection::findOrFail($carrier))]);
    }

    /**
     * L'entrepôt du transporteur devient un emplacement de stock de la boutique.
     */
    public function linkHub(Request $request, int $carrier, CarrierStock $stock): JsonResponse
    {
        $data = $request->validate(['remote_hub_id' => ['required', 'string', 'max:40']]);
        $location = $stock->linkHub(CarrierConnection::findOrFail($carrier), $data['remote_hub_id']);

        return response()->json(['data' => ['id' => $location->id, 'name' => $location->name]], 201);
    }

    public function payouts(): JsonResponse
    {
        $payouts = CarrierPayout::with(['connection:id,name', 'lines.shipment.order:id,number,store_id', 'lines.shipment.order.store:id,order_prefix'])
            ->latest('paid_at')->latest('id')->limit(100)->get();

        return response()->json(['data' => $payouts->map(fn (CarrierPayout $p) => [
            'id' => $p->id,
            'carrier' => $p->connection?->name,
            'reference' => $p->remote_id,
            'amount' => $p->amount,
            'total_collected' => $p->total_collected,
            'total_fees' => $p->total_fees,
            'method' => $p->method,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'status' => $p->reconciliation_status,
            'difference' => $p->difference,
            'lines' => $p->lines->map(fn ($l) => [
                'tracking_code' => $l->remote_tracking_code,
                'order_id' => $l->shipment?->order_id,
                'order_reference' => $l->shipment?->order?->reference(),
                'collected' => $l->collected,
                'fees' => $l->fees,
                'net' => $l->net,
                'expected_net' => $l->expected_net,
                'difference' => $l->difference,
            ]),
        ])]);
    }

    private function present(CarrierConnection $c): array
    {
        $unmapped = $c->isApi() ? CarrierZoneMapping::where('connection_id', $c->id)->whereNull('carrier_zone_id')->count() : 0;

        return [
            'id' => $c->id,
            'driver' => $c->driver,
            'name' => $c->name,
            'status' => $c->status,
            'base_url' => $c->base_url,
            'api_key_prefix' => $c->api_key_prefix,
            'scopes' => $c->scopes ?? [],
            'merchant_name' => $c->remote_account['merchant']['business_name'] ?? null,
            'contact_phone' => $c->contact_phone,
            'webhook_url' => $c->isApi() ? $this->connections->webhookUrl($c) : null,
            'webhook_ready' => $c->webhook_secret !== null,
            'webhook_auto' => $c->remote_webhook_id !== null,
            'settings' => [
                'fee_payer' => $c->setting('fee_payer', 'store'),
                'pickup_location_id' => $c->setting('pickup_location_id'),
            ],
            'zones_count' => $c->isApi() ? CarrierZone::where('connection_id', $c->id)->where('is_active', true)->count() : 0,
            'unmapped_count' => $unmapped,
            'last_error' => $c->last_error,
            'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            'connected_at' => $c->connected_at?->toIso8601String(),
        ];
    }
}
