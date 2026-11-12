<?php

namespace App\Services\Billing;

use App\Enums\CancelReason;
use App\Exceptions\BusinessRuleException;
use App\Models\AiUsage;
use App\Models\Boost;
use App\Models\LiveReservation;
use App\Models\LiveSession;
use App\Models\LiveSessionBill;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Live\LiveSales;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Solde unique de la boutique : des commandes et des actions IA, achetés ensemble. Payantes : les commandes prises en
 * live ou par WhatsApp, et celles venues d'un boost Meta (1 commande chacune, réglable). Gratuites : la boutique en
 * ligne et la saisie à la main. Deux façons de recharger : un pack (commandes + IA incluse) ou l'illimité 30 jours
 * (IA incluse). Des commandes et des actions IA sont offertes au départ. Tant que « commandes payantes » est éteint
 * dans la console, rien n'est compté.
 *
 * Les commandes d'un live sont retirées à la fin du live (même annulées ensuite, sauf les fausses), celles des
 * commandes WhatsApp chaque soir, celles des boosts à chaque relecture. Le solde peut passer sous zéro après un gros
 * live : le live suivant attend alors une recharge ; un live en cours n'est jamais coupé.
 */
class Credits
{
    public const DEFAULTS = [
        'paid' => false,
        'pack1_orders' => 100, 'pack1_price' => 2500, 'pack1_ai' => 100,
        'pack2_orders' => 300, 'pack2_price' => 6000, 'pack2_ai' => 300,
        'pack3_orders' => 1000, 'pack3_price' => 15000, 'pack3_ai' => 1000,
        'month_price' => 7000, 'month_ai' => 1000,
        'welcome_orders' => 50, 'welcome_ai' => 20,
        'boost_weight' => 1,
        'min_orders' => 10,
        'alert_at' => 10,
        'provider' => 'paydunya',
    ];

    /** Paiements du solde (et anciens paiements des lives et de l'IA, pour l'historique) */
    public const KINDS = ['credits_pack', 'credits_month', 'live_credit', 'live_month', 'live_day', 'ai_pack'];

    /** Pack mis en avant */
    public const ADVISED = 2;

    /** Un live resté ouvert plus longtemps est fermé (et compté) tout seul */
    public const MAX_HOURS = 12;

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $saved = json_decode((string) PlatformSetting::where('key', 'billing.credits')->value('value'), true) ?: [];

        return collect([...self::DEFAULTS, ...array_intersect_key($saved, self::DEFAULTS)])->map(fn ($v, $k) => match ($k) {
            'paid' => (bool) $v,
            'provider' => (string) $v,
            default => (int) $v,
        })->all();
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): array
    {
        PlatformSetting::put('billing.credits', json_encode([...$this->settings(), ...array_intersect_key($values, self::DEFAULTS)]));

        return $this->settings();
    }

    /**
     * Les 3 packs : commandes, prix, IA incluse, prix par commande.
     *
     * @return list<array{index: int, orders: int, price: int, ai: int, unit: int, advised: bool}>
     */
    public function packs(): array
    {
        $s = $this->settings();

        return array_map(fn (int $i) => [
            'index' => $i, 'orders' => $s["pack{$i}_orders"], 'price' => $s["pack{$i}_price"], 'ai' => $s["pack{$i}_ai"],
            'unit' => $s["pack{$i}_orders"] > 0 ? (int) round($s["pack{$i}_price"] / $s["pack{$i}_orders"]) : 0, 'advised' => $i === self::ADVISED,
        ], [1, 2, 3]);
    }

    /** Commandes et actions IA offertes au départ, une seule fois par boutique */
    public function welcome(Store $store): void
    {
        if ($store->credits_welcomed_at !== null) {
            return;
        }
        $s = $this->settings();
        DB::transaction(function () use ($store, $s) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($locked->credits_welcomed_at === null) {
                $locked->forceFill([
                    'credits_welcomed_at' => now(),
                    'order_credits' => (int) $locked->order_credits + $s['welcome_orders'],
                    'ai_credits' => (int) $locked->ai_credits + $s['welcome_ai'],
                ])->save();
            }
        });
        $store->refresh();
    }

    /** Illimité 30 jours en cours (ou ancien pass 24 h) : fin la plus lointaine, sinon null */
    public function coveredUntil(Store $store): ?CarbonInterface
    {
        return collect([$store->live_pass_until, $store->live_month_until])->filter(fn ($at) => $at !== null && $at->isFuture())->sortDesc()->first();
    }

    /** Ces commandes sont-elles comptées pour cette boutique en ce moment ? */
    public function counting(Store $store): bool
    {
        return $this->settings()['paid'] && $this->coveredUntil($store) === null;
    }

    /** Retire des commandes du solde (sous verrou ; peut passer sous zéro) */
    public function debit(Store $store, int $orders): void
    {
        if ($orders > 0) {
            Store::whereKey($store->id)->decrement('order_credits', $orders);
        }
    }

    /**
     * Commandes créées pendant ce live (même annulées ensuite), sauf les fausses : annulées pour faux numéro, fraude
     * ou doublon (des inconnus ne peuvent pas faire monter le compte avec de fausses réservations).
     */
    public function ordersOf(LiveSession $session, ?CarbonInterface $day = null): int
    {
        $fake = [CancelReason::WrongNumber->value, CancelReason::Fraud->value, CancelReason::Duplicate->value];

        return LiveReservation::forStore($session->store_id)->where('live_session_id', $session->id)->whereNotNull('order_id')
            ->whereHas('order', fn ($o) => $o->where(fn ($q) => $q->whereNull('cancel_reason')->orWhereNotIn('cancel_reason', $fake))
                // Commandes WhatsApp : seulement celles créées ce jour-là (heure de la boutique)
                ->when($day !== null, fn ($q) => $q->whereBetween('created_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])))
            ->distinct()->count('order_id');
    }

    /**
     * Ouvrir les commandes d'un live : illimité en cours, sinon au moins le minimum de commandes dans le solde. Rien
     * n'est compté quand les commandes payantes sont éteintes.
     */
    public function authorize(Store $store, LiveSession $session): void
    {
        $s = $this->settings();
        if (! $s['paid']) {
            $session->forceFill(['billing' => 'free_mode'])->save();

            return;
        }
        $this->welcome($store);
        DB::transaction(function () use ($store, $session, $s) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($this->coveredUntil($store) !== null) {
                $session->forceFill(['billing' => 'month'])->save();

                return;
            }
            if ((int) $store->order_credits < $s['min_orders']) {
                throw new BusinessRuleException('Il te faut au moins '.$s['min_orders'].' commandes dans ton solde pour ouvrir les commandes. Recharge ton solde.', 'payment');
            }
            $session->forceFill(['billing' => 'credits'])->save();
        });
    }

    /**
     * Fin du live : ses commandes retirées du solde, une seule fois. Commandes WhatsApp : jour par jour.
     */
    public function bill(LiveSession $session): void
    {
        if ($session->isWhatsApp()) {
            $this->billWhatsApp($session, true);

            return;
        }
        DB::transaction(function () use ($session) {
            $session = LiveSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->billed_at !== null) {
                return;
            }
            $orders = $this->ordersOf($session);
            $used = 0;
            if ($session->billing === 'credits') {
                $store = Store::whereKey($session->store_id)->lockForUpdate()->firstOrFail();
                $used = $orders;
                $this->debit($store, $used);
            }
            $session->forceFill(['billed_orders' => $orders, 'billed_offered' => 0, 'billed_amount' => $used, 'billed_at' => now()])->save();
        });
    }

    /**
     * Commandes WhatsApp : chaque jour d'ouverture compté le soir venu (commandes du jour retirées du solde) ; à la
     * fermeture ($closing), aussi le jour en cours, puis les totaux sont gardés sur la session.
     */
    public function billWhatsApp(LiveSession $session, bool $closing): void
    {
        if ($session->started_at === null || $session->billed_at !== null) {
            return;
        }
        $tz = Store::find($session->store_id)?->timezone ?: config('app.timezone');
        $last = $closing ? ($session->ended_at ?? now())->copy()->setTimezone($tz)->startOfDay() : now($tz)->startOfDay()->subDay();
        $done = LiveSessionBill::forStore($session->store_id)->where('live_session_id', $session->id)->pluck('day')->map(fn ($d) => $d->toDateString())->all();
        for ($day = $session->started_at->copy()->setTimezone($tz)->startOfDay(); $day->lte($last); $day->addDay()) {
            if (! in_array($day->toDateString(), $done, true)) {
                $this->billDay($session, $day->copy());
            }
        }
        if ($closing) {
            DB::transaction(function () use ($session) {
                $session = LiveSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($session->billed_at !== null) {
                    return;
                }
                $bills = LiveSessionBill::forStore($session->store_id)->where('live_session_id', $session->id);
                $session->forceFill(['billed_orders' => (int) (clone $bills)->sum('orders'), 'billed_offered' => 0,
                    'billed_amount' => (int) (clone $bills)->sum('amount'), 'billed_at' => now()])->save();
            });
        }
    }

    private function billDay(LiveSession $session, CarbonInterface $day): void
    {
        DB::transaction(function () use ($session, $day) {
            $store = Store::whereKey($session->store_id)->lockForUpdate()->firstOrFail();
            if (LiveSessionBill::forStore($store->id)->where('live_session_id', $session->id)->whereDate('day', $day->toDateString())->exists()) {
                return;
            }
            $orders = $this->ordersOf($session, $day);
            // Ouvertes pendant l'illimité : comptées comme d'habitude une fois les 30 jours finis
            $used = $session->billing === 'credits' || ($session->billing === 'month' && $this->coveredUntil($store) === null) ? $orders : 0;
            $this->debit($store, $used);
            LiveSessionBill::create(['store_id' => $store->id, 'live_session_id' => $session->id, 'day' => $day->toDateString(),
                'orders' => $orders, 'offered' => 0, 'amount' => $used]);
        });
    }

    /**
     * Commandes WhatsApp en pause : commandes payantes, pas d'illimité, et solde à zéro après les jours déjà comptés.
     * Les nouveaux codes ne sont plus pris ; recharger relance les commandes.
     */
    public function paused(LiveSession $session): bool
    {
        if (! $session->isWhatsApp() || $session->billing === 'free_mode' || ! $this->settings()['paid']) {
            return false;
        }
        $store = Store::find($session->store_id);

        return $store !== null && $this->coveredUntil($store) === null && (int) $store->order_credits <= 0;
    }

    /**
     * Pendant le live (ou après) : commandes prises, solde, solde après le live. Null quand rien n'est compté.
     *
     * @return array<string, mixed>|null
     */
    public function progress(LiveSession $session): ?array
    {
        if ($session->billing !== 'credits') {
            return null;
        }
        $store = Store::findOrFail($session->store_id);
        $billed = $session->billed_at !== null;
        $days = $session->isWhatsApp() ? $session->bills()->get() : collect();
        $orders = match (true) {
            $billed => (int) $session->billed_orders,
            $session->isWhatsApp() => $this->ordersOf($session, now($store->timezone ?: config('app.timezone'))),
            default => $this->ordersOf($session),
        };
        $balance = (int) $store->order_credits;
        $min = $this->settings()['min_orders'];

        return [
            'orders' => $orders,
            'used' => $billed ? (int) $session->billed_amount : $orders,
            'balance' => $balance,
            // Pendant : le solde quand ces commandes seront retirées ; après : le solde actuel
            'after' => $billed ? $balance : $balance - $orders,
            'recharge' => $billed && $balance < $min,
            'min_orders' => $min,
            'billed' => $billed,
            'per_day' => $session->isWhatsApp(),
            'days' => $days->map(fn (LiveSessionBill $b) => ['day' => $b->day->toDateString(), 'orders' => $b->orders, 'used' => $b->amount])->values()->all(),
        ];
    }

    /**
     * Ce que voit la boutique : solde, packs et illimité, ce qui a été utilisé ce mois-ci, paiements.
     *
     * @return array<string, mixed>
     */
    public function overview(Store $store): array
    {
        $s = $this->settings();
        if ($s['paid']) {
            $this->welcome($store);
        }
        $store->refresh();
        $orders = (int) $store->order_credits;
        $since = now()->startOfMonth();
        $covered = $this->coveredUntil($store);

        $lives = LiveSession::forStore($store->id)->where('billing', 'credits')->where('channel', 'live')->where('billed_at', '>=', $since)
            ->latest('billed_at')->get(['id', 'title', 'started_at', 'billed_amount'])
            ->map(fn (LiveSession $l) => ['kind' => 'live', 'label' => $l->title ?: 'Live', 'at' => $l->started_at?->toIso8601String(), 'orders' => -(int) $l->billed_amount]);
        $whatsapp = (int) LiveSessionBill::forStore($store->id)->where('day', '>=', $since->toDateString())->sum('amount');
        $boosts = Boost::forStore($store->id)->where('credits_used', '>', 0)->where('updated_at', '>=', $since)->get(['id', 'name', 'credits_used'])
            ->map(fn (Boost $b) => ['kind' => 'boost', 'label' => $b->name, 'at' => null, 'orders' => -(int) $b->credits_used]);
        $ai = AiUsage::where('store_id', $store->id)->where('succeeded', true)->where('created_at', '>=', $since)->count();

        return [
            'paid' => $s['paid'],
            'orders' => $orders,
            'ai' => (int) $store->ai_credits,
            'low' => $s['paid'] && $covered === null && $orders <= $s['alert_at'],
            'month_until' => $covered?->toIso8601String(),
            'packs' => $this->packs(),
            'month' => ['price' => $s['month_price'], 'ai' => $s['month_ai']],
            'min_orders' => $s['min_orders'],
            'boost_weight' => $s['boost_weight'],
            // Le prochain live peut-il s'ouvrir : rien n'est compté, illimité, oui, ou recharge demandée
            'next' => ! $s['paid'] ? 'free_mode' : ($covered !== null ? 'covered' : ($orders >= $s['min_orders'] ? 'ok' : 'pay')),
            'usage' => [
                'lives' => $lives->values()->all(),
                'whatsapp' => $whatsapp,
                'boosts' => $boosts->values()->all(),
                'ai' => $ai,
            ],
            'payments' => SubscriptionInvoice::forStore($store->id)->whereIn('kind', self::KINDS)->whereIn('status', ['paid', 'offered', 'pending'])
                ->latest('id')->limit(30)->get()->map(fn (SubscriptionInvoice $i) => [
                    'number' => $i->number, 'kind' => $i->kind, 'label' => self::label($i), 'amount' => $i->amount,
                    'status' => $i->status, 'paid_at' => $i->paid_at?->toIso8601String(), 'created_at' => $i->created_at->toIso8601String(),
                ])->all(),
        ];
    }

    public static function label(SubscriptionInvoice $invoice): string
    {
        return match ($invoice->kind) {
            'credits_pack' => 'Pack de '.number_format((int) $invoice->credit_orders, 0, ',', ' ').' commandes'.($invoice->ai_actions ? ' + '.$invoice->ai_actions.' IA' : ''),
            'credits_month', 'live_month' => 'Illimité 30 jours',
            'live_day' => 'Lives pendant 24 h',
            'ai_pack' => 'Recharge IA : '.$invoice->ai_actions.' actions',
            default => 'Crédit live',
        };
    }

    /**
     * Facture et page de paiement PayDunya : un pack (1, 2 ou 3) ou l'illimité 30 jours.
     */
    public function checkout(Store $store, string $kind, User $user, ?int $pack = null): SubscriptionInvoice
    {
        $s = $this->settings();
        if ($kind === 'month') {
            $data = ['kind' => 'credits_month', 'billing_cycle' => 'monthly', 'amount' => $s['month_price'], 'ai_actions' => $s['month_ai']];
        } else {
            $chosen = collect($this->packs())->firstWhere('index', $pack);
            if (! $chosen || $chosen['orders'] <= 0) {
                throw new BusinessRuleException('Choisis un pack.', 'pack');
            }
            $data = ['kind' => 'credits_pack', 'billing_cycle' => 'once', 'amount' => $chosen['price'], 'credit_orders' => $chosen['orders'], 'ai_actions' => $chosen['ai']];
        }
        $invoice = new SubscriptionInvoice($data);

        return app(Billing::class)->payCredits($store, $user, $s['provider'], $data, self::label($invoice).' · '.$store->name);
    }

    /**
     * Paiement reçu (ou offert par la console) : commandes et IA ajoutées, ou 30 jours de plus à partir de maintenant
     * ou de la fin en cours. Les anciens paiements encore en attente (crédit live, recharge IA) sont convertis.
     */
    public function activate(Store $store, SubscriptionInvoice $invoice): string
    {
        $ai = (int) $invoice->ai_actions;
        switch ($invoice->kind) {
            case 'credits_pack':
            case 'live_credit':
                $orders = $invoice->kind === 'credits_pack' ? (int) $invoice->credit_orders : intdiv((int) $invoice->amount, max(1, $this->packs()[0]['unit']));
                $store->forceFill(['order_credits' => (int) $store->order_credits + $orders, 'ai_credits' => (int) $store->ai_credits + $ai])->save();

                return $orders.' commandes'.($ai ? ' et '.$ai.' actions IA' : '').' ajoutées : solde de '.$store->order_credits.' commandes';
            case 'ai_pack':
                $store->forceFill(['ai_credits' => (int) $store->ai_credits + $ai])->save();

                return $ai.' actions IA ajoutées';
            case 'live_day':
                $from = $store->live_pass_until?->isFuture() ? $store->live_pass_until : now();
                $store->forceFill(['live_pass_until' => $from->copy()->addDay()])->save();

                return 'Illimité jusqu\'à '.$store->live_pass_until->locale('fr')->isoFormat('dddd HH:mm');
            default:
                $from = $store->live_month_until?->isFuture() ? $store->live_month_until : now();
                $store->forceFill(['live_month_until' => $from->copy()->addDays(30), 'ai_credits' => (int) $store->ai_credits + $ai])->save();

                return 'Illimité jusqu\'au '.$store->live_month_until->locale('fr')->isoFormat('D MMMM à HH:mm').($ai ? ', '.$ai.' actions IA ajoutées' : '');
        }
    }

    /**
     * Lives restés ouverts plus de MAX_HOURS heures : fermés et comptés ; commandes WhatsApp comptées chaque soir.
     */
    public function closeStale(): int
    {
        $stale = LiveSession::withoutGlobalScopes()->where('status', 'live')->where('channel', 'live')->where('started_at', '<', now()->subHours(self::MAX_HOURS))->get();
        $stale = $stale->merge(LiveSession::withoutGlobalScopes()->where('status', 'live')->where('channel', LiveSession::CHANNEL_WHATSAPP)
            ->whereNotNull('orders_until')->where('orders_until', '<=', now())->get());
        $stale->each(fn (LiveSession $session) => app(LiveSales::class)->end($session));
        LiveSession::withoutGlobalScopes()->where('status', 'live')->where('channel', LiveSession::CHANNEL_WHATSAPP)->get()
            ->each(fn (LiveSession $session) => $this->billWhatsApp($session, false));
        // Commandes gardées actives après le live : à l'heure dite, elles s'arrêtent et le live est compté
        $expired = LiveSession::withoutGlobalScopes()->where('status', 'ended')->whereNull('billed_at')
            ->whereNotNull('orders_until')->where('orders_until', '<=', now())->get();
        $expired->each(fn (LiveSession $session) => $this->bill($session));

        return $stale->count() + $expired->count();
    }
}
