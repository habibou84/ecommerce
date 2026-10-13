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
    ) {}

    /**
     * Compte de la plateforme chez le prestataire (configuration, jamais en base).
     */
    public function account(): PaymentAccount
    {
        $config = config('platform.billing');

        return (new PaymentAccount)->forceFill([
            'provider' => $config['provider'],
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
        $gateway = $this->payments->gateway(config('platform.billing.provider'));

        return array_intersect_key(PaymentDesk::METHODS, array_flip($gateway->methods()));
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Store $store): array
    {
        $subscription = $store->currentSubscription()->with('plan')->first();
        $plan = $this->limits->plan($store);

        return [
            'plan' => ['code' => $plan->code, 'name' => $plan->name],
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
            'features' => collect(PlanLimits::FEATURES)->map(fn ($label, $key) => ['label' => $label, 'enabled' => $plan->hasFeature($key)])->all(),
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get()->map(fn (Plan $p) => [
                'code' => $p->code, 'name' => $p->name, 'monthly_price' => $p->monthly_price, 'yearly_price' => $p->yearly_price,
                'limits' => $p->limits, 'features' => array_keys(array_filter($p->features ?? [])),
            ]),
            'methods' => $this->methods(),
            'invoices' => SubscriptionInvoice::forStore($store->id)->with('plan')->whereIn('status', ['paid', 'offered', 'pending'])->latest('id')->limit(12)->get()
                ->map(fn (SubscriptionInvoice $i) => $this->presentInvoice($i)),
        ];
    }

    /**
     * Facture et page de paiement du prestataire pour une offre payante.
     */
    public function checkout(Store $store, Plan $plan, string $cycle, string $method, User $user): SubscriptionInvoice
    {
        $amount = $cycle === 'yearly' ? $plan->yearly_price : $plan->monthly_price;
        if (! $amount) {
            throw new BusinessRuleException('Cette offre ne se paie pas en ligne.', 'plan');
        }
        if (! array_key_exists($method, $this->methods())) {
            throw new BusinessRuleException('Moyen de paiement non disponible.', 'method');
        }

        $invoice = $this->newInvoice([
            'store_id' => $store->id,
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'amount' => $amount,
            'status' => 'pending',
            'provider' => config('platform.billing.provider'),
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
                'description' => "Abonnement {$plan->name} ({$this->cycleLabel($cycle)}) · {$store->name}",
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
     * Statut relu chez le prestataire (retour sur la gestion, notification, vérification de secours).
     */
    public function confirm(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status !== 'pending') {
            return $invoice;
        }

        $result = $this->payments->gateway($invoice->provider)->verify($this->account(), $this->payment($invoice));
        if ($result->status === GatewayResult::SUCCEEDED && ($result->amount === null || $result->amount >= $invoice->amount)) {
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
        $parsed = $this->payments->gateway(config('platform.billing.provider'))->parseWebhook($this->account(), $request);
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

            if ($sub->status === SubscriptionStatus::Trialing) {
                if ($sub->trial_ends_at?->isPast()) {
                    $this->expire($sub, 'Fin de l\'essai', "Votre essai de l'offre {$sub->plan->name} est terminé : la boutique passe à l'offre gratuite. Choisissez une offre pour retrouver toutes les fonctions.");
                    $counts['trials_ended']++;
                } elseif ($sub->trial_ends_at?->lte($remind) && $this->once($store, 'trial_reminder', $sub->id)) {
                    $this->notify($store, 'Votre essai se termine bientôt', "Votre essai de l'offre {$sub->plan->name} se termine le {$sub->trial_ends_at->locale('fr')->isoFormat('D MMMM')}. Choisissez votre offre pour garder toutes les fonctions.");
                    $counts['reminded']++;
                }

                continue;
            }

            if ($sub->status === SubscriptionStatus::Active && $end?->isPast()) {
                if ($sub->cancel_at_period_end) {
                    $this->expire($sub, 'Abonnement terminé', "Votre abonnement {$sub->plan->name} est terminé comme demandé : la boutique passe à l'offre gratuite.");
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
                $this->expire($sub, 'Retour à l\'offre gratuite', "Votre abonnement {$sub->plan->name} n'a pas été renouvelé : la boutique passe à l'offre gratuite. Vos données sont conservées.");
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
            'plan' => $i->plan?->name,
            'billing_cycle' => $i->billing_cycle,
            'cycle_label' => $this->cycleLabel($i->billing_cycle),
            'amount' => $i->amount,
            'status' => $i->status,
            'status_label' => SubscriptionInvoice::STATUSES[$i->status] ?? $i->status,
            'method' => $i->method ? (PaymentDesk::METHODS[$i->method] ?? $i->method) : null,
            'period_start' => $i->period_start?->toIso8601String(),
            'period_end' => $i->period_end?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'checkout_url' => $i->status === 'pending' ? $i->checkout_url : null,
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
            $months = $status === 'offered' ? (int) ($raw['months'] ?? 1) : ($invoice->billing_cycle === 'yearly' ? 12 : 1);
            $current = $store->currentSubscription()->first();

            if ($current && $current->plan_id === $invoice->plan_id && $current->status !== SubscriptionStatus::Trialing) {
                // Même offre : la nouvelle période commence à la fin de la précédente (paiement anticipé ou en retard)
                $start = $current->current_period_end && $current->current_period_end->isFuture() ? CarbonImmutable::instance($current->current_period_end) : CarbonImmutable::now();
                $current->update([
                    'status' => SubscriptionStatus::Active, 'billing_cycle' => $invoice->billing_cycle, 'current_period_end' => $start->addMonths($months),
                    'cancel_at_period_end' => false,
                ]);
                $subscription = $current;
            } else {
                $current?->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
                $start = CarbonImmutable::now();
                $subscription = $store->subscriptions()->create([
                    'plan_id' => $invoice->plan_id, 'status' => SubscriptionStatus::Active, 'billing_cycle' => $invoice->billing_cycle,
                    'current_period_start' => $start, 'current_period_end' => $start->addMonths($months),
                ]);
            }

            $invoice->update([
                'status' => $status, 'subscription_id' => $subscription->id, 'method' => $method ?? $invoice->method, 'raw' => $raw,
                'period_start' => $start, 'period_end' => $subscription->current_period_end, 'paid_at' => now(),
            ]);
            if (in_array($store->status, [StoreStatus::Trial, StoreStatus::PastDue], true)) {
                $store->forceFill(['status' => StoreStatus::Active])->save();
            }
            $store->forceFill(['billing_notices' => null])->save();

            $plan = $invoice->plan;
            DB::afterCommit(fn () => $this->notify($store, $status === 'offered' ? 'Abonnement offert' : 'Paiement reçu',
                "Offre {$plan->name} active jusqu'au {$subscription->current_period_end->locale('fr')->isoFormat('D MMMM YYYY')}"
                .($status === 'paid' ? ' ('.Money::format($invoice->amount).' reçus). Merci !' : '.')));

            return $invoice->fresh(['plan']);
        });
    }

    private function expire(Subscription $sub, string $title, string $body): void
    {
        $sub->update(['status' => SubscriptionStatus::Expired]);
        $store = $sub->store;
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
        return $cycle === 'yearly' ? 'annuel' : 'mensuel';
    }
}
