<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Offres d'abonnement (docs/PLAN.md §9). Les prix restent à fixer : 0 en attendant la phase 8.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'free', 'name' => 'Gratuit', 'sort' => 1, 'monthly_price' => 0, 'yearly_price' => 0,
                'limits' => ['orders_per_month' => 30, 'products' => 20, 'members' => 1, 'couriers' => 1, 'carrier_connections' => 0, 'payment_accounts' => 0],
                'features' => []],
            ['code' => 'starter', 'name' => 'Starter', 'sort' => 2, 'monthly_price' => 5000, 'yearly_price' => 50000,
                'limits' => ['orders_per_month' => null, 'products' => null, 'members' => 3, 'couriers' => 5, 'carrier_connections' => 1, 'payment_accounts' => 1],
                'features' => []],
            ['code' => 'pro', 'name' => 'Pro', 'sort' => 3, 'monthly_price' => 15000, 'yearly_price' => 150000,
                'limits' => ['orders_per_month' => null, 'products' => null, 'members' => 10, 'couriers' => null, 'carrier_connections' => null, 'payment_accounts' => null],
                'features' => ['custom_domain' => true, 'online_payment' => true, 'whatsapp_api' => true, 'ai_product' => true, 'advanced_stats' => true]],
            ['code' => 'business', 'name' => 'Business', 'sort' => 4, 'monthly_price' => 35000, 'yearly_price' => 350000,
                'limits' => ['orders_per_month' => null, 'products' => null, 'members' => null, 'couriers' => null, 'carrier_connections' => null, 'payment_accounts' => null],
                'features' => ['custom_domain' => true, 'online_payment' => true, 'whatsapp_api' => true, 'ai_product' => true, 'advanced_stats' => true, 'merchant_api' => true, 'multi_store' => true]],
        ];

        foreach ($plans as $plan) {
            // Prix indicatifs (FCFA), modifiables dans la console : seule la création les fixe
            $existing = Plan::where('code', $plan['code'])->first();
            $existing ? $existing->update(array_diff_key($plan, array_flip(['monthly_price', 'yearly_price']))) : Plan::create($plan);
        }
    }
}
