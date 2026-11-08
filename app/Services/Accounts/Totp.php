<?php

namespace App\Services\Accounts;

/**
 * Codes à 6 chiffres des applications d'authentification (RFC 6238 : HMAC-SHA1, pas de 30 secondes).
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secret de 160 bits en base 32 (32 caractères), comme l'attendent les applications. */
    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? now()->getTimestamp(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Pas de 30 s auquel le code correspond (un pas d'écart toléré pour l'horloge du téléphone), ou null.
     */
    public static function match(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $now = self::step($time);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** Lien lu par les applications (QR code) : otpauth://totp/Émetteur:compte?secret=… */
    public static function uri(string $secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query(
            ['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[\s=]/', '', $secret))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index !== false) {
                $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
