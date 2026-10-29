<?php

namespace App\Services\WhatsApp;

use App\Support\Tenancy;

/**
 * Application Meta utilisée pour le WhatsApp en un clic. Par défaut celle de la plateforme (META_APP_ID) ; elle peut
 * être une autre application déjà validée, par exemple celle de Jibiat (META_WHATSAPP_APP_ID / _SECRET / _CONFIG_ID),
 * les réseaux sociaux restant sur l'application de la plateforme.
 *
 * Application partagée : son adresse de notifications est celle de l'autre site ; chaque compte WhatsApp connecté ici
 * demande donc à Meta d'envoyer ses notifications à notre adresse (override_callback_uri du compte).
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

    /** Abonne l'application au compte WhatsApp (notifications), vers notre adresse si l'application est partagée */
    public static function subscribe(WhatsAppCloud $cloud, string $wabaId, string $token): void
    {
        $cloud->graph('POST', $wabaId.'/subscribed_apps', $token, self::shared() ? [
            'override_callback_uri' => Tenancy::apiUrl('/webhooks/whatsapp'),
            'verify_token' => (string) config('services.meta.webhook_verify_token'),
        ] : []);
    }
}
