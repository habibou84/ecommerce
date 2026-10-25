<?php

namespace App\Services\Partners;

use App\Exceptions\BusinessRuleException;
use App\Jobs\ReportPartnerReferral;
use App\Models\CarrierConnection;
use App\Models\PlatformSetting;
use App\Models\ReferralCommission;
use App\Models\ReferralPayout;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Shipping\CarrierDirectory;
use App\Services\Shipping\Drivers\DeliveryAppDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parrainage par les entreprises de livraison de Jibiat : une boutique créée avec le lien d'une entreprise de
 * l'annuaire (stores.referred_by) lui rapporte une part de chaque abonnement payé (20 % par défaut), pendant
 * 12 mois à partir du premier paiement. La console verse ces parts ; l'entreprise suit tout dans Jibiat
 * (PUT /api/public/v1/merchant-app avec la clé de la connexion de la boutique à cette entreprise).
 */
class PartnerReferrals
{
    public const DEFAULTS = ['rate' => 0.2, 'months' => 12];

    public function __construct(private readonly CarrierDirectory $directory) {}

    /**
     * @return array{rate: float, months: int}
     */
    public function settings(): array
    {
        $s = PlatformSetting::group('referral');

        return [
            'rate' => isset($s['rate']) ? (float) $s['rate'] : self::DEFAULTS['rate'],
            'months' => isset($s['months']) ? (int) $s['months'] : self::DEFAULTS['months'],
        ];
    }

    /**
     * @param  array{rate: float, months: int}  $values
     */
    public function updateSettings(array $values): void
    {
        PlatformSetting::put('referral.rate', (string) $values['rate']);
        PlatformSetting::put('referral.months', (string) $values['months']);
    }

    /**
     * Abonnement payé : part de l'entreprise qui a parrainé la boutique (une seule fois par facture).
     */
    public function record(SubscriptionInvoice $invoice): ?ReferralCommission
    {
        $store = Store::find($invoice->store_id);
        if ($store === null || blank($store->referred_by) || $invoice->status !== 'paid' || $invoice->kind === 'ai_pack' || $invoice->amount <= 0) {
            return null;
        }
        $settings = $this->settings();
        $first = ReferralCommission::where('store_id', $store->id)->min('earned_at');
        $paidAt = $invoice->paid_at ?? now();
        if ($first !== null && Carbon::parse($first)->addMonths($settings['months'])->lte($paidAt)) {
            return null; // durée du parrainage écoulée
        }

        $commission = ReferralCommission::firstOrCreate(['subscription_invoice_id' => $invoice->id], [
            'store_id' => $store->id,
            'partner' => $store->referred_by,
            'invoice_amount' => $invoice->amount,
            'rate' => $settings['rate'],
            'amount' => (int) round($invoice->amount * $settings['rate']),
            'earned_at' => $paidAt,
        ]);
        DB::afterCommit(fn () => ReportPartnerReferral::dispatch($store->id));

        return $commission;
    }

    /**
     * Fin du parrainage de la boutique (null avant le premier paiement).
     */
    public function endsAt(Store $store): ?Carbon
    {
        $first = ReferralCommission::where('store_id', $store->id)->min('earned_at');

        return $first !== null ? Carbon::parse($first)->addMonths($this->settings()['months']) : null;
    }

    /**
     * Compte Jibiat Marchand de la boutique tel qu'envoyé à l'entreprise qui l'a parrainée (contrat merchant-app).
     *
     * @return array<string, mixed>
     */
    public function report(Store $store): array
    {
        $commissions = ReferralCommission::where('store_id', $store->id)->get(['amount', 'earned_at', 'referral_payout_id']);
        $subscription = $store->currentSubscription()->with('plan')->first();
        $settings = $this->settings();

        return [
            'store_name' => $store->name,
            'store_url' => $store->url(),
            'referred' => true,
            'signed_up_at' => $store->created_at?->toIso8601String(),
            'plan' => ['name' => $subscription?->plan?->name, 'status' => $subscription?->status->value ?? 'none'],
            'commission' => [
                'rate' => $settings['rate'],
                'months' => $settings['months'],
                'ends_at' => $this->endsAt($store)?->toDateString(),
                'currency' => 'XOF',
                'earned_total' => (int) $commissions->sum('amount'),
                'earned_month' => (int) $commissions->filter(fn ($c) => $c->earned_at->isSameMonth(now()))->sum('amount'),
                'paid_total' => (int) $commissions->whereNotNull('referral_payout_id')->sum('amount'),
            ],
        ];
    }

    /**
     * Connexion de la boutique à l'entreprise qui l'a parrainée (compte lu chez elle : company.slug), sinon null.
     */
    public function partnerConnection(Store $store): ?CarrierConnection
    {
        if (blank($store->referred_by)) {
            return null;
        }

        return CarrierConnection::forStore($store->id)->where('driver', CarrierConnection::DELIVERY_APP)->where('status', 'active')->get()
            ->first(fn (CarrierConnection $c) => ($c->remote_account['company']['slug'] ?? null) === $store->referred_by);
    }

    /**
     * La connexion est celle de l'entreprise qui a parrainé la boutique (entreprise attitrée).
     */
    public static function isPartner(Store $store, CarrierConnection $connection): bool
    {
        return filled($store->referred_by) && ($connection->remote_account['company']['slug'] ?? null) === $store->referred_by;
    }

    /**
     * Envoie le compte de la boutique à l'entreprise qui l'a parrainée, si la boutique y est connectée.
     */
    public function push(Store $store): bool
    {
        $connection = $this->partnerConnection($store);
        if ($connection === null) {
            return false;
        }
        try {
            $response = Http::baseUrl(rtrim((string) $connection->base_url, '/').'/')
                ->withToken((string) $connection->api_key)->acceptJson()->asJson()
                ->withHeaders(['X-Client-App' => DeliveryAppDriver::CLIENT_APP])
                ->timeout(15)->connectTimeout(5)->withoutRedirecting()
                ->put('merchant-app', $this->report($store));
        } catch (Throwable $e) {
            Log::warning('Parrainage : compte non envoyé à l\'entreprise', ['store' => $store->id, 'error' => $e->getMessage()]);

            return false;
        }
        // Jibiat plus ancien (404) : rien à envoyer pour l'instant
        if ($response->failed() && $response->status() !== 404) {
            Log::warning('Parrainage : compte refusé par l\'entreprise', ['store' => $store->id, 'status' => $response->status()]);
        }

        return $response->successful();
    }

    /**
     * Entreprises qui ont parrainé des boutiques : boutiques, parts gagnées, versées et à verser.
     *
     * @return list<array<string, mixed>>
     */
    public function partners(): array
    {
        $stores = Store::whereNotNull('referred_by')->with('currentSubscription')->get(['id', 'name', 'slug', 'referred_by', 'created_at']);
        $commissions = ReferralCommission::get(['store_id', 'partner', 'amount', 'earned_at', 'referral_payout_id']);
        $payouts = ReferralPayout::latest('paid_at')->get();

        return $stores->groupBy('referred_by')->map(function ($group, string $slug) use ($commissions, $payouts) {
            $mine = $commissions->where('partner', $slug);
            $earned = (int) $mine->sum('amount');
            $paid = (int) $mine->whereNotNull('referral_payout_id')->sum('amount');
            $company = $this->directory->find($slug);

            return [
                'partner' => $slug,
                'name' => $company['name'] ?? $slug,
                'phone' => $company['phone'] ?? null,
                'url' => $company['url'] ?? null,
                'stores' => $group->map(fn (Store $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'status' => $s->currentSubscription?->status->value ?? 'none',
                    'earned' => (int) $mine->where('store_id', $s->id)->sum('amount'),
                    'created_at' => $s->created_at?->toIso8601String(),
                ])->sortByDesc('earned')->values()->all(),
                'paying' => $group->filter(fn (Store $s) => $s->currentSubscription?->status->value === 'active')->count(),
                'earned_month' => (int) $mine->filter(fn ($c) => $c->earned_at->isSameMonth(now()))->sum('amount'),
                'earned_total' => $earned,
                'paid_total' => $paid,
                'due' => $earned - $paid,
                'payouts' => $payouts->where('partner', $slug)->take(10)->map(fn (ReferralPayout $p) => [
                    'id' => $p->id, 'amount' => $p->amount, 'method' => $p->method, 'reference' => $p->reference,
                    'note' => $p->note, 'paid_at' => $p->paid_at->toIso8601String(),
                ])->values()->all(),
            ];
        })->sortByDesc('due')->values()->all();
    }

    /**
     * Verse à l'entreprise toutes ses parts pas encore versées.
     *
     * @param  array{method?: ?string, reference?: ?string, note?: ?string}  $data
     */
    public function pay(string $partner, User $admin, array $data): ReferralPayout
    {
        return DB::transaction(function () use ($partner, $admin, $data) {
            $due = ReferralCommission::where('partner', $partner)->whereNull('referral_payout_id')->lockForUpdate()->get();
            if ($due->isEmpty()) {
                throw new BusinessRuleException('Rien à verser à cette entreprise.');
            }
            $payout = ReferralPayout::create([
                'partner' => $partner, 'amount' => (int) $due->sum('amount'), 'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => $admin->id, 'paid_at' => now(),
            ]);
            ReferralCommission::whereIn('id', $due->pluck('id'))->update(['referral_payout_id' => $payout->id]);
            $storeIds = $due->pluck('store_id')->unique();
            DB::afterCommit(fn () => $storeIds->each(fn (int $id) => ReportPartnerReferral::dispatch($id)));

            return $payout;
        });
    }
}
