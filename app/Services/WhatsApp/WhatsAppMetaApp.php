<?php

namespace App\Services\WhatsApp;

use App\Support\Tenancy;

/**
 * Application Meta utilisée pour le WhatsApp en un clic. Par défaut celle de la plateforme (META_APP_ID) ; elle peut
 * être une autre application déjà validée, par exemple celle de Jibiat (META_WHATSAPP_APP_ID / _SECRET / _CONFIG_ID),
 * les réseaux sociaux restant sur l'application de la plateforme.
 *
 * Chaque compte WhatsApp connecté ici demande à Meta d'envoyer ses notifications à l'adresse de CE serveur
 * (override_callback_uri du compte) : l'application n'a qu'une adresse de notifications, alors que la préproduction et
 * la production (ou Jibiat, pour une application partagée) s'en servent en même temps.
 */
class WhatsAppMetaApp
{
    public static function id(): ?string
    {
        return config('services.meta.whatsapp_app_id') ?: config('services.meta.app_id');
    }

    public static function secret(): ?string
    {
        return config('services.meta.whatsapp_app_secret') ?: config('services.meta.app_secret');
    }

    public static function configId(): ?string
    {
        return config('services.meta.whatsapp_config_id');
    }

    /** Application d'un autre site : notifications redirigées compte par compte vers notre adresse */
    public static function shared(): bool
    {
        return filled(config('services.meta.whatsapp_app_id')) && config('services.meta.whatsapp_app_id') !== config('services.meta.app_id');
    }

    /**
     * Abonne l'application au compte WhatsApp, notifications vers l'adresse de ce serveur (Meta la vérifie avec le
     * jeton META_WEBHOOK_VERIFY_TOKEN ; sans jeton, l'adresse de l'application est gardée).
     */
    public static function subscribe(WhatsAppCloud $cloud, string $wabaId, string $token): void
    {
        $verify = (string) config('services.meta.webhook_verify_token');
        $cloud->graph('POST', $wabaId.'/subscribed_apps', $token, $verify !== '' ? [
            'override_callback_uri' => Tenancy::apiUrl('/webhooks/whatsapp'),
            'verify_token' => $verify,
        ] : []);
    }
}
