<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route à certaines adresses : zone:platform, zone:store, zone:store,custom_domain…
 */
class EnsureZone
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next, string ...$zones): Response
    {
        abort_unless(in_array($this->tenancy->zone(), $zones, true), 404);

        return $next($request);
    }
}
