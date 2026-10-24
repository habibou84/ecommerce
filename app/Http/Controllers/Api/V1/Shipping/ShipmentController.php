<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StockLocation;
use App\Services\Shipping\CarrierException;
use App\Services\Shipping\DeliveryAdvisor;
use App\Services\Shipping\ShipmentDesk;
use App\Services\Shipping\ShipmentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Contract\Contract;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expéditions : devis des transporteurs, demande de course, annulation, statut manuel, suivi.
 */
class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentDesk $desk) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', 'max:30'], 'carrier_id' => ['nullable', 'integer']]);

        $shipments = Shipment::with(['order.store', 'connection', 'courier', 'origin'])
            ->when($request->query('status') === 'active', fn ($q) => $q->whereNotIn('status', ['delivered', 'returned', 'cancelled', 'rejected', 'lost']))
            ->when($request->query('status') === 'problems', fn ($q) => $q->whereIn('status', ['rejected', 'failed', 'rescheduled', 'returning', 'lost']))
            ->when($request->filled('carrier_id'), fn ($q) => $q->where('carrier_connection_id', $request->integer('carrier_id')))
            ->when($request->filled('courier_id'), fn ($q) => $q->where('courier_id', $request->integer('courier_id')))
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($shipments->items())->map(fn (Shipment $s) => ShipmentPresenter::present($s, false) + [
                'customer_name' => $s->order->shipping_name ?? $s->order->customer_name,
                'place' => $s->order->shipping_place_label,
            ]),
            'meta' => ['current_page' => $shipments->currentPage(), 'last_page' => $shipments->lastPage(), 'total' => $shipments->total()],
        ]);
    }

    /**
     * Comparateur : devis des transporteurs et livreurs de la boutique, avec leur historique, et suggestion des règles.
     */
    public function quotes(int $order, DeliveryAdvisor $advisor): JsonResponse
    {
        $model = Order::with('items.product')->findOrFail($order);
        $options = $advisor->options($model);

        return response()->json([
            'data' => $options['carriers'],
            // Départ possible : emplacements actifs (celui de la commande en premier)
            'locations' => StockLocation::where('is_active', true)->orderByRaw('id = ? desc', [(int) $model->location_id])->orderBy('priority')->get(['id', 'name', 'type', 'carrier_connection_id']),
            'location_id' => $model->location_id,
            // Livreurs de la boutique : ceux qui couvrent la zone de la commande d'abord, puis les disponibles
            'couriers' => $options['couriers'],
            'suggestion' => $advisor->suggest($model, $options),
            'assignment_mode' => $this->currentStore()->assignment_mode,
        ]);
    }

    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'carrier_connection_id' => ['required_without:courier_id', 'nullable', 'integer'],
            'courier_id' => ['required_without:carrier_connection_id', 'nullable', 'integer'],
            'origin_location_id' => ['nullable', 'integer'],
            'fee_payer' => ['nullable', Rule::in(['store', 'customer'])],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['carrier_connection_id' => 'entreprise de livraison', 'courier_id' => 'livreur']);

        $model = Order::findOrFail($order);
        $shipment = isset($data['courier_id'])
            ? $this->desk->assignCourier($model, Courier::findOrFail($data['courier_id']), $data, $request->user())
            : $this->desk->create($model, CarrierConnection::findOrFail($data['carrier_connection_id']), $data, $request->user());

        return response()->json(['data' => $this->present($shipment)], 201);
    }

    /**
     * Mission confiée à un autre livreur de la boutique.
     */
    public function assign(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate(['courier_id' => ['required', 'integer']]);
        $model = $this->desk->reassign(Shipment::findOrFail($shipment), Courier::findOrFail($data['courier_id']), $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * Photo de preuve : prise par le livreur de la boutique (stockage privé), ou chez l'entreprise de livraison par un
     * lien signé qui expire au bout d'un jour, redemandé quand il a expiré.
     */
    public function proof(int $shipment): StreamedResponse|RedirectResponse
    {
        $model = Shipment::findOrFail($shipment);
        if ($model->proof_photo_path) {
            abort_unless(Storage::disk('local')->exists($model->proof_photo_path), 404);

            return Storage::disk('local')->response($model->proof_photo_path);
        }

        abort_unless($model->proof_photo_url, 404);
        parse_str((string) parse_url($model->proof_photo_url, PHP_URL_QUERY), $query);
        if (isset($query['expires']) && (int) $query['expires'] <= now()->addMinutes(5)->timestamp && $model->connection?->isApi()) {
            try {
                $model = $this->desk->refresh($model);
            } catch (CarrierException) {
                // Transporteur injoignable : l'ancien lien est rendu tel quel
            }
        }

        return redirect()->away($model->proof_photo_url);
    }

    /**
     * Course chez l'entreprise de livraison : modification (champs choisis, ou reprise de la commande modifiée).
     */
    public function updateAtCarrier(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate([
            'from_order' => ['sometimes', 'boolean'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'recipient_phone' => ['sometimes', 'string', 'max:30'],
            'recipient_phone2' => ['sometimes', 'nullable', 'string', 'max:30'],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery_landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_scheduled_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'items_amount' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'is_fragile' => ['sometimes', 'boolean'],
        ], [], ['delivery_scheduled_date' => 'date de livraison', 'items_amount' => 'montant à encaisser', 'recipient_phone' => 'téléphone']);
        $model = Shipment::findOrFail($shipment);

        $model = $request->boolean('from_order')
            ? $this->desk->syncOrderToCarrier($model, $request->user())
            : $this->desk->updateAtCarrier($model, collect($data)->except('from_order')->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function decide(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['redeliver', 'return'])],
            'date' => ['required_if:decision,redeliver', 'nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['decision' => 'décision', 'date' => 'date de livraison']);
        $model = $this->desk->decideAtCarrier(Shipment::findOrFail($shipment), $data['decision'], $data['date'] ?? null, $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function requestReturn(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $model = $this->desk->requestReturnAtCarrier(Shipment::findOrFail($shipment), $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function note(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']], [], ['note' => 'note']);
        $model = $this->desk->noteToCarrier(Shipment::findOrFail($shipment), $data['note'], $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function rate(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:7'],
            'tags.*' => ['string', Rule::in(array_keys(Contract::MERCHANT_RATING_TAGS))],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [], ['score' => 'note', 'tags' => 'remarques', 'comment' => 'commentaire']);
        $model = $this->desk->rateCourier(Shipment::findOrFail($shipment), $data['score'], $data['tags'] ?? [], $data['comment'] ?? null, $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function cancel(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $model = $this->desk->cancel(Shipment::findOrFail($shipment), $data['reason'] ?? null, $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function status(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, ShipmentStatus::manual()))],
            'note' => ['nullable', 'string', 'max:255'],
            'collected_amount' => ['nullable', 'integer', 'min:0'],
        ]);
        $model = $this->desk->updateManually(Shipment::findOrFail($shipment), ShipmentStatus::from($data['status']), $data['note'] ?? null, $data['collected_amount'] ?? null, $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function refresh(int $shipment): JsonResponse
    {
        return response()->json(['data' => $this->present($this->desk->refresh(Shipment::findOrFail($shipment)))]);
    }

    /**
     * Message WhatsApp pour un transporteur sans API.
     */
    public function message(int $shipment): JsonResponse
    {
        $model = Shipment::with(['order.store', 'order.items', 'origin', 'connection'])->findOrFail($shipment);
        $text = $this->desk->requestMessage($model);
        $phone = $model->connection?->contact_phone;

        return response()->json(['data' => [
            'text' => $text,
            'whatsapp_url' => $phone ? 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($text) : null,
        ]]);
    }

    private function present(Shipment $shipment): array
    {
        return ShipmentPresenter::present($shipment->load(['order.store', 'connection', 'courier', 'events', 'origin']));
    }
}
