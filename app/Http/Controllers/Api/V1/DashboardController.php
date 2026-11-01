<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\LiveSession;
use App\Models\Order;
use App\Models\WhatsappAccount;
use App\Services\Live\LiveSales;
use App\Services\Orders\OrderAlerts;
use App\Services\Reports\DailyAdvice;
use App\Services\Reports\DailyReport;
use App\Services\Shipping\OutsideStock;
use App\Services\Stock\StockLevels;
use App\Services\WhatsApp\MetaHealth;
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
            // Ce qui distingue Jibiat Marchand : on vend partout (canaux) et sur sa propre boutique en ligne
            $data['channels'] = $this->channels();
        }

        // Vendre en live : en tête de l'accueil
        if ($user->can(Permission::OrdersManage->value)) {
            $data['live'] = $this->live();
            $data['shop'] = $this->shop();
        }

        // De l'argent à aller chercher : clientes fidèles sans commande depuis 30 jours, promotions en cours
        if ($user->can(Permission::CustomersView->value)) {
            $data['to_relaunch'] = Customer::toRelaunch()->count();
        }
        $data['promotions'] = $this->promotions();

        // WhatsApp bloqué (ou en partie) par Meta : à régler dans Réglages › WhatsApp
        if ($user->can(Permission::StoreManage->value)) {
            $account = WhatsappAccount::query()->whereIn('meta_state', ['blocked', 'partial'])->first();
            if ($account !== null) {
                $meta = MetaHealth::present($account);
                $data['whatsapp_meta'] = ['state' => $meta['state'], 'reason' => $meta['reason']];
            }
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
            // Bénéfice du mois (ventes moins achats, livraison et dépenses), le même que dans le rapport du soir
            $data['profit'] = Cache::remember("month-profit:{$store->id}", 1800, fn () => app(DailyReport::class)->monthProfit($store, now()->toImmutable()));
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

    /**
     * Ventes du jour par canal (WhatsApp, Facebook, live, boutique en ligne…), le plus fort en premier.
     * Saisie à la main, import et API sont regroupés dans « Autre ».
     *
     * @return list<array{source: string, label: string, amount: int, count: int}>
     */
    private function channels(): array
    {
        $other = [OrderSource::Manual->value, OrderSource::Import->value, OrderSource::Api->value];

        return Order::where('created_at', '>=', now()->startOfDay())->where('status', '!=', OrderStatus::Cancelled->value)
            ->get(['source', 'total'])
            ->groupBy(fn (Order $o) => in_array($o->source?->value, $other, true) || $o->source === null ? 'other' : $o->source->value)
            ->map(fn ($orders, string $source) => [
                'source' => $source,
                'label' => $source === 'other' ? 'Autre' : OrderSource::from($source)->label(),
                'amount' => (int) $orders->sum('total'),
                'count' => $orders->count(),
            ])
            ->sortByDesc('amount')->values()->all();
    }

    /**
     * Boutique en ligne : son adresse, ses commandes du jour et du mois, et les paniers laissés à relancer
     * (même règle que la page Paniers abandonnés : plus d'une heure, moins de 7 jours, avec un téléphone).
     *
     * @return array{url: string, today: int, month: int, abandoned: int}
     */
    private function shop(): array
    {
        $site = Order::where('source', OrderSource::OnlineStore->value);

        return [
            'url' => $this->currentStore()->url('/'),
            'today' => (clone $site)->where('created_at', '>=', now()->startOfDay())->count(),
            'month' => (clone $site)->where('created_at', '>=', now()->startOfMonth())->count(),
            'abandoned' => Cart::where('status', 'active')->whereNotNull('phone')->where('last_activity_at', '<=', now()->subHour())
                ->where('last_activity_at', '>=', now()->subDays(7))->whereHas('items')->count(),
        ];
    }

    /**
     * Promotions en cours : leur nombre et celle qui se termine le plus tôt.
     *
     * @return array{active: int, ending: ?array{title: string, ends_at: string}}
     */
    private function promotions(): array
    {
        $active = Discount::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
        $ending = (clone $active)->whereNotNull('ends_at')->orderBy('ends_at')->first();

        return [
            'active' => (clone $active)->count(),
            'ending' => $ending ? ['title' => $ending->title ?: $ending->code, 'ends_at' => $ending->ends_at->toIso8601String()] : null,
        ];
    }
}
