<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\AccessKey;
use App\Models\AccessKeyBatch;
use App\Models\Plan;
use App\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\PhoneNumber;

/**
 * Cartes Membre Premium Jibiat Marchand offertes par les entreprises de livraison de Jibiat (contrat socle 1.6,
 * POST/GET/DELETE /api/partners/v1/premium-cards, appelé par Jibiat avec un secret partagé).
 *
 * Formule réglée dans la console (groupe « premium ») : l'offre « plan » pendant « free_months » mois à partir de
 * l'activation, puis « discount_rate » de réduction sur l'abonnement tant que la boutique livre avec l'entreprise
 * (une expédition confiée dans les « active_days » derniers jours). Une carte garde la formule du jour où elle a été
 * offerte : un lot par entreprise et par formule, numéros suivis par entreprise.
 */
class PremiumCards
{
    public const DEFAULTS = ['plan' => 'pro', 'free_months' => 6, 'discount_rate' => 0.3, 'active_days' => 60];

    /** @var array{plan: string, free_months: int, discount_rate: float, active_days: int}|null */
    private ?array $settings = null;

    public function __construct(private readonly AccessKeys $keys) {}

    /**
     * @return array{plan: string, free_months: int, discount_rate: float, active_days: int}
     */
    public function settings(): array
    {
        if ($this->settings === null) {
            $s = PlatformSetting::group('premium');
            $this->settings = [
                'plan' => $s['plan'] ?? self::DEFAULTS['plan'],
                'free_months' => isset($s['free_months']) ? (int) $s['free_months'] : self::DEFAULTS['free_months'],
                'discount_rate' => isset($s['discount_rate']) ? (float) $s['discount_rate'] : self::DEFAULTS['discount_rate'],
                'active_days' => isset($s['active_days']) ? (int) $s['active_days'] : self::DEFAULTS['active_days'],
            ];
        }

        return $this->settings;
    }

    /**
     * @param  array{plan: string, free_months: int, discount_rate: float, active_days: int}  $values
     */
    public function updateSettings(array $values): void
    {
        foreach (['plan', 'free_months', 'discount_rate', 'active_days'] as $field) {
            PlatformSetting::put("premium.{$field}", (string) $values[$field]);
        }
        $this->settings = null;
    }

    /**
     * Carte demandée par une entreprise pour un de ses marchands (corps premium-card-request). La carte déjà offerte
     * à ce marchand, si elle n'est pas annulée, est rendue telle quelle.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: AccessKey, 1: bool} la carte, et true si elle vient d'être créée
     */
    public function offer(array $data): array
    {
        $slug = (string) $data['company']['slug'];
        $reference = (string) $data['merchant']['reference'];
        if (($existing = $this->find($slug, $reference)) !== null) {
            return [$existing, false];
        }
        $settings = $this->formula($data['offer'] ?? null);
        $plan = Plan::where('code', $settings['plan'])->first()
            ?? throw new BusinessRuleException('L\'offre des cartes Membre Premium n\'est pas réglée.', 'plan');

        $key = DB::transaction(function () use ($data, $slug, $reference, $settings, $plan) {
            $batch = AccessKeyBatch::where('kind', AccessKeyBatch::PARTNER)->where('partner_slug', $slug)->where('plan_id', $plan->id)
                ->where('months', $settings['free_months'])->where('discount_rate', $settings['discount_rate'])->lockForUpdate()->first()
                ?? AccessKeyBatch::create([
                    'kind' => AccessKeyBatch::PARTNER, 'partner_slug' => $slug, 'plan_id' => $plan->id, 'months' => $settings['free_months'],
                    'discount_rate' => $settings['discount_rate'], 'quantity' => 0,
                ]);
            // Une seule carte par marchand : vérifiée de nouveau sous verrou
            if (($existing = $this->find($slug, $reference)) !== null) {
                return $existing;
            }
            $batch->forceFill([
                'distributor_name' => (string) $data['company']['name'],
                'distributor_phone' => $this->phone($data['company']['phone'] ?? null),
                'quantity' => $batch->quantity + 1,
            ])->save();
            $number = 1 + (int) AccessKey::whereIn('batch_id', AccessKeyBatch::where('partner_slug', $slug)->select('id'))->max('number');

            return $batch->keys()->create([
                'number' => $number,
                'code' => $this->keys->newCode(),
                'partner_reference' => $reference,
                'partner_reason' => (string) $data['reason'],
                'merchant_name' => (string) $data['merchant']['name'],
                'merchant_phone' => $this->phone($data['merchant']['phone'] ?? null),
            ]);
        });

        return [$key->load('batch.plan', 'store'), $key->wasRecentlyCreated];
    }

    /**
     * Formule de la carte : celle choisie par l'entreprise (socle 1.8, champ « offer », dans les limites fixées par
     * la console Jibiat), sinon celle réglée ici.
     *
     * @param  mixed  $offer
     * @return array{plan: string, free_months: int, discount_rate: float, active_days: int}
     */
    public function formula($offer): array
    {
        $settings = $this->settings();
        if (! is_array($offer) || ! in_array($offer['plan'] ?? null, ['starter', 'pro', 'business'], true)) {
            return $settings;
        }

        return [
            ...$settings,
            'plan' => $offer['plan'],
            'free_months' => max(0, min(36, (int) ($offer['free_months'] ?? $settings['free_months']))),
            'discount_rate' => round(max(0, min(0.9, (float) ($offer['discount_rate'] ?? $settings['discount_rate']))), 3),
        ];
    }

    /**
     * Carte non annulée offerte par l'entreprise à ce marchand.
     */
    public function find(string $slug, string $reference): ?AccessKey
    {
        return $this->query($slug)->where('partner_reference', $reference)->whereNull('revoked_at')->latest('id')->first();
    }

    /**
     * @return Collection<int, AccessKey> cartes de l'entreprise, les plus récentes d'abord
     */
    public function list(string $slug): Collection
    {
        return $this->query($slug)->latest('id')->get();
    }

    /**
     * Annule une carte de l'entreprise (null si le code n'est pas à elle).
     */
    public function revoke(string $slug, string $code): ?AccessKey
    {
        $key = $this->query($slug)->where('code', AccessKeys::normalize($code))->first();
        if ($key !== null && $key->revoked_at === null) {
            $this->keys->revoke($key);
        }

        return $key;
    }

    /**
     * Carte telle que l'entreprise la voit (contrat premium-card).
     *
     * @return array<string, mixed>
     */
    public function present(AccessKey $key): array
    {
        $batch = $key->batch;

        return [
            'code' => $key->code,
            'number' => $key->number,
            'label' => AccessKeys::PREMIUM_LABEL.' n° '.$this->keys->number($key),
            'url' => $this->keys->url($key),
            'state' => $this->keys->state($key),
            'plan' => $batch->plan->name,
            'free_months' => (int) $batch->months,
            'discount_rate' => (float) $batch->discount_rate,
            'free_until' => $key->activated_at !== null ? $key->expires_at?->toDateString() : null,
            'offered_by' => ['slug' => (string) $batch->partner_slug, 'name' => (string) $batch->distributor_name],
            'merchant_reference' => (string) $key->partner_reference,
            'store' => $key->store_id !== null && $key->store !== null ? ['name' => $key->store->name, 'url' => $key->store->url()] : null,
            'activated_at' => $key->activated_at?->toIso8601String(),
            'created_at' => $key->created_at->toIso8601String(),
        ];
    }

    /**
     * @return Builder<AccessKey>
     */
    private function query(string $slug): Builder
    {
        return AccessKey::with(['batch.plan', 'store'])->whereIn('batch_id', AccessKeyBatch::where('kind', AccessKeyBatch::PARTNER)->where('partner_slug', $slug)->select('id'));
    }

    private function phone(mixed $phone): ?string
    {
        return filled($phone) ? substr((string) PhoneNumber::normalize((string) $phone), 0, 32) : null;
    }
}
