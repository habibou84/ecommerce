<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureCourier;
use App\Http\Middleware\EnsureStorefrontOpen;
use App\Http\Middleware\EnsureStoreMember;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureZone;
use App\Http\Middleware\PreprodGate;
use App\Http\Middleware\PreventIndexing;
use App\Http\Middleware\RequireAjax;
use App\Http\Middleware\ResolveStore;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Boutique en ligne : groupe léger, sans session (voir routes/storefront.php)
        then: fn () => Route::middleware('storefront')->group(base_path('routes/storefront.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Boutique désignée par l'adresse ({slug}.jibiat-marchand.com ou domaine personnalisé)
        $middleware->prepend(ResolveStore::class);
        // Préproduction : aucune page dans les moteurs de recherche
        $middleware->append(PreventIndexing::class);
        // Préproduction fermée au public par un code d'accès (PREPROD_ACCESS_CODE)
        $middleware->append(PreprodGate::class);
        // En-têtes de sécurité (cadres, type des fichiers, adresse précédente, HTTPS)
        $middleware->append(SecurityHeaders::class);
        // Gestion (SPA) authentifiée par cookie de session, limité au nom d'hôte de la boutique
        $middleware->statefulApi();
        // Cookies des pixels publicitaires (posés par leur script) : lus tels quels pour l'API Conversions
        $middleware->encryptCookies(except: ['_fbp', '_fbc', '_ttp', PreprodGate::COOKIE]);
        $middleware->alias([
            'zone' => EnsureZone::class,
            'member' => EnsureStoreMember::class,
            'permission' => PermissionMiddleware::class,
            'storefront.open' => EnsureStorefrontOpen::class,
            'ajax' => RequireAjax::class,
            'courier' => EnsureCourier::class,
            'super_admin' => EnsureSuperAdmin::class,
        ]);
        $middleware->group('storefront', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Supervision : erreurs envoyées à Sentry si SENTRY_LARAVEL_DSN est renseigné
        Integration::handles($exceptions);

        // Toujours répondre en JSON sur l'API, au format { message, errors? }
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $json = fn (string $message, int $status) => response()->json(['message' => $message], $status);
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(fn (BusinessRuleException $e) => $e->render());

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $isApi($request) ? $json('Non authentifié.', 401) : null);

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException|UnauthorizedException $e, Request $request) use ($json, $isApi) {
            if (! $isApi($request)) {
                return null;
            }

            // Conserve un message métier explicite (abort(403, '…')), sinon message générique
            $message = $e->getMessage();
            $generic = $message === '' || $message === 'This action is unauthorized.' || $e instanceof UnauthorizedException;

            return $json($generic ? 'Action non autorisée.' : $message, 403);
        });

        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => $isApi($request) ? $json('Ressource introuvable.', 404) : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $isApi($request)
            ? $json('Trop de tentatives. Réessayez dans quelques instants.', 429)->withHeaders($e->getHeaders())
            : null);
    })->create();
