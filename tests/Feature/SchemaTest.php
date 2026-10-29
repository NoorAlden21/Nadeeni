<?php

namespace Tests\Feature;

use App\Enums\AccessTokenPurpose;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Models\PushSubscription;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_restaurant_has_one_live_order_per_number(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_platform_admin' => false,
        ]);

        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $cashier->id,
            'order_number' => '0047',
            'status' => OrderStatus::Waiting,
        ]);

        $this->assertSame('0047', $order->order_number);

        try {
            Order::factory()->create([
                'restaurant_id' => $restaurant->id,
                'created_by_user_id' => $cashier->id,
                'order_number' => '0047',
                'status' => OrderStatus::Ready,
            ]);
            $this->fail('A second active order with the same number was stored.');
        } catch (QueryException) {
            $this->assertSame(1, Order::query()->where('restaurant_id', $restaurant->id)->count());
        }

        $order->update([
            'status' => OrderStatus::Collected,
            'collected_at' => now(),
        ]);

        Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $cashier->id,
            'order_number' => '0047',
            'status' => OrderStatus::Waiting,
        ]);

        $otherRestaurant = Restaurant::factory()->create();

        Order::factory()->create([
            'restaurant_id' => $otherRestaurant->id,
            'order_number' => '0047',
            'status' => OrderStatus::Waiting,
        ]);

        $this->assertSame(3, Order::query()->where('order_number', '0047')->count());
    }

    public function test_an_order_has_one_active_cashier_qr(): void
    {
        $order = Order::factory()->create();

        $token = OrderAccessToken::factory()->create([
            'order_id' => $order->id,
            'purpose' => AccessTokenPurpose::CashierQr,
        ]);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token->token);

        OrderAccessToken::factory()->count(2)->create([
            'order_id' => $order->id,
            'purpose' => AccessTokenPurpose::SharedRegistration,
        ]);

        try {
            OrderAccessToken::factory()->create([
                'order_id' => $order->id,
                'purpose' => AccessTokenPurpose::CashierQr,
            ]);
            $this->fail('A second cashier QR was stored for the same order.');
        } catch (QueryException) {
            $this->assertSame(1, $order->accessTokens()->where('purpose', AccessTokenPurpose::CashierQr)->count());
        }
    }

    public function test_a_subscription_cannot_pair_an_order_with_another_orders_token(): void
    {
        $order = Order::factory()->create();
        $token = OrderAccessToken::factory()->create();

        $this->expectException(QueryException::class);

        PushSubscription::factory()->create([
            'order_id' => $order->id,
            'order_access_token_id' => $token->id,
        ]);
    }

    public function test_a_past_expiry_does_not_free_the_order_number_until_the_status_changes(): void
    {
        $restaurant = Restaurant::factory()->create();
        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'order_number' => '0047',
            'status' => OrderStatus::Waiting,
            'expires_at' => now()->subMinute(),
        ]);

        try {
            Order::factory()->create([
                'restaurant_id' => $restaurant->id,
                'order_number' => '0047',
                'status' => OrderStatus::Waiting,
            ]);
            $this->fail('An order past expires_at still holding waiting status did not reserve its number.');
        } catch (QueryException) {
            $this->assertSame(1, Order::query()->where('restaurant_id', $restaurant->id)->count());
        }

        $order->update([
            'status' => OrderStatus::Expired,
            'expired_at' => now(),
        ]);

        Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'order_number' => '0047',
            'status' => OrderStatus::Waiting,
        ]);

        $this->assertSame(2, Order::query()->where('restaurant_id', $restaurant->id)->count());
    }

    public function test_a_user_is_either_a_platform_admin_or_a_cashier(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->assertTrue($admin->is_platform_admin);
        $this->assertNull($admin->restaurant_id);

        $this->expectException(InvalidArgumentException::class);

        User::factory()->create([
            'is_platform_admin' => true,
            'restaurant_id' => Restaurant::factory(),
        ]);
    }
}
