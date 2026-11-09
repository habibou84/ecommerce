<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Controller;
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
