<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'order_access_token_id' => function (array $attributes) {
                return OrderAccessToken::factory()->create([
                    'order_id' => $attributes['order_id'],
                ])->getKey();
            },
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::uuid()->toString(),
            'p256dh' => Str::random(88),
            'auth' => Str::random(24),
            'content_encoding' => 'aes128gcm',
            'failure_count' => 0,
        ];
    }
}
