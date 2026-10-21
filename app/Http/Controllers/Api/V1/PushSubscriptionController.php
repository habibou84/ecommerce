<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications sur l'appareil, sans l'application Android : navigateur de l'ordinateur ou gestion installée
 * sur l'écran d'accueil (Android, iPhone). Même méthode que Jibiat (livraison).
 */
class PushSubscriptionController extends Controller
{
    /**
     * Clé publique (nécessaire au navigateur pour s'abonner) et appareils déjà abonnés.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => WebPush::enabled(),
            'public_key' => WebPush::enabled() ? config('services.webpush.public_key') : null,
            'devices' => PushSubscription::where('user_id', $request->user()->id)->count(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(WebPush::enabled(), 422, 'Les notifications ne sont pas encore prêtes sur le serveur.');

        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
        ]);
        if (strlen(WebPush::unb64($data['keys']['p256dh'])) !== 65 || strlen(WebPush::unb64($data['keys']['auth'])) !== 16) {
            abort(422, 'Abonnement de l\'appareil invalide.');
        }

        // Un appareil n'appartient qu'à une personne : la dernière connectée le reprend
        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['data' => ['id' => $subscription->id]], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint((string) $request->string('endpoint')))
            ->delete();

        return response()->json(null, 204);
    }

    /**
     * Notification d'essai sur les appareils de la personne.
     */
    public function test(Request $request, WebPush $push): JsonResponse
    {
        abort_unless(WebPush::enabled(), 422, 'Les notifications ne sont pas encore prêtes sur le serveur.');
        $subscriptions = PushSubscription::where('user_id', $request->user()->id)->get();
        abort_if($subscriptions->isEmpty(), 422, 'Aucun appareil : activez d\'abord les notifications.');

        $url = $request->input('app') === 'livreur' ? '/livreur' : '/gestion';
        $sent = $subscriptions->filter(fn (PushSubscription $s) => $push->send($s, [
            'title' => '🔔 Notifications activées',
            'body' => 'Vous serez prévenu même application fermée.',
            'url' => $request->getSchemeAndHttpHost().$url,
            'tag' => 'test',
        ]))->count();

        return response()->json(['data' => ['devices' => $sent]]);
    }
}
