<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Stock\StockLevels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chiffres de l'accueil de la gestion (selon les droits de la personne).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, StockLevels $levels): JsonResponse
    {
        $user = $request->user();
        $data = [];

        if ($user->can(Permission::OrdersView->value)) {
            $today = Order::where('created_at', '>=', now()->startOfDay());
            $month = Order::where('status', OrderStatus::Completed->value)->where('completed_at', '>=', now()->startOfMonth());
            $data['orders'] = [
                'today_count' => (clone $today)->count(),
                'today_amount' => (int) (clone $today)->where('status', '!=', OrderStatus::Cancelled->value)->sum('total'),
                'to_confirm' => Order::where('status', OrderStatus::PendingConfirmation->value)->count(),
                'callbacks_due' => Order::where('status', OrderStatus::PendingConfirmation->value)->where('callback_at', '<=', now())->count(),
                'to_ship' => Order::whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Processing->value])->count(),
                'month_revenue' => (int) (clone $month)->sum('total'),
                'month_delivered' => (clone $month)->count(),
            ];
        }

        if ($user->can(Permission::StockManage->value)) {
            $data['stock'] = $levels->summary($this->currentStore());
        }

        return response()->json(['data' => $data]);
    }
}
