<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Models\ScheduledReport;
use App\Services\Accounts\StoreGroup;
use App\Services\Billing\PlanLimits;
use App\Services\Reports\DailyReport;
use App\Services\Reports\StatsQuestion;
use App\Services\Reports\StoreStats;
use App\Services\WhatsApp\CustomerMessenger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jibiat\Socle\PhoneNumber;

/**
 * Statistiques avancées (offre Pro et plus) et rapport quotidien de chaque membre de l'équipe.
 */
class StatsController extends Controller
{
    /**
     * Question en langage courant sur les chiffres de la boutique (assistant IA).
     */
    public function ask(Request $request, StatsQuestion $questions, PlanLimits $limits): JsonResponse
    {
        $store = $this->currentStore();
        if (! ($limits->plan($store)?->hasFeature('advanced_stats') ?? false)) {
            return response()->json(['message' => 'Les statistiques avancées sont comprises dans l\'offre Pro.', 'upgrade' => true], 403);
        }
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:500']], [], ['question' => 'question']);

        return response()->json(['data' => ['answer' => $questions->answer($store, $request->user(), $data['question'])]]);
    }

    public function show(Request $request, StoreStats $stats, PlanLimits $limits): JsonResponse
    {
        $store = $this->currentStore();
        if (! ($limits->plan($store)?->hasFeature('advanced_stats') ?? false)) {
            return response()->json(['message' => 'Les statistiques avancées sont comprises dans l\'offre Pro.', 'upgrade' => true], 403);
        }

        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $tz = $store->timezone;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz)->startOfDay() : $to->subDays(29)->startOfDay();
        if ($from->diffInDays($to) > 366) {
            return response()->json(['message' => 'Choisissez une période d\'un an au plus.'], 422);
        }

        return response()->json(['data' => $stats->report($store, $from->utc(), $to->utc())]);
    }

    /**
     * Rapports quotidiens de la personne connectée, et aperçu du rapport du jour.
     */
    public function schedule(Request $request, DailyReport $reports): JsonResponse
    {
        $store = $this->currentStore();
        $mine = ScheduledReport::where('user_id', $request->user()->id)->get()->keyBy('channel');
        $figures = $reports->figures($store, CarbonImmutable::now());

        return response()->json(['data' => [
            'channels' => collect(['whatsapp', 'email'])->mapWithKeys(fn ($channel) => [$channel => [
                'is_active' => (bool) $mine->get($channel)?->is_active,
                'all_stores' => (bool) $mine->get($channel)?->all_stores,
                'send_at' => substr((string) ($mine->get($channel)?->send_at ?? '20:00'), 0, 5),
                'last_sent_at' => $mine->get($channel)?->last_sent_at?->toIso8601String(),
            ]]),
            'phone' => PhoneNumber::display($request->user()->phone),
            'email' => $request->user()->email,
            'whatsapp_ready' => app(CustomerMessenger::class)->account($store->id, 'daily_report') !== null,
            'preview' => $reports->text($store, $figures),
            // Propriétaire de plusieurs boutiques sous un abonnement commun : rapport commun possible
            'group_stores' => $store->owner_id === $request->user()->id ? app(StoreGroup::class)->stores($store)?->count() : null,
        ]]);
    }

    public function updateSchedule(Request $request, DailyReport $reports): JsonResponse
    {
        $data = $request->validate([
            'channels' => ['required', 'array'],
            'channels.whatsapp.is_active' => ['sometimes', 'boolean'],
            'channels.whatsapp.send_at' => ['sometimes', 'date_format:H:i'],
            'channels.whatsapp.all_stores' => ['sometimes', 'boolean'],
            'channels.email.all_stores' => ['sometimes', 'boolean'],
            'channels.email.is_active' => ['sometimes', 'boolean'],
            'channels.email.send_at' => ['sometimes', 'date_format:H:i'],
        ]);
        if (($data['channels']['email']['is_active'] ?? false) && blank($request->user()->email)) {
            return response()->json(['message' => 'Ajoutez d\'abord votre adresse email (Réglages › Mon compte).', 'errors' => ['channels.email.is_active' => ['Adresse email manquante.']]], 422);
        }

        foreach ($data['channels'] as $channel => $settings) {
            if (! in_array($channel, ['whatsapp', 'email'], true)) {
                continue;
            }
            ScheduledReport::updateOrCreate(
                ['store_id' => $this->currentStore()->id, 'user_id' => $request->user()->id, 'frequency' => 'daily', 'channel' => $channel],
                array_filter(['is_active' => $settings['is_active'] ?? null, 'send_at' => $settings['send_at'] ?? null, 'all_stores' => $settings['all_stores'] ?? null], fn ($v) => $v !== null),
            );
        }

        return $this->schedule($request, $reports);
    }
}
