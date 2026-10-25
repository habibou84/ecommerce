<?php

namespace App\Services\Billing;

use App\Enums\Permission;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Accounts\TeamNotifier;
use App\Services\Partners\PartnerReferrals;
use App\Services\Payments\Gateways\GatewayResult;
use App\Services\Payments\PaymentDesk;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use Throwable;

/**
 * Abonnements des boutiques (docs/PLAN.md §6.5) : paiement sur le compte de la plateforme avec les mêmes pilotes
 * que les marchands (Wave, CinetPay, PayDunya), renouvellement, retard, fin d'essai et retour à l'offre gratuite.
 *
 * Une boutique dont l'abonnement n'est pas renouvelé n'est jamais fermée : elle repasse à l'offre gratuite
 * (ses données restent, les limites de l'offre gratuite s'appliquent aux ajouts).
 */
class Billing
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly PaymentDesk $payments,
        private readonly TeamNotifier $notifier,
        private readonly BillingSettings $settings,
        private readonly PaymentAlerts $alerts,
    ) {}

    /**
     * Compte de la plateforme chez le prestataire (prestataire choisi dans la console, clés sur le serveur).
     */
    public function account(): PaymentAccount
    {
        $config = config('platform.billing');

        return (new PaymentAccount)->forceFill([
            'provider' => $this->settings->provider(),
            'mode' => $config['mode'],
            'credentials' => $config['credentials'],
            'label' => 'Jibiat Marchand',
        ]);
    }

    /**
     * Moyens de paiement acceptés pour l'abonnement.
     *
     * @return array<string, string>
     */
    public function methods(): array
    {
        if ($this->manual()) {
            return ['wave' => 'Wave'];
        }
        $gateway = $this->payments->gateway($this->settings->provider());

        return array_intersect_key(PaymentDesk::METHODS, array_flip($gateway->methods()));
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Store $store): array
    {
        $subscription = $store->currentSubscription()->with('plan')->first();
        $plan = $this->limits->plan($store);
        $covering = $this->limits->coveringStore($store);

        return [
            'plan' => ['code' => $plan->code, 'name' => $plan->name],
            // Abonnement d'une autre boutique du propriétaire qui couvre celle-ci
            'covered_by' => $covering ? ['id' => $covering->id, 'name' => $covering->name, 'url' => $covering->url('/gestion/abonnement')] : null,
            // Jours payés non utilisés, convertis en jours supplémentaires en cas de changement d'offre
            'credit' => $subscription && ! $covering ? $this->unusedCredit($subscription) : 0,
            'store_status' => $store->status->value,
            'subscription' => $subscription ? [
                'status' => $subscription->status->value,
                'billing_cycle' => $subscription->billing_cycle,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'grace_ends_at' => $subscription->status === SubscriptionStatus::PastDue ? $subscription->current_period_end?->addDays(config('platform.billing.grace_days'))->toIso8601String() : null,
            ] : null,
            'usage' => $this->limits->usage($store),
            'features' => collect(PlanLimits::FEATURES)->map(fn ($label, $key) => ['label' => $label, 'enabled' => $plan->hasFeature($key), 'soon' => in_array($key, PlanLimits::COMING_SOON, true)])->all(),
            'coming_soon' => PlanLimits::COMING_SOON,
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get()->map(fn (Plan $p) => [
                'code' => $p->code, 'name' => $p->name, 'monthly_price' => $p->monthly_price, 'weekly_price' => $p->weekly_price, 'yearly_price' => $p->yearly_price,
                'limits' => $p->limits, 'features' => array_keys(array_filter($p->features ?? [])),
            ]),
            'methods' => $this->methods(),
            // Lien marchand Wave : le numéro qui paie est demandé, la confirmation se fait à la main
            'manual_payment' => $this->manual(),
            'wave_qr_url' => $this->manual() ? $this->settings->qrUrl() : null,
            // Recharges de l'assistant : actions achetées restantes et prix d'une recharge
            'ai_credits' => (int) $store->ai_credits,
            'ai_pack' => config('platform.ai_pack'),
            'invoices' => SubscriptionInvoice::forStore($store->id)->with('plan')->whereIn('status', ['paid', 'offered', 'pending'])->latest('id')->limit(12)->get()
                ->map(fn (SubscriptionInvoice $i) => $this->presentInvoice($i)),
        ];
    }

    /**
     * Facture et page de paiement du prestataire pour une offre payante.
     */
    public function checkout(Store $store, Plan $plan, string $cycle, string $method, User $user, ?string $payerPhone = null): SubscriptionInvoice
    {
        if (($covering = $this->limits->coveringStore($store)) !== null) {
            throw new BusinessRuleException("Cette boutique est couverte par l'abonnement de {$covering->name}. Retirez-la de cet abonnement (Mes boutiques) pour prendre une offre séparée.", 'plan');
        }
        $amount = match ($cycle) {
            'yearly' => $plan->yearly_price,
            'weekly' => $plan->weekly_price,
            default => $plan->monthly_price,
        };
        if (! $amount) {
            throw new BusinessRuleException($cycle === 'weekly' ? 'Cette offre ne se paie pas à la semaine.' : 'Cette offre ne se paie pas en ligne.', 'plan');
        }

        return $this->pay($store, $user, $method, [
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'amount' => $amount,
        ], "Abonnement {$plan->name} ({$this->cycleLabel($cycle)}) · {$store->name}", $payerPhone);
    }

    /**
     * Recharge de l'assistant : des actions en plus, utilisées après celles de l'offre et sans date de fin.
     */
    public function buyAiPack(Store $store, string $method, User $user, ?string $payerPhone = null): SubscriptionInvoice
    {
        ['actions' => $actions, 'price' => $price] = config('platform.ai_pack');

        return $this->pay($store, $user, $method, [
            'kind' => 'ai_pack',
            'ai_actions' => $actions,
            'billing_cycle' => 'pack',
            'amount' => $price,
        ], "Recharge de l'assistant ({$actions} actions) · {$store->name}", $payerPhone);
    }

    /**
     * Facture en attente et page de paiement du prestataire.
     *
     * @param  array<string, mixed>  $data
     */
    private function pay(Store $store, User $user, string $method, array $data, string $description, ?string $payerPhone = null): SubscriptionInvoice
    {
        if (! array_key_exists($method, $this->methods())) {
            throw new BusinessRuleException('Moyen de paiement non disponible.', 'method');
        }
        if ($this->manual()) {
            return $this->payByLink($store, $user, $data, $description, $payerPhone);
        }

        $invoice = $this->newInvoice($data + [
            'store_id' => $store->id,
            'status' => 'pending',
            'provider' => $this->settings->provider(),
            'method' => $method,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
        ]);

        $return = $store->url('/gestion/abonnement?facture='.$invoice->number);
        try {
            $checkout = $this->payments->gateway($invoice->provider)->createCheckout($this->account(), $this->payment($invoice), [
                'return_url' => $return,
                'cancel_url' => $return,
                'notify_url' => Tenancy::apiUrl('/webhooks/billing'),
                'description' => $description,
                'customer_name' => $user->name,
                'customer_phone' => $user->phone,
                'store_name' => 'Jibiat Marchand',
            ]);
        } catch (Throwable $e) {
            $invoice->update(['status' => 'failed', 'raw' => ['error' => mb_substr($e->getMessage(), 0, 300)]]);
            Log::warning('Paiement d\'abonnement impossible', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);

            throw new BusinessRuleException('Le paiement en ligne est indisponible pour le moment. Réessayez dans quelques minutes.');
        }

        $invoice->update(['checkout_url' => $checkout->url, 'provider_reference' => $checkout->reference, 'raw' => $checkout->raw]);

        return $invoice;
    }

    /**
     * Paiement par le lien marchand Wave (sans API) : facture en attente, lien avec le montant (et QR code à scanner),
     * et alerte au validateur avec le numéro qui paie et le lien pour confirmer quand le message de Wave arrive.
     */
    private function payByLink(Store $store, User $user, array $data, string $description, ?string $payerPhone): SubscriptionInvoice
    {
        if (! $this->settings->ready()) {
            throw new BusinessRuleException('Le paiement en ligne est indisponible pour le moment. Réessayez dans quelques minutes.');
        }
        if (blank($payerPhone)) {
            throw new BusinessRuleException('Indiquez le numéro Wave qui va payer.', 'payer_phone');
        }

        $invoice = $this->newInvoice($data + [
            'store_id' => $store->id,
            'status' => 'pending',
            'provider' => 'wave_link',
            'method' => 'wave',
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
        ]);
        $link = $this->settings->waveLink();
        $url = match (true) {
            $link === null => null,
            str_contains($link, '{montant}') => str_replace('{montant}', (string) $invoice->amount, $link),
            default => $link.(str_contains($link, '?') ? '&' : '?').'amount='.$invoice->amount,
        };
        $invoice->update(['checkout_url' => $url, 'raw' => ['payer_phone' => $payerPhone, 'description' => $description, 'payer_name' => $user->name]]);
        $this->alerts->alert($invoice, claimed: false);

        return $invoice->fresh();
    }

    /**
     * « J'ai payé » : l'e-commerçant confirme son paiement Wave, le validateur est prévenu une seconde fois.
     */
    public function claimPaid(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status !== 'pending' || $invoice->provider !== 'wave_link' || isset($invoice->raw['claimed_at'])) {
            return $invoice;
        }
        $invoice->update(['raw' => ['claimed_at' => now()->toIso8601String()] + ($invoice->raw ?? [])]);
        $this->alerts->alert($invoice, claimed: true);

        return $invoice->fresh();
    }

    /**
     * Décision du validateur sur un paiement par lien Wave : reçu (l'offre ou la recharge s'active) ou non reçu.
     */
    public function decide(SubscriptionInvoice $invoice, bool $received): SubscriptionInvoice
    {
        if ($invoice->status !== 'pending' || $invoice->provider !== 'wave_link') {
            return $invoice;
        }
        if ($received) {
            return $this->activate($invoice, 'paid', 'wave', ($invoice->raw ?? []) + ['validated_at' => now()->toIso8601String()]);
        }

        $invoice->update(['status' => 'failed']);
        $this->notify(Store::findOrFail($invoice->store_id), 'Paiement Wave non reçu',
            "Nous n'avons pas reçu le paiement Wave de la facture {$invoice->number}. Si vous avez bien payé, écrivez-nous sur WhatsApp avec la référence de la transaction.");

        return $invoice->fresh();
    }

    /**
     * Abonnements payés par le lien marchand Wave et validés à la main.
     */
    public function manual(): bool
    {
        return $this->settings->manual();
    }

    /**
     * Statut relu chez le prestataire (retour sur la gestion, notification, vérification de secours).
     */
    public function confirm(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status !== 'pending' || $invoice->provider === 'wave_link') {
            return $invoice;
        }

        $result = $this->payments->gateway($invoice->provider)->verify($this->account(), $this->payment($invoice));
        // Comme pour les commandes : sans montant confirmé par le prestataire (ou s'il manque), l'offre n'est pas activée
        if ($result->status === GatewayResult::SUCCEEDED && $result->amount !== null && $result->amount >= $invoice->amount) {
            return $this->activate($invoice, 'paid', $result->method, $result->raw);
        }
        if ($result->status === GatewayResult::FAILED) {
            $invoice->update(['status' => 'failed', 'raw' => $result->raw]);
        }

        return $invoice->fresh();
    }

    /**
     * Notification du prestataire sur le compte de la plateforme.
     */
    public function webhook(Request $request): bool
    {
        // Lien marchand Wave : aucun prestataire n'envoie de notification
        if ($this->manual()) {
            return false;
        }
        $parsed = $this->payments->gateway($this->settings->provider())->parseWebhook($this->account(), $request);
        if ($parsed === null) {
            return false;
        }

        $key = $parsed['payment_key'];
        $invoice = SubscriptionInvoice::withoutGlobalScopes()->where('status', 'pending')
            ->where(function ($q) use ($key, $parsed) {
                $q->when($key, fn ($q) => $q->where('idempotency_key', strlen($key) === 32 ? vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($key, 4)) : $key))
                    ->when($parsed['provider_reference'], fn ($q) => $q->orWhere('provider_reference', $parsed['provider_reference']));
            })->first();

        // Une notification ne valide jamais un paiement seule : statut relu chez le prestataire
        if ($invoice !== null) {
            $this->confirm($invoice);
        }

        return true;
    }

    /**
     * Mois offerts par la plateforme (console) : même effet qu'un paiement.
     */
    public function offer(Store $store, Plan $plan, int $months, User $admin): SubscriptionInvoice
    {
        $invoice = $this->newInvoice([
            'store_id' => $store->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'amount' => 0, 'status' => 'pending', 'idempotency_key' => (string) Str::uuid(), 'created_by' => $admin->id,
        ]);

        return $this->activate($invoice, 'offered', null, ['months' => $months]);
    }

    /**
     * Fin de l'abonnement à l'échéance (retour à l'offre gratuite), ou annulation de cette demande.
     */
    public function cancelAtPeriodEnd(Store $store, bool $cancel): void
    {
        $subscription = $store->currentSubscription()->first();
        if ($subscription === null || $subscription->status === SubscriptionStatus::Trialing) {
            throw new BusinessRuleException('Aucun abonnement payant en cours.');
        }
        $subscription->update(['cancel_at_period_end' => $cancel]);
    }

    /**
     * Tâche quotidienne : rappels, fins d'essai, échéances, retards.
     *
     * @return array<string, int>
     */
    public function run(): array
    {
        $counts = ['reminded' => 0, 'trials_ended' => 0, 'past_due' => 0, 'downgraded' => 0];
        $grace = config('platform.billing.grace_days');
        $remind = now()->addDays(config('platform.billing.reminder_days'));

        $subscriptions = Subscription::whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->with(['store', 'plan'])->get();

        foreach ($subscriptions as $sub) {
            $store = $sub->store;
            if ($store === null || $store->status === StoreStatus::Closed) {
                continue;
            }
            $end = $sub->current_period_end;

            // Boutique couverte par l'abonnement d'une autre : son propre abonnement s'éteint sans rappel
            if ($this->limits->coveringStore($store) !== null) {
                if (($sub->status === SubscriptionStatus::Trialing && $sub->trial_ends_at?->isPast()) || $end?->isPast()) {
                    $sub->update(['status' => SubscriptionStatus::Expired]);
                }

                continue;
            }

            if ($sub->status === SubscriptionStatus::Trialing) {
                if ($sub->trial_ends_at?->isPast()) {
                    $this->expire($sub, 'Fin de l\'essai', "Votre essai de l'offre {$sub->plan->name} est terminé : votre espace marchand passe à l'offre gratuite. Choisissez une offre pour retrouver toutes les fonctions.");
                    $counts['trials_ended']++;
                } elseif ($sub->trial_ends_at?->lte($remind) && $this->once($store, 'trial_reminder', $sub->id)) {
                    $this->notify($store, 'Votre essai se termine bientôt', "Votre essai de l'offre {$sub->plan->name} se termine le {$sub->trial_ends_at->locale('fr')->isoFormat('D MMMM')}. Choisissez votre offre pour garder toutes les fonctions.");
                    $counts['reminded']++;
                }

                continue;
            }

            if ($sub->status === SubscriptionStatus::Active && $end?->isPast()) {
                if ($sub->cancel_at_period_end) {
                    $this->expire($sub, 'Abonnement terminé', "Votre abonnement {$sub->plan->name} est terminé comme demandé : votre espace marchand passe à l'offre gratuite.");
                    $counts['downgraded']++;
                } else {
                    $sub->update(['status' => SubscriptionStatus::PastDue]);
                    $store->forceFill(['status' => StoreStatus::PastDue])->save();
                    $this->notify($store, 'Abonnement à renouveler', "Votre abonnement {$sub->plan->name} est arrivé à échéance. Renouvelez-le sous {$grace} jours pour garder ses fonctions.");
                    $counts['past_due']++;
                }

                continue;
            }

            if ($sub->status === SubscriptionStatus::Active && ! $sub->cancel_at_period_end && $end?->lte($remind) && $this->once($store, 'renewal_reminder', $sub->id.'-'.$end->toDateString())) {
                $this->notify($store, 'Renouvellement de l\'abonnement', "Votre abonnement {$sub->plan->name} arrive à échéance le {$end->locale('fr')->isoFormat('D MMMM')}. Renouvelez-le depuis Réglages › Abonnement.");
                $counts['reminded']++;
            }

            if ($sub->status === SubscriptionStatus::PastDue && $end?->addDays($grace)->isPast()) {
                $this->expire($sub, 'Retour à l\'offre gratuite', "Votre abonnement {$sub->plan->name} n'a pas été renouvelé : votre espace marchand passe à l'offre gratuite. Vos données sont conservées.");
                $counts['downgraded']++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentInvoice(SubscriptionInvoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'plan' => $i->kind === 'ai_pack' ? "Recharge de l'assistant ({$i->ai_actions} actions)" : $i->plan?->name,
            'kind' => $i->kind,
            'billing_cycle' => $i->billing_cycle,
            'cycle_label' => $this->cycleLabel($i->billing_cycle),
            'amount' => $i->amount,
            'credit_days' => $i->credit_days,
            'status' => $i->status,
            'status_label' => SubscriptionInvoice::STATUSES[$i->status] ?? $i->status,
            'method' => $i->method ? (PaymentDesk::METHODS[$i->method] ?? $i->method) : null,
            'period_start' => $i->period_start?->toIso8601String(),
            'period_end' => $i->period_end?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'checkout_url' => $i->status === 'pending' ? $i->checkout_url : null,
            // Lien marchand Wave : paiement validé à la main, « J'ai payé » déjà signalé ou non
            'manual' => $i->provider === 'wave_link',
            'claimed_at' => $i->provider === 'wave_link' ? ($i->raw['claimed_at'] ?? null) : null,
            'created_at' => $i->created_at->toIso8601String(),
        ];
    }

    /**
     * Paiement reçu (ou offert) : l'abonnement est prolongé s'il s'agit de la même offre, sinon remplacé.
     */
    private function activate(SubscriptionInvoice $invoice, string $status, ?string $method, array $raw): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoice, $status, $method, $raw) {
            $invoice = SubscriptionInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'pending') {
                return $invoice;
            }

            $store = Store::findOrFail($invoice->store_id);

            if ($invoice->kind === 'ai_pack') {
                $store->increment('ai_credits', (int) $invoice->ai_actions);
                $invoice->update(['status' => $status, 'method' => $method ?? $invoice->method, 'raw' => $raw, 'paid_at' => now()]);
                $credits = $store->fresh()->ai_credits;
                DB::afterCommit(fn () => $this->notify($store, 'Recharge de l\'assistant reçue',
                    "{$invoice->ai_actions} actions de l'assistant ajoutées ({$credits} en réserve, ".Money::format($invoice->amount).' reçus). Merci !'));

                return $invoice->fresh();
            }

            $months = $status === 'offered' ? (int) ($raw['months'] ?? 1) : ($invoice->billing_cycle === 'yearly' ? 12 : 1);
            // Paiement à la semaine : 7 jours au lieu d'un mois
            $period = fn (CarbonImmutable $from) => $status !== 'offered' && $invoice->billing_cycle === 'weekly' ? $from->addWeek() : $from->addMonths($months);
            $current = $store->currentSubscription()->first();

            if ($current && $current->plan_id === $invoice->plan_id && $current->status !== SubscriptionStatus::Trialing) {
                // Même offre : la nouvelle période commence à la fin de la précédente (paiement anticipé ou en retard)
                $start = $current->current_period_end && $current->current_period_end->isFuture() ? CarbonImmutable::instance($current->current_period_end) : CarbonImmutable::now();
                $current->update([
                    'status' => SubscriptionStatus::Active, 'billing_cycle' => $invoice->billing_cycle, 'current_period_end' => $period($start),
                    'cancel_at_period_end' => false,
                ]);
                $subscription = $current;
            } else {
                // Changement d'offre : les jours payés non utilisés de l'offre précédente deviennent des jours en plus
                $credit = $current ? $this->unusedCredit($current) : 0;
                $days = $this->creditDays($credit, $invoice->plan, $invoice->billing_cycle);
                $current?->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
                $start = CarbonImmutable::now();
                $subscription = $store->subscriptions()->create([
                    'plan_id' => $invoice->plan_id, 'status' => SubscriptionStatus::Active, 'billing_cycle' => $invoice->billing_cycle,
                    'current_period_start' => $start, 'current_period_end' => $period($start)->addDays($days),
                ]);
                if ($days > 0) {
                    $invoice->update(['credit_amount' => $credit, 'credit_days' => $days]);
                }
            }

            $invoice->update([
                'status' => $status, 'subscription_id' => $subscription->id, 'method' => $method ?? $invoice->method, 'raw' => $raw,
                'period_start' => $start, 'period_end' => $subscription->current_period_end, 'paid_at' => now(),
            ]);
            if (in_array($store->status, [StoreStatus::Trial, StoreStatus::PastDue], true)) {
                $store->forceFill(['status' => StoreStatus::Active])->save();
            }
            $store->forceFill(['billing_notices' => null])->save();

            // Boutique parrainée par une entreprise de livraison : sa part de l'abonnement payé
            if ($status === 'paid') {
                app(PartnerReferrals::class)->record($invoice->fresh());
            }

            $plan = $invoice->plan;
            DB::afterCommit(fn () => $this->notify($store, $status === 'offered' ? 'Abonnement offert' : 'Paiement reçu',
                "Offre {$plan->name} active jusqu'au {$subscription->current_period_end->locale('fr')->isoFormat('D MMMM YYYY')}"
                .($status === 'paid' ? ' ('.Money::format($invoice->amount).' reçus). Merci !' : '.')));

            return $invoice->fresh(['plan']);
        });
    }

    /**
     * Valeur des jours payés et non encore utilisés de l'abonnement (périodes payées en cours ou à venir).
     */
    public function unusedCredit(Subscription $subscription): int
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            return 0;
        }

        $now = CarbonImmutable::now();

        return (int) SubscriptionInvoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)
            ->where('status', 'paid')->where('amount', '>', 0)->where('period_end', '>', $now)->get()
            ->sum(function (SubscriptionInvoice $i) use ($now) {
                $length = $i->period_start->diffInSeconds($i->period_end);
                $left = max($now, CarbonImmutable::instance($i->period_start))->diffInSeconds($i->period_end);

                return $length > 0 ? intdiv((int) ($i->amount * $left), (int) $length) : 0;
            });
    }

    /**
     * Jours de la nouvelle offre achetés par le crédit (au prix du jour de cette offre et de ce rythme).
     */
    private function creditDays(int $credit, Plan $plan, string $cycle): int
    {
        $perDay = match ($cycle) {
            'yearly' => $plan->yearly_price / 365,
            'weekly' => $plan->weekly_price / 7,
            default => $plan->monthly_price / 30,
        };

        return $credit > 0 && $perDay > 0 ? min(366, (int) floor($credit / $perDay)) : 0;
    }

    private function expire(Subscription $sub, string $title, string $body): void
    {
        $sub->update(['status' => SubscriptionStatus::Expired]);
        $store = $sub->store;
        if ($sub->plan?->hasFeature('multi_store') && Store::where('billing_store_id', $store->id)->exists()) {
            $body .= ' Vos autres boutiques qui partageaient cet abonnement repassent aussi à leur propre offre.';
        }
        if (in_array($store->status, [StoreStatus::Trial, StoreStatus::PastDue], true)) {
            $store->forceFill(['status' => StoreStatus::Active])->save();
        }
        $store->unsetRelation('currentSubscription');
        $this->notify($store, $title, $body);
    }

    private function notify(Store $store, string $title, string $body): void
    {
        $this->notifier->notify($store, Permission::BillingManage->value, new BillingNotice($store->id, $title, $body));
    }

    /**
     * Avertissement envoyé une seule fois pour une même échéance.
     */
    private function once(Store $store, string $key, string|int $value): bool
    {
        $notices = $store->billing_notices ?? [];
        if (($notices[$key] ?? null) === (string) $value) {
            return false;
        }
        $store->forceFill(['billing_notices' => [$key => (string) $value] + $notices])->save();

        return true;
    }

    /**
     * Paiement en mémoire pour les pilotes (montant, référence) : jamais enregistré parmi les paiements des boutiques.
     */
    private function payment(SubscriptionInvoice $invoice): Payment
    {
        return (new Payment)->forceFill([
            'amount' => $invoice->amount,
            'currency' => 'XOF',
            'idempotency_key' => $invoice->idempotency_key,
            'provider_reference' => $invoice->provider_reference,
            'method' => $invoice->method,
        ]);
    }

    /**
     * Numéro de facture continu : JM-2026-000123 (tiré de l'identifiant, sans risque de doublon).
     */
    private function newInvoice(array $data): SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::create($data + ['number' => Str::random(20)]);
        $invoice->update(['number' => 'JM-'.now()->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);

        return $invoice;
    }

    private function cycleLabel(string $cycle): string
    {
        return match ($cycle) {
            'yearly' => 'annuel',
            'weekly' => 'à la semaine',
            'pack' => 'recharge',
            default => 'mensuel',
        };
    }
}
