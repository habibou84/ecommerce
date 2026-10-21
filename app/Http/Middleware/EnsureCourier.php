<?php

namespace App\Http\Middleware;

use App\Services\Couriers\CourierRoster;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application livreur : la personne connectée doit être un livreur actif de la boutique.
 */
class EnsureCourier
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $courier = CourierRoster::of($request->user(), $this->tenancy->store());
        if ($courier === null) {
            return response()->json(['message' => 'Vous n\'êtes pas livreur de cet espace marchand.'], 403);
        }

        $request->attributes->set('courier', $courier->setRelation('store', $this->tenancy->store()));

        return $next($request);
    }
}
