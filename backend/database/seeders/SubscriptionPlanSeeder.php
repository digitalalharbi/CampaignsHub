<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * The global plan catalogue (starter | growth | scale). Idempotent (upsert by code) and structural — safe in
 * every environment. `limits` are the per-metric caps; `scale` is the most permissive (unlimited where the cap
 * is null), which is also the default a tenant with no subscription falls back to.
 */
final class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'price_monthly' => 0,
                'currency' => 'SAR',
                'features' => ['support' => 'community', 'ai_assist' => false, 'white_label' => false],
                'limits' => ['projects' => 3, 'team_members' => 3, 'connections' => 3, 'reports_per_month' => 10],
            ],
            [
                'code' => 'growth',
                'name' => 'Growth',
                'price_monthly' => 499,
                'currency' => 'SAR',
                'features' => ['support' => 'email', 'ai_assist' => true, 'white_label' => false],
                'limits' => ['projects' => 25, 'team_members' => 15, 'connections' => 25, 'reports_per_month' => 100],
            ],
            [
                'code' => 'scale',
                'name' => 'Scale',
                'price_monthly' => 1499,
                'currency' => 'SAR',
                'features' => ['support' => 'priority', 'ai_assist' => true, 'white_label' => true],
                // null == unlimited. Scale is the most permissive plan and the no-subscription default.
                'limits' => ['projects' => null, 'team_members' => null, 'connections' => null, 'reports_per_month' => null],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['code' => $plan['code']],
                $plan + ['is_active' => true],
            );
        }
    }
}
