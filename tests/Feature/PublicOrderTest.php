<?php

namespace Tests\Feature;

use App\Enums\AccessTokenPurpose;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Models\OrderEvent;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\OrderService;
use App\Support\FrontendUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_restaurant_returns_its_name_only(): void
    {
        Restaurant::factory()->create([
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
        ]);
        Restaurant::factory()->create([
            'name' => 'Closed Grill',
            'slug' => 'closed-grill',
            'is_active' => false,
        ]);

        $this->getJson('/api/public/restaurants/Shawarma-House')
            ->assertOk()
            ->assertExactJson(['name' => 'Shawarma House']);

        $missing = $this->getJson('/api/public/restaurants/missing');
        $inactive = $this->getJson('/api/public/restaurants/closed-grill');

        $missing->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->assertSame($missing->json(), $inactive->json());

        $this->postJson('/api/public/restaurants/closed-grill/register', [
            'order_number' => '0047',
        ])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_a_customer_can_register_and_poll_an_order(): void
    {
        [$restaurant, $cashier, $order] = $this->placeOrder();
        $cashierToken = $order->cashierQr->token;

        $registered = $this->postJson('/api/public/restaurants/shawarma-house/register', [
            'order_number' => '  0047  ',
        ]);

        $token = $registered->json('token');

        $registered->assertCreated()->assertExactJson([
            'token' => $token,
            'url' => FrontendUrl::order($token),
            'restaurant_name' => 'Shawarma House',
            'order_number' => '0047',
            'status' => OrderStatus::Waiting->value,
        ]);

        $this->assertSame(43, strlen($token));
        $this->assertNotSame($cashierToken, $token);

        $accessToken = OrderAccessToken::query()->where('token', $token)->first();
        $this->assertSame(AccessTokenPurpose::SharedRegistration, $accessToken->purpose);
        $this->assertTrue($accessToken->expires_at->equalTo($order->expires_at));
        $this->assertSame($order->id, $accessToken->order_id);

        $event = OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', OrderEventType::CustomerLinked)
            ->first();
        $this->assertNotNull($event);
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->metadata);

        $this->getJson('/api/public/orders/'.$cashierToken)
            ->assertOk()
            ->assertExactJson([
                'restaurant_name' => 'Shawarma House',
                'order_number' => '0047',
                'status' => OrderStatus::Waiting->value,
            ]);

        $status = $this->getJson('/api/public/orders/'.$token);
        $status->assertOk()->assertExactJson([
            'restaurant_name' => 'Shawarma House',
            'order_number' => '0047',
            'status' => OrderStatus::Waiting->value,
        ]);
        $this->assertStringNotContainsString($token, $status->getContent());
        $this->assertNotNull($accessToken->refresh()->last_seen_at);

        app(OrderService::class)->notify($order, $cashier);

        $this->getJson('/api/public/orders/'.$token)
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::Ready->value);

        app(OrderService::class)->collect($order, $cashier);

        $this->getJson('/api/public/orders/'.$token)
            ->assertOk()
            ->assertExactJson([
                'restaurant_name' => 'Shawarma House',
                'order_number' => '0047',
                'status' => OrderStatus::Collected->value,
            ]);

        $this->assertSame($restaurant->id, $order->restaurant_id);
    }

    public function test_unavailable_numbers_share_one_response(): void
    {
        [$restaurant, $cashier, $order] = $this->placeOrder();
        Restaurant::factory()->create(['slug' => 'other-grill']);

        $unknown = $this->register('shawarma-house', '999');
        $unknown->assertNotFound()->assertExactJson(['message' => 'order_unavailable']);

        $this->register('shawarma-house', '47')->assertExactJson($unknown->json());
        $this->register('shawarma-house', 'a47')->assertExactJson($unknown->json());
        $this->register('other-grill', '0047')->assertExactJson($unknown->json());

        $order->update(['expires_at' => now()->subMinute()]);
        $this->register('shawarma-house', '0047')->assertExactJson($unknown->json());
        $this->assertSame(OrderStatus::Waiting, $order->refresh()->status);

        foreach ([OrderStatus::Collected, OrderStatus::Cancelled, OrderStatus::Expired] as $status) {
            $number = '10'.$status->value;
            [, $closedCashier, $closed] = $this->placeOrder($number, $restaurant);
            app(OrderService::class)->collect($closed, $closedCashier);
            if ($status !== OrderStatus::Collected) {
                $closed->update([
                    'status' => $status,
                    'collected_at' => null,
                ]);
            }

            $this->register($restaurant->slug, $number)->assertExactJson($unknown->json());
        }
        $this->assertSame(0, OrderAccessToken::query()
            ->where('purpose', AccessTokenPurpose::SharedRegistration)
            ->count());
        $this->assertSame($cashier->restaurant_id, $order->restaurant_id);
    }

    public function test_registration_keeps_case_and_follows_a_reused_number(): void
    {
        [, $cashier, $order] = $this->placeOrder('A47');

        $this->register('shawarma-house', 'a47')->assertNotFound()
            ->assertJsonPath('message', 'order_unavailable');

        $this->register('shawarma-house', 'A47')->assertCreated()
            ->assertJsonPath('order_number', 'A47')
            ->assertJsonPath('status', OrderStatus::Waiting->value);

        app(OrderService::class)->collect($order, $cashier);
        $replacement = app(OrderService::class)->create($cashier, 'A47');

        $registered = $this->register('shawarma-house', 'A47')->assertCreated();
        $accessToken = OrderAccessToken::query()->where('token', $registered->json('token'))->first();

        $this->assertSame($replacement->id, $accessToken->order_id);
        $this->assertSame(OrderStatus::Waiting->value, $registered->json('status'));
    }

    public function test_a_fifth_customer_link_is_rejected_until_one_is_revoked(): void
    {
        [, , $order] = $this->placeOrder();

        for ($i = 0; $i < 4; $i++) {
            $this->register('shawarma-house', '0047')->assertCreated();
        }

        $this->register('shawarma-house', '0047')
            ->assertStatus(409)
            ->assertExactJson(['message' => 'order_link_limit']);

        $this->assertSame(5, OrderAccessToken::query()
            ->where('order_id', $order->id)
            ->whereNull('revoked_at')
            ->count());

        OrderAccessToken::query()
            ->where('order_id', $order->id)
            ->where('purpose', AccessTokenPurpose::SharedRegistration)
            ->first()
            ->update(['revoked_at' => now()]);

        $revoked = OrderAccessToken::query()
            ->where('order_id', $order->id)
            ->whereNotNull('revoked_at')
            ->first();

        $this->getJson('/api/public/orders/'.$revoked->token)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        $this->register('shawarma-house', '0047')->assertCreated();
        $this->getJson('/api/public/orders/not-a-token')->assertNotFound();
    }

    public function test_registration_validates_the_order_number(): void
    {
        $this->placeOrder();

        $this->register('shawarma-house', '   ')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_number']);

        $this->postJson('/api/public/restaurants/shawarma-house/register', [
            'order_number' => 47,
        ])->assertUnprocessable()->assertJsonValidationErrors(['order_number']);

        $this->register('shawarma-house', str_repeat('1', 33))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_number']);
    }

    public function test_registration_and_polling_are_rate_limited(): void
    {
        [, , $first] = $this->placeOrder('1');
        [, , $second] = $this->placeOrder('2', Restaurant::factory()->create([
            'name' => 'Second House',
            'slug' => 'second-house',
        ]));

        RateLimiter::for('register', fn () => Limit::perMinute(1)->by('register-test'));

        $firstToken = $this->register('shawarma-house', '1')->assertCreated()->json('token');
        $this->register('shawarma-house', '1')
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too Many Attempts.');
        $this->assertSame(1, OrderAccessToken::query()
            ->where('order_id', $first->id)
            ->where('purpose', AccessTokenPurpose::SharedRegistration)
            ->count());

        $secondToken = OrderAccessToken::query()
            ->where('order_id', $second->id)
            ->where('purpose', AccessTokenPurpose::CashierQr)
            ->first()
            ->token;

        RateLimiter::for('order-status', function (Request $request) {
            return Limit::perMinute(2)->by((string) $request->route('token'));
        });

        $this->getJson('/api/public/orders/'.$firstToken)->assertOk();
        $this->getJson('/api/public/orders/'.$firstToken)->assertOk();
        $this->getJson('/api/public/orders/'.$firstToken)->assertStatus(429);
        $this->getJson('/api/public/orders/'.$secondToken)->assertOk();
    }

    private function register(string $slug, string $orderNumber): TestResponse
    {
        return $this->postJson('/api/public/restaurants/'.$slug.'/register', [
            'order_number' => $orderNumber,
        ]);
    }

    /**
     * @return array{0: Restaurant, 1: User, 2: Order}
     */
    private function placeOrder(string $number = '0047', ?Restaurant $restaurant = null): array
    {
        $restaurant ??= Restaurant::factory()->create([
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
        ]);
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
        ]);
        $order = app(OrderService::class)->create($cashier, $number);

        return [$restaurant, $cashier, $order];
    }
}
