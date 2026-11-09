<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Services\WhatsApp\WhatsAppMetaApp;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Connexion WhatsApp depuis l'application installée sur le téléphone : la fenêtre Facebook s'ouvre par-dessus
 * l'application (la page de la plateforme n'est pas dans l'application de la boutique). À la fin, cette page dépose
 * le résultat ici sous une clé tirée au hasard par l'application, qui le récupère et termine la connexion sans
 * jamais quitter l'application.
 */
class WhatsAppConnectSessionController extends Controller
{
    private const MINUTES = 30;

    /** Résultat accepté depuis la page de la plateforme : rien d'autre que ce que renvoie la fenêtre Facebook */
    private const FIELDS = ['code', 'waba_id', 'phone_number_id', 'coexistence', 'redirect_uri', 'erreur', 'depuis'];

    private static function cacheKey(string $key): string
    {
        return 'wa-connect:'.$key;
    }

    /** Application : clé de la connexion qui commence (boutique en cours) */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32,64}$/']]);
        Cache::put(self::cacheKey($data['key']), ['store' => $this->currentStore()->id, 'result' => null], now()->addMinutes(self::MINUTES));

        return response()->json(['data' => ['key' => $data['key']]], 201);
    }

    /** Application : résultat arrivé (une seule fois), ou null en attendant */
    public function show(string $key): JsonResponse
    {
        $session = Cache::get(self::cacheKey($key));
        abort_unless(is_array($session) && $session['store'] === $this->currentStore()->id, 404);
        if ($session['result'] !== null) {
            Cache::forget(self::cacheKey($key));
        }

        return response()->json(['data' => ['result' => $session['result']]]);
    }

    /**
     * Page de la plateforme, avant d'ouvrir Facebook : à quel espace la connexion va (affiché en grand), avec
     * l'application Meta de la plateforme (jamais celle d'une adresse reçue). Une clé d'application doit appartenir à
     * cette même boutique : un lien fabriqué par un autre marchand avec sa propre clé est refusé.
     */
    public function destination(Request $request): JsonResponse
    {
        $data = $request->validate([
            'retour' => ['required', 'string', 'max:500'],
            'cle' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{32,64}$/'],
        ]);
        $host = strtolower((string) parse_url($data['retour'], PHP_URL_HOST));
        $suffix = '.'.Tenancy::domain();
        abort_unless($host !== '' && str_ends_with($host, $suffix), 404);
        $sub = substr($host, 0, -strlen($suffix));
        $path = (string) parse_url($data['retour'], PHP_URL_PATH);

        if ($sub === config('platform.admin_subdomain')) {
            abort_if(filled($data['cle'] ?? null), 404);
            $name = 'Console Jibiat Marchand';
        } else {
            // Retour seulement dans la gestion (jamais sur la boutique en ligne, où des scripts publicitaires tournent)
            abort_unless(str_starts_with($path, '/gestion'), 404);
            $store = Store::where('slug', $sub)->first();
            abort_if($store === null, 404);
            if (filled($data['cle'] ?? null)) {
                $session = Cache::get(self::cacheKey($data['cle']));
                abort_unless(is_array($session) && $session['store'] === $store->id && $session['result'] === null, 404);
            }
            $name = $store->name;
        }

        return response()->json(['data' => [
            'name' => $name, 'host' => $host,
            'app_id' => WhatsAppMetaApp::id(), 'config_id' => WhatsAppMetaApp::configId(), 'graph_version' => WhatsAppCloud::VERSION,
        ]]);
    }

    /** Page de la plateforme (fenêtre Facebook) : dépose le résultat pour une clé connue */
    public function deliver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32,64}$/'],
            'result' => ['required', 'array'],
            'result.*' => ['nullable', 'string', 'max:500'],
        ]);
        $session = Cache::get(self::cacheKey($data['key']));
        abort_unless(is_array($session) && $session['result'] === null, 404);
        $session['result'] = array_filter(Arr::only($data['result'], self::FIELDS), fn ($v) => $v !== null && $v !== '');
        Cache::put(self::cacheKey($data['key']), $session, now()->addMinutes(self::MINUTES));

        return response()->json(['data' => ['ok' => true]]);
    }
}
