<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Offres d'abonnement (docs/PLAN.md §9). Aucune offre ne comprend l'assistant IA : il marche avec les recharges
 * (config platform.ai_pack), quelle que soit l'offre.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'free', 'name' => 'Gratuit', 'sort' => 1, 'monthly_price' => 0, 'yearly_price' => 0,
                'limits' => ['orders_per_month' => 60, 'products' => 30, 'members' => 1, 'couriers' => 1, 'carrier_connections' => 0, 'payment_accounts' => 0, 'stores' => 1, 'ai_actions_per_month' => 0],
                'features' => []],
            ['code' => 'starter', 'name' => 'Starter', 'sort' => 2, 'monthly_price' => 3500, 'yearly_price' => 35000, 'weekly_price' => 1500,
                'limits' => ['orders_per_month' => null, 'products' => null, 'members' => 3, 'couriers' => 5, 'carrier_connections' => 1, 'payment_accounts' => 1, 'stores' => 1, 'ai_actions_per_month' => 0],
                'features' => ['online_payment' => true]],
            ['code' => 'pro', 'name' => 'Pro', 'sort' => 3, 'monthly_price' => 12500, 'yearly_price' => 125000,
                'limits' => ['orders_per_month' => null, 'products' => null, 'members' => 10, 'couriers' => null, 'carrier_connections' => null, 'payment_accounts' => null, 'stores' => 1, 'ai_actions_per_month' => 0],
                'features' => ['custom_domain' => true, 'online_payment' => true, 'whatsapp_api' => true, 'advanced_stats' => true]],
            ['code' => 'business', 'name' => 'Business', 'sort' => 4, 'monthly_price' => 35000, 'yearly_price' => 350000,
                'limits' => ['orders_per_month' => null, 'products' => null, 'members' => null, 'couriers' => null, 'carrier_connections' => null, 'payment_accounts' => null, 'stores' => 3, 'ai_actions_per_month' => 0],
                'features' => ['custom_domain' => true, 'online_payment' => true, 'whatsapp_api' => true, 'advanced_stats' => true, 'merchant_api' => true, 'multi_store' => true]],
        ];

        foreach ($plans as $plan) {
            // Prix indicatifs (FCFA), modifiables dans la console : seule la création les fixe
            $existing = Plan::where('code', $plan['code'])->first();
            $existing ? $existing->update(array_diff_key($plan, array_flip(['monthly_price', 'yearly_price', 'weekly_price']))) : Plan::create($plan);
        }
    }
}
