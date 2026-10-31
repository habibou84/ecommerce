<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'assistant IA compris dans chaque offre (actions par mois, remises à zéro chaque mois) ; les recharges prennent
 * le relais quand elles sont utilisées. Une offre dont le quota a déjà été réglé dans la console le garde.
 */
return new class extends Migration
{
    private const QUOTAS = ['free' => 10, 'starter' => 50, 'pro' => 300, 'business' => 1000];

    public function up(): void
    {
        foreach (DB::table('plans')->whereIn('code', array_keys(self::QUOTAS))->get(['id', 'code', 'limits']) as $plan) {
            $limits = json_decode($plan->limits ?? '[]', true) ?: [];
            if (($limits['ai_actions_per_month'] ?? 0) !== 0) {
                continue;
            }
            $limits['ai_actions_per_month'] = self::QUOTAS[$plan->code];
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->whereIn('code', array_keys(self::QUOTAS))->get(['id', 'code', 'limits']) as $plan) {
            $limits = json_decode($plan->limits ?? '[]', true) ?: [];
            if (($limits['ai_actions_per_month'] ?? null) === self::QUOTAS[$plan->code]) {
                $limits['ai_actions_per_month'] = 0;
                DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
            }
        }
    }
};
