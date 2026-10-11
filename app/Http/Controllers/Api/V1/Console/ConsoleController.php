<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SupportSession;
use App\Services\Billing\Billing;
use App\Services\Billing\PlanLimits;
use App\Services\Console\SupportSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;

/**
 * Console du super administrateur : chiffres de la plateforme, boutiques, abonnements, sessions de support.
 * Toutes les requêtes ignorent le filtre par boutique ; chaque action est journalisée (admin_actions).
 */
class ConsoleController extends Controller
{
    public function __construct(private readonly PlanLimits $limits, private readonly Billing $billing) {}

    public function stats(): JsonResponse
    {
        $since = now()->subDays(30);
        $orders = Order::withoutGlobalScopes()->where('created_at', '>=', $since);
        $monthly = Subscription::where('status', SubscriptionStatus::Active->value)->with('plan')->get()
            ->sum(fn (Subscription $s) => $s->billing_cycle === 'yearly' ? (int) round(($s->plan->yearly_price ?? 0) / 12) : $s->plan->monthly_price);

        return response()->json(['data' => [
            'stores' => Store::count(),
            'by_status' => Store::groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status'),
            'by_plan' => Subscription::whereIn('status', ['trialing', 'active', 'past_due'])->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->groupBy('plans.name', 'subscriptions.status')->selectRaw('plans.name AS plan, subscriptions.status, COUNT(*) AS total')->get(),
            'new_7d' => Store::where('created_at', '>=', now()->subDays(7))->count(),
            'new_30d' => Store::where('created_at', '>=', $since)->count(),
            // Boutiques actives : au moins une commande sur 30 jours
            'active_30d' => (clone $orders)->distinct('store_id')->count('store_id'),
            'orders_30d' => (clone $orders)->count(),
            'gmv_30d' => (int) (clone $orders)->where('status', 'completed')->sum('total'),
            'mrr' => $monthly,
            'billed_30d' => (int) SubscriptionInvoice::withoutGlobalScopes()->where('status', 'paid')->where('paid_at', '>=', $since)->sum('amount'),
            'trials_ending_7d' => Subscription::where('status', 'trialing')->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
            'signups' => Store::where('created_at', '>=', $since)->groupByRaw('DATE(created_at)')->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')->orderBy('day')->pluck('total', 'day'),
        ]]);
    }

    public function stores(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string'], 'plan' => ['nullable', 'string']]);
        $search = trim((string) $request->query('search'));
        $digits = preg_replace('/\D/', '', $search);

        $page = Store::query()->with(['owner:id,name,phone', 'currentSubscription.plan'])
            ->withCount(['orders as orders_30d' => fn ($q) => $q->withoutGlobalScopes()->where('created_at', '>=', now()->subDays(30))])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('slug', 'ilike', "%{$search}%")
                ->when(strlen($digits) >= 6, fn ($q) => $q->orWhereHas('owner', fn ($o) => $o->where('phone', 'like', '%'.substr($digits, -8).'%')))))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('plan'), fn ($q, $plan) => $plan === 'free'
                ? $q->whereDoesntHave('currentSubscription')
                : $q->whereHas('currentSubscription.plan', fn ($p) => $p->where('code', $plan)))
            ->latest('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Store $s) => $this->summary($s)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(int $store): JsonResponse
    {
        $model = Store::with(['owner', 'currentSubscription.plan'])->findOrFail($store);
        $since = now()->subDays(30);

        return response()->json(['data' => $this->summary($model) + [
            'suspended_reason' => $model->suspended_reason,
            'owner' => ['id' => $model->owner->id, 'name' => $model->owner->name, 'phone' => PhoneNumber::display($model->owner->phone), 'email' => $model->owner->email],
            'members' => $model->members()->where('status', 'active')->count(),
            'subscription' => ($sub = $model->currentSubscription) ? [
                'status' => $sub->status->value, 'billing_cycle' => $sub->billing_cycle, 'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
                'current_period_end' => $sub->current_period_end?->toIso8601String(), 'cancel_at_period_end' => $sub->cancel_at_period_end,
            ] : null,
            'usage' => $this->limits->usage($model),
            'revenue_30d' => (int) Order::forStore($model->id)->where('created_at', '>=', $since)->where('status', 'completed')->sum('total'),
            'invoices' => SubscriptionInvoice::forStore($model->id)->with('plan')->latest('id')->limit(20)->get()->map(fn ($i) => $this->billing->presentInvoice($i)),
            'domains' => StoreDomain::where('store_id', $model->id)->get(['hostname', 'is_primary', 'verified_at']),
            'support_sessions' => SupportSession::forStore($model->id)->with('admin:id,name')->latest('id')->limit(10)->get()->map(fn (SupportSession $s) => [
                'id' => $s->id, 'admin' => $s->admin?->name, 'reason' => $s->reason, 'started_at' => $s->started_at->toIso8601String(),
                'expires_at' => $s->expires_at->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(), 'ongoing' => $s->isOngoing(),
            ]),
            'actions' => AdminAction::where('store_id', $model->id)->with('admin:id,name')->latest('id')->limit(20)->get()->map(fn (AdminAction $a) => [
                'action' => $a->action, 'data' => $a->data, 'admin' => $a->admin?->name, 'created_at' => $a->created_at->toIso8601String(),
            ]),
        ]]);
    }

    public function suspend(Request $request, int $store): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motif']);
        $model = Store::findOrFail($store);
        $model->forceFill(['status' => StoreStatus::Suspended, 'suspended_reason' => $data['reason']])->save();
        $this->log($request, $model, 'suspend', $data);

        return $this->store($model->id);
    }

    public function reactivate(Request $request, int $store): JsonResponse
    {
        $model = Store::with('currentSubscription')->findOrFail($store);
        $status = match ($model->currentSubscription?->status) {
            SubscriptionStatus::Trialing => StoreStatus::Trial,
            SubscriptionStatus::PastDue => StoreStatus::PastDue,
            default => StoreStatus::Active,
        };
        $model->forceFill(['status' => $status, 'suspended_reason' => null])->save();
        $this->log($request, $model, 'reactivate');

        return $this->store($model->id);
    }

    /**
     * Essai prolongé (ou nouvel essai de l'offre d'essai si la boutique n'en a plus).
     */
    public function extendTrial(Request $request, int $store): JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'between:1,90']], [], ['days' => 'jours']);
        $model = Store::findOrFail($store);

        DB::transaction(function () use ($model, $data) {
            $trial = $model->currentSubscription()->first();
            if ($trial?->status === SubscriptionStatus::Trialing) {
                $end = $trial->trial_ends_at->max(now())->addDays($data['days']);
                $trial->update(['trial_ends_at' => $end, 'current_period_end' => $end]);
            } else {
                abort_if($trial !== null, 422, 'La boutique a un abonnement payant en cours : offrez plutôt des mois.');
                $model->subscriptions()->create([
                    'plan_id' => Plan::where('code', config('platform.trial_plan'))->value('id'), 'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => now()->addDays($data['days']), 'current_period_start' => now(), 'current_period_end' => now()->addDays($data['days']),
                ]);
            }
        });
        $this->log($request, $model, 'extend_trial', $data);

        return $this->store($model->id);
    }

    public function offer(Request $request, int $store): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', Rule::exists('plans', 'code')], 'months' => ['required', 'integer', 'between:1,24']], [], ['plan' => 'offre', 'months' => 'mois']);
        $model = Store::findOrFail($store);
        $plan = Plan::where('code', $data['plan'])->firstOrFail();
        abort_if($plan->code === 'free', 422, 'Choisissez une offre payante.');
        $this->billing->offer($model, $plan, $data['months'], $request->user());
        $this->log($request, $model, 'offer', $data);

        return $this->store($model->id);
    }

    public function support(Request $request, int $store, SupportSessions $sessions): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255'], 'minutes' => ['nullable', 'integer', 'between:5,240']], [], ['reason' => 'motif']);
        $model = Store::findOrFail($store);
        abort_unless($model->isOpen(), 422, 'Boutique suspendue ou fermée : réactivez-la d\'abord.');
        [$session, $url] = $sessions->start($model, $request->user(), $data['reason'], $data['minutes'] ?? config('platform.support_session_minutes'), $request->ip());

        return response()->json(['data' => ['id' => $session->id, 'expires_at' => $session->expires_at->toIso8601String()], 'redirect' => $url], 201);
    }

    public function endSupport(Request $request, int $session, SupportSessions $sessions): JsonResponse
    {
        $model = SupportSession::withoutGlobalScopes()->findOrFail($session);
        $sessions->end($model, $request->user());

        return response()->json(['message' => 'Session terminée.']);
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => Plan::orderBy('sort')->get(),
            'limits' => PlanLimits::LABELS,
            'features' => PlanLimits::FEATURES,
        ]);
    }

    public function updatePlan(Request $request, int $plan): JsonResponse
    {
        $model = Plan::findOrFail($plan);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'monthly_price' => ['sometimes', 'integer', 'min:0', 'multiple_of:5'],
            'yearly_price' => ['sometimes', 'nullable', 'integer', 'min:0', 'multiple_of:5'],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['limits'])) {
            $data['limits'] = array_intersect_key($data['limits'], PlanLimits::LABELS) + array_fill_keys(array_keys(PlanLimits::LABELS), null);
        }
        if (isset($data['features'])) {
            $data['features'] = array_filter(array_intersect_key($data['features'], PlanLimits::FEATURES));
        }
        $before = $model->only(array_keys($data));
        $model->update($data);
        $this->log($request, null, 'plan_updated', ['plan' => $model->code, 'before' => $before, 'after' => $model->only(array_keys($data))]);

        return response()->json(['data' => $model]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $page = SubscriptionInvoice::withoutGlobalScopes()->with(['plan', 'store:id,name,slug'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (SubscriptionInvoice $i) => $this->billing->presentInvoice($i) + ['store' => ['id' => $i->store?->id, 'name' => $i->store?->name]]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Store $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'url' => rtrim($s->url(), '/'),
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'plan' => $s->currentSubscription?->plan?->name ?? 'Gratuit',
            'subscription_status' => $s->currentSubscription?->status->value,
            'owner_name' => $s->owner?->name,
            'owner_phone' => PhoneNumber::display($s->owner?->phone),
            'orders_30d' => $s->orders_30d ?? Order::forStore($s->id)->where('created_at', '>=', now()->subDays(30))->count(),
            'created_at' => $s->created_at->toIso8601String(),
        ];
    }

    private function log(Request $request, ?Store $store, string $action, array $data = []): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => $store?->id, 'action' => $action, 'data' => $data ?: null, 'ip' => $request->ip()]);
    }
}
