<?php

namespace App\Support;

/**
 * Texte saisi par un marchand ou un client, placé dans un e-mail (lignes en Markdown) : affiché tel quel, sans
 * devenir un lien ou un bouton (« [Vérifier et confirmer](https://…) » dans un nom de boutique).
 */
class MailText
{
    public static function plain(?string $value): string
    {
        return addcslashes(str_replace(["\r", "\n"], ' ', (string) $value), '\\`*_[]()<>!');
    }
}
