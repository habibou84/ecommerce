<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retrouve la boutique désignée par l'adresse du site et prépare le contexte de la requête :
 * équipe des rôles (spatie) = boutique, redirection des anciens sous-domaines, pages d'erreur.
 */
class ResolveStore
{
    // Accessibles sur tous les noms d'hôte (sondes de disponibilité, autorisation des certificats par Caddy)
    private const ANY_HOST = ['up', '_caddy/ask'];

    // Sur un domaine personnalisé, ces espaces sont servis par le sous-domaine de la boutique
    private const SUBDOMAIN_ONLY = ['gestion', 'gestion/*', 'livreur', 'livreur/*', 'invitation/*', 'auth/*'];

    public function __construct(private Tenancy $tenancy, private PermissionRegistrar $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenancy->resolve($request);
        $this->permissions->setPermissionsTeamId($this->tenancy->store()?->id);

        if ($request->is(...self::ANY_HOST)) {
            return $next($request);
        }

        $api = $request->is('api/*');
        $zone = $this->tenancy->zone();

        if ($zone === Tenancy::STORE && ($slug = $this->tenancy->movedTo()) !== null) {
            return redirect()->away(Tenancy::storeUrl($slug, $request->getRequestUri()), 301);
        }

        if ($problem = $this->tenancy->problem()) {
            [$status, $message] = $problem === Tenancy::CLOSED
                ? [403, 'Cette boutique est momentanément indisponible.']
                : [404, 'Aucune boutique à cette adresse.'];

            return $api
                ? response()->json(['message' => $message], $status)
                : response()->view('errors.store', ['message' => $message, 'platformUrl' => Tenancy::platformUrl()], $status);
        }

        if ($zone === Tenancy::CUSTOM_DOMAIN && ($api || $request->is(...self::SUBDOMAIN_ONLY))) {
            return $api
                ? response()->json(['message' => 'Utilisez l\'adresse '.$this->tenancy->store()->slug.'.'.Tenancy::domain().'.'], 404)
                : redirect()->away($this->tenancy->store()->url($request->getRequestUri()));
        }

        return $next($request);
    }
}
