<?php

namespace App\Services\Domains;

use App\Exceptions\BusinessRuleException;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Services\Billing\PlanLimits;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * Domaines personnalisés (www.maboutique.ci) : le marchand prouve qu'il possède le domaine (enregistrement TXT)
 * et le fait pointer vers la plateforme (CNAME, ou adresses IP pour un domaine nu). Le certificat est ensuite
 * demandé à la volée par Caddy, qui interroge /_caddy/ask avant chaque émission (docs/PLAN.md §4.4).
 */
class CustomDomains
{
    /** Délai pour vérifier un domaine déclaré avant qu'une autre boutique puisse le déclarer */
    public const UNVERIFIED_HOURS = 48;

    public const MAX_PER_STORE = 3;

    public const TXT_PREFIX = '_jibiat';

    public function __construct(private readonly DnsResolver $dns, private readonly PlanLimits $limits) {}

    public function add(Store $store, string $input): StoreDomain
    {
        $this->limits->ensureFeature($store, 'custom_domain');
        if ($store->isHosted()) {
            throw new BusinessRuleException('Cette boutique est une page de '.$store->host->name.' : ajoutez le nom de domaine à '.$store->host->name.', il servira aussi sa page.');
        }
        $hostname = $this->normalize($input);

        if (StoreDomain::where('store_id', $store->id)->count() >= self::MAX_PER_STORE) {
            throw new BusinessRuleException('Une boutique a au plus '.self::MAX_PER_STORE.' domaines personnalisés.', 'hostname');
        }
        // Un domaine déclaré mais jamais vérifié n'est retenu que 48 h : personne ne bloque le domaine d'un autre
        StoreDomain::where('hostname', $hostname)->whereNull('verified_at')->where('store_id', '!=', $store->id)
            ->where('created_at', '<', now()->subHours(self::UNVERIFIED_HOURS))->delete();
        if (StoreDomain::where('hostname', $hostname)->exists()) {
            throw new BusinessRuleException('Ce domaine est déjà déclaré par une boutique.', 'hostname');
        }

        return StoreDomain::create([
            'store_id' => $store->id,
            'hostname' => $hostname,
            'is_primary' => ! StoreDomain::where('store_id', $store->id)->exists(),
            'verification_token' => 'jibiat-verification='.Str::random(32),
        ]);
    }

    /**
     * Contrôle DNS : propriété (TXT) et redirection vers la plateforme (CNAME ou A).
     *
     * @return array{owned: bool, points_here: bool}
     */
    public function verify(StoreDomain $domain): array
    {
        $owned = in_array($domain->verification_token, $this->dns->txt(self::TXT_PREFIX.'.'.$domain->hostname), true);
        $target = (string) config('platform.custom_domains.cname_target');
        $ips = config('platform.custom_domains.ips');
        $pointsHere = in_array($target, $this->dns->cname($domain->hostname), true)
            || ($ips !== [] && array_intersect($ips, $this->dns->a($domain->hostname)) !== []);

        $error = match (true) {
            ! $owned => 'Enregistrement TXT de vérification introuvable (la propagation DNS peut prendre jusqu\'à quelques heures).',
            ! $pointsHere => 'Le domaine ne pointe pas encore vers Jibiat Marchand (enregistrement CNAME ou A).',
            default => null,
        };

        $domain->forceFill([
            'verified_at' => $error === null ? ($domain->verified_at ?? now()) : ($owned ? $domain->verified_at : null),
            'ssl_status' => $error === null ? 'active' : 'pending',
            'last_checked_at' => now(),
            'last_error' => $error,
        ])->save();

        return ['owned' => $owned, 'points_here' => $pointsHere];
    }

    public function makePrimary(StoreDomain $domain): void
    {
        if ($domain->verified_at === null) {
            throw new BusinessRuleException('Vérifiez d\'abord le domaine.');
        }
        StoreDomain::where('store_id', $domain->store_id)->update(['is_primary' => false]);
        $domain->forceFill(['is_primary' => true])->save();
    }

    public function remove(StoreDomain $domain): void
    {
        $wasPrimary = $domain->is_primary;
        $domain->delete();
        if ($wasPrimary) {
            StoreDomain::where('store_id', $domain->store_id)->whereNotNull('verified_at')->orderBy('id')->first()?->forceFill(['is_primary' => true])->save();
        }
    }

    /**
     * Caddy demande s'il peut émettre un certificat pour ce nom : uniquement pour un domaine vérifié d'une boutique ouverte.
     */
    public function allowsCertificate(string $hostname): bool
    {
        $domain = StoreDomain::where('hostname', strtolower($hostname))->whereNotNull('verified_at')->with('store')->first();

        return $domain?->store?->isOpen() ?? false;
    }

    /**
     * Instructions DNS à donner au marchand.
     *
     * @return list<array{type: string, name: string, value: string, purpose: string}>
     */
    public function instructions(StoreDomain $domain): array
    {
        $apex = substr_count($domain->hostname, '.') === 1;
        $records = [['type' => 'TXT', 'name' => self::TXT_PREFIX.'.'.$domain->hostname, 'value' => $domain->verification_token, 'purpose' => 'Prouve que le domaine vous appartient']];

        if ($apex && config('platform.custom_domains.ips') !== []) {
            foreach (config('platform.custom_domains.ips') as $ip) {
                $records[] = ['type' => 'A', 'name' => $domain->hostname, 'value' => $ip, 'purpose' => 'Envoie les visiteurs vers votre boutique'];
            }
        } else {
            $records[] = ['type' => 'CNAME', 'name' => $domain->hostname, 'value' => (string) config('platform.custom_domains.cname_target'), 'purpose' => 'Envoie les visiteurs vers votre boutique'];
        }

        return $records;
    }

    private function normalize(string $input): string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = rtrim(explode('/', $host)[0], '.');

        if (! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new BusinessRuleException('Nom de domaine invalide (ex. www.maboutique.ci).', 'hostname');
        }
        $platform = Tenancy::domain();
        if ($host === $platform || str_ends_with($host, '.'.$platform)) {
            throw new BusinessRuleException('Ce domaine appartient à la plateforme : choisissez votre propre domaine.', 'hostname');
        }

        return $host;
    }
}
