<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\RestaurantRole;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerStatsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const BROWSER_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const BROWSER_C = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    public function test_an_admin_can_create_an_owner_and_only_that_owner_can_read_stats(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $restaurant = Restaurant::factory()->create(['slug' => 'shawarma-house']);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/admin/restaurants/'.$restaurant->id.'/owners', [
            'name' => 'Nabil',
            'email' => 'nabil@shawarma.test',
            'password' => 'owner-password',
        ]);

        $created->assertCreated()
            ->assertJsonPath('restaurant_role', RestaurantRole::Owner->value)
            ->assertJsonPath('is_platform_admin', false)
            ->assertJsonPath('restaurant.slug', 'shawarma-house')
            ->assertJsonMissingPath('password');

        $owner = User::query()->where('email', 'nabil@shawarma.test')->first();
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
        ]);

        Sanctum::actingAs($cashier);
        $this->getJson('/api/restaurant/stats?from=2026-10-29&to=2026-10-29')
            ->assertForbidden()
            ->assertJsonPath('message', 'forbidden');
        $this->postJson('/api/admin/restaurants/'.$restaurant->id.'/owners', [
            'name' => 'Other',
            'email' => 'other@shawarma.test',
            'password' => 'owner-password',
        ])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->postJson('/api/orders', ['order_number' => '0047'])->assertForbidden();
        $this->getJson('/api/admin/restaurants')->assertForbidden();
        $this->getJson('/api/restaurant/stats?from=2026-01-01&to=2026-04-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
        $this->getJson('/api/restaurant/stats?from=2026-01-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('orders', 0)
            ->assertJsonCount(90, 'days')
            ->assertJsonPath('days.0.date', '2026-01-01')
            ->assertJsonPath('days.89.date', '2026-03-31');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', [
            'email' => 'nabil@shawarma.test',
            'password' => 'owner-password',
        ])->assertOk()
            ->assertJsonPath('user.restaurant_role', RestaurantRole::Owner->value);
    }

    public function test_stats_use_the_restaurant_timezone_and_browser_history(): void
    {
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Asia/Damascus',
            'slug' => 'shawarma-house',
        ]);
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
        ]);
        $owner = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Owner,
        ]);

        $this->place($cashier, '0001', '2026-10-27 10:00:00', 8, self::BROWSER_A);
        $this->place($cashier, '0002', '2026-10-29 10:00:00', 10, self::BROWSER_A);
        $cancelled = $this->place($cashier, '0003', '2026-10-29 12:00:00', null, self::BROWSER_B);
        $cancelled->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => Carbon::parse('2026-10-29 12:20:00', 'Asia/Damascus')->utc(),
        ]);
        $this->place($cashier, '0004', '2026-10-29 15:00:00', 6, null);
        $this->place($cashier, '0005', '2026-10-28 22:30:00', 4, self::BROWSER_C, 'UTC');

        $other = Restaurant::factory()->create(['slug' => 'other-grill']);
        $otherCashier = User::factory()->create(['restaurant_id' => $other->id]);
        $this->place($otherCashier, '0002', '2026-10-29 10:00:00', 10, self::BROWSER_B);

        Sanctum::actingAs($owner);

        $this->getJson('/api/restaurant/stats?from=2026-10-29&to=2026-10-29')
            ->assertOk()
            ->assertExactJson([
                'from' => '2026-10-29',
                'to' => '2026-10-29',
                'timezone' => 'Asia/Damascus',
                'orders' => 4,
                'ready_orders' => 3,
                'cancelled_orders' => 1,
                'average_ready_seconds' => 400,
                'longest_ready_seconds' => 600,
                'orders_without_browser' => 1,
                'average_service_rating' => null,
                'average_food_rating' => null,
                'new_browsers' => 2,
                'returning_browsers' => 1,
                'days' => [[
                    'date' => '2026-10-29',
                    'orders' => 4,
                    'ready_orders' => 3,
                    'cancelled_orders' => 1,
                    'average_ready_seconds' => 400,
                    'longest_ready_seconds' => 600,
                    'orders_without_browser' => 1,
                    'average_service_rating' => null,
                    'average_food_rating' => null,
                    'new_browsers' => 2,
                    'returning_browsers' => 1,
                ]],
            ]);

        $this->assertStringNotContainsString(self::BROWSER_A, $this->getJson('/api/restaurant/stats?from=2026-10-29&to=2026-10-29')->getContent());
    }

    public function test_the_first_browser_id_sticks_to_the_order(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shawarma-house']);
        $cashier = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $order = app(OrderService::class)->create($cashier, '0047');

        $this->postJson('/api/public/restaurants/shawarma-house/register', [
            'order_number' => '0047',
            'visitor_id' => strtoupper(self::BROWSER_A),
        ])->assertCreated()
            ->assertJsonMissingPath('visitor_id');

        $this->assertSame(self::BROWSER_A, $order->refresh()->visitor_id);

        $token = $order->cashierQr->token;

        $this->getJson('/api/public/orders/'.$token, [
            'X-Visitor-Id' => self::BROWSER_B,
        ])->assertOk()
            ->assertJsonMissingPath('visitor_id');

        $this->assertSame(self::BROWSER_A, $order->refresh()->visitor_id);

        $perOrder = app(OrderService::class)->create($cashier, '0048');

        $this->getJson('/api/public/orders/'.$perOrder->cashierQr->token, [
            'X-Visitor-Id' => 'not-a-uuid',
        ])->assertOk();
        $this->assertNull($perOrder->refresh()->visitor_id);

        $this->getJson('/api/public/orders/'.$perOrder->cashierQr->token, [
            'X-Visitor-Id' => self::BROWSER_C,
        ])->assertOk();
        $this->assertSame(self::BROWSER_C, $perOrder->refresh()->visitor_id);

        $this->postJson('/api/public/restaurants/shawarma-house/register', [
            'order_number' => '0048',
            'visitor_id' => 'phone',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['visitor_id']);

        Sanctum::actingAs($cashier);
        $this->getJson('/api/orders/'.$order->id)
            ->assertOk()
            ->assertJsonMissingPath('visitor_id');
    }

    private function place(
        User $cashier,
        string $number,
        string $createdAt,
        ?int $readyMinutes,
        ?string $visitorId,
        string $timezone = 'Asia/Damascus',
    ): Order {
        $order = app(OrderService::class)->create($cashier, $number);
        $created = Carbon::parse($createdAt, $timezone);
        $ready = $readyMinutes === null ? null : $created->copy()->addMinutes($readyMinutes);

        $order->forceFill([
            'created_at' => $created->copy()->utc(),
            'ready_at' => $ready?->copy()->utc(),
            'status' => $ready === null ? OrderStatus::Waiting : OrderStatus::Ready,
            'visitor_id' => $visitorId,
        ])->save();

        return $order;
    }
}
