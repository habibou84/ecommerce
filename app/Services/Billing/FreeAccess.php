<?php

namespace App\Services\Billing;

use App\Enums\Permission;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\Subscription;
use App\Notifications\BillingNotice;
use App\Services\Accounts\TeamNotifier;
use Carbon\CarbonImmutable;

/**
 * Gratuité donnée depuis la console, sans paiement ni fin d'essai :
 *  - pour tous (interrupteur général) : une offre au choix, jusqu'à une date ou sans limite, avec un bandeau facultatif ;
 *  - pour un espace marchand (« gratuit à vie ») : prioritaire, même quand l'interrupteur général est éteint.
 * Les limites de l'offre donnée s'appliquent (dont l'assistant IA). Un abonnement payé plus élevé est gardé.
 * À la fin de la gratuité générale, chaque espace marchand qui ne paie pas reçoit un délai (essai) avant le retour
 * au fonctionnement normal.
 */
class FreeAccess
{
    public const GRACE_DAYS = 14;

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    private Plan|false|null $plan = null;

    public function __construct(private readonly TeamNotifier $notifier) {}

    /**
     * @return array{enabled: bool, plan: ?string, until: ?string, banner: ?string, grace_days: int}
     */
    public function settings(): array
    {
        if ($this->settings === null) {
            $s = PlatformSetting::group('free');
            $this->settings = [
                'enabled' => ($s['enabled'] ?? '0') === '1',
                'plan' => $s['plan'] ?? null,
                'until' => $s['until'] ?? null,
                'banner' => filled($s['banner'] ?? null) ? $s['banner'] : null,
                'grace_days' => (int) ($s['grace_days'] ?? self::GRACE_DAYS),
            ];
        }

        return $this->settings;
    }

    /**
     * Offre donnée à tous en ce moment (interrupteur allumé et date de fin non passée).
     */
    public function globalPlan(): ?Plan
    {
        if ($this->plan === null) {
            $s = $this->settings();
            $this->plan = $s['enabled'] && ! $this->expired() ? (Plan::where('code', $s['plan'])->first() ?? false) : false;
        }

        return $this->plan ?: null;
    }

    /**
     * Offre gratuite qui s'applique à cet espace marchand : « gratuit à vie » d'abord, sinon la gratuité générale.
     */
    public function planFor(Store $store): ?Plan
    {
        if ($store->free_plan_id !== null) {
            return $store->freePlan;
        }

        return $this->globalPlan();
    }

    /**
     * Ce que l'espace marchand voit : offre donnée, sans limite ou jusqu'à quand, bandeau.
     *
     * @return array{plan: string, plan_code: string, forever: bool, until: ?string, banner: ?string}|null
     */
    public function present(Store $store): ?array
    {
        $plan = $this->planFor($store);
        if ($plan === null) {
            return null;
        }
        $forever = $store->free_plan_id !== null;

        return [
            'plan' => $plan->name,
            'plan_code' => $plan->code,
            'forever' => $forever,
            'until' => $forever ? null : $this->settings()['until'],
            'banner' => $forever ? null : $this->settings()['banner'],
        ];
    }

    /**
     * Réglages de la gratuité générale. L'extinction (ou une date de fin déjà passée) lance le délai pour chacun.
     *
     * @param  array{enabled: bool, plan: string, until: ?string, banner: ?string, grace_days: int}  $data
     */
    public function update(array $data): int
    {
        $wasActive = $this->globalPlan() !== null;
        PlatformSetting::put('free.enabled', $data['enabled'] ? '1' : '0');
        PlatformSetting::put('free.plan', $data['plan']);
        PlatformSetting::put('free.until', $data['until'] ?? null);
        PlatformSetting::put('free.banner', filled($data['banner'] ?? null) ? trim($data['banner']) : null);
        PlatformSetting::put('free.grace_days', (string) $data['grace_days']);
        $this->settings = null;
        $this->plan = null;

        return $wasActive && $this->globalPlan() === null ? $this->endForAll() : 0;
    }

    /**
     * Tâche quotidienne : la date de fin de la gratuité générale est passée.
     */
    public function sweep(): int
    {
        $s = $this->settings();
        if (! $s['enabled'] || ! $this->expired()) {
            return 0;
        }
        PlatformSetting::put('free.enabled', '0');
        $this->settings = null;
        $this->plan = null;

        return $this->endForAll(Plan::where('code', $s['plan'])->first());
    }

    /**
     * Fin de la gratuité générale : un délai (essai de l'offre donnée) pour chaque espace marchand qui ne paie pas.
     */
    private function endForAll(?Plan $given = null): int
    {
        $given ??= Plan::where('code', $this->settings()['plan'])->first();
        if ($given === null) {
            return 0;
        }
        $days = max(1, $this->settings()['grace_days']);
        $end = CarbonImmutable::now()->addDays($days)->endOfDay();
        $count = 0;

        Store::query()->whereNull('free_plan_id')->where('status', '!=', StoreStatus::Closed->value)->where('status', '!=', StoreStatus::Suspended->value)
            ->with('currentSubscription')->chunkById(200, function ($stores) use ($given, $end, &$count) {
                foreach ($stores as $store) {
                    $sub = $store->currentSubscription;
                    if ($sub?->status === SubscriptionStatus::Active || $store->billing_store_id !== null) {
                        continue;
                    }
                    if ($sub?->status === SubscriptionStatus::Trialing) {
                        if ($sub->trial_ends_at === null || $sub->trial_ends_at->lt($end)) {
                            $sub->update(['trial_ends_at' => $end, 'current_period_end' => $end]);
                        }
                    } else {
                        Subscription::create([
                            'store_id' => $store->id, 'plan_id' => $given->id, 'status' => SubscriptionStatus::Trialing,
                            'trial_ends_at' => $end, 'current_period_start' => now(), 'current_period_end' => $end,
                        ]);
                        $store->forceFill(['status' => StoreStatus::Trial])->save();
                    }
                    $this->notifier->notify($store, Permission::BillingManage->value, new BillingNotice(
                        $store->id,
                        'Fin de la période gratuite',
                        "La période gratuite de Jibiat Marchand se termine. Vous gardez l'offre {$given->name} jusqu'au {$end->locale('fr')->isoFormat('D MMMM')} : choisissez ensuite votre offre dans Réglages › Abonnement.",
                    ));
                    $count++;
                }
            });

        return $count;
    }

    private function expired(): bool
    {
        $until = $this->settings()['until'];

        return $until !== null && CarbonImmutable::parse($until)->endOfDay()->isPast();
    }
}
