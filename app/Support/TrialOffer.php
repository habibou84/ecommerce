<?php

namespace App\Support;

use App\Models\Plan;

/**
 * Essai gratuit offert à chaque nouvelle boutique, tel qu'on l'annonce : « 1 mois de l'offre Pro ».
 */
class TrialOffer
{
    public static function days(): int
    {
        return (int) config('platform.trial_days');
    }

    /**
     * Durée lisible : « 1 mois » pour 30 jours, « 2 mois » pour 60, sinon « 14 jours ».
     */
    public static function duration(): string
    {
        $days = self::days();

        return $days >= 30 && $days % 30 === 0 ? ($days / 30).' mois' : $days.' jours';
    }

    /**
     * Nom de l'offre essayée (Pro), tel qu'il est réglé dans la console.
     */
    public static function planName(): string
    {
        $code = (string) config('platform.trial_plan');

        return Plan::where('code', $code)->value('name') ?? ucfirst($code);
    }
}
