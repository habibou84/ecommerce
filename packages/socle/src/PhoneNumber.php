<?php

namespace Jibiat\Socle;

/**
 * Normalise les numéros de téléphone au format E.164.
 * Par défaut, un numéro local à 10 chiffres est considéré comme ivoirien (+225).
 */
class PhoneNumber
{
    public const DEFAULT_COUNTRY_CODE = '225';

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if (! $hasPlus) {
            if (strlen($digits) === 10) {
                $digits = self::DEFAULT_COUNTRY_CODE.$digits;
            } elseif (! (strlen($digits) === 13 && str_starts_with($digits, self::DEFAULT_COUNTRY_CODE))) {
                return null;
            }
        }

        // E.164 : 8 à 15 chiffres, indicatif pays inclus
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * Affichage lisible : +2250707070707 → « 07 07 07 07 07 » (numéros ivoiriens).
     */
    public static function display(?string $value): string
    {
        $normalized = self::normalize($value);

        if ($normalized !== null && strlen($normalized) === 14 && str_starts_with($normalized, '+'.self::DEFAULT_COUNTRY_CODE)) {
            return trim(chunk_split(substr($normalized, 4), 2, ' '));
        }

        return (string) ($normalized ?? $value);
    }

    /**
     * Mobile ivoirien (01 Moov, 05 MTN, 07 Orange) : seuls numéros auxquels on envoie
     * des SMS de vérification.
     */
    public static function isLocalMobile(?string $value): bool
    {
        return (bool) preg_match('/^\+'.self::DEFAULT_COUNTRY_CODE.'0[157]\d{8}$/', (string) self::normalize($value));
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }
}
