<?php

namespace Database\Factories;

use App\Enums\OrderEventType;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderEvent>
 */
class OrderEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'actor_user_id' => User::factory(),
            'event_type' => OrderEventType::Created,
            'metadata' => ['source' => 'factory'],
        ];
    }
}
