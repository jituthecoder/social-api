<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free Plan',
                'price_monthly' => 0.00,
                'price_yearly' => 0.00,
                'features' => [
                    'social_accounts' => 3,
                    'posts_per_month' => 30,
                    'ai_generations' => 10,
                    'media_storage_mb' => 500,
                ],
                'limits' => [
                    'social_accounts' => 3,
                    'posts_published' => 30,
                    'ai_generations' => 10,
                ],
                'status' => 'active',
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro Plan',
                'price_monthly' => 29.00,
                'price_yearly' => 290.00,
                'features' => [
                    'social_accounts' => 15,
                    'posts_per_month' => 500,
                    'ai_generations' => 250,
                    'media_storage_mb' => 10000,
                ],
                'limits' => [
                    'social_accounts' => 15,
                    'posts_published' => 500,
                    'ai_generations' => 250,
                ],
                'status' => 'active',
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'agency'],
            [
                'name' => 'Agency Plan',
                'price_monthly' => 99.00,
                'price_yearly' => 990.00,
                'features' => [
                    'social_accounts' => 100,
                    'posts_per_month' => 5000,
                    'ai_generations' => 2000,
                    'media_storage_mb' => 100000,
                ],
                'limits' => [
                    'social_accounts' => 100,
                    'posts_published' => 5000,
                    'ai_generations' => 2000,
                ],
                'status' => 'active',
            ]
        );
    }
}
