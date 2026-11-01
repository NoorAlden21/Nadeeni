<?php

namespace Database\Factories;

use App\Enums\PlanInterval;
use App\Enums\PreferredQrMode;
use App\Models\Restaurant;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'preferred_qr_mode' => PreferredQrMode::Shared,
            'timezone' => 'Asia/Damascus',
            'order_ttl_minutes' => 240,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Restaurant $restaurant): void {
            if ($restaurant->subscription_plan_id !== null) {
                return;
            }

            $plan = SubscriptionPlan::query()->firstOrCreate(
                ['name' => 'Factory Monthly'],
                [
                    'interval' => PlanInterval::Month,
                    'duration_days' => null,
                    'price' => 0,
                    'currency' => 'USD',
                    'is_active' => true,
                ],
            );

            $restaurant->grant($plan);
        });
    }

    public function withoutSubscription(): static
    {
        return $this->afterCreating(function (Restaurant $restaurant): void {
            $restaurant->forceFill([
                'subscription_plan_id' => null,
                'subscription_starts_at' => null,
                'subscription_ends_at' => null,
            ])->save();
        });
    }

    public function expiredSubscription(): static
    {
        return $this->afterCreating(function (Restaurant $restaurant): void {
            $restaurant->forceFill([
                'subscription_ends_at' => now()->subMinute(),
            ])->save();
        });
    }
}
