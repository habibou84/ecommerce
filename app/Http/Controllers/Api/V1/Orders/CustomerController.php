<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Tag;
use App\Services\Customers\CustomerBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Fichier clients.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerBook $book) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:blacklisted,risky,loyal'],
            'tag' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'in:recent,spent,orders'],
        ]);
        $search = trim((string) $request->query('search'));
        $digits = preg_replace('/\D/', '', $search);

        $page = Customer::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                ->when(strlen($digits) >= 4, fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%"))))
            ->when($request->query('filter') === 'blacklisted', fn ($q) => $q->whereNotNull('blacklisted_at'))
            ->when($request->query('filter') === 'risky', fn ($q) => $q->where('refused_count', '>', 0))
            ->when($request->query('filter') === 'loyal', fn ($q) => $q->where('delivered_count', '>=', 3))
            ->when($request->query('tag'), fn ($q, $tag) => $q->whereHas('tags', fn ($t) => $t->where('name', $tag)))
            ->with('tags:id,name')
            ->when($request->query('sort') === 'spent', fn ($q) => $q->orderByDesc('total_spent'))
            ->when($request->query('sort') === 'orders', fn ($q) => $q->orderByDesc('orders_count'))
            ->orderByDesc('last_order_at')->orderByDesc('id')
            ->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Customer $c) => $this->present($c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $customer): JsonResponse
    {
        $model = Customer::with(['tags:id,name', 'addresses.place.parent'])->findOrFail($customer);
        $orders = $model->orders()->with('store')->withCount('items')->limit(50)->get();

        return response()->json(['data' => $this->present($model) + [
            'phone2' => $model->phone2,
            'email' => $model->email,
            'note' => $model->note,
            'accepts_marketing' => $model->accepts_marketing,
            'marketing_opt_out_at' => $model->marketing_opt_out_at?->toIso8601String(),
            'blacklist_reason' => $model->blacklist_reason,
            'addresses' => $model->addresses->map(fn ($a) => [
                'id' => $a->id,
                'place_id' => $a->place_id,
                'place_label' => $a->place?->label(),
                'address_line' => $a->address_line,
                'landmark' => $a->landmark,
                'is_default' => $a->is_default,
            ]),
            'orders' => OrderResource::collection($orders)->resolve(),
            'whatsapp_url' => 'https://wa.me/'.ltrim($model->phone, '+'),
            'other_stores' => $this->book->elsewhere(request()->user(), $this->currentStore(), $model->phone),
        ]]);
    }

    /**
     * Le numéro dans les autres boutiques de l'adresse (commande d'un client pas encore connu ici).
     */
    public function elsewhere(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);

        return response()->json(['data' => $this->book->elsewhere($request->user(), $this->currentStore(), $data['phone'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $customer = $this->book->findOrCreate($this->currentStore(), $data['phone'], $data['name'] ?? null, ['source' => 'manual']);
        $this->fill($customer, $data);

        return $this->show($customer->id)->setStatusCode(201);
    }

    public function update(Request $request, int $customer): JsonResponse
    {
        $model = Customer::findOrFail($customer);
        $this->fill($model, $this->validated($request, false));

        return $this->show($model->id);
    }

    public function blacklist(Request $request, int $customer): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motif']);
        $this->book->blacklist(Customer::findOrFail($customer), $data['reason']);

        return $this->show($customer);
    }

    public function unblacklist(int $customer): JsonResponse
    {
        $this->book->unblacklist(Customer::findOrFail($customer));

        return $this->show($customer);
    }

    private function validated(Request $request, bool $creating): array
    {
        if (is_string($request->input('phone')) && PhoneNumber::isValid($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }
        if (is_string($request->input('phone2')) && PhoneNumber::isValid($request->input('phone2'))) {
            $request->merge(['phone2' => PhoneNumber::normalize($request->input('phone2'))]);
        }

        return $request->validate([
            'phone' => [$creating ? 'required' : 'prohibited', new PhoneRule],
            'name' => ['nullable', 'string', 'max:120'],
            'phone2' => ['nullable', new PhoneRule],
            'email' => ['nullable', 'email', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'accepts_marketing' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
        ], [], ['phone' => 'téléphone']);
    }

    private function fill(Customer $customer, array $data): void
    {
        $customer->update(collect($data)->only(['name', 'phone2', 'email', 'note', 'accepts_marketing'])->all());
        // Le client a redonné son accord (au marchand) après avoir répondu STOP
        if (($data['accepts_marketing'] ?? false) && $customer->marketing_opt_out_at) {
            $customer->forceFill(['marketing_opt_out_at' => null])->save();
        }

        if (array_key_exists('tags', $data)) {
            $ids = collect($data['tags'])->map(fn ($n) => trim($n))->filter()->unique(fn ($n) => mb_strtolower($n))
                ->map(fn ($name) => (Tag::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                    ?? Tag::create(['store_id' => $customer->store_id, 'name' => $name]))->id);
            $customer->tags()->sync($ids);
        }
    }

    private function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'phone_display' => PhoneNumber::display($customer->phone),
            'orders_count' => $customer->orders_count,
            'delivered_count' => $customer->delivered_count,
            'refused_count' => $customer->refused_count,
            'delivery_rate' => $customer->deliveryRate(),
            'total_spent' => $customer->total_spent,
            'last_order_at' => $customer->last_order_at?->toIso8601String(),
            'is_blacklisted' => $customer->isBlacklisted(),
            'tags' => $customer->tags->pluck('name'),
        ];
    }
}
