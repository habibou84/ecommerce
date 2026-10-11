<?php

namespace Jibiat\Socle\Webhooks;

/**
 * Signature des webhooks : X-Webhook-Signature = « sha256= » + HMAC_SHA256(secret, "<timestamp>.<corps brut>"),
 * avec l'horodatage dans X-Webhook-Timestamp (contrat de l'API publique de Delivery App).
 */
class WebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Signature valide et horodatage récent (protection contre le rejeu).
     */
    public static function verify(string $secret, ?string $timestamp, string $body, ?string $signature, ?int $now = null): bool
    {
        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($secret, (int) $timestamp, $body), $signature);
    }
}
