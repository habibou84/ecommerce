<?php

namespace App\Services\Couriers;

use App\Models\Shipment;
use Illuminate\Support\Carbon;

/**
 * Jour d'une course pour le livreur de la boutique, et ce qu'il peut en faire ce jour-là.
 *
 * - Le jour de la course : la plus récente de la date de livraison de la commande et de la date de report
 *   (sans date : aujourd'hui).
 * - Date passée sans livraison : la course sort de son application, le marchand choisit une nouvelle date.
 * - Jour à venir : lecture seule, sauf ramasser le colis la veille.
 * - Échec sans nouvelle date : le marchand décide (relivrer ou retour), le livreur attend.
 */
class CourierDays
{
    /** Courses en cours chez le livreur dont le jour compte (le retour se fait dès qu'il est demandé) */
    public const DATED = ['awaiting_pickup', 'in_transit', 'out_for_delivery', 'rescheduled'];

    /** La plus récente de la date de livraison de la commande et de la date de report (elle reste après le report) */
    public static function dueDay(Shipment $s): ?Carbon
    {
        $days = array_filter([$s->order?->scheduled_date, $s->rescheduled_to]);

        return $days === [] ? null : max(array_map(fn (Carbon $d) => $d->copy()->startOfDay(), $days));
    }

    /** Date passée sans livraison : à reporter par le marchand */
    public static function isOverdue(Shipment $s, ?Carbon $today = null): bool
    {
        $day = self::dueDay($s);

        return in_array($s->status->value, self::DATED, true) && $day !== null && $day->lt(($today ?? today())->copy()->startOfDay());
    }

    /**
     * Étapes que le livreur peut faire ce jour-là, selon le statut et le jour de la course.
     *
     * @param  list<string>  $steps  étapes possibles depuis le statut
     * @return list<string>
     */
    public static function allowedSteps(Shipment $s, array $steps, ?Carbon $day = null): array
    {
        $day = ($day ?? today())->copy()->startOfDay();
        $due = self::dueDay($s);
        if ($due === null || ! in_array($s->status->value, self::DATED, true) || $due->equalTo($day)) {
            return $steps;
        }
        // Date passée : le marchand doit d'abord choisir une nouvelle date
        if ($due->lt($day)) {
            return [];
        }

        // Prévue plus tard : seulement ramasser le colis la veille
        return $due->equalTo($day->copy()->addDay()) ? array_values(array_intersect($steps, ['in_transit'])) : [];
    }

    /** Pourquoi l'étape est refusée ce jour-là (null si elle est permise) */
    public static function refusal(Shipment $s, ?Carbon $day = null): ?string
    {
        $due = self::dueDay($s);
        $day = ($day ?? today())->copy()->startOfDay();
        if ($due === null || ! in_array($s->status->value, self::DATED, true)) {
            return null;
        }
        if ($due->lt($day)) {
            return 'La date de livraison ('.$due->format('d/m').') est passée : le marchand doit d\'abord choisir une nouvelle date.';
        }

        return $due->gt($day) ? 'Cette course est prévue le '.$due->format('d/m').' : vous la ferez ce jour-là.' : null;
    }
}
