<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Models\OrderEvent;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_platform_admins_cannot_manage_orders(): void
    {
        $this->postJson('/api/orders', ['order_number' => '1'])->assertUnauthorized();
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->getJson('/api/restaurant/shared-qr')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->postJson('/api/orders', ['order_number' => '1'])
            ->assertForbidden()
            ->assertJsonPath('message', 'forbidden');

        $this->getJson('/api/orders')
            ->assertForbidden()
            ->assertJsonPath('message', 'forbidden');

        $this->getJson('/api/restaurant/shared-qr')
            ->assertForbidden()
            ->assertJsonPath('message', 'forbidden');

        $this->postJson('/api/orders/00000000-0000-7000-8000-000000000001/notify')
            ->assertForbidden()
            ->assertJsonPath('message', 'forbidden');
    }

    public function test_an_inactive_restaurant_or_cashier_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create(['is_active' => false]);
        $cashier = User::factory()->create(['restaurant_id' => $restaurant->id]);
        Sanctum::actingAs($cashier);

        $this->postJson('/api/orders', ['order_number' => '1'])
            ->assertForbidden()
            ->assertJsonPath('message', 'inactive');

        $restaurant->update(['is_active' => true]);
        $cashier->update(['is_active' => false]);
        $cashier->unsetRelation('restaurant');

        $this->getJson('/api/orders')
            ->assertForbidden()
            ->assertJsonPath('message', 'inactive');
    }

    public function test_a_cashier_can_create_list_and_show_an_order(): void
    {
        $other = Restaurant::factory()->create();
        $cashier = $this->actingCashier();
        $base = rtrim((string) config('nadeeni.frontend_url'), '/');

        $this->postJson('/api/orders', ['order_number' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_number']);

        $this->postJson('/api/orders', ['order_number' => 47])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_number']);

        $this->postJson('/api/orders', ['order_number' => str_repeat('1', 33)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_number']);

        $this->getJson('/api/orders/not-a-uuid')->assertNotFound();

        $created = $this->postJson('/api/orders', [
            'order_number' => '  0047  ',
            'restaurant_id' => $other->id,
        ]);

        $created->assertCreated()
            ->assertJsonPath('order_number', '0047')
            ->assertJsonPath('status', OrderStatus::Waiting->value)
            ->assertJsonPath('ready_at', null);

        $token = $created->json('cashier_qr.token');
        $id = $created->json('id');

        $this->assertSame(43, strlen($token));
        $this->assertSame($base.'/o/'.$token, $created->json('cashier_qr.url'));
        $this->assertSame(FrontendUrl::order($token), $created->json('cashier_qr.url'));
        $this->assertDatabaseHas('orders', [
            'id' => $id,
            'restaurant_id' => $cashier->restaurant_id,
            'order_number' => '0047',
        ]);
        $this->assertSame(0, Order::query()->where('restaurant_id', $other->id)->count());
        $this->assertSame(1, OrderAccessToken::query()->where('order_id', $id)->count());
        $this->assertDatabaseHas('order_events', [
            'order_id' => $id,
            'actor_user_id' => $cashier->id,
            'event_type' => OrderEventType::Created->value,
        ]);

        $this->travel(1)->second();

        $newer = $this->postJson('/api/orders', ['order_number' => '0048'])->assertCreated();

        $list = $this->getJson('/api/orders');
        $list->assertOk()
            ->assertJsonPath('0.id', $newer->json('id'))
            ->assertJsonPath('0.order_number', '0048')
            ->assertJsonPath('1.id', $id)
            ->assertJsonMissingPath('0.cashier_qr');
        $this->assertStringNotContainsString($token, $list->getContent());

        $this->getJson('/api/orders/'.$id)
            ->assertOk()
            ->assertJsonPath('cashier_qr.token', $token)
            ->assertJsonPath('cashier_qr.url', $base.'/o/'.$token);

        $this->getJson('/api/orders?status=collected')
            ->assertOk()
            ->assertJsonCount(0);

        $this->getJson('/api/orders?status=nope')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/orders/'.$newer->json('id').'/collect')->assertOk();

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $id);

        $this->getJson('/api/orders?status=collected')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $newer->json('id'))
            ->assertJsonMissingPath('0.cashier_qr');

        Order::query()->whereKey($newer->json('id'))->update([
            'updated_at' => now()->subMinutes($cashier->restaurant->order_ttl_minutes + 1),
        ]);

        $this->getJson('/api/orders?status=collected')
            ->assertOk()
            ->assertJsonCount(0);

        Order::query()->whereKey($id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('0.id', $id);

        $this->getJson('/api/restaurant/shared-qr')
            ->assertOk()
            ->assertJsonPath('url', $base.'/r/'.$cashier->restaurant->slug);
    }

    public function test_a_due_number_can_be_reused_and_a_live_number_cannot(): void
    {
        $cashier = $this->actingCashier();
        $first = $this->postJson('/api/orders', ['order_number' => '0047'])->assertCreated();

        $this->postJson('/api/orders', ['order_number' => '0047'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.order_number.0', 'An active order with this number already exists.');

        $this->assertSame(OrderStatus::Waiting, Order::query()->find($first->json('id'))->status);

        $other = $this->actingCashier();
        $this->postJson('/api/orders', ['order_number' => '0047'])
            ->assertCreated()
            ->assertJsonPath('order_number', '0047');

        $this->actAs($cashier);
        $this->travel($cashier->restaurant->order_ttl_minutes + 1)->minutes();

        $second = $this->postJson('/api/orders', ['order_number' => '0047'])->assertCreated();

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertNotSame($first->json('cashier_qr.token'), $second->json('cashier_qr.token'));
        $this->assertSame(OrderStatus::Expired, Order::query()->find($first->json('id'))->status);
        $this->assertSame(OrderStatus::Waiting, Order::query()->find($second->json('id'))->status);

        $expired = OrderEvent::query()
            ->where('order_id', $first->json('id'))
            ->where('event_type', OrderEventType::Expired)
            ->first();

        $this->assertNotNull($expired);
        $this->assertSame($cashier->id, $expired->actor_user_id);
        $this->assertSame(1, Order::query()->where('restaurant_id', $other->restaurant_id)->count());
    }

    public function test_a_cashier_cannot_touch_another_restaurants_order(): void
    {
        $cashier = $this->actingCashier();
        $id = $this->postJson('/api/orders', ['order_number' => '15'])->assertCreated()->json('id');

        $this->actingCashier();

        $this->getJson('/api/orders/'.$id)->assertNotFound();
        $this->postJson('/api/orders/'.$id.'/notify')->assertNotFound();
        $this->postJson('/api/orders/'.$id.'/resend')->assertNotFound();
        $this->postJson('/api/orders/'.$id.'/collect')->assertNotFound();
        $this->postJson('/api/orders/'.$id.'/cancel')->assertNotFound();
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(0);

        $this->assertSame(OrderStatus::Waiting, Order::query()->find($id)->status);
        $this->assertSame($cashier->restaurant_id, Order::query()->find($id)->restaurant_id);
    }

    public function test_notify_resend_collect_and_cancel_follow_the_lifecycle(): void
    {
        $cashier = $this->actingCashier();
        $waiting = $this->postJson('/api/orders', ['order_number' => '1'])->assertCreated()->json('id');

        $this->postJson('/api/orders/'.$waiting.'/resend')
            ->assertStatus(409)
            ->assertJsonPath('message', 'invalid_status');

        $notified = $this->postJson('/api/orders/'.$waiting.'/notify');
        $notified->assertOk()
            ->assertJsonPath('status', OrderStatus::Ready->value)
            ->assertJsonPath('already_notified', false)
            ->assertJsonMissingPath('cashier_qr');

        $readyAt = $notified->json('ready_at');
        $this->assertNotNull($readyAt);

        $this->postJson('/api/orders/'.$waiting.'/notify')
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::Ready->value)
            ->assertJsonPath('already_notified', true)
            ->assertJsonPath('ready_at', $readyAt);

        $this->assertSame(1, OrderEvent::query()
            ->where('order_id', $waiting)
            ->where('event_type', OrderEventType::OrderMarkedReady)
            ->count());
        $this->assertSame(0, OrderEvent::query()
            ->where('order_id', $waiting)
            ->where('event_type', OrderEventType::NotificationSent)
            ->count());

        $this->postJson('/api/orders/'.$waiting.'/resend')
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::Ready->value)
            ->assertJsonPath('ready_at', $readyAt)
            ->assertJsonMissingPath('already_notified');

        $this->assertSame(1, OrderEvent::query()
            ->where('order_id', $waiting)
            ->where('event_type', OrderEventType::NotificationResent)
            ->where('actor_user_id', $cashier->id)
            ->count());

        $this->postJson('/api/orders/'.$waiting.'/cancel')
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::Cancelled->value)
            ->assertJsonMissingPath('cashier_qr');

        $this->assertNotNull(Order::query()->find($waiting)->cancelled_at);
        $this->assertSame($readyAt, Order::query()->find($waiting)->ready_at?->toISOString());

        $this->postJson('/api/orders/'.$waiting.'/notify')
            ->assertStatus(409)
            ->assertJsonPath('message', 'invalid_status');
        $this->postJson('/api/orders/'.$waiting.'/collect')
            ->assertStatus(409)
            ->assertJsonPath('message', 'invalid_status');

        $toCollect = $this->postJson('/api/orders', ['order_number' => '2'])->assertCreated()->json('id');

        $this->postJson('/api/orders/'.$toCollect.'/collect')
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::Collected->value);

        $collected = Order::query()->find($toCollect);
        $this->assertNotNull($collected->collected_at);
        $this->assertNull($collected->ready_at);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $toCollect,
            'event_type' => OrderEventType::Collected->value,
            'actor_user_id' => $cashier->id,
        ]);

        $this->postJson('/api/orders/'.$toCollect.'/notify')->assertStatus(409);
        $this->postJson('/api/orders/'.$toCollect.'/cancel')->assertStatus(409);

        $toExpire = $this->postJson('/api/orders', ['order_number' => '3'])->assertCreated()->json('id');
        Order::query()->whereKey($toExpire)->update([
            'status' => OrderStatus::Expired->value,
            'expired_at' => now(),
        ]);

        $this->postJson('/api/orders/'.$toExpire.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('message', 'invalid_status');
    }

    public function test_resend_is_rate_limited(): void
    {
        $this->actingCashier();
        $id = $this->postJson('/api/orders', ['order_number' => '9'])->assertCreated()->json('id');
        $this->postJson('/api/orders/'.$id.'/notify')->assertOk();

        RateLimiter::for('resend', function () {
            return Limit::perMinute(1)->by('resend-test');
        });

        $this->postJson('/api/orders/'.$id.'/resend')->assertOk();
        $this->postJson('/api/orders/'.$id.'/resend')
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too Many Attempts.');

        $this->assertSame(1, OrderEvent::query()
            ->where('order_id', $id)
            ->where('event_type', OrderEventType::NotificationResent)
            ->count());
    }

    public function test_the_expiry_command_marks_due_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $dueWaiting = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => OrderStatus::Waiting,
            'expires_at' => now()->subMinute(),
        ]);
        $dueReady = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => OrderStatus::Ready,
            'expires_at' => now()->subMinute(),
        ]);
        $fresh = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => OrderStatus::Waiting,
            'expires_at' => now()->addHour(),
        ]);
        $collected = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => OrderStatus::Collected,
            'collected_at' => now()->subMinute(),
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('orders:expire')
            ->expectsOutputToContain('Expired 2 orders.')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $dueWaiting->refresh()->status);
        $this->assertNotNull($dueWaiting->expired_at);
        $this->assertSame(OrderStatus::Expired, $dueReady->refresh()->status);
        $this->assertSame(OrderStatus::Waiting, $fresh->refresh()->status);
        $this->assertSame(OrderStatus::Collected, $collected->refresh()->status);

        $event = OrderEvent::query()
            ->where('order_id', $dueWaiting->id)
            ->where('event_type', OrderEventType::Expired)
            ->first();

        $this->assertNotNull($event);
        $this->assertNull($event->actor_user_id);

        $this->artisan('orders:expire')
            ->expectsOutputToContain('Expired 0 orders.')
            ->assertSuccessful();

        $this->assertSame(1, OrderEvent::query()
            ->where('order_id', $dueWaiting->id)
            ->where('event_type', OrderEventType::Expired)
            ->count());
    }

    public function test_due_orders_are_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('orders:expire')
            ->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'orders:expire'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    private function actingCashier(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
        ]);

        $this->actAs($cashier);

        return $cashier->load('restaurant');
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }
}
