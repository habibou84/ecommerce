<?php

namespace App\Services\Shipping;

use App\Exceptions\BusinessRuleException;
use App\Models\CarrierConnection;
use App\Models\CarrierRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\Catalog\DeliveryAppImporter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jibiat\Socle\Contract\Contract;
use Jibiat\Socle\Places\Places;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Throwable;

/**
 * Connexion directe à une entreprise de Jibiat (contrat socle 1.12) : le serveur de la boutique demande l'ouverture
 * du compte, requête signée, sans redirection ni mot de passe. L'entreprise répond tout de suite (compte ouvert, clé
 * d'API) ou plus tard (POST /api/partners/v1/connections) ; la clé ne passe que de serveur à serveur, et la
 * connexion habituelle (zones, webhook) est faite avec elle.
 */
class CarrierDirectConnect
{
    public const CLIENT = 'jibiat-marchand';

    public function __construct(
        private readonly CarrierDirectory $directory,
        private readonly CarrierConnections $connections,
        private readonly DeliveryAppImporter $addresses,
    ) {}

    public static function secret(): string
    {
        return (string) config('services.jibiat.connect_secret');
    }

    /**
     * « Travailler avec … » : demande envoyée à l'entreprise ; une demande déjà en cours chez elle est rendue telle quelle.
     */
    public function request(Store $store, User $user, string $slug, int $waitingOrders = 0): CarrierRequest
    {
        $company = $this->directory->find($slug)
            ?? throw new BusinessRuleException('Entreprise introuvable dans l\'annuaire : rechargez la page.', 'slug');
        if (! ($company['direct'] ?? false)) {
            throw new BusinessRuleException('Cette entreprise n\'accepte pas encore l\'ouverture de compte en un clic : touchez « Connecter ».', 'slug');
        }
        if (self::secret() === '') {
            throw new BusinessRuleException('Ouverture de compte en un clic indisponible pour le moment : touchez « Connecter ».', 'slug');
        }

        $open = CarrierRequest::forStore($store->id)->where('slug', $slug)->whereIn('status', CarrierRequest::OPEN)->first();
        if ($open !== null) {
            return $open;
        }

        // Le téléphone du gérant doit être vérifié : c'est lui qui prouve, chez l'entreprise, qui ouvre le compte
        if ($user->phone === null || $user->phone_verified_at === null) {
            throw new BusinessRuleException('Vérifiez d\'abord votre numéro de téléphone (Mon compte) : l\'entreprise ouvre le compte à ce numéro.');
        }
        app(PlanLimits::class)->ensureCanAdd($store, 'carrier_connections');

        $request = CarrierRequest::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'request_id' => (string) Str::uuid(),
            'slug' => $company['slug'],
            'name' => $company['name'],
            'url' => $company['url'],
            'status' => 'pending',
            'waiting_orders' => $waitingOrders,
        ]);

        $body = [
            'client_id' => self::CLIENT,
            'request_id' => $request->request_id,
            'store' => ['reference' => (string) $store->id, 'name' => $store->name, 'url' => $store->url('/'), 'city' => null],
            'owner' => [
                'name' => $user->name,
                'phone' => $user->phone,
                'phone_verified_at' => $user->phone_verified_at->toIso8601ZuluString(),
                'email' => $user->email,
            ],
            'pickup' => ['place' => $this->pickupPlace($store), 'address' => $store->address ?: null],
            'consent_at' => now()->toIso8601ZuluString(),
            'waiting_orders' => $waitingOrders,
            'activity' => null,
            'message' => null,
        ];

        $response = $this->send($request, 'POST', '', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($response === null || ! $response->successful() || ! is_array($response->json('data'))) {
            $request->delete();
            $message = $response?->json('message');

            throw new BusinessRuleException(is_string($message) && $message !== '' ? $message : 'L\'entreprise de livraison ne répond pas : réessayez dans un instant.');
        }

        // Demande nouvelle : le contrôleur répond 201
        return tap($this->apply($request, $response->json('data')), fn (CarrierRequest $r) => $r->wasRecentlyCreated = true);
    }

    /**
     * Décision reçue de l'entreprise (connect-decision). Une demande déjà tranchée ne change plus.
     *
     * @param  array<string, mixed>  $decision
     */
    public function receive(array $decision): ?CarrierRequest
    {
        $request = CarrierRequest::withoutGlobalScopes()->where('request_id', $decision['request_id'] ?? '')->first();
        if ($request === null || ! $request->isOpen()) {
            return $request;
        }

        return $this->apply($request, $decision);
    }

    /**
     * Relire l'état chez l'entreprise (décision perdue, ou vérification de l'écran).
     */
    public function refresh(CarrierRequest $request): CarrierRequest
    {
        if (! $request->isOpen()) {
            return $request;
        }
        $response = $this->send($request, 'GET', '/'.$request->request_id);
        $request->forceFill(['last_checked_at' => now()])->save();

        return is_array($response?->json('data')) && $response->successful() ? $this->apply($request, $response->json('data')) : $request;
    }

    /**
     * Le marchand renonce (ou confie ses colis à une autre entreprise).
     */
    public function cancel(CarrierRequest $request): CarrierRequest
    {
        if (! $request->isOpen()) {
            return $request;
        }
        $response = $this->send($request, 'DELETE', '/'.$request->request_id);
        if (is_array($response?->json('data')) && $response->successful()) {
            return $this->apply($request, $response->json('data'));
        }
        $request->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();

        return $request;
    }

    /**
     * Applique un état de l'entreprise (connect-direct ou connect-decision) : avec la clé, la connexion est créée.
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(CarrierRequest $request, array $data): CarrierRequest
    {
        return DB::transaction(function () use ($request, $data) {
            $request = CarrierRequest::withoutGlobalScopes()->lockForUpdate()->find($request->id);
            if (! $request->isOpen()) {
                return $request;
            }

            $status = (string) ($data['status'] ?? '');
            $key = $data['connection']['api_key'] ?? null;

            if ($status === 'connected' && is_string($key)) {
                $store = Store::find($request->store_id);
                try {
                    $connection = $this->connect($store, $request, $key);
                    $request->forceFill(['status' => 'connected', 'connection_id' => $connection->id, 'reason' => null, 'decided_at' => now(), 'decide_before' => null])->save();
                } catch (Throwable $e) {
                    // Compte ouvert chez l'entreprise mais connexion impossible ici (limite de l'offre, entreprise injoignable)
                    Log::warning('Connexion directe : clé reçue mais connexion impossible', ['carrier_request_id' => $request->id, 'error' => $e->getMessage()]);
                    $request->forceFill([
                        'status' => 'failed', 'decided_at' => now(), 'decide_before' => null,
                        'reason' => mb_substr($e instanceof BusinessRuleException ? $e->getMessage() : 'Compte ouvert, mais la connexion a échoué : touchez « Connecter ».', 0, 500),
                    ])->save();
                }

                return $request;
            }

            if (in_array($status, ['pending', 'confirmation'], true)) {
                $request->forceFill(['status' => $status, 'decide_before' => isset($data['decide_before']) ? now()->parse($data['decide_before']) : null])->save();
            } elseif (in_array($status, ['declined', 'cancelled', 'expired'], true)) {
                $request->forceFill(['status' => $status, 'reason' => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 500) : null, 'decided_at' => now(), 'decide_before' => null])->save();
            }

            return $request;
        });
    }

    private function connect(Store $store, CarrierRequest $request, string $key): CarrierConnection
    {
        // Déjà connectée à cette adresse (connexion faite autrement entre-temps) : rien à refaire
        $existing = CarrierConnection::forStore($store->id)->where('driver', CarrierConnection::DELIVERY_APP)->where('status', 'active')
            ->where('base_url', $this->addresses->apiUrl($request->url))->first();

        return $existing ?? $this->connections->connectDeliveryApp($store, $request->url, $key);
    }

    private function pickupPlace(Store $store): ?string
    {
        // Lieux cités dans l'adresse, le plus précis d'abord
        $found = filled($store->address) ? Places::inText((string) $store->address) : [];

        return $found[0]['code'] ?? null;
    }

    /**
     * Appel signé à l'entreprise (même secret dans les deux sens) : corps brut pour POST, chemin pour GET et DELETE.
     */
    private function send(CarrierRequest $request, string $method, string $suffix, string $body = ''): ?Response
    {
        try {
            $base = Str::beforeLast($this->addresses->apiUrl($request->url), '/v1').'/connect/direct';
        } catch (BusinessRuleException) {
            return null;
        }
        $url = $base.$suffix;
        $timestamp = time();
        $signed = $method === 'POST' ? $body : (string) parse_url($url, PHP_URL_PATH);

        $http = Http::acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()->withHeaders([
            'X-Client-App' => self::CLIENT,
            Contract::PARTNER_TIMESTAMP_HEADER => (string) $timestamp,
            Contract::PARTNER_SIGNATURE_HEADER => WebhookSignature::sign(self::secret(), $timestamp, $signed),
        ]);

        try {
            return match ($method) {
                'POST' => $http->withBody($body, 'application/json')->post($url),
                'DELETE' => $http->delete($url),
                default => $http->get($url),
            };
        } catch (Throwable $e) {
            Log::warning('Connexion directe : entreprise injoignable', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
