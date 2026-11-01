<?php

namespace Database\Factories;

use App\Enums\PlanInterval;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'interval' => PlanInterval::Month,
            'duration_days' => null,
            'price' => 25,
            'currency' => 'USD',
            'is_active' => true,
        ];
    }

    public function annual(): static
    {
        return $this->state([
            'interval' => PlanInterval::Year,
            'duration_days' => null,
        ]);
    }

    public function trial(int $days = 7): static
    {
        return $this->state([
            'interval' => PlanInterval::Trial,
            'duration_days' => $days,
            'price' => 0,
        ]);
    }
}
