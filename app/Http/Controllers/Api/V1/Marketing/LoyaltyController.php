<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyEntry;
use App\Services\Customers\Loyalty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Programme de fidélité et de parrainage : réglages, chiffres, meilleurs clients, ajustement des points.
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly Loyalty $loyalty) {}

    public function show(): JsonResponse
    {
        $store = $this->currentStore();
        $settings = $this->loyalty->settings($store);
        $outstanding = (int) Customer::forStore($store->id)->where('loyalty_points', '>', 0)->sum('loyalty_points');

        return response()->json(['data' => [
            'settings' => $settings,
            'stats' => [
                'members' => Customer::forStore($store->id)->where('loyalty_points', '>', 0)->count(),
                'outstanding_points' => $outstanding,
                'outstanding_value' => $outstanding * $settings['point_value'],
                'redeemed_points' => (int) -LoyaltyEntry::forStore($store->id)->where('type', 'redeemed')->sum('points')
                    - (int) LoyaltyEntry::forStore($store->id)->where('type', 'refunded')->sum('points'),
                'referrals' => Customer::forStore($store->id)->whereNotNull('referred_by_id')->count(),
            ],
            'top' => Customer::forStore($store->id)->where('loyalty_points', '>', 0)->orderByDesc('loyalty_points')->limit(10)->get(['id', 'name', 'phone', 'loyalty_points']),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'points_per_1000' => ['required', 'integer', 'min:1', 'max:100'],
            'point_value' => ['required', 'integer', 'min:1', 'max:10000'],
            'min_points' => ['required', 'integer', 'min:1', 'max:100000'],
            'referral_enabled' => ['required', 'boolean'],
            'referral_discount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'referral_points' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [], ['points_per_1000' => 'points par 1 000 F', 'point_value' => 'valeur d\'un point', 'min_points' => 'minimum de points', 'referral_discount' => 'réduction du filleul', 'referral_points' => 'points du parrain']);
        $store = $this->currentStore();
        $store->forceFill(['loyalty_settings' => $data])->save();

        return response()->json(['data' => $this->loyalty->settings($store)]);
    }

    public function adjust(Request $request, int $customer): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'], 'note' => ['nullable', 'string', 'max:255']], [], ['points' => 'points']);
        $model = Customer::findOrFail($customer);
        $this->loyalty->adjust($model, $data['points'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->loyalty->forCustomer($model->fresh(), true)]);
    }
}
