<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderReview>
 */
class OrderReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'service_rating' => fake()->numberBetween(1, 5),
            'food_rating' => fake()->numberBetween(1, 5),
            'message' => fake()->optional()->sentence(),
        ];
    }
}
