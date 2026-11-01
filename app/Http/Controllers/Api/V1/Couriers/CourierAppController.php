<?php

namespace App\Http\Controllers\Api\V1\Couriers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierAssignment;
use App\Models\CourierEarning;
use App\Models\CourierRemittance;
use App\Models\CourierRemittanceDeclaration;
use App\Models\OrderReturn;
use App\Models\Scopes\StoreScope;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Couriers\CourierDesk;
use App\Services\Couriers\CourierPayroll;
use App\Services\Couriers\MissionResponses;
use App\Services\Couriers\RemittanceDeclarations;
use App\Services\Couriers\SharedCouriers;
use App\Services\Orders\MessageTexts;
use App\Services\Payments\PaymentDesk;
use App\Services\Places\DeliveryRates;
use chillerlan\QRCode\QRCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;

/**
 * API de l'application livreur (/livreur) : ses missions, leurs étapes, son argent.
 *
 * Un livreur partagé entre plusieurs boutiques (abonnement commun) voit ici ses missions, ses espèces et ses gains
 * de toutes ces boutiques, quelle que soit l'adresse depuis laquelle il ouvre l'application.
 */
class CourierAppController extends Controller
{
    private const ACTIVE = SharedCouriers::ACTIVE_MISSIONS;

    /** Affectations en cours des missions affichées (vue, acceptée) */
    private array $assignments = [];

    public function __construct(private readonly SharedCouriers $shared, private readonly MissionResponses $responses) {}

    public function me(Request $request, CourierPayroll $payroll): JsonResponse
    {
        $courier = $this->courier($request);
        $all = $this->couriers($request);
        $ids = $all->pluck('id');

        return response()->json(['data' => [
            'id' => $courier->id,
            'name' => $courier->name,
            'is_available' => $courier->is_available,
            'store' => ['name' => $courier->store->name, 'phone' => $courier->store->phone],
            'stores' => $all->map(fn (Courier $c) => ['courier_id' => $c->id, 'name' => $c->store->name, 'phone' => $c->store->phone, 'is_available' => $c->is_available])->values(),
            'cash_held' => (int) $all->sum(fn (Courier $c) => $this->shared->held($c)),
            'cash_limit' => $courier->cash_limit,
            // Dernière réponse de la boutique à un versement déclaré (point sur « Ma caisse » tant qu'il ne l'a pas vue)
            'remittance_decided_at' => CourierRemittanceDeclaration::withoutGlobalScope(StoreScope::class)->whereIn('courier_id', $ids)->max('decided_at'),
            // Position envoyée pendant la tournée : une de ses boutiques l'a demandé
            'tracking' => $all->contains(fn (Courier $c) => $c->store->courier_tracking),
            'unpaid' => (int) $all->sum(fn (Courier $c) => $payroll->unpaid($c)),
            'today' => [
                'delivered' => $this->shipments()->whereIn('courier_id', $ids)->whereDate('delivered_at', today())->count(),
                'collected' => (int) CashCollection::withoutGlobalScope(StoreScope::class)->whereIn('courier_id', $ids)->whereDate('collected_at', today())->sum('amount'),
                // Résumé de fin de journée : échecs, argent versé, paie gagnée aujourd'hui
                'failed' => ShipmentEvent::withoutGlobalScope(StoreScope::class)->where('source', 'courier')->whereIn('status', ['failed', 'rescheduled'])
                    ->whereDate('occurred_at', today())->whereIn('shipment_id', $this->shipments()->whereIn('courier_id', $ids)->select('id'))->count(),
                'remitted' => (int) CourierRemittance::withoutGlobalScope(StoreScope::class)->whereIn('courier_id', $ids)->whereDate('received_at', today())->sum('received_amount'),
                'earned' => (int) CourierEarning::withoutGlobalScope(StoreScope::class)->whereIn('courier_id', $ids)->whereDate('created_at', today())->sum('amount'),
            ],
        ]]);
    }

    public function availability(Request $request): JsonResponse
    {
        $ids = $this->couriers($request)->pluck('id');
        // Pour toutes ses boutiques, ou pour une seule (courier_id)
        $data = $request->validate(['is_available' => ['required', 'boolean'], 'courier_id' => ['nullable', 'integer', Rule::in($ids->all())]]);
        Courier::withoutGlobalScope(StoreScope::class)->whereIn('id', isset($data['courier_id']) ? [$data['courier_id']] : $ids)->update(['is_available' => $data['is_available']]);

        return response()->json(['data' => ['is_available' => $data['is_available']]]);
    }

    /**
     * Missions en cours, puis celles terminées aujourd'hui.
     */
    public function missions(Request $request): JsonResponse
    {
        $missions = $this->shipments()->with(['order' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'order.store', 'order.place.parent'])->whereIn('courier_id', $this->couriers($request)->pluck('id'))
            ->where(fn ($q) => $q->whereIn('status', self::ACTIVE)->orWhereDate('updated_at', today()))
            ->orderByRaw("CASE status WHEN 'out_for_delivery' THEN 0 WHEN 'in_transit' THEN 1 WHEN 'awaiting_pickup' THEN 2 WHEN 'rescheduled' THEN 3 WHEN 'failed' THEN 4 WHEN 'returning' THEN 5 ELSE 6 END")
            ->orderBy('requested_at')->get();

        $several = $this->couriers($request)->count() > 1;
        $this->responses->markSeen($missions);
        $this->loadAssignments($missions);

        // Avec full=1, le détail de chaque mission en cours : le téléphone le garde pour travailler sans réseau
        if ($request->boolean('full')) {
            return response()->json(['data' => $missions->map(fn (Shipment $s) => in_array($s->status->value, self::ACTIVE, true)
                ? $this->detail($s) : $this->summary($s, $several))]);
        }

        return response()->json(['data' => $missions->map(fn (Shipment $s) => $this->summary($s, $several))]);
    }

    public function show(Request $request, int $shipment): JsonResponse
    {
        $mission = $this->mission($request, $shipment);
        $this->responses->markSeen(collect([$mission]));
        $this->loadAssignments(collect([$mission]));

        return response()->json(['data' => $this->detail($mission)]);
    }

    /**
     * Le livreur accepte la mission : la boutique le voit.
     */
    public function accept(Request $request, int $shipment): JsonResponse
    {
        $mission = $this->mission($request, $shipment);
        $courier = $this->couriers($request)->firstWhere('id', $mission->courier_id);
        SharedCouriers::within($mission->order->store, fn () => $this->responses->accept($mission, $courier));
        $this->loadAssignments(collect([$mission]));

        return response()->json(['data' => $this->summary($mission, true)]);
    }

    /**
     * Le livreur refuse la mission (avant le ramassage), avec un motif : elle repart vers un autre livreur.
     */
    public function decline(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motif']);
        $mission = $this->mission($request, $shipment);
        $courier = $this->couriers($request)->firstWhere('id', $mission->courier_id);
        SharedCouriers::within($mission->order->store, fn () => $this->responses->decline($mission, $courier, $data['reason']));

        return response()->json(['message' => 'Mission refusée : le marchand est prévenu.']);
    }

    /**
     * Tout ce qu'il faut pour livrer : client, adresse, articles, montant, étapes possibles.
     */
    private function detail(Shipment $mission): array
    {
        $model = SharedCouriers::within($mission->order->store, fn () => $mission->load(['order.items', 'order.place.parent', 'origin']));
        $order = $model->order;

        return $this->summary($model, true) + [
            'store_phone' => $order->store->phone,
            'customer_phone' => $order->shipping_phone ?? $order->customer_phone,
            'customer_phone_display' => PhoneNumber::display($order->shipping_phone ?? $order->customer_phone),
            'customer_phone2' => $order->shipping_phone2,
            'address' => $order->shipping_address,
            'landmark' => $order->shipping_landmark,
            // Point GPS donné par le client (commande en ligne) : itinéraire et ordre de la tournée
            'latitude' => $order->shipping_latitude !== null ? (float) $order->shipping_latitude : null,
            'longitude' => $order->shipping_longitude !== null ? (float) $order->shipping_longitude : null,
            'customer_note' => $order->customer_note,
            'note' => $model->note,
            'pickup' => $model->origin ? ['name' => $model->origin->name, 'address' => $model->origin->address] : null,
            'items' => $order->items->map(fn ($i) => ['label' => $i->label(), 'quantity' => $i->quantity]),
            'requires_code' => $model->delivery_code !== null && ! $order->isExpedition(),
            // Expédition : colis à déposer à la gare, compagnies proposées
            'expedition' => $order->isExpedition() ? [
                'city' => $order->expeditionCity?->name,
                'payer' => $order->expedition_payer,
                // Ce que le livreur gagne pour ce dépôt (sa règle de paie) ; le prix convenu avec le client reste au marchand
                'pay' => SharedCouriers::within($order->store, fn () => $model->courier?->payRule?->amountFor(null, true)),
                'companies' => SharedCouriers::within($order->store, fn () => app(DeliveryRates::class)->companies()),
            ] : null,
            'failure_reason' => $model->failure_reason,
            'rescheduled_to' => $model->rescheduled_to?->toDateString(),
            'next_steps' => CourierDesk::nextSteps($model),
            // Échange : articles à reprendre au client en lui remettant le colis
            'take_back' => SharedCouriers::within($order->store, fn () => OrderReturn::forStore($order->store_id)->where('exchange_order_id', $order->id)
                ->where('status', 'pending')->with('items.orderItem')->get()
                ->flatMap(fn (OrderReturn $r) => $r->items->map(fn ($l) => ['label' => $l->orderItem?->label(), 'quantity' => $l->quantity]))->values()),
            // Message « livreur en route » : envoyé par la boutique, réponse du client, ou à envoyer soi-même
            'customer_notified_at' => $model->customer_notified_at?->toIso8601String(),
            'customer_response' => $model->customer_response,
            'customer_response_at' => $model->customer_response_at?->toIso8601String(),
            'notify_url' => 'https://wa.me/'.ltrim((string) ($order->shipping_phone ?? $order->customer_phone), '+').'?text='
                .rawurlencode(SharedCouriers::within($order->store, fn () => app(MessageTexts::class)->render($order, 'courier_on_the_way'))),
            // Reste à payer (0 si le client a payé en ligne, à la porte ou avant) et paiement à la porte possible
            'amount_due' => $order->amountDue(),
            'door_payment' => $order->amountDue() > 0 && SharedCouriers::within($order->store, fn () => app(PaymentDesk::class)->availableMethods($order->store)) !== [],
        ];
    }

    /**
     * Tournée du jour : ordre des arrêts choisi par le livreur (ses missions en cours, dans l'ordre donné).
     */
    public function route(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer', 'distinct']]);
        $missions = $this->shipments()->whereIn('courier_id', $this->couriers($request)->pluck('id'))->whereIn('status', self::ACTIVE)
            ->whereIn('id', $data['ids'])->pluck('id')->all();

        foreach (array_values(array_intersect($data['ids'], $missions)) as $i => $id) {
            $this->shipments()->whereKey($id)->update(['route_position' => $i + 1]);
        }

        return response()->json(['data' => ['saved' => count($missions)]]);
    }

    /**
     * Position du livreur pendant sa tournée, gardée pour les boutiques qui l'ont activé (la dernière seulement).
     */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180']]);
        $ids = $this->couriers($request)->filter(fn (Courier $c) => $c->store->courier_tracking)->pluck('id');
        Courier::withoutGlobalScope(StoreScope::class)->whereIn('id', $ids)
            ->update(['last_latitude' => $data['latitude'], 'last_longitude' => $data['longitude'], 'last_located_at' => now()]);

        return response()->json(['data' => ['shared' => $ids->isNotEmpty()]]);
    }

    /**
     * Paiement à la porte : lien de paiement mobile money (Wave, Orange Money…) à faire scanner au client (QR code)
     * ou à lui envoyer ; la commande passe à « payée » dès que le prestataire confirme, sans espèces à rapporter.
     */
    public function doorPayment(Request $request, int $shipment, PaymentDesk $payments): JsonResponse
    {
        $mission = $this->mission($request, $shipment);
        if (! in_array($mission->status->value, ['in_transit', 'out_for_delivery', 'rescheduled', 'failed'], true)) {
            throw new BusinessRuleException('Le paiement à la porte se fait pendant la livraison.');
        }
        $order = $mission->order;
        $courier = $this->couriers($request)->firstWhere('id', $mission->courier_id);
        $link = SharedCouriers::within($order->store, fn () => $payments->createLink($order, null, 'Paiement à la livraison', $courier->user, 24));
        $url = $order->store->url('/payer/'.$link->token);
        $text = 'Bonjour'.($order->customer_name ? ' '.Str::before($order->customer_name, ' ') : '').", voici le lien pour payer votre commande {$order->reference()} chez {$order->store->name} (".Money::format($link->amount).") : {$url}";
        $phone = ltrim((string) ($order->shipping_phone ?? $order->customer_phone), '+');

        return response()->json(['data' => [
            'url' => $url,
            'amount' => $link->amount,
            'qr' => (new QRCode)->render($url),
            'whatsapp_url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($text),
            'sms_url' => 'sms:+'.$phone.'?body='.rawurlencode($text),
        ]], 201);
    }

    public function step(Request $request, int $shipment, CourierDesk $desk): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_transit', 'out_for_delivery', 'delivered', 'failed', 'returning'])],
            'code' => ['nullable', 'string', 'max:10'],
            'payment_method' => ['nullable', Rule::in(['cash', ...CourierDesk::MOBILE_METHODS])],
            'collected_amount' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'rescheduled_to' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
            // Expédition : dépôt à la gare (compagnie, frais payés, ticket, avancés de sa poche)
            'expedition_company' => ['nullable', 'string', 'max:60'],
            'expedition_fee' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'expedition_ticket' => ['nullable', 'string', 'max:60'],
            'expedition_advanced' => ['nullable', 'boolean'],
            // Étape faite sans réseau : identifiant donné par le téléphone et heure réelle de l'étape
            'offline_id' => ['nullable', 'uuid'],
            'occurred_at' => ['nullable', 'date'],
        ], [], ['code' => 'code', 'reason' => 'motif', 'photo' => 'photo']);

        // La mission peut appartenir à une autre de ses boutiques : elle avance dans le contexte de cette boutique
        $mission = $this->mission($request, $shipment);
        $courier = $this->couriers($request)->firstWhere('id', $mission->courier_id);

        // Étape renvoyée par le téléphone (réponse perdue en route) : déjà enregistrée, rien ne se refait
        $replay = isset($data['offline_id']) ? 'courier-step:'.$courier->user_id.':'.$data['offline_id'] : null;
        if ($replay !== null && Cache::has($replay)) {
            return response()->json(['data' => $this->summary($mission, true)]);
        }
        // Heure réelle de l'étape, jamais dans le futur ni plus de trois jours en arrière
        if (isset($data['occurred_at'])) {
            $data['occurred_at'] = Carbon::parse($data['occurred_at'])->max(now()->subDays(3))->min(now())->toIso8601String();
        }

        $model = SharedCouriers::within($mission->order->store, fn () => $desk->update($mission, $courier, $data['status'], $data, $request->file('photo')));
        if ($replay !== null) {
            Cache::put($replay, $model->id, now()->addDays(7));
        }

        return response()->json(['data' => $this->summary($model, true)]);
    }

    /**
     * Ses encaissements non versés et ses derniers versements.
     */
    public function cash(Request $request): JsonResponse
    {
        $all = $this->couriers($request);
        $ids = $all->pluck('id');
        $several = $all->count() > 1;

        return response()->json(['data' => [
            'held' => (int) $all->sum(fn (Courier $c) => $this->shared->held($c)),
            'collections' => CashCollection::withoutGlobalScope(StoreScope::class)->with(['shipment' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'shipment.order' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'shipment.order.store'])
                ->whereIn('courier_id', $ids)->whereNull('remittance_id')->where('method', 'cash')->orderByDesc('collected_at')->get()
                ->map(fn ($c) => ['reference' => $c->shipment->order->reference(), 'store' => $several ? $c->shipment->order->store->name : null, 'amount' => $c->amount, 'collected_at' => $c->collected_at->toIso8601String()]),
            'remittances' => CourierRemittance::withoutGlobalScope(StoreScope::class)->whereIn('courier_id', $ids)->latest('received_at')->limit(10)->get()
                ->map(fn ($r) => ['received_amount' => $r->received_amount, 'difference' => $r->difference, 'received_at' => $r->received_at->toIso8601String()]),
            // Espèces par boutique (à qui verser) et versements déclarés
            'stores' => $all->map(fn (Courier $c) => ['courier_id' => $c->id, 'store' => $c->store->name, 'held' => $this->shared->held($c)])->values(),
            'declarations' => CourierRemittanceDeclaration::withoutGlobalScope(StoreScope::class)->with(['courier.store', 'remittance'])->whereIn('courier_id', $ids)
                ->latest('id')->limit(10)->get()->map(fn ($d) => $this->declaration($d, $several)),
        ]]);
    }

    /**
     * Le livreur déclare un versement à une de ses boutiques (espèces remises, ou envoi mobile money avec la référence).
     */
    public function declareRemittance(Request $request, RemittanceDeclarations $declarations): JsonResponse
    {
        $all = $this->couriers($request);
        $data = $request->validate([
            'courier_id' => ['nullable', 'integer', Rule::in($all->pluck('id')->all())],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::in(array_keys(CourierRemittanceDeclaration::METHODS))],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
        ], [], ['amount' => 'montant', 'reference' => 'référence', 'photo' => 'photo']);

        $courier = $all->firstWhere('id', $data['courier_id'] ?? $this->courier($request)->id);
        $declaration = SharedCouriers::within($courier->store, fn () => $declarations->declare($courier, $data, $request->file('photo')));

        return response()->json(['data' => $this->declaration($declaration->setRelation('courier', $courier), true)], 201);
    }

    public function earnings(Request $request, CourierPayroll $payroll): JsonResponse
    {
        $all = $this->couriers($request);
        $names = $all->mapWithKeys(fn (Courier $c) => [$c->id => $c->store->name]);
        $several = $all->count() > 1;

        return response()->json(['data' => [
            'unpaid' => (int) $all->sum(fn (Courier $c) => $payroll->unpaid($c)),
            'lines' => CourierEarning::withoutGlobalScope(StoreScope::class)->with(['shipment' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'shipment.order' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'shipment.order.store'])
                ->whereIn('courier_id', $all->pluck('id'))->whereNull('payout_id')->latest('id')->limit(100)->get()
                ->map(fn ($e) => ['type_label' => CourierEarning::TYPES[$e->type] ?? $e->type, 'amount' => $e->amount, 'reference' => $e->shipment?->reference(),
                    'store' => $several ? $names[$e->courier_id] : null, 'created_at' => $e->created_at->toIso8601String()]),
        ]]);
    }

    /**
     * @param  Collection<int, Shipment>  $missions
     */
    private function loadAssignments(Collection $missions): void
    {
        $this->assignments = CourierAssignment::withoutGlobalScope(StoreScope::class)->whereIn('shipment_id', $missions->pluck('id'))
            ->where('status', 'assigned')->get()->keyBy('shipment_id')->all();
    }

    private function declaration(CourierRemittanceDeclaration $d, bool $withStore): array
    {
        return [
            'id' => $d->id,
            'store' => $withStore ? $d->courier->store->name : null,
            'amount' => $d->amount,
            'method' => $d->method,
            'method_label' => CourierRemittanceDeclaration::METHODS[$d->method],
            'reference' => $d->reference,
            'status' => $d->status,
            'received_amount' => $d->remittance?->received_amount,
            'difference' => $d->remittance?->difference,
            'reject_reason' => $d->reject_reason,
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }

    private function courier(Request $request): Courier
    {
        return $request->attributes->get('courier');
    }

    /**
     * Ses fiches de livreur : celle de la boutique de l'adresse et celles de toutes ses autres boutiques (du même
     * commerçant ou non) — son espace livreur réunit leurs courses ; l'argent reste séparé par boutique.
     *
     * @return Collection<int, Courier>
     */
    private function couriers(Request $request): Collection
    {
        if (! $request->attributes->has('couriers')) {
            $request->attributes->set('couriers', $this->shared->ofPerson($this->courier($request)));
        }

        return $request->attributes->get('couriers');
    }

    /**
     * Livraisons de toutes ses boutiques (le filtre automatique sur la boutique de l'adresse est levé).
     */
    private function shipments(): Builder
    {
        return Shipment::withoutGlobalScope(StoreScope::class);
    }

    private function mission(Request $request, int $id): Shipment
    {
        return $this->shipments()->with(['order' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'order.store'])
            ->whereIn('courier_id', $this->couriers($request)->pluck('id'))->findOrFail($id);
    }

    private function summary(Shipment $s, bool $withStore = false): array
    {
        $order = $s->order;

        return [
            'store' => $withStore ? $order->store->name : null,
            'id' => $s->id,
            'reference' => $s->reference(),
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'customer_name' => $order->shipping_name ?? $order->customer_name,
            'place' => $order->shipping_place_label,
            'expedition_city' => $order->isExpedition() ? $order->expeditionCity?->name : null,
            'cod_amount' => $s->cod_amount,
            'customer_response' => $s->customer_response,
            'collected_amount' => $s->collected_amount,
            'attempts_count' => $s->attempts_count,
            'scheduled_date' => $order->scheduled_date?->toDateString(),
            'rescheduled_to' => $s->rescheduled_to?->toDateString(),
            // Tournée : commune et quartier pour regrouper les arrêts, ordre choisi par le livreur
            'commune' => $order->place ? ($order->place->type === 'neighborhood' ? $order->place->parent?->name : $order->place->name) : null,
            'route_position' => $s->route_position,
            // Réponse du livreur : à accepter tant qu'il n'a ni accepté ni récupéré le colis
            'acceptance' => isset($this->assignments[$s->id]) ? ($this->assignments[$s->id]->accepted_at ? 'accepted' : 'pending') : null,
            'assigned_at' => isset($this->assignments[$s->id]) ? $this->assignments[$s->id]->assigned_at->toIso8601String() : null,
        ];
    }
}
