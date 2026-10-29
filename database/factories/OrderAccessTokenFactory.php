<?php

namespace Database\Factories;

use App\Enums\AccessTokenPurpose;
use App\Models\Order;
use App\Models\OrderAccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAccessToken>
 */
class OrderAccessTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'token' => OrderAccessToken::generateToken(),
            'purpose' => AccessTokenPurpose::CashierQr,
            'expires_at' => now()->addMinutes(240),
        ];
    }
}
