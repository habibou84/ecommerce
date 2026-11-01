<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Jobs\InstallMissingTemplates;
use App\Models\Cart;
use App\Models\OutboundMessage;
use App\Models\WhatsappAccount;
use App\Services\Billing\PlanLimits;
use App\Services\Platform\Features;
use App\Services\Storefront\CartService;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\EmbeddedSignup;
use App\Services\WhatsApp\MetaHealth;
use App\Services\WhatsApp\NumberStatus;
use App\Services\WhatsApp\TemplateLibrary;
use App\Services\WhatsApp\WhatsAppCloud;
use App\Services\WhatsApp\WhatsAppMetaApp;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;

/**
 * WhatsApp de la boutique (API Cloud de Meta) : connexion du numéro, modèles et automatismes, messages envoyés ;
 * paniers abandonnés à relancer.
 */
class WhatsAppController extends Controller
{
    public function show(): JsonResponse
    {
        $account = WhatsappAccount::first();
        if ($account !== null) {
            app(NumberStatus::class)->refresh($account);
            // Messages automatiques ajoutés depuis la connexion du numéro : soumis à Meta sans reconnexion
            InstallMissingTemplates::dispatch($account->id)->afterResponse();
        }

        return response()->json(['data' => [
            'account' => $account ? [
                'connection' => $account->connection,
                'phone_number_id' => $account->phone_number_id,
                'waba_id' => $account->waba_id,
                'display_phone' => $account->display_phone,
                'status' => $account->status,
                'last_error' => $account->last_error,
                'error_help' => NumberStatus::explain($account->last_error),
                'verified_name' => $account->verified_name,
                'name_status' => $account->name_status,
                'meta_number' => NumberStatus::metaProvided($account->display_phone, $account->last_error),
                'templates' => (object) ($account->templates ?? []),
                'template_status' => (object) ($account->template_status ?? []),
                'automations' => (object) ($account->automations ?? []),
                'webhook_url' => Tenancy::apiUrl('/webhooks/whatsapp/'.$account->webhook_uuid),
                'verify_token' => $account->verify_token,
                'meta' => MetaHealth::present($account),
            ] : null,
            'events' => CustomerMessenger::EVENTS,
            'previews' => $this->previews(),
            // Bouton « Connecter WhatsApp avec Facebook » : application Meta de la plateforme
            'embedded' => EmbeddedSignup::enabled() && app(Features::class)->enabled('whatsapp_embedded', $this->currentStore()) ? ['app_id' => WhatsAppMetaApp::id(), 'config_id' => WhatsAppMetaApp::configId(), 'graph_version' => WhatsAppCloud::VERSION] : null,
            'messages' => OutboundMessage::latest('id')->limit(30)->get()->map(fn (OutboundMessage $m) => [
                'id' => $m->id, 'event' => $m->event, 'to' => PhoneNumber::display($m->to_phone), 'status' => $m->status, 'error' => $m->error,
                'error_help' => NumberStatus::explain($m->error)['title'] ?? null, 'related_type' => $m->related_type, 'related_id' => $m->related_id, 'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]]);
    }

    /**
     * Aperçu de chaque message tel que le client le reçoit : texte du modèle rempli avec un exemple (nom de la
     * boutique compris), à la place des {{1}}, {{2}}…
     *
     * @return array<string, string>
     */
    private function previews(): array
    {
        $store = $this->currentStore();

        return collect(TemplateLibrary::TEMPLATES)->map(function (array $t) use ($store) {
            $values = array_map(fn ($v) => str_replace(['Awa Mode', 'https://boutique.jibiat-marchand.com'], [$store->name, rtrim($store->url('/'), '/')], $v), $t['example']);

            return strtr($t['body'], collect($values)->mapWithKeys(fn ($v, $i) => ['{{'.($i + 1).'}}' => $v])->all());
        })->intersectByKeys(CustomerMessenger::EVENTS)->all();
    }

    public function update(Request $request): JsonResponse
    {
        $account = WhatsappAccount::first();
        if ($account === null) {
            app(PlanLimits::class)->ensureFeature($this->currentStore(), 'whatsapp_api');
        }
        $required = $account ? 'sometimes' : 'required';
        $data = $request->validate([
            'phone_number_id' => [$required, 'nullable', 'string', 'max:40'],
            'waba_id' => ['nullable', 'string', 'max:40'],
            'display_phone' => ['nullable', 'string', 'max:20'],
            'access_token' => [$account ? 'nullable' : 'required', 'string', 'max:1000'],
            'app_secret' => [$account ? 'nullable' : 'required', 'string', 'max:100'],
            'templates' => ['sometimes', 'array'],
            'templates.*' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'automations' => ['sometimes', 'array'],
            'automations.*' => ['boolean'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ], ['templates.*.regex' => 'Nom de modèle Meta : lettres minuscules, chiffres et _ uniquement.'], ['phone_number_id' => 'identifiant du numéro', 'access_token' => 'jeton d\'accès', 'app_secret' => 'clé secrète de l\'application']);

        foreach (['templates', 'automations'] as $key) {
            if (isset($data[$key])) {
                // Les réglages envoyés complètent ceux déjà enregistrés
                $data[$key] = array_intersect_key($data[$key], CustomerMessenger::EVENTS) + ($account?->{$key} ?? []);
            }
        }
        // Jeton et secret laissés vides : les valeurs enregistrées sont gardées
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        $account
            ? $account->update($data)
            : WhatsappAccount::create($data + ['store_id' => $this->currentStore()->id, 'status' => 'active', 'automations' => array_fill_keys(array_keys(CustomerMessenger::EVENTS), false)]);

        return $this->show();
    }

    /**
     * Retour de la fenêtre Facebook (inscription intégrée) : code et identifiants du compte choisi.
     */
    public function connectEmbedded(Request $request, EmbeddedSignup $signup): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:1000'],
            // Connexion par la page entière (téléphone) : compte retrouvé par le serveur, adresse de retour redonnée à Meta
            'waba_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
            'phone_number_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
            'coexistence' => ['sometimes', 'boolean'],
            'redirect_uri' => ['nullable', 'string', 'max:300', 'starts_with:'.Tenancy::platformUrl('/whatsapp/facebook')],
        ]);
        if (WhatsappAccount::first() === null) {
            app(PlanLimits::class)->ensureFeature($this->currentStore(), 'whatsapp_api');
        }

        $result = $signup->connect($this->currentStore(), $data['code'], $data['waba_id'] ?? null, $data['phone_number_id'] ?? null, (bool) ($data['coexistence'] ?? false), $data['redirect_uri'] ?? null);

        $payload = $this->show()->getData(true);
        $payload['data']['warnings'] = $result['warnings'];

        return response()->json($payload);
    }

    /**
     * « Actualiser » : relit tout de suite l'état du numéro chez Meta.
     */
    public function health(MetaHealth $health): JsonResponse
    {
        $account = WhatsappAccount::firstOrFail();
        $health->refresh($account);

        return $this->show();
    }

    public function destroy(): JsonResponse
    {
        WhatsappAccount::query()->delete();

        return $this->show();
    }

    /**
     * Paniers avec un numéro et des articles, sans commande depuis plus d'une heure (7 derniers jours).
     */
    public function abandonedCarts(CartService $carts): JsonResponse
    {
        $store = $this->currentStore();
        $list = Cart::where('status', 'active')->whereNotNull('phone')->where('last_activity_at', '<=', now()->subHour())
            ->where('last_activity_at', '>=', now()->subDays(7))->whereHas('items')->latest('last_activity_at')->limit(100)->get();

        return response()->json(['data' => $list->map(function (Cart $cart) use ($carts, $store) {
            $summary = $carts->summary($store, $cart);
            $link = $store->url('/panier/reprendre/'.$cart->token);
            $text = 'Bonjour'.($cart->name ? ' '.Str::before($cart->name, ' ') : '').", vous avez laissé des articles dans votre panier chez {$store->name} ("
                .Money::format($summary['subtotal']).'). Vous pouvez finaliser votre commande ici : '.$link;

            return [
                'id' => $cart->id,
                'name' => $cart->name,
                'phone' => PhoneNumber::display($cart->phone),
                'items' => collect($summary['lines'])->map(fn ($l) => $l['quantity'].' × '.$l['title'].($l['variant_title'] ? ' ('.$l['variant_title'].')' : ''))->values(),
                'subtotal' => $summary['subtotal'],
                'last_activity_at' => $cart->last_activity_at?->toIso8601String(),
                'recovery_sent_at' => $cart->recovery_sent_at?->toIso8601String(),
                'whatsapp_url' => 'https://wa.me/'.ltrim($cart->phone, '+').'?text='.rawurlencode($text),
            ];
        })]);
    }

    /**
     * Relance faite à la main (lien WhatsApp ouvert par le vendeur).
     */
    public function markRecovered(int $cart): JsonResponse
    {
        Cart::findOrFail($cart)->forceFill(['recovery_sent_at' => now()])->save();

        return response()->json(['message' => 'Relance notée.']);
    }
}
