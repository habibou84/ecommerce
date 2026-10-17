<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Boutiques hébergées à l'adresse d'une autre (offre Business) : nom de leur page ({adresse}/{path}) et
 * préfixe de leurs liens, la boutique en ligne étant écrite avec des liens depuis la racine (« /produits »).
 */
final class HostedStores
{
    /**
     * Premiers segments déjà utilisés à la racine d'une adresse (pages, gestion, fichiers publics).
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        static $reserved = null;

        return $reserved ??= collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => strtolower(explode('/', ltrim($route->uri(), '/'))[0]))
            ->merge(self::publicEntries())
            ->merge(['api', 'build', 'storage', 'up', 'sitemap.xml', 'robots.txt', 'livreur', 'gestion', 'auth', 'invitation', 'console', 'admin', 'www'])
            ->filter(fn ($segment) => $segment !== '' && ! str_starts_with($segment, '{'))
            ->unique()->values()->all();
    }

    /**
     * Erreur de validation du nom de page, ou null s'il est utilisable.
     */
    public static function error(string $path, Store $host, ?int $ignoreStoreId = null): ?string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?$/', $path) || str_contains($path, '--')) {
            return 'Le nom de la page fait 1 à 30 caractères : lettres minuscules sans accent, chiffres et tirets.';
        }
        if (in_array($path, self::reserved(), true)) {
            return 'Ce nom de page est réservé.';
        }
        $taken = Store::withTrashed()->where('host_store_id', $host->id)->where('path', $path)
            ->when($ignoreStoreId, fn ($q) => $q->whereKeyNot($ignoreStoreId))->exists();

        return $taken ? 'Une de vos boutiques utilise déjà ce nom de page.' : null;
    }

    /**
     * Nom de page libre tiré d'un nom (« Awa Kids » → « awa-kids », puis « awa-kids-2 »…).
     */
    public static function freePath(string $name, Store $host, ?int $ignoreStoreId = null): string
    {
        $base = Str::limit(Str::slug($name), 26, '') ?: 'boutique';
        $base = trim($base, '-') ?: 'boutique';
        $path = $base;
        for ($i = 2; self::error($path, $host, $ignoreStoreId) !== null; $i++) {
            $path = "{$base}-{$i}";
        }

        return $path;
    }

    /**
     * Liens d'une page de la boutique hébergée : « /produits » devient « /kids/produits » (sauf fichiers publics,
     * gestion et adresses déjà préfixées).
     */
    public static function prefixHtml(string $html, string $base): string
    {
        $skip = collect([ltrim($base, '/'), ...self::publicEntries(), 'build', 'storage', 'gestion', 'livreur', 'api'])
            ->map(fn ($s) => preg_quote($s, '#'))->implode('|');

        return preg_replace("#(\\s(?:href|action|src|poster)=[\"'])/(?!/|(?:{$skip})(?:[/?\"'\\#]|$))#i", '$1'.$base.'/', $html) ?? $html;
    }

    /**
     * Redirection vers une page de la boutique hébergée : préfixe ajouté aux adresses de la racine.
     */
    public static function prefixLocation(string $location, string $base, string $root): string
    {
        $path = str_starts_with($location, $root) ? substr($location, strlen($root)) : $location;
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, $base.'/') || $path === $base
            || preg_match('#^/(gestion|livreur|api|auth)(/|\?|$)#', $path)) {
            return $location;
        }

        return ($path === $location ? '' : $root).$base.($path === '/' ? '' : $path);
    }

    /**
     * Fichiers et dossiers du dossier public, servis directement par le serveur web.
     *
     * @return list<string>
     */
    private static function publicEntries(): array
    {
        static $entries = null;

        return $entries ??= array_values(array_filter(scandir(public_path()) ?: [], fn ($f) => ! str_starts_with($f, '.') && $f !== 'index.php'));
    }
}
