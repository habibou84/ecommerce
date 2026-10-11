<?php

namespace App\Http\Controllers;

use App\Services\Domains\CustomDomains;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * « on_demand_tls ask » de Caddy : avant d'émettre un certificat, Caddy demande si le nom est bien servi ici.
 * 200 pour la plateforme et ses sous-domaines, et pour un domaine personnalisé vérifié d'une boutique ouverte.
 * Appelé uniquement par Caddy, sur la même machine.
 */
class CaddyAskController extends Controller
{
    public function __invoke(Request $request, CustomDomains $domains): Response
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        $host = strtolower((string) $request->query('domain'));
        $platform = Tenancy::domain();
        $allowed = $host !== '' && ($host === $platform || str_ends_with($host, '.'.$platform) || $domains->allowsCertificate($host));

        return response($allowed ? 'OK' : 'Inconnu', $allowed ? 200 : 404);
    }
}
