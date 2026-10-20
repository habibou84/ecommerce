<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Order;
use App\Services\Orders\OrderAlerts;
use App\Services\Reports\DailyAdvice;
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
                // Hier à la même heure : la comparaison reste juste en pleine journée
                'yesterday_count' => Order::whereBetween('created_at', [now()->subDay()->startOfDay(), now()->subDay()])->count(),
                'yesterday_amount' => (int) Order::whereBetween('created_at', [now()->subDay()->startOfDay(), now()->subDay()])
                    ->where('status', '!=', OrderStatus::Cancelled->value)->sum('total'),
                'to_confirm' => Order::where('status', OrderStatus::PendingConfirmation->value)->count(),
                'callbacks_due' => Order::where('status', OrderStatus::PendingConfirmation->value)->where('callback_at', '<=', now())->count(),
                'to_ship' => Order::whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Processing->value])->count(),
                // Files des alertes : sans livreur, pas ramassées, problèmes de livraison sans suite
                'waiting' => OrderAlerts::counts(),
                'month_revenue' => (int) (clone $month)->sum('total'),
                'month_delivered' => (clone $month)->count(),
            ];
            $data['week'] = $this->week();
        }

        // Boîte Messages : conversations pas encore lues
        if ($user->can(Permission::OrdersManage->value) || $user->can(Permission::OrdersConfirm->value)) {
            $data['unread_messages'] = Conversation::query()->where('unread_count', '>', 0)->count();
        }

        // Conseils du jour (les mêmes que ceux du rapport du soir) pour qui voit les finances
        if ($user->can(Permission::FinanceView->value)) {
            $data['tips'] = app(DailyAdvice::class)->tips($this->currentStore());
        }

        if ($user->can(Permission::StockManage->value)) {
            $data['stock'] = $levels->summary($this->currentStore());
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Ventes des 7 derniers jours (aujourd'hui compris) : montant des commandes non annulées et nombre, jour par jour.
     *
     * @return list<array{date: string, amount: int, count: int}>
     */
    private function week(): array
    {
        $from = now()->subDays(6)->startOfDay();
        $orders = Order::where('created_at', '>=', $from)->where('status', '!=', OrderStatus::Cancelled->value)
            ->get(['created_at', 'total'])
            ->groupBy(fn (Order $o) => $o->created_at->toDateString());

        return collect(range(0, 6))->map(function (int $i) use ($from, $orders) {
            $date = $from->copy()->addDays($i)->toDateString();

            return [
                'date' => $date,
                'amount' => (int) ($orders->get($date)?->sum('total') ?? 0),
                'count' => $orders->get($date)?->count() ?? 0,
            ];
        })->all();
    }
}
