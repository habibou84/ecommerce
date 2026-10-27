<?php

namespace App\Services\Billing;

use App\Enums\Permission;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AccessKey;
use App\Models\AccessKeyBatch;
use App\Models\CarrierConnection;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Accounts\TeamNotifier;
use App\Services\Orders\OrderDocuments;
use App\Support\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clés d'accès imprimées en cartes à QR code (console › Clés d'accès) :
 *  - « Membre Premium » à vie (valeur interne « founder ») : carte numérotée, tant que la boutique vit (sans commande
 *    pendant IDLE_DAYS jours, la clé se met en pause et se rallume à la commande suivante) ;
 *  - « Durée » : l'offre pendant quelques mois, puis retour à l'offre gratuite ;
 *  - « Membre Premium » offerte par une entreprise de livraison de Jibiat (« partner », voir PremiumCards) : l'offre
 *    pendant les mois offerts, puis une réduction sur l'abonnement tant que la boutique livre avec cette entreprise.
 * Une clé sert une seule fois, pour un seul espace marchand ; elle s'annule depuis la console.
 * L'assistant IA n'est dans aucune offre : il reste à la recharge.
 */
class AccessKeys
{
    /** Caractères des codes : sans 0/O, 1/I/L, pour se lire sans erreur sur la carte. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const IDLE_DAYS = 60;

    public const IDLE_WARNING_DAYS = 15;

    public const END_WARNING_DAYS = 7;

    public const PREMIUM_LABEL = 'Membre Premium Jibiat Marchand';

    /** @var array<int, AccessKey|false> */
    private array $keys = [];

    /** @var array<int, CarbonImmutable> */
    private array $activity = [];

    /** @var array<int, bool> */
    private array $delivering = [];

    public function __construct(private readonly TeamNotifier $notifier) {}

    public static function normalize(string $code): string
    {
        return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '', 0, 16);
    }

    public function find(string $code): ?AccessKey
    {
        $code = self::normalize($code);

        return strlen($code) === 8 ? AccessKey::with('batch.plan')->where('code', $code)->first() : null;
    }

    public function url(AccessKey $key): string
    {
        return Tenancy::platformUrl('/cle/'.$key->code);
    }

    /**
     * @param  array{kind: string, plan: string, months: ?int, quantity: int, distributor_name: ?string, distributor_phone: ?string, note: ?string}  $data
     */
    public function createBatch(array $data, ?User $by = null): AccessKeyBatch
    {
        $plan = Plan::where('code', $data['plan'])->firstOrFail();

        return DB::transaction(function () use ($data, $plan, $by) {
            $batch = AccessKeyBatch::create([
                'kind' => $data['kind'],
                'plan_id' => $plan->id,
                'months' => $data['kind'] === 'timed' ? $data['months'] : null,
                'quantity' => $data['quantity'],
                'distributor_name' => $data['distributor_name'] ?? null,
                'distributor_phone' => $data['distributor_phone'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $by?->id,
            ]);
            for ($n = 1; $n <= $batch->quantity; $n++) {
                $batch->keys()->create(['number' => $n, 'code' => $this->newCode()]);
            }

            return $batch;
        });
    }

    public function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (AccessKey::where('code', $code)->exists());

        return $code;
    }

    /**
     * Clé de l'espace marchand (la plus récente activée et non annulée), active ou non.
     */
    public function keyOf(Store $store): ?AccessKey
    {
        if (! array_key_exists($store->id, $this->keys)) {
            $this->keys[$store->id] = AccessKey::with('batch.plan')->where('store_id', $store->id)->whereNull('revoked_at')
                ->latest('activated_at')->latest('id')->first() ?? false;
        }

        return $this->keys[$store->id] ?: null;
    }

    /**
     * État d'une clé : available (pas encore activée), active, paused (carte à vie sans commande), ended (durée
     * terminée), revoked. Carte offerte par une entreprise : free (mois offerts), discount (réduction, la boutique
     * livre avec l'entreprise), lapsed (elle ne livre plus avec elle), comme Contract::PREMIUM_CARD_STATES.
     */
    public function state(AccessKey $key): string
    {
        if ($key->revoked_at !== null) {
            return 'revoked';
        }
        if ($key->store_id === null || $key->activated_at === null) {
            return 'available';
        }
        if ($key->batch->isPartner()) {
            if ($key->expires_at !== null && $key->expires_at->isFuture()) {
                return 'free';
            }

            return $this->deliversWithPartner($key) ? 'discount' : 'lapsed';
        }

        return match (true) {
            $key->expires_at !== null && $key->expires_at->isPast() => 'ended',
            $key->batch->isFounder() && $this->idleSince($key)->lt(now()->subDays(self::IDLE_DAYS)) => 'paused',
            default => 'active',
        };
    }

    /**
     * La boutique livre toujours avec l'entreprise qui lui a offert la carte : connexion active à cette entreprise
     * et une expédition confiée dans les derniers jours (réglage « active_days »).
     */
    public function deliversWithPartner(AccessKey $key): bool
    {
        if (! array_key_exists($key->id, $this->delivering)) {
            $slug = $key->batch->partner_slug;
            $connections = CarrierConnection::forStore($key->store_id)->where('driver', CarrierConnection::DELIVERY_APP)->where('status', 'active')->get()
                ->filter(fn (CarrierConnection $c) => ($c->remote_account['company']['slug'] ?? null) === $slug)->pluck('id');
            $this->delivering[$key->id] = $connections->isNotEmpty() && Shipment::forStore($key->store_id)->whereIn('carrier_connection_id', $connections)
                ->where('created_at', '>=', now()->subDays(app(PremiumCards::class)->settings()['active_days']))->exists();
        }

        return $this->delivering[$key->id];
    }

    /**
     * Dernière activité de la boutique : sa dernière commande, ou l'activation de la clé si elle est plus récente.
     */
    public function idleSince(AccessKey $key): CarbonImmutable
    {
        if (! array_key_exists($key->id, $this->activity)) {
            $last = Order::forStore($key->store_id)->max('created_at');
            $activated = CarbonImmutable::parse($key->activated_at);
            $this->activity[$key->id] = $last !== null && CarbonImmutable::parse($last)->gt($activated) ? CarbonImmutable::parse($last) : $activated;
        }

        return $this->activity[$key->id];
    }

    /**
     * Offre donnée par la clé de la boutique en ce moment (clé active seulement).
     */
    public function planFor(Store $store): ?Plan
    {
        $key = $this->keyOf($store);

        return $key !== null && in_array($this->state($key), ['active', 'free'], true) ? $key->batch->plan : null;
    }

    /**
     * Réduction sur l'abonnement donnée par la carte de la boutique (carte offerte par une entreprise, après les mois
     * offerts, tant que la boutique livre avec elle), sinon null.
     *
     * @return array{rate: float, company: string}|null
     */
    public function discountFor(Store $store): ?array
    {
        $key = $this->keyOf($store);
        if ($key === null || ! $key->batch->isPartner() || $this->state($key) !== 'discount' || ($key->batch->discount_rate ?? 0) <= 0) {
            return null;
        }

        return ['rate' => (float) $key->batch->discount_rate, 'company' => (string) $key->batch->distributor_name];
    }

    /**
     * Activation par l'espace marchand. Une boutique n'a qu'une clé : une carte Membre Premium (à vie ou offerte par
     * une entreprise) peut remplacer une clé à durée, jamais l'inverse, et une seule clé à durée par boutique. Une
     * carte offerte par une entreprise rattache la boutique à son parrainage si elle n'en a pas.
     */
    public function redeem(string $code, Store $store): AccessKey
    {
        $found = $this->find($code);
        if ($found === null || $found->revoked_at !== null) {
            throw new BusinessRuleException('Cette clé n\'existe pas ou n\'est plus valable. Vérifiez le code écrit sur la carte.', 'code');
        }

        $key = DB::transaction(function () use ($found, $store) {
            $key = AccessKey::whereKey($found->id)->lockForUpdate()->first();
            if ($key->store_id !== null) {
                throw new BusinessRuleException($key->store_id === $store->id ? 'Cette clé est déjà activée sur votre espace marchand.' : 'Cette clé a déjà été utilisée.', 'code');
            }
            $current = $this->keyOf($store);
            if ($current !== null) {
                if ($current->batch->isPremium()) {
                    throw new BusinessRuleException('Votre espace marchand a déjà sa carte Membre Premium.', 'code');
                }
                if (! $found->batch->isPremium()) {
                    throw new BusinessRuleException('Votre espace marchand a déjà utilisé une clé à durée : une seule par boutique.', 'code');
                }
            }

            $months = $found->batch->months;
            $key->forceFill([
                'store_id' => $store->id,
                'activated_at' => now(),
                'expires_at' => $found->batch->isFounder() ? null : now()->addMonthsNoOverflow((int) $months)->endOfDay(),
            ])->save();
            if ($found->batch->isPartner() && blank($store->referred_by)) {
                $store->forceFill(['referred_by' => $found->batch->partner_slug])->save();
            }

            return $key;
        });

        unset($this->keys[$store->id]);
        $key->setRelation('batch', $found->batch);
        $this->endCoveredTrial($store, $found->batch->plan);

        return $key;
    }

    /**
     * Essai en cours d'une offre égale ou plus petite que celle de la clé : la clé prend le relais tout de suite.
     */
    private function endCoveredTrial(Store $store, Plan $plan): void
    {
        $sub = $store->currentSubscription()->with('plan')->first();
        if ($sub?->status !== SubscriptionStatus::Trialing || ($sub->plan?->sort ?? 0) > $plan->sort) {
            return;
        }
        $sub->update(['status' => SubscriptionStatus::Expired]);
        if ($store->status === StoreStatus::Trial) {
            $store->forceFill(['status' => StoreStatus::Active])->save();
        }
        $store->unsetRelation('currentSubscription');
    }

    public function revoke(AccessKey $key): void
    {
        $key->forceFill(['revoked_at' => now()])->save();
        if ($key->store_id !== null) {
            unset($this->keys[$key->store_id]);
        }
    }

    /**
     * Ce que l'espace marchand voit de sa clé.
     *
     * @return array<string, mixed>|null
     */
    public function present(Store $store): ?array
    {
        $key = $this->keyOf($store);
        if ($key === null) {
            return null;
        }
        $state = $this->state($key);
        $batch = $key->batch;
        $founder = $batch->isFounder();

        return [
            'kind' => $batch->kind,
            // Carte à vie (offre sans fin) ; « premium » : toute carte Membre Premium, à vie ou offerte par une entreprise
            'founder' => $founder,
            'premium' => $batch->isPremium(),
            'badge' => $batch->isPremium() ? self::PREMIUM_LABEL.' n° '.$this->number($key) : null,
            'offered_by' => $batch->isPartner() ? $batch->distributor_name : null,
            'free_months' => $batch->isPartner() ? $batch->months : null,
            'discount_rate' => $batch->isPartner() ? $batch->discount_rate : null,
            'number' => $key->number,
            'quantity' => $key->batch->quantity,
            'plan' => $key->batch->plan->name,
            'state' => $state,
            'activated_at' => $key->activated_at?->toIso8601String(),
            'until' => $key->expires_at?->toDateString(),
            // Carte à vie : date à laquelle la clé se met en pause sans nouvelle commande
            'pause_on' => $founder && $state === 'active' ? $this->idleSince($key)->addDays(self::IDLE_DAYS)->toDateString() : null,
        ];
    }

    public function number(AccessKey $key): string
    {
        return str_pad((string) $key->number, max(3, strlen((string) $key->batch->quantity)), '0', STR_PAD_LEFT);
    }

    /**
     * Informations publiques d'une clé (page du QR code), sans rien sur la boutique qui l'a activée.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $code): ?array
    {
        $key = $this->find($code);
        if ($key === null || $key->revoked_at !== null) {
            return null;
        }
        $batch = $key->batch;

        return [
            'code' => $key->displayCode(),
            'founder' => $batch->isFounder(),
            'premium' => $batch->isPremium(),
            'badge' => match (true) {
                $batch->isFounder() => self::PREMIUM_LABEL.' n° '.$this->number($key).' / '.$batch->quantity,
                $batch->isPartner() => self::PREMIUM_LABEL.' n° '.$this->number($key),
                default => null,
            },
            // Carte offerte par une entreprise : son nom, son adresse dans l'annuaire (parrainage) et la formule
            'offered_by' => $batch->isPartner() ? ['slug' => $batch->partner_slug, 'name' => $batch->distributor_name] : null,
            'discount_rate' => $batch->isPartner() ? $batch->discount_rate : null,
            'plan' => $batch->plan->name,
            'months' => $batch->months,
            'available' => $key->store_id === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentKey(AccessKey $key): array
    {
        return [
            'id' => $key->id,
            'number' => $key->number,
            'code' => $key->displayCode(),
            'url' => $this->url($key),
            'state' => $this->state($key),
            'store' => $key->store ? ['id' => $key->store->id, 'name' => $key->store->name] : null,
            'activated_at' => $key->activated_at?->toIso8601String(),
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'merchant_name' => $key->merchant_name,
            'partner_reason' => $key->partner_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBatch(AccessKeyBatch $batch, bool $withKeys = false): array
    {
        $keys = $batch->keys()->with(['store:id,name', 'batch.plan'])->orderBy('number')->get();
        $states = $keys->map(fn (AccessKey $k) => $this->state($k))->countBy();

        return [
            'id' => $batch->id,
            'kind' => $batch->kind,
            'kind_label' => AccessKeyBatch::LABELS[$batch->kind] ?? $batch->kind,
            'partner_slug' => $batch->partner_slug,
            'discount_rate' => $batch->discount_rate,
            'plan' => $batch->plan->name,
            'plan_code' => $batch->plan->code,
            'months' => $batch->months,
            'quantity' => $batch->quantity,
            'distributor_name' => $batch->distributor_name,
            'distributor_phone' => $batch->distributor_phone,
            'note' => $batch->note,
            'created_at' => $batch->created_at->toIso8601String(),
            'counts' => [
                'available' => $states['available'] ?? 0,
                'activated' => $keys->whereNotNull('activated_at')->count(),
                'active' => $states['active'] ?? 0,
                'paused' => $states['paused'] ?? 0,
                'ended' => $states['ended'] ?? 0,
                'revoked' => $states['revoked'] ?? 0,
                'free' => $states['free'] ?? 0,
                'discount' => $states['discount'] ?? 0,
                'lapsed' => $states['lapsed'] ?? 0,
            ],
            'keys' => $withKeys ? $keys->map(fn (AccessKey $k) => $this->presentKey($k))->values() : null,
        ];
    }

    /**
     * Cartes à imprimer (format carte bancaire, 10 par page A4) : clés du lot pas encore activées ni annulées.
     */
    public function cards(AccessKeyBatch $batch): Response
    {
        $docs = app(OrderDocuments::class);
        $cards = $batch->keys()->whereNull('store_id')->whereNull('revoked_at')->orderBy('number')->get()
            ->map(fn (AccessKey $k) => [
                'code' => $k->displayCode(),
                'number' => match (true) {
                    $batch->isFounder() => $this->number($k->setRelation('batch', $batch)).' / '.$batch->quantity,
                    $batch->isPartner() => $this->number($k->setRelation('batch', $batch)),
                    default => null,
                },
                'qr' => $docs->qr($this->url($k)),
            ]);

        return Pdf::loadView('pdf.access-keys', [
            'batch' => $batch,
            'cards' => $cards,
            'domain' => Tenancy::domain(),
        ])->setPaper('a4')->download("cles-{$batch->id}.pdf");
    }

    /**
     * Tâche quotidienne : préviens avant la pause d'une carte à vie et avant la fin d'une clé à durée, puis à la pause
     * et à la fin. Carte offerte par une entreprise : prévient avant la fin des mois offerts, puis quand la réduction
     * commence, s'arrête ou reprend (« discount »).
     *
     * @return array{warned: int, paused: int, ended: int, discount: int}
     */
    public function sweep(): array
    {
        $counts = ['warned' => 0, 'paused' => 0, 'ended' => 0, 'discount' => 0];

        AccessKey::with(['batch.plan', 'store'])->whereNotNull('store_id')->whereNull('revoked_at')->whereNull('ended_at')
            ->chunkById(200, function ($keys) use (&$counts) {
                foreach ($keys as $key) {
                    $store = $key->store;
                    if ($store === null || $store->status === StoreStatus::Closed) {
                        continue;
                    }
                    $state = $this->state($key);
                    $plan = $key->batch->plan->name;

                    if ($key->batch->isPartner()) {
                        $this->sweepPartner($key, $store, $state, $counts);

                        continue;
                    }

                    if (! $key->batch->isFounder()) {
                        if ($state === 'ended') {
                            $key->forceFill(['ended_at' => now()])->save();
                            $this->notify($store, 'Fin de votre clé d\'accès', "Votre clé d'accès à l'offre {$plan} est terminée : votre espace marchand passe à l'offre gratuite, sans rien perdre. Choisissez une offre dans Réglages › Abonnement pour garder toutes les fonctions.");
                            $counts['ended']++;
                        } elseif ($key->warned_at === null && $key->expires_at->lte(now()->addDays(self::END_WARNING_DAYS))) {
                            $key->forceFill(['warned_at' => now()])->save();
                            $this->notify($store, 'Votre clé d\'accès se termine bientôt', "Votre clé d'accès à l'offre {$plan} se termine le {$key->expires_at->locale('fr')->isoFormat('D MMMM')}. Choisissez une offre dans Réglages › Abonnement pour garder toutes les fonctions.");
                            $counts['warned']++;
                        }

                        continue;
                    }

                    // Carte à vie : rallumée par une commande (l'état se calcule à chaque lecture)
                    $idle = $this->idleSince($key);
                    if ($state === 'active') {
                        $warnFrom = $idle->addDays(self::IDLE_DAYS - self::IDLE_WARNING_DAYS);
                        $changes = $key->paused_at !== null ? ['paused_at' => null] : [];
                        if ($key->warned_at !== null && $key->warned_at->lt($idle)) {
                            $changes['warned_at'] = null;
                        }
                        $warned = array_key_exists('warned_at', $changes) ? $changes['warned_at'] : $key->warned_at;
                        if ($warned === null && now()->gte($warnFrom)) {
                            $changes['warned_at'] = now();
                            $this->notify($store, 'Votre carte Membre Premium', "Aucune commande depuis le {$idle->locale('fr')->isoFormat('D MMMM')} : votre carte Membre Premium se met en pause le {$idle->addDays(self::IDLE_DAYS)->locale('fr')->isoFormat('D MMMM')}. Enregistrez une commande pour la garder active.");
                            $counts['warned']++;
                        }
                        if ($changes) {
                            $key->forceFill($changes)->save();
                        }
                    } elseif ($state === 'paused' && $key->paused_at === null) {
                        $key->forceFill(['paused_at' => now()])->save();
                        $this->notify($store, 'Carte Membre Premium en pause', 'Sans commande depuis '.self::IDLE_DAYS." jours, votre carte Membre Premium est en pause : votre espace marchand passe à l'offre gratuite, sans rien perdre. Elle se rallume toute seule dès votre prochaine commande.");
                        $counts['paused']++;
                    }
                }
            });

        return $counts;
    }

    /**
     * Carte offerte par une entreprise : prévient END_WARNING_DAYS jours avant la fin des mois offerts, puis quand la
     * réduction commence, s'arrête (plus d'expédition avec l'entreprise) ou reprend.
     *
     * @param  array<string, int>  $counts
     */
    private function sweepPartner(AccessKey $key, Store $store, string $state, array &$counts): void
    {
        $batch = $key->batch;
        $company = (string) $batch->distributor_name;
        $rate = (int) round(($batch->discount_rate ?? 0) * 100);
        if ($state === 'free') {
            if ($key->warned_at === null && $key->expires_at->lte(now()->addDays(self::END_WARNING_DAYS))) {
                $key->forceFill(['warned_at' => now()])->save();
                $this->notify($store, 'Vos mois offerts se terminent bientôt', "Vos mois offerts de l'offre {$batch->plan->name} se terminent le {$key->expires_at->locale('fr')->isoFormat('D MMMM')}. Ensuite, votre carte Membre Premium vous donne −{$rate} % sur votre abonnement tant que vous livrez avec {$company}. Choisissez votre offre dans Réglages › Abonnement.");
                $counts['warned']++;
            }

            return;
        }
        $on = $state === 'discount';
        if ($key->discount_on === $on) {
            return;
        }
        $first = $key->discount_on === null;
        $key->forceFill(['discount_on' => $on])->save();
        $days = app(PremiumCards::class)->settings()['active_days'];
        match (true) {
            $on => $this->notify($store, 'Votre réduction Membre Premium', "Votre carte Membre Premium vous donne −{$rate} % sur votre abonnement Jibiat Marchand, tant que vous livrez avec {$company}."),
            $first => $this->notify($store, 'Fin de vos mois offerts', "Vos mois offerts sont terminés. Confiez vos livraisons à {$company} depuis Jibiat Marchand : votre carte Membre Premium vous donne alors −{$rate} % sur votre abonnement."),
            default => $this->notify($store, 'Réduction Membre Premium arrêtée', "Aucune livraison confiée à {$company} depuis {$days} jours : la réduction de {$rate} % de votre carte Membre Premium est arrêtée. Elle reprend dès votre prochaine livraison avec {$company}."),
        };
        $counts['discount']++;
    }

    private function notify(Store $store, string $title, string $body): void
    {
        $this->notifier->notify($store, Permission::BillingManage->value, new BillingNotice($store->id, $title, $body));
    }
}
