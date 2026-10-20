<?php

namespace App\Support;

/**
 * Cellule d'export CSV ouvert dans Excel : un texte saisi par un client (nom, adresse, note) qui commence par
 * = + - @ ou une tabulation serait exécuté comme une formule. On le préfixe d'une apostrophe, sauf les nombres et
 * les numéros de téléphone (+225…), qui ne peuvent pas contenir de formule.
 */
class SpreadsheetCell
{
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || ! in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        return preg_match('/^[+-]?[\d\s.,]+$/', $value) ? $value : "'".$value;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), $row);
    }
}
