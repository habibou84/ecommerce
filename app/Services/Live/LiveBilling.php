<?php

namespace App\Services\Live;

use App\Exceptions\BusinessRuleException;
use App\Models\LiveSession;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\Billing;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Lives payants, à part des abonnements (l'application est gratuite, les lives se paient) : lives offerts au départ
 * (avec des actions IA au 1er live), puis un pass de 24 h ou des lives illimités 30 jours, payés par PayDunya au
 * moment de démarrer. Tout se règle dans la console ; tant que « lives payants » est éteint, les lives sont gratuits.
 */
class LiveBilling
{
    public const DEFAULTS = [
        'paid' => false,
        'day_price' => 500,
        'month_price' => 7000,
        'free_lives' => 10,
        'free_ai' => 20,
        'provider' => 'paydunya',
    ];

    public const KINDS = ['live_day', 'live_month'];

    /** @return array{paid: bool, day_price: int, month_price: int, free_lives: int, free_ai: int, provider: string} */
    public function settings(): array
    {
        $saved = json_decode((string) PlatformSetting::where('key', 'live.billing')->value('value'), true) ?: [];
        $s = [...self::DEFAULTS, ...array_intersect_key($saved, self::DEFAULTS)];

        return ['paid' => (bool) $s['paid'], 'day_price' => (int) $s['day_price'], 'month_price' => (int) $s['month_price'],
            'free_lives' => (int) $s['free_lives'], 'free_ai' => (int) $s['free_ai'], 'provider' => (string) $s['provider']];
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): array
    {
        PlatformSetting::put('live.billing', json_encode([...$this->settings(), ...array_intersect_key($values, self::DEFAULTS)]));

        return $this->settings();
    }

    public function freeLeft(Store $store): int
    {
        return max(0, $this->settings()['free_lives'] + (int) $store->live_free_bonus - (int) $store->live_free_used);
    }

    /** Pass 24 h ou lives illimités en cours : fin la plus lointaine, sinon null */
    public function coveredUntil(Store $store): ?CarbonInterface
    {
        return collect([$store->live_pass_until, $store->live_month_until])->filter(fn ($at) => $at !== null && $at->isFuture())->sortDesc()->first();
    }

    /**
     * Ce que voit la boutique : prix, lives offerts restants, pass ou forfait en cours, paiements.
     *
     * @return array<string, mixed>
     */
    public function overview(Store $store): array
    {
        $s = $this->settings();
        $store->refresh();

        return [
            'paid' => $s['paid'],
            'prices' => ['day' => $s['day_price'], 'month' => $s['month_price']],
            'free_lives' => $s['free_lives'] + (int) $store->live_free_bonus,
            'free_left' => $this->freeLeft($store),
            'free_ai' => $store->live_ai_gift_at === null ? $s['free_ai'] : 0,
            'pass_until' => $store->live_pass_until?->isFuture() ? $store->live_pass_until->toIso8601String() : null,
            'month_until' => $store->live_month_until?->isFuture() ? $store->live_month_until->toIso8601String() : null,
            // Ce que coûtera le prochain live : couvert (pass ou forfait), offert, ou à payer
            'next' => ! $s['paid'] ? 'free_mode' : ($this->coveredUntil($store) !== null ? 'covered' : ($this->freeLeft($store) > 0 ? 'offered' : 'pay')),
            'payments' => SubscriptionInvoice::forStore($store->id)->whereIn('kind', self::KINDS)->whereIn('status', ['paid', 'offered', 'pending'])
                ->latest('id')->limit(30)->get()->map(fn (SubscriptionInvoice $i) => [
                    'number' => $i->number, 'kind' => $i->kind, 'label' => self::label($i->kind), 'amount' => $i->amount,
                    'status' => $i->status, 'paid_at' => $i->paid_at?->toIso8601String(), 'created_at' => $i->created_at->toIso8601String(),
                ])->all(),
            'offered_lives' => LiveSession::forStore($store->id)->where('billing', 'offered')->latest('started_at')->limit(30)
                ->get(['id', 'title', 'started_at'])->map(fn (LiveSession $l) => ['id' => $l->id, 'title' => $l->title, 'started_at' => $l->started_at?->toIso8601String()])->all(),
        ];
    }

    public static function label(string $kind): string
    {
        return $kind === 'live_month' ? 'Lives illimités 30 jours' : 'Lives pendant 24 h';
    }

    /**
     * Droit de démarrer ce live : pass ou forfait en cours, sinon un live offert (le 1er ajoute les actions IA
     * offertes), sinon paiement demandé. Rien n'est compté quand les lives payants sont éteints.
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
                $session->forceFill(['billing' => $store->live_month_until?->isFuture() ? 'month' : 'day'])->save();

                return;
            }
            if ($this->freeLeft($store) <= 0) {
                throw new BusinessRuleException('Vos lives offerts sont utilisés : choisissez 24 h ou 30 jours pour démarrer.', 'payment');
            }
            $store->increment('live_free_used');
            if ($store->live_ai_gift_at === null && $s['free_ai'] > 0) {
                $store->forceFill(['live_ai_gift_at' => now(), 'ai_credits' => (int) $store->ai_credits + $s['free_ai']])->save();
            }
            $session->forceFill(['billing' => 'offered'])->save();
        });
    }

    /**
     * Facture et page de paiement PayDunya : 24 h ou 30 jours.
     */
    public function checkout(Store $store, string $kind, User $user): SubscriptionInvoice
    {
        $s = $this->settings();
        if (! in_array($kind, self::KINDS, true)) {
            throw new BusinessRuleException('Choisissez 24 h ou 30 jours.', 'kind');
        }
        $amount = $kind === 'live_month' ? $s['month_price'] : $s['day_price'];

        return app(Billing::class)->payLive($store, $user, $s['provider'], [
            'kind' => $kind,
            'billing_cycle' => $kind === 'live_month' ? 'monthly' : 'day',
            'amount' => $amount,
        ], self::label($kind).' · '.$store->name);
    }

    /**
     * Paiement reçu (ou offert par la console) : 24 h ou 30 jours de plus, à partir de maintenant ou de la fin en cours.
     */
    public function activate(Store $store, SubscriptionInvoice $invoice): string
    {
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
