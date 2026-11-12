<?php

namespace App\Services\Live;

use App\Enums\CancelReason;
use App\Exceptions\BusinessRuleException;
use App\Models\LiveReservation;
use App\Models\LiveSession;
use App\Models\LiveSessionBill;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\Billing;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Lives payants, à part des abonnements (l'application est gratuite) : la vendeuse paie selon le nombre de commandes
 * de son live, par palier (0 à 10 gratuit, 11 à 30, 31 à 100, plus de 100). Toutes les commandes créées pendant le
 * live comptent, même annulées ensuite : le service (prendre la commande) a été rendu. Le prix est retiré à la fin
 * du live d'un crédit live rechargé à l'avance (PayDunya) ; sans crédit suffisant, le live suivant ne s'ouvre pas.
 * Des commandes en live sont offertes au départ (et des actions IA au 1er live) ; lives illimités 30 jours pour celles
 * qui ne veulent pas compter. Tout se règle dans la console ; tant que « lives payants » est éteint, tout est gratuit.
 */
class LiveBilling
{
    public const DEFAULTS = [
        'paid' => false,
        'free_upto' => 10,
        'tier2_upto' => 30,
        'tier2_price' => 500,
        'tier3_upto' => 100,
        'tier3_price' => 1000,
        'top_price' => 2000,
        'month_price' => 7000,
        'free_orders' => 300,
        'min_credit' => 500,
        'free_ai' => 20,
        'provider' => 'paydunya',
        // Assistant Boost : prix par commande venue d'une pub créée par l'assistant, retiré du même crédit
        'boost_paid' => false,
        'boost_price' => 300,
    ];

    /** Paiements des lives : recharges du crédit, 30 jours ; live_day = ancien pass 24 h (historique) */
    public const KINDS = ['live_credit', 'live_month', 'live_day'];

    /** Montants proposés pour recharger le crédit */
    public const RECHARGES = [2000, 5000, 10000];

    /** Un live resté ouvert plus longtemps est fermé (et compté) tout seul */
    public const MAX_HOURS = 12;

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $saved = json_decode((string) PlatformSetting::where('key', 'live.billing')->value('value'), true) ?: [];
        $s = [...self::DEFAULTS, ...array_intersect_key($saved, self::DEFAULTS)];

        return collect($s)->map(fn ($v, $k) => match ($k) {
            'paid', 'boost_paid' => (bool) $v,
            'provider' => (string) $v,
            default => (int) $v,
        })->all();
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): array
    {
        PlatformSetting::put('live.billing', json_encode([...$this->settings(), ...array_intersect_key($values, self::DEFAULTS)]));

        return $this->settings();
    }

    /**
     * Paliers affichés : de, à (null = sans limite), prix.
     *
     * @return list<array{from: int, to: ?int, price: int}>
     */
    public function tiers(): array
    {
        $s = $this->settings();

        return [
            ['from' => 0, 'to' => $s['free_upto'], 'price' => 0],
            ['from' => $s['free_upto'] + 1, 'to' => $s['tier2_upto'], 'price' => $s['tier2_price']],
            ['from' => $s['tier2_upto'] + 1, 'to' => $s['tier3_upto'], 'price' => $s['tier3_price']],
            ['from' => $s['tier3_upto'] + 1, 'to' => null, 'price' => $s['top_price']],
        ];
    }

    /** Prix d'un live selon son nombre de commandes payantes */
    public function priceFor(int $orders): int
    {
        foreach ($this->tiers() as $tier) {
            if ($tier['to'] === null || $orders <= $tier['to']) {
                return $tier['price'];
            }
        }

        return 0;
    }

    public function freeLeft(Store $store): int
    {
        return max(0, $this->settings()['free_orders'] + (int) $store->live_orders_bonus - (int) $store->live_orders_used);
    }

    /** Lives illimités en cours (30 jours, ou ancien pass 24 h) : fin la plus lointaine, sinon null */
    public function coveredUntil(Store $store): ?CarbonInterface
    {
        return collect([$store->live_pass_until, $store->live_month_until])->filter(fn ($at) => $at !== null && $at->isFuture())->sortDesc()->first();
    }

    /**
     * Commandes créées pendant ce live (même annulées ensuite), sauf les fausses : annulées pour faux numéro, fraude
     * ou doublon (des inconnus ne peuvent pas faire monter la facture du live avec de fausses réservations).
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
     * Pendant le live : commandes, combien sont couvertes par les commandes offertes, prix du palier atteint et
     * combien avant le palier suivant. Null quand le live n'est pas compté (lives payants éteints, illimités).
     *
     * @return array<string, mixed>|null
     */
    public function progress(LiveSession $session): ?array
    {
        if ($session->billing !== 'tier') {
            return null;
        }
        $store = Store::findOrFail($session->store_id);
        $billed = $session->billed_at !== null;
        if ($session->isWhatsApp() && ! $billed) {
            return $this->dayProgress($session, $store);
        }
        $orders = $billed ? (int) $session->billed_orders : $this->ordersOf($session);
        $offered = $billed ? (int) $session->billed_offered : min($orders, $this->freeLeft($store));
        $billable = max(0, $orders - $offered);
        $tiers = $this->tiers();
        $index = collect($tiers)->search(fn ($t) => $t['to'] === null || $billable <= $t['to']);
        $next = $tiers[$index + 1] ?? null;
        $price = $session->billed_amount ?? $tiers[$index]['price'];
        $credit = (int) $store->live_credit;
        $min = $this->settings()['min_credit'];

        return [
            'orders' => $orders,
            'offered' => $offered,
            'billable' => $billable,
            'tier' => $index + 1,
            'price' => $price,
            // Crédit live : pendant le live, ce qui manquera à la fermeture ; après, la recharge pour le live suivant
            'credit' => $credit,
            'shortfall' => $billed ? 0 : max(0, $price - $credit),
            'recharge' => $billed ? max(0, $min - $credit) : 0,
            'next' => $next ? ['in' => $next['from'] - $billable, 'price' => $next['price']] : null,
            'tiers' => $tiers,
            'billed' => $billed,
            // Commandes WhatsApp : jours comptés
            'per_day' => $session->isWhatsApp(),
            'days' => $session->isWhatsApp() ? $session->bills()->get()->map(fn (LiveSessionBill $b) => ['day' => $b->day->toDateString(), 'orders' => $b->orders, 'offered' => $b->offered, 'amount' => $b->amount])->values()->all() : [],
        ];
    }

    /**
     * Ce que voit la boutique : paliers, crédit, commandes offertes restantes, forfait en cours, paiements et lives
     * comptés.
     *
     * @return array<string, mixed>
     */
    public function overview(Store $store): array
    {
        $s = $this->settings();
        $store->refresh();
        $credit = (int) $store->live_credit;
        $free = $this->freeLeft($store);

        return [
            'paid' => $s['paid'],
            'tiers' => $this->tiers(),
            'month_price' => $s['month_price'],
            'min_credit' => $s['min_credit'],
            'credit' => $credit,
            'free_orders' => $s['free_orders'] + (int) $store->live_orders_bonus,
            'free_left' => $free,
            'free_ai' => $store->live_ai_gift_at === null ? $s['free_ai'] : 0,
            'month_until' => $this->coveredUntil($store)?->toIso8601String(),
            'recharges' => self::RECHARGES,
            // Le prochain live peut-il s'ouvrir : gratuit (éteint), illimité, oui, ou recharge demandée
            'next' => ! $s['paid'] ? 'free_mode' : ($this->coveredUntil($store) !== null ? 'covered' : ($free > 0 || $credit >= $s['min_credit'] ? 'ok' : 'pay')),
            'payments' => SubscriptionInvoice::forStore($store->id)->whereIn('kind', self::KINDS)->whereIn('status', ['paid', 'offered', 'pending'])
                ->latest('id')->limit(30)->get()->map(fn (SubscriptionInvoice $i) => [
                    'number' => $i->number, 'kind' => $i->kind, 'label' => self::label($i->kind), 'amount' => $i->amount,
                    'status' => $i->status, 'paid_at' => $i->paid_at?->toIso8601String(), 'created_at' => $i->created_at->toIso8601String(),
                ])->all(),
            'billed_lives' => LiveSession::forStore($store->id)->whereNotNull('billed_at')->where('billing', 'tier')->latest('billed_at')->limit(30)
                ->get(['id', 'title', 'started_at', 'billed_orders', 'billed_amount'])->map(fn (LiveSession $l) => [
                    'id' => $l->id, 'title' => $l->title, 'started_at' => $l->started_at?->toIso8601String(), 'orders' => $l->billed_orders, 'amount' => $l->billed_amount,
                ])->all(),
        ];
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            'live_month' => 'Lives illimités 30 jours',
            'live_day' => 'Lives pendant 24 h',
            default => 'Crédit live',
        };
    }

    /**
     * Droit d'ouvrir les commandes du live : illimité en cours, sinon commandes offertes restantes ou crédit d'au
     * moins le minimum ; le 1er live ajoute les actions IA offertes. Rien n'est compté quand les lives payants sont
     * éteints.
     */
    public function authorize(Store $store, LiveSession $session): void
    {
        $s = $this->settings();
        if (! $s['paid']) {
            $session->forceFill(['billing' => 'free_mode'])->save();

            return;
        }
        DB::transaction(function () use ($store, $session, $s) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($this->coveredUntil($store) !== null) {
                $session->forceFill(['billing' => 'month'])->save();

                return;
            }
            if ($this->freeLeft($store) <= 0 && (int) $store->live_credit < $s['min_credit']) {
                throw new BusinessRuleException('Recharge ton crédit live (au moins '.number_format($s['min_credit'], 0, ',', ' ').' F) pour ouvrir les commandes du live.', 'payment');
            }
            if ($store->live_ai_gift_at === null && $s['free_ai'] > 0) {
                $store->forceFill(['live_ai_gift_at' => now(), 'ai_credits' => (int) $store->ai_credits + $s['free_ai']])->save();
            }
            $session->forceFill(['billing' => 'tier'])->save();
        });
    }

    /**
     * Fin du live : commandes comptées et prix du palier retiré du crédit (commandes offertes d'abord). Une seule fois
     * par live ; le crédit peut passer sous zéro après un gros live, le live suivant attend alors une recharge.
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
            $price = 0;
            $offered = 0;
            if ($session->billing === 'tier') {
                $store = Store::whereKey($session->store_id)->lockForUpdate()->firstOrFail();
                $offered = min($orders, $this->freeLeft($store));
                $price = $this->priceFor($orders - $offered);
                $store->forceFill([
                    'live_orders_used' => (int) $store->live_orders_used + $offered,
                    'live_credit' => (int) $store->live_credit - $price,
                ])->save();
            }
            $session->forceFill(['billed_orders' => $orders, 'billed_offered' => $offered, 'billed_amount' => $price, 'billed_at' => now()])->save();
        });
    }

    /**
     * Commandes WhatsApp : chaque jour d'ouverture compté comme un live (commandes du jour, commandes offertes d'abord,
     * palier retiré du crédit live). Les jours passés sont comptés le soir venu ; à la fermeture ($closing), aussi le
     * jour en cours, puis les totaux sont gardés sur la session.
     */
    public function billWhatsApp(LiveSession $session, bool $closing): void
    {
        if ($session->started_at === null || $session->billed_at !== null) {
            return;
        }
        $tz = Store::find($session->store_id)?->timezone ?: config('app.timezone');
        $today = now($tz)->startOfDay();
        $last = $closing ? ($session->ended_at ?? now())->copy()->setTimezone($tz)->startOfDay() : $today->copy()->subDay();
        $done = LiveSessionBill::forStore($session->store_id)->where('live_session_id', $session->id)->pluck('day')
            ->map(fn ($d) => $d->toDateString())->all();
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
                $session->forceFill(['billed_orders' => (int) (clone $bills)->sum('orders'), 'billed_offered' => (int) (clone $bills)->sum('offered'),
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
            $offered = 0;
            $price = 0;
            // Ouvertes pendant des lives illimités : comptées comme d'habitude une fois les 30 jours finis
            if ($session->billing === 'tier' || ($session->billing === 'month' && $this->coveredUntil($store) === null)) {
                $offered = min($orders, $this->freeLeft($store));
                $price = $this->priceFor($orders - $offered);
                $store->forceFill([
                    'live_orders_used' => (int) $store->live_orders_used + $offered,
                    'live_credit' => (int) $store->live_credit - $price,
                ])->save();
            }
            LiveSessionBill::create(['store_id' => $store->id, 'live_session_id' => $session->id, 'day' => $day->toDateString(),
                'orders' => $orders, 'offered' => $offered, 'amount' => $price]);
        });
    }

    /**
     * Commandes WhatsApp en pause : lives payants, ni illimité, ni commande offerte, et crédit sous le minimum après
     * les jours déjà comptés. Les nouveaux codes ne sont plus pris ; les commandes enregistrées restent. Recharger
     * relance les commandes.
     */
    public function paused(LiveSession $session): bool
    {
        if (! $session->isWhatsApp() || $session->billing === 'free_mode' || ! $this->settings()['paid']) {
            return false;
        }
        $store = Store::find($session->store_id);

        return $store !== null && $this->coveredUntil($store) === null && $this->freeLeft($store) <= 0 && (int) $store->live_credit < $this->settings()['min_credit'];
    }

    /**
     * Commandes WhatsApp ouvertes : le jour en cours (comme un live) et les jours déjà comptés.
     *
     * @return array<string, mixed>|null
     */
    private function dayProgress(LiveSession $session, Store $store): ?array
    {
        $tz = $store->timezone ?: config('app.timezone');
        $orders = $this->ordersOf($session, now($tz));
        $offered = min($orders, $this->freeLeft($store));
        $billable = max(0, $orders - $offered);
        $tiers = $this->tiers();
        $index = collect($tiers)->search(fn ($t) => $t['to'] === null || $billable <= $t['to']);
        $next = $tiers[$index + 1] ?? null;
        $price = $tiers[$index]['price'];
        $credit = (int) $store->live_credit;

        return [
            'orders' => $orders,
            'offered' => $offered,
            'billable' => $billable,
            'tier' => $index + 1,
            'price' => $price,
            'credit' => $credit,
            'shortfall' => max(0, $price - $credit),
            'recharge' => 0,
            'next' => $next ? ['in' => $next['from'] - $billable, 'price' => $next['price']] : null,
            'tiers' => $tiers,
            'billed' => false,
            'per_day' => true,
            'days' => $session->bills()->get()->map(fn (LiveSessionBill $b) => ['day' => $b->day->toDateString(), 'orders' => $b->orders, 'offered' => $b->offered, 'amount' => $b->amount])->values()->all(),
        ];
    }

    /**
     * Lives restés ouverts plus de MAX_HOURS heures : fermés et comptés (une vendeuse qui oublie de fermer, ou qui
     * voudrait ne jamais payer).
     */
    public function closeStale(): int
    {
        $stale = LiveSession::withoutGlobalScopes()->where('status', 'live')->where('channel', 'live')->where('started_at', '<', now()->subHours(self::MAX_HOURS))->get();
        // Commandes WhatsApp : fermées à l'heure choisie, et chaque jour passé compté (le soir, comme un live)
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

    /**
     * Facture et page de paiement PayDunya : recharge du crédit (montant choisi) ou 30 jours illimités.
     */
    public function checkout(Store $store, string $kind, User $user, ?int $amount = null): SubscriptionInvoice
    {
        $s = $this->settings();
        if (! in_array($kind, ['live_credit', 'live_month'], true)) {
            throw new BusinessRuleException('Choisissez une recharge ou les lives illimités 30 jours.', 'kind');
        }
        if ($kind === 'live_credit' && ($amount === null || $amount < $s['min_credit'])) {
            throw new BusinessRuleException('Recharge d\'au moins '.number_format($s['min_credit'], 0, ',', ' ').' F.', 'amount');
        }
        $price = $kind === 'live_month' ? $s['month_price'] : (int) $amount;

        return app(Billing::class)->payLive($store, $user, $s['provider'], [
            'kind' => $kind,
            'billing_cycle' => $kind === 'live_month' ? 'monthly' : 'once',
            'amount' => $price,
        ], self::label($kind).' · '.$store->name);
    }

    /**
     * Paiement reçu (ou offert par la console) : crédit augmenté, ou 30 jours de plus à partir de maintenant ou de la
     * fin en cours.
     */
    public function activate(Store $store, SubscriptionInvoice $invoice): string
    {
        if ($invoice->kind === 'live_credit') {
            $store->forceFill(['live_credit' => (int) $store->live_credit + (int) $invoice->amount])->save();

            return 'Crédit live : '.number_format((int) $store->live_credit, 0, ',', ' ').' F';
        }
        if ($invoice->kind === 'live_month') {
            $from = $store->live_month_until?->isFuture() ? $store->live_month_until : now();
            $store->forceFill(['live_month_until' => $from->copy()->addDays(30)])->save();

            return 'Lives illimités jusqu\'au '.$store->live_month_until->locale('fr')->isoFormat('D MMMM à HH:mm');
        }
        $from = $store->live_pass_until?->isFuture() ? $store->live_pass_until : now();
        $store->forceFill(['live_pass_until' => $from->copy()->addDay()])->save();

        return 'Lives illimités jusqu\'à '.$store->live_pass_until->locale('fr')->isoFormat('dddd HH:mm');
    }
}
