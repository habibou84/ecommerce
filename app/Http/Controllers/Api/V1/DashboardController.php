<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\LiveSession;
use App\Models\Order;
use App\Services\Live\LiveSales;
use App\Services\Orders\OrderAlerts;
use App\Services\Reports\DailyAdvice;
use App\Services\Shipping\OutsideStock;
use App\Services\Stock\StockLevels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        // Vendre en live : en tête de l'accueil
        if ($user->can(Permission::OrdersManage->value)) {
            $data['live'] = $this->live();
        }

        // Boîte Messages : conversations pas encore lues
        if ($user->can(Permission::OrdersManage->value) || $user->can(Permission::OrdersConfirm->value)) {
            $data['unread_messages'] = Conversation::query()->where('unread_count', '>', 0)->count();
        }

        // Conseils du jour (les mêmes que ceux du rapport du soir) pour qui voit les finances
        if ($user->can(Permission::FinanceView->value)) {
            // Conseils tirés des chiffres des 30 derniers jours : recalculés au plus toutes les 30 minutes
            $store = $this->currentStore();
            $data['tips'] = Cache::remember("daily-advice:{$store->id}", 1800, fn () => app(DailyAdvice::class)->tips($store));
        }

        // Dehors en ce moment : argent et colis chez les livreurs de la boutique et chez les entreprises de livraison
        $outside = app(OutsideStock::class);
        if ($user->can(Permission::CouriersManage->value) || $user->can(Permission::FinanceView->value)) {
            $data['couriers'] = $outside->couriers($this->currentStore());
        }
        if ($user->can(Permission::CarriersManage->value) || $user->can(Permission::FinanceView->value)) {
            $data['carriers'] = $outside->carriers($this->currentStore());
        }

        if ($user->can(Permission::StockManage->value)) {
            $data['stock'] = $levels->summary($this->currentStore());
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Carte « Vendre en live » : le live en cours avec ses chiffres, sinon le live prêt à lancer, sinon une invitation
     * avec le résultat du dernier live.
     *
     * @return array<string, mixed>
     */
    private function live(): array
    {
        $store = $this->currentStore();
        $sales = app(LiveSales::class);
        $present = fn (LiveSession $s) => ['id' => $s->id, 'title' => $s->title, 'items_count' => $s->items()->count()];

        if (($current = LiveSales::current($store->id)) !== null) {
            $items = $current->items()->get();

            return ['state' => 'live', 'page_url' => $store->url('/live'), 'session' => $present($current) + [
                'started_at' => $current->started_at?->toIso8601String(),
                'summary' => $sales->summary($current),
                'sold_out' => $items->filter(fn ($item) => $sales->remaining($item) === 0)->count(),
            ]];
        }
        $draft = LiveSession::forStore($store->id)->where('status', 'draft')->latest('id')->first();
        $last = LiveSession::forStore($store->id)->where('status', 'ended')->latest('ended_at')->first();

        return [
            'state' => $draft !== null ? 'ready' : 'idle',
            'page_url' => $store->url('/live'),
            'session' => $draft !== null ? $present($draft) : null,
            'last' => $last !== null ? ['id' => $last->id, 'title' => $last->title, 'ended_at' => $last->ended_at?->toIso8601String()] + array_intersect_key($sales->summary($last), array_flip(['orders', 'amount'])) : null,
        ];
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
