<?php

namespace App\Services\Billing;

use App\Enums\Permission;
use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AccessKey;
use App\Models\AccessKeyBatch;
use App\Models\Order;
use App\Models\Plan;
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
 *  - « Membre fondateur » : offre à vie, carte numérotée, tant que la boutique vit (sans commande pendant
 *    IDLE_DAYS jours, la clé se met en pause et se rallume à la commande suivante) ;
 *  - « Durée » : l'offre pendant quelques mois, puis retour à l'offre gratuite.
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

    /** @var array<int, AccessKey|false> */
    private array $keys = [];

    /** @var array<int, CarbonImmutable> */
    private array $activity = [];

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

    private function newCode(): string
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
     * État d'une clé : available (pas encore activée), active, paused (fondateur sans commande), ended (durée
     * terminée), revoked.
     */
    public function state(AccessKey $key): string
    {
        return match (true) {
            $key->revoked_at !== null => 'revoked',
            $key->store_id === null || $key->activated_at === null => 'available',
            $key->expires_at !== null && $key->expires_at->isPast() => 'ended',
            $key->batch->isFounder() && $this->idleSince($key)->lt(now()->subDays(self::IDLE_DAYS)) => 'paused',
            default => 'active',
        };
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

        return $key !== null && $this->state($key) === 'active' ? $key->batch->plan : null;
    }

    /**
     * Activation par l'espace marchand. Une boutique n'a qu'une clé : une carte fondateur peut remplacer une clé à
     * durée, jamais l'inverse, et une seule clé à durée par boutique.
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
                if ($current->batch->isFounder()) {
                    throw new BusinessRuleException('Votre espace marchand a déjà sa carte de membre fondateur.', 'code');
                }
                if (! $found->batch->isFounder()) {
                    throw new BusinessRuleException('Votre espace marchand a déjà utilisé une clé à durée : une seule par boutique.', 'code');
                }
            }

            $months = $found->batch->months;
            $key->forceFill([
                'store_id' => $store->id,
                'activated_at' => now(),
                'expires_at' => $found->batch->isFounder() ? null : now()->addMonthsNoOverflow((int) $months)->endOfDay(),
            ])->save();

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
        $founder = $key->batch->isFounder();

        return [
            'kind' => $key->batch->kind,
            'founder' => $founder,
            'badge' => $founder ? 'Membre fondateur n° '.$this->number($key) : null,
            'number' => $key->number,
            'quantity' => $key->batch->quantity,
            'plan' => $key->batch->plan->name,
            'state' => $state,
            'activated_at' => $key->activated_at?->toIso8601String(),
            'until' => $key->expires_at?->toDateString(),
            // Fondateur : date à laquelle la clé se met en pause sans nouvelle commande
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
            'badge' => $batch->isFounder() ? 'Membre fondateur n° '.$this->number($key).' / '.$batch->quantity : null,
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
            'kind_label' => AccessKeyBatch::KINDS[$batch->kind] ?? $batch->kind,
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
                'number' => $batch->isFounder() ? $this->number($k->setRelation('batch', $batch)).' / '.$batch->quantity : null,
                'qr' => $docs->qr($this->url($k)),
            ]);

        return Pdf::loadView('pdf.access-keys', [
            'batch' => $batch,
            'cards' => $cards,
            'domain' => Tenancy::domain(),
        ])->setPaper('a4')->download("cles-{$batch->id}.pdf");
    }

    /**
     * Tâche quotidienne : préviens avant la pause d'une carte fondateur et avant la fin d'une clé à durée, puis à
     * la pause et à la fin.
     *
     * @return array{warned: int, paused: int, ended: int}
     */
    public function sweep(): array
    {
        $counts = ['warned' => 0, 'paused' => 0, 'ended' => 0];

        AccessKey::with(['batch.plan', 'store'])->whereNotNull('store_id')->whereNull('revoked_at')->whereNull('ended_at')
            ->chunkById(200, function ($keys) use (&$counts) {
                foreach ($keys as $key) {
                    $store = $key->store;
                    if ($store === null || $store->status === StoreStatus::Closed) {
                        continue;
                    }
                    $state = $this->state($key);
                    $plan = $key->batch->plan->name;

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

                    // Carte fondateur : rallumée par une commande (l'état se calcule à chaque lecture)
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
                            $this->notify($store, 'Votre carte de membre fondateur', "Aucune commande depuis le {$idle->locale('fr')->isoFormat('D MMMM')} : votre carte de membre fondateur se met en pause le {$idle->addDays(self::IDLE_DAYS)->locale('fr')->isoFormat('D MMMM')}. Enregistrez une commande pour la garder active.");
                            $counts['warned']++;
                        }
                        if ($changes) {
                            $key->forceFill($changes)->save();
                        }
                    } elseif ($state === 'paused' && $key->paused_at === null) {
                        $key->forceFill(['paused_at' => now()])->save();
                        $this->notify($store, 'Carte de membre fondateur en pause', 'Sans commande depuis '.self::IDLE_DAYS." jours, votre carte de membre fondateur est en pause : votre espace marchand passe à l'offre gratuite, sans rien perdre. Elle se rallume toute seule dès votre prochaine commande.");
                        $counts['paused']++;
                    }
                }
            });

        return $counts;
    }

    private function notify(Store $store, string $title, string $body): void
    {
        $this->notifier->notify($store, Permission::BillingManage->value, new BillingNotice($store->id, $title, $body));
    }
}
