<?php

namespace App\Support;

use App\Models\SlugHistory;
use App\Models\Store;
use App\Models\StoreDomain;
use Illuminate\Http\Request;

/**
 * Boutique désignée par l'adresse du site :
 *  - jibiat-marchand.com, www.jibiat-marchand.com → présentation et création de boutique (zone « platform ») ;
 *  - admin.jibiat-marchand.com → console du super administrateur (zone « console ») ;
 *  - api.jibiat-marchand.com → API publique et webhooks entrants (zone « api ») ;
 *  - {slug}.jibiat-marchand.com → la boutique : site, gestion, livreurs (zone « store ») ;
 *  - autre nom d'hôte déclaré et vérifié par une boutique → son site public uniquement (zone « custom_domain »).
 */
class Tenancy
{
    public const PLATFORM = 'platform';

    public const CONSOLE = 'console';

    public const API = 'api';

    public const STORE = 'store';

    public const CUSTOM_DOMAIN = 'custom_domain';

    // Problèmes d'une adresse de boutique
    public const UNKNOWN = 'unknown';

    public const CLOSED = 'closed';

    private bool $resolved = false;

    private string $zone = self::PLATFORM;

    private ?Store $store = null;

    private ?string $problem = null;

    // Ancien sous-domaine : nouveau slug vers lequel rediriger
    private ?string $movedTo = null;

    public static function domain(): string
    {
        return ltrim((string) config('platform.domain'), '.');
    }

    public function resolve(Request $request): void
    {
        $this->resolved = true;
        $this->store = null;
        $this->problem = null;
        $this->movedTo = null;

        $host = strtolower($request->getHost());
        $domain = self::domain();

        if ($host === $domain || $host === "www.{$domain}") {
            $this->zone = self::PLATFORM;

            return;
        }

        if (! str_ends_with($host, ".{$domain}")) {
            $this->zone = self::CUSTOM_DOMAIN;
            $this->resolveCustomDomain($host);

            return;
        }

        $slug = substr($host, 0, -strlen(".{$domain}"));

        $this->zone = match ($slug) {
            config('platform.admin_subdomain') => self::CONSOLE,
            config('platform.api_subdomain') => self::API,
            default => self::STORE,
        };

        if ($this->zone === self::STORE) {
            $this->resolveSlug($slug);
        }
    }

    public function zone(): string
    {
        $this->ensureResolved();

        return $this->zone;
    }

    /**
     * Boutique de l'adresse, si elle existe et est ouverte.
     */
    public function store(): ?Store
    {
        $this->ensureResolved();

        return $this->store;
    }

    public function problem(): ?string
    {
        $this->ensureResolved();

        return $this->problem;
    }

    public function movedTo(): ?string
    {
        $this->ensureResolved();

        return $this->movedTo;
    }

    /**
     * Fixe la boutique courante hors requête HTTP (tests, commandes, files d'attente).
     */
    public function setStore(?Store $store): void
    {
        $this->resolved = true;
        $this->zone = $store ? self::STORE : self::PLATFORM;
        $this->store = $store;
        $this->problem = null;
        $this->movedTo = null;
    }

    public static function storeUrl(string $slug, string $path = '/'): string
    {
        return self::url("{$slug}.".self::domain(), $path);
    }

    public static function platformUrl(string $path = '/'): string
    {
        return self::url(self::domain(), $path);
    }

    /**
     * Adresse sur api.jibiat-marchand.com (webhooks des transporteurs et prestataires de paiement).
     */
    public static function apiUrl(string $path = '/'): string
    {
        return self::url(config('platform.api_subdomain').'.'.self::domain(), $path);
    }

    public static function consoleUrl(string $path = '/'): string
    {
        return self::url(config('platform.admin_subdomain').'.'.self::domain(), $path);
    }

    /**
     * Adresse complète sur un nom d'hôte de la plateforme, avec le protocole et le port d'APP_URL.
     */
    private static function url(string $host, string $path): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ":{$port}" : '').'/'.ltrim($path, '/');
    }

    private function resolveSlug(string $slug): void
    {
        $store = str_contains($slug, '.') ? null : Store::query()->where('slug', $slug)->first();

        if ($store === null) {
            $this->problem = self::UNKNOWN;
            $this->movedTo = SlugHistory::query()->where('old_slug', $slug)->where('expires_at', '>', now())
                ->with('store:id,slug')->first()?->store?->slug;

            return;
        }

        $this->accept($store);
    }

    private function resolveCustomDomain(string $host): void
    {
        $domain = StoreDomain::query()->where('hostname', $host)->whereNotNull('verified_at')->with('store')->first();

        if ($domain?->store === null) {
            $this->problem = self::UNKNOWN;

            return;
        }

        $this->accept($domain->store);
    }

    private function accept(Store $store): void
    {
        if ($store->isOpen()) {
            $this->store = $store;
        } else {
            $this->problem = self::CLOSED;
        }
    }

    private function ensureResolved(): void
    {
        if (! $this->resolved && app()->bound('request')) {
            $this->resolve(app('request'));
        }
    }
}
