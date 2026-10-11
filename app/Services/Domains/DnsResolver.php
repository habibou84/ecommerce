<?php

namespace App\Services\Domains;

/**
 * Lecture DNS (remplacée dans les tests).
 */
class DnsResolver
{
    /**
     * @return list<string> valeurs TXT
     */
    public function txt(string $host): array
    {
        return array_values(array_filter(array_map(fn ($r) => $r['txt'] ?? null, @dns_get_record($host, DNS_TXT) ?: [])));
    }

    /**
     * @return list<string> cibles CNAME
     */
    public function cname(string $host): array
    {
        return array_values(array_filter(array_map(fn ($r) => isset($r['target']) ? rtrim(strtolower($r['target']), '.') : null, @dns_get_record($host, DNS_CNAME) ?: [])));
    }

    /**
     * @return list<string> adresses IPv4
     */
    public function a(string $host): array
    {
        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? null, @dns_get_record($host, DNS_A) ?: [])));
    }
}
