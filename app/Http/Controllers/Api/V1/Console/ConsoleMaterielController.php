<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AccessoryOrder;
use App\Models\AdminAction;
use App\Services\Materiel\MaterielShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Console › Matériel : produits (stock de la plateforme ou partenaire), commandes des espaces marchands, réglages
 * (boutique ouverte, moyens de paiement, options de livraison). Chaque changement est journalisé.
 */
class ConsoleMaterielController extends Controller
{
    public function __construct(private readonly MaterielShop $shop) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $orders = AccessoryOrder::with('store:id,name')
            ->when($status === 'open', fn ($q) => $q->whereNotIn('status', ['delivered', 'cancelled']))
            ->when($status && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(100)->get();
        $done = AccessoryOrder::where('status', 'delivered');

        return response()->json(['data' => [
            'settings' => $this->shop->settings(),
            'methods' => $this->shop->methods(),
            'categories' => Accessory::CATEGORIES,
            'statuses' => AccessoryOrder::STATUSES,
            'products' => Accessory::orderBy('category')->orderBy('sort')->orderBy('name')->get()->map(fn (Accessory $a) => $this->shop->presentAccessory($a, true))->values(),
            'orders' => $orders->map(fn (AccessoryOrder $o) => $this->shop->present($o, true))->values(),
            'totals' => [
                'open' => AccessoryOrder::whereNotIn('status', ['delivered', 'cancelled'])->count(),
                'to_check' => AccessoryOrder::where('payment_status', 'claimed')->where('status', '!=', 'cancelled')->count(),
                'sales' => (int) (clone $done)->sum('total'),
                'earnings' => (int) (clone $done)->sum('earnings'),
            ],
        ]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'wave' => ['required', 'boolean'],
            'cod' => ['required', 'boolean'],
            'deliveries' => ['present', 'array', 'max:12'],
            'deliveries.*.label' => ['required', 'string', 'max:80'],
            'deliveries.*.fee' => ['required', 'integer', 'min:0', 'max:1000000'],
            'deliveries.*.note' => ['nullable', 'string', 'max:160'],
            'deliveries.*.address' => ['required', 'boolean'],
        ], [], ['deliveries' => 'options de livraison', 'deliveries.*.label' => 'nom de l\'option', 'deliveries.*.fee' => 'prix de la livraison']);
        $this->shop->updateSettings($data);
        $this->log($request, 'materiel_settings', $data);

        return $this->index($request);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $product = Accessory::create($this->validated($request));
        $this->log($request, 'materiel_product_added', ['id' => $product->id, 'name' => $product->name]);

        return $this->index($request);
    }

    public function updateProduct(Request $request, int $product): JsonResponse
    {
        $model = Accessory::findOrFail($product);
        $model->update($this->validated($request));
        $this->log($request, 'materiel_product_updated', ['id' => $model->id, 'name' => $model->name]);

        return $this->index($request);
    }

    public function image(Request $request, int $product): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096']], [], ['image' => 'photo']);
        $model = Accessory::findOrFail($product);
        $disk = config('filesystems.catalog_disk', 'public');
        if ($model->image_path) {
            Storage::disk($disk)->delete($model->image_path);
        }
        $model->forceFill(['image_path' => $request->file('image')->store('materiel', ['disk' => $disk, 'visibility' => 'public'])])->save();

        return $this->index($request);
    }

    public function advance(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([...MaterielShop::FLOW, 'cancelled'])]]);
        $model = AccessoryOrder::with('store')->findOrFail($order);
        $data['status'] === 'cancelled' ? $this->shop->cancel($model, byConsole: true) : $this->shop->advance($model, $data['status']);
        $this->log($request, 'materiel_order_status', ['number' => $model->number, 'status' => $data['status']], $model->store_id);

        return $this->index($request);
    }

    public function paid(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['paid' => ['required', 'boolean']]);
        $model = AccessoryOrder::with('store')->findOrFail($order);
        $this->shop->markPaid($model, $data['paid']);
        $this->log($request, $data['paid'] ? 'materiel_order_paid' : 'materiel_order_unpaid', ['number' => $model->number], $model->store_id);

        return $this->index($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(Accessory::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'supplier' => ['required', 'in:own,partner'],
            'partner_name' => ['nullable', 'required_if:supplier,partner', 'string', 'max:120'],
            'partner_phone' => ['nullable', 'string', 'max:30'],
            'commission_type' => ['nullable', 'required_if:supplier,partner', 'in:percent,amount'],
            'commission_value' => ['nullable', 'required_if:supplier,partner', 'integer', 'min:0', 'max:10000000'],
            'is_active' => ['required', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ], [], ['name' => 'nom', 'category' => 'catégorie', 'price' => 'prix', 'cost_price' => 'prix d\'achat', 'partner_name' => 'nom du partenaire', 'commission_type' => 'type de commission', 'commission_value' => 'commission']);
        if ($data['supplier'] === 'own') {
            $data = array_merge($data, ['partner_name' => null, 'partner_phone' => null, 'commission_type' => null, 'commission_value' => null]);
        } else {
            $data['cost_price'] = null;
        }
        if ($data['commission_type'] === 'percent' && ($data['commission_value'] ?? 0) > 100) {
            abort(422, 'Une commission en pourcentage ne dépasse pas 100.');
        }

        return $data + ['sort' => 0];
    }

    private function log(Request $request, string $action, array $data, ?int $storeId = null): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => $storeId, 'action' => $action, 'data' => $data, 'ip' => $request->ip()]);
    }
}
