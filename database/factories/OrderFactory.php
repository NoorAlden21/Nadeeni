<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'order_number' => fake()->unique()->numerify('####'),
            'status' => OrderStatus::Waiting,
            'created_by_user_id' => User::factory(),
            'expires_at' => now()->addMinutes(240),
        ];
    }
}
