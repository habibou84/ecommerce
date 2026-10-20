<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Enums\CancelReason;
use App\Enums\ConfirmationOutcome;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Customers\CustomerBook;
use App\Services\Orders\OrderBulk;
use App\Services\Orders\OrderDesk;
use App\Services\Orders\OrderDocuments;
use App\Services\Orders\OrderDrafter;
use App\Services\Orders\OrderListing;
use App\Services\Orders\OrderMessageParser;
use App\Services\Orders\OrderMessages;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(private readonly OrderDesk $desk) {}

    /**
     * Liste : onglet (statut, à faire livrer, en retard), recherche, période, qui livre, commune, paiement, canal, tri ;
     * compteurs par onglet et résumé de la liste.
     */
    public function index(Request $request, OrderListing $listing): JsonResponse
    {
        $request->validate(OrderListing::rules());

        $page = $listing->query($request)->with(OrderListing::relations())->withCount('items')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $o) => OrderResource::make($o)->resolve() + $listing->extras($o))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => $listing->counts($request),
            'summary' => $listing->summary($request),
        ]);
    }

    /**
     * Export de la liste affichée (mêmes filtres), en CSV lisible par Excel (séparateur « ; », accents conservés).
     */
    public function export(Request $request, OrderListing $listing): Response
    {
        $request->validate(OrderListing::rules());
        $orders = $listing->query($request)->with([...OrderListing::relations(), 'items:id,order_id,title,variant_title,quantity'])->limit(5000)->get();

        return response()->streamDownload(function () use ($orders, $listing) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['N°', 'Date', 'Client', 'Téléphone', 'Quartier', 'Adresse', 'Date de livraison', 'Articles', 'Qui livre', 'Total', 'Payé', 'Reste à encaisser', 'Paiement', 'Statut', 'Canal'], ';');
            foreach ($orders as $o) {
                $extras = $listing->extras($o);
                fputcsv($out, [
                    $o->reference(), $o->created_at->format('d/m/Y H:i'), $o->customer_name, PhoneNumber::display($o->customer_phone),
                    $o->delivery_method === 'pickup' ? 'Retrait' : $o->shipping_place_label, $o->shipping_address, $o->scheduled_date?->format('d/m/Y'),
                    $o->items->map(fn ($i) => $i->quantity.' × '.$i->title.($i->variant_title ? ' ('.$i->variant_title.')' : ''))->implode(', '),
                    $extras['deliverer'], $o->total, $o->amount_paid - $o->amount_refunded, $o->amountDue(), $o->payment_status->label(), $o->status->label(), $o->source->label(),
                ], ';');
            }
            fclose($out);
        }, 'commandes-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Étiquettes colis de plusieurs commandes, une par page.
     */
    public function labels(Request $request, OrderDocuments $documents): Response
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer']]);

        return $documents->labels(Order::whereIn('id', $data['ids'])->orderBy('id')->get());
    }

    /**
     * Action sur plusieurs commandes : confier à un livreur ou une entreprise, changer le statut, envoyer le message
     * WhatsApp de l'étape. Chaque commande est traitée à part : le résultat dit ce qui a été fait et ce qui a échoué.
     */
    public function bulk(Request $request, OrderBulk $bulk): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['assign', 'status', 'whatsapp'])],
            'courier_id' => ['nullable', 'integer'],
            'carrier_connection_id' => ['nullable', 'integer'],
            'status' => ['required_if:action,status', 'nullable', Rule::in([OrderStatus::Confirmed->value, OrderStatus::Processing->value, OrderStatus::Cancelled->value])],
            'reason' => ['required_if:status,cancelled', 'nullable', Rule::enum(CancelReason::class)],
        ], [], ['status' => 'statut', 'reason' => 'motif']);

        abort_if($data['action'] === 'assign' && ! $request->user()->can(Permission::ShipmentsManage->value), 403, 'Vous ne pouvez pas confier les livraisons.');

        return response()->json(['data' => $bulk->run(Order::whereIn('id', $data['ids'])->orderBy('id')->get(), $data, $request->user())]);
    }

    /**
     * File de confirmation : relances dues d'abord, puis les plus anciennes ; nombre de tentatives.
     */
    public function queue(): JsonResponse
    {
        $orders = Order::where('status', OrderStatus::PendingConfirmation->value)
            ->with(['store', 'items', 'confirmations' => fn ($q) => $q->latest('id')])
            ->withCount('confirmations')
            ->orderByRaw('CASE WHEN callback_at IS NULL THEN 1 WHEN callback_at <= ? THEN 0 ELSE 2 END', [now()])
            ->orderBy('callback_at')->orderBy('id')
            ->limit(100)->get();

        $messages = app(OrderMessages::class);

        return response()->json(['data' => $orders->map(fn (Order $o) => OrderResource::make($o)->resolve() + [
            'items' => $o->items->map(fn ($i) => ['label' => $i->label(), 'quantity' => $i->quantity]),
            'attempts' => $o->confirmations_count,
            'last_attempt' => $o->confirmations->first() ? [
                'outcome_label' => $o->confirmations->first()->outcome->label(),
                'created_at' => $o->confirmations->first()->created_at->toIso8601String(),
            ] : null,
            'whatsapp' => $messages->message($o, 'confirm_request')['url'],
        ])]);
    }

    public function show(int $order, CustomerBook $customers, OrderMessages $messages): JsonResponse
    {
        $model = OrderResource::loadDetail(Order::findOrFail($order));
        $customer = $model->customer;

        return response()->json(['data' => OrderResource::make($model)->detailed()->resolve() + [
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'orders_count' => $customer->orders_count,
                'delivered_count' => $customer->delivered_count,
                'refused_count' => $customer->refused_count,
                'delivery_rate' => $customer->deliveryRate(),
                'is_blacklisted' => $customer->isBlacklisted(),
                'blacklist_reason' => $customer->blacklist_reason,
            ] : null,
            'duplicates' => $customer ? $customers->openDuplicates($customer, $model->id)
                ->map(fn ($o) => ['id' => $o->id, 'reference' => $model->store->order_prefix.$o->number, 'total' => $o->total]) : [],
            // Confirmation par le WhatsApp de la boutique (API) : remplace le lien vers le WhatsApp du téléphone
            'whatsapp_confirmation' => $channel = app(CustomerMessenger::class)->confirmationChannel($model),
            'messages' => array_values(array_filter($messages->suggestions($model), fn ($m) => ! ($channel && $m['key'] === 'confirm_request'))),
            'call_url' => $model->customer_phone ? 'tel:'.$model->customer_phone : null,
        ]]);
    }

    public function store(OrderRequest $request): JsonResponse
    {
        $order = $this->desk->create($this->currentStore(), $request->orderData(), $request->user());

        // Commande créée depuis la boîte « Messages » : la conversation la garde et retrouve la fiche client
        if ($request->filled('conversation_id') && ($conversation = Conversation::find($request->integer('conversation_id')))) {
            $conversation->forceFill(['order_id' => $order->id, 'customer_id' => $order->customer_id ?? $conversation->customer_id])->save();
        }

        return response()->json(['data' => ['id' => $order->id, 'reference' => $order->reference()]], 201);
    }

    /**
     * L'agent de confirmation peut corriger une commande encore à confirmer (« modification demandée »).
     */
    public function update(OrderRequest $request, int $order): JsonResponse
    {
        $model = Order::findOrFail($order);
        $user = $request->user();
        abort_unless($user->can(Permission::OrdersManage->value)
            || ($user->can(Permission::OrdersConfirm->value) && $model->status === OrderStatus::PendingConfirmation), 403);

        $this->desk->update($model, $request->orderData(), $user);

        return $this->show($order, app(CustomerBook::class), app(OrderMessages::class));
    }

    public function transition(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'reason' => ['required_if:status,cancelled', 'nullable', Rule::enum(CancelReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'collect_payment' => ['sometimes', 'boolean'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'restock' => ['sometimes', 'boolean'],
        ], [], ['reason' => 'motif']);

        $this->desk->transition(Order::findOrFail($order), OrderStatus::from($data['status']), $request->user(), array_filter([
            'reason' => isset($data['reason']) ? CancelReason::from($data['reason']) : null,
            'note' => $data['note'] ?? null,
            'collect_payment' => $data['collect_payment'] ?? null,
            'method' => isset($data['method']) ? PaymentMethod::from($data['method']) : null,
            'restock' => $data['restock'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->show($order, app(CustomerBook::class), app(OrderMessages::class));
    }

    public function payment(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'kind' => ['nullable', Rule::in([PaymentKind::Deposit->value, PaymentKind::Manual->value, PaymentKind::CodCash->value, PaymentKind::CodMobile->value])],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'method' => 'moyen de paiement']);

        $this->desk->recordPayment(Order::findOrFail($order), $data, $request->user());

        return $this->show($order, app(CustomerBook::class), app(OrderMessages::class));
    }

    public function confirmation(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::enum(ConfirmationOutcome::class)],
            'channel' => ['required', Rule::in(['call', 'whatsapp', 'sms'])],
            'note' => ['nullable', 'string', 'max:500'],
            'callback_at' => ['nullable', 'date', 'after:now'],
        ], [], ['callback_at' => 'heure de rappel']);

        $this->desk->recordConfirmation(Order::findOrFail($order), ConfirmationOutcome::from($data['outcome']), $data['channel'],
            $data['note'] ?? null, isset($data['callback_at']) ? now()->parse($data['callback_at']) : null, $request->user());

        return $this->show($order, app(CustomerBook::class), app(OrderMessages::class));
    }

    public function whatsappConfirmation(int $order, CustomerMessenger $messenger): JsonResponse
    {
        $model = Order::findOrFail($order);
        if ($messenger->requestConfirmation($model) === null) {
            throw new BusinessRuleException('Demande impossible par WhatsApp : le client doit avoir écrit à la boutique depuis moins de 24 h, ou le modèle de confirmation doit être enregistré dans Réglages › WhatsApp.');
        }

        return $this->show($order, app(CustomerBook::class), app(OrderMessages::class));
    }

    public function note(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $this->desk->addNote(Order::findOrFail($order), $data['note'], $request->user());

        return response()->json(['message' => 'Note ajoutée.'], 201);
    }

    /**
     * Saisie rapide : analyse du message collé (rien n'est enregistré).
     */
    public function parse(Request $request, OrderMessageParser $parser): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);

        return $this->parsed($parser->parse($this->currentStore(), $data['text']));
    }

    /**
     * Saisie rapide par l'assistant IA : conversation collée et/ou captures d'écran (même réponse que parse).
     */
    public function draft(Request $request, OrderDrafter $drafter): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'required_without:screenshots', 'string', 'max:10000'],
            'screenshots' => ['nullable', 'array', 'max:3'],
            'screenshots.*' => ['file', 'image', 'max:10240'],
        ], [], ['text' => 'conversation', 'screenshots' => 'captures d\'écran']);

        return $this->parsed($drafter->draft($this->currentStore(), $request->user(), $data['text'] ?? null, $data['screenshots'] ?? []));
    }

    /**
     * Résultat d'analyse complété du client déjà connu : son nom et sa dernière adresse.
     *
     * @param  array<string, mixed>  $result
     */
    private function parsed(array $result): JsonResponse
    {
        $known = $result['phone'] ? Customer::where('phone', $result['phone'])->with('addresses.place.parent')->first() : null;
        $address = $known?->addresses->first();

        return response()->json(['data' => $result + ['customer' => $known ? [
            'id' => $known->id,
            'name' => $known->name,
            'orders_count' => $known->orders_count,
            'is_blacklisted' => $known->isBlacklisted(),
            'address' => $address ? [
                'place_id' => $address->place_id,
                'place_label' => $address->place?->label(),
                'address_line' => $address->address_line,
                'landmark' => $address->landmark,
            ] : null,
        ] : null]]);
    }

    public function invoice(int $order, OrderDocuments $documents): Response
    {
        return $documents->invoice(Order::findOrFail($order));
    }

    public function label(int $order, OrderDocuments $documents): Response
    {
        return $documents->label(Order::findOrFail($order));
    }
}
