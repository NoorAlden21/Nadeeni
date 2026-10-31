<?php

namespace Tests\Feature;

use App\Contracts\WebPushClient;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Models\OrderEvent;
use App\Models\PushSubscription;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\MinishlinkWebPushClient;
use App\Services\OrderService;
use App\Services\WebPushResult;
use App\Support\FrontendUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeWebPushClient;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_browser_can_save_move_and_delete_a_subscription(): void
    {
        [$order, $token] = $this->openOrder();
        $endpoint = 'https://push.example/browser-1';
        $body = $this->subscription($endpoint);

        $created = $this->postJson($this->subscriptionUrl($token), $body);
        $created->assertCreated()->assertExactJson(['subscribed' => true]);
        $this->assertStringNotContainsString($body['keys']['p256dh'], $created->getContent());
        $this->assertStringNotContainsString($body['keys']['auth'], $created->getContent());

        $this->assertSame(1, PushSubscription::query()->where('order_id', $order->id)->count());
        $stored = PushSubscription::query()->where('endpoint', $endpoint)->first();
        $this->assertSame($body['keys']['p256dh'], $stored->p256dh);
        $this->assertSame('aes128gcm', $stored->content_encoding);
        $this->assertNull($stored->expires_at);

        $updated = $body;
        $updated['keys']['auth'] = str_repeat('c', 22);
        $updated['content_encoding'] = 'aesgcm';
        $updated['expirationTime'] = 1_800_000_000_000;

        $this->postJson($this->subscriptionUrl($token), $updated)
            ->assertOk()
            ->assertExactJson(['subscribed' => true]);

        $stored->refresh();
        $this->assertSame(str_repeat('c', 22), $stored->auth);
        $this->assertSame('aesgcm', $stored->content_encoding);
        $this->assertNotNull($stored->expires_at);
        $this->assertSame(1, PushSubscription::query()->count());

        $stored->update([
            'failure_count' => 2,
            'last_error_at' => now(),
        ]);
        $this->postJson($this->subscriptionUrl($token), $updated)->assertOk();
        $stored->refresh();
        $this->assertSame(2, $stored->failure_count);
        $this->assertNotNull($stored->last_error_at);

        [, $otherToken] = $this->openOrder('0099', Restaurant::factory()->create([
            'name' => 'Other Grill',
            'slug' => 'other-grill',
        ]));

        $this->postJson($this->subscriptionUrl($otherToken), $updated)->assertOk();

        $stored->refresh();
        $this->assertSame(0, PushSubscription::query()->where('order_id', $order->id)->count());
        $this->assertSame($otherToken, $stored->accessToken->token);
        $this->assertSame(0, $stored->failure_count);
        $this->assertNull($stored->last_error_at);

        $this->deleteJson($this->subscriptionUrl($token), ['endpoint' => $endpoint])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        $this->deleteJson($this->subscriptionUrl($otherToken), ['endpoint' => $endpoint])
            ->assertOk()
            ->assertExactJson(['message' => 'deleted']);

        $this->assertSame(0, PushSubscription::query()->count());
        $this->deleteJson($this->subscriptionUrl($otherToken), ['endpoint' => $endpoint])
            ->assertNotFound();
    }

    public function test_subscription_limits_and_closed_tokens_are_rejected(): void
    {
        [$order, $token, $cashier] = $this->openOrder();

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/'.$i))
                ->assertCreated();
        }

        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/6'))
            ->assertStatus(409)
            ->assertExactJson(['message' => 'subscription_limit']);
        $this->assertSame(5, PushSubscription::query()->where('order_id', $order->id)->count());

        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/1'))
            ->assertOk();

        [, $fullToken] = $this->openOrder('0088', Restaurant::factory()->create([
            'slug' => 'full-grill',
        ]));
        $this->postJson($this->subscriptionUrl($fullToken), $this->subscription('https://push.example/6'))
            ->assertCreated();
        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/6'))
            ->assertStatus(409);
        $this->assertSame($fullToken, PushSubscription::query()->where('endpoint', 'https://push.example/6')->first()->accessToken->token);

        app(OrderService::class)->collect($order, $cashier);
        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/7'))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        [, $freshToken] = $this->openOrder('0077', $order->restaurant);
        OrderAccessToken::query()->where('token', $freshToken)->update([
            'expires_at' => now()->subMinute(),
        ]);
        $this->postJson($this->subscriptionUrl($freshToken), $this->subscription('https://push.example/8'))
            ->assertNotFound();

        [, $revokedToken] = $this->openOrder('0076', $order->restaurant);
        OrderAccessToken::query()->where('token', $revokedToken)->update([
            'revoked_at' => now(),
        ]);
        $this->postJson($this->subscriptionUrl($revokedToken), $this->subscription('https://push.example/9'))
            ->assertNotFound();

        $this->postJson($this->subscriptionUrl($fullToken), [
            'endpoint' => 'not-a-url',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['endpoint']);
    }

    public function test_subscription_writes_are_rate_limited(): void
    {
        [, $token] = $this->openOrder();

        RateLimiter::for('push-subscription', fn () => Limit::perMinute(1)->by('push-test'));

        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/a'))
            ->assertCreated();
        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/b'))
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too Many Attempts.');
        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_notify_sends_once_and_resend_sends_again(): void
    {
        $fake = $this->fakePush();
        [$order, $token, $cashier] = $this->openOrder();
        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/a'))
            ->assertCreated();

        Sanctum::actingAs($cashier);

        $this->postJson('/api/orders/'.$order->id.'/notify')
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::Ready->value)
            ->assertJsonPath('already_notified', false);

        $this->assertSame(1, $fake->sends);
        $payload = json_decode($fake->payloads[0], true);
        $this->assertSame('Shawarma House', $payload['restaurant_name']);
        $this->assertSame('0047', $payload['order_number']);
        $this->assertSame('طلب 0047 جاهز', $payload['message']);
        $this->assertSame(FrontendUrl::order($token), $payload['url']);
        $this->assertStringNotContainsString('browser-public-key', $fake->payloads[0]);
        $this->assertSame(1, OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', OrderEventType::NotificationSent)
            ->count());
        $this->assertSame(1, OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', OrderEventType::OrderMarkedReady)
            ->count());

        $this->postJson('/api/orders/'.$order->id.'/notify')
            ->assertOk()
            ->assertJsonPath('already_notified', true);
        $this->assertSame(1, $fake->sends);

        $this->postJson('/api/orders/'.$order->id.'/resend')->assertOk();
        $this->assertSame(2, $fake->sends);
        $this->assertSame(OrderStatus::Ready, $order->refresh()->status);
        $this->assertSame(2, OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', OrderEventType::NotificationSent)
            ->count());
    }

    public function test_a_failed_push_keeps_the_order_ready_and_drops_gone_subscriptions(): void
    {
        $fake = $this->fakePush();
        [$order, $token, $cashier] = $this->openOrder();
        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/a'))
            ->assertCreated();
        Sanctum::actingAs($cashier);

        $fake->result = new WebPushResult(false, 500);
        $this->postJson('/api/orders/'.$order->id.'/notify')->assertOk()
            ->assertJsonPath('status', OrderStatus::Ready->value);

        $subscription = PushSubscription::query()->where('order_id', $order->id)->first();
        $this->assertSame(1, $subscription->failure_count);
        $this->assertNotNull($subscription->last_error_at);
        $this->assertSame(500, OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', OrderEventType::NotificationFailed)
            ->first()
            ->metadata['status_code']);

        $fake->result = new WebPushResult(true, 201);
        $this->postJson('/api/orders/'.$order->id.'/resend')->assertOk();
        $this->assertSame(0, $subscription->refresh()->failure_count);
        $this->assertNotNull($subscription->last_success_at);

        $subscription->update(['failure_count' => 4]);
        $fake->result = new WebPushResult(false, 503);
        $this->postJson('/api/orders/'.$order->id.'/resend')->assertOk();
        $this->assertNull(PushSubscription::query()->where('endpoint', 'https://push.example/a')->first());

        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/b'))
            ->assertCreated();
        $fake->result = new WebPushResult(false, 410);
        $this->postJson('/api/orders/'.$order->id.'/resend')->assertOk();
        $this->assertSame(0, PushSubscription::query()->where('order_id', $order->id)->count());
        $this->assertSame(410, OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', OrderEventType::NotificationFailed)
            ->orderByDesc('id')
            ->first()
            ->metadata['status_code']);
        $this->assertSame(OrderStatus::Ready, $order->refresh()->status);
    }

    public function test_another_restaurants_cashier_does_not_send(): void
    {
        $fake = $this->fakePush();
        [$order, $token] = $this->openOrder();
        $this->postJson($this->subscriptionUrl($token), $this->subscription('https://push.example/a'))
            ->assertCreated();

        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);

        $this->postJson('/api/orders/'.$order->id.'/notify')->assertNotFound();
        $this->postJson('/api/orders/'.$order->id.'/resend')->assertNotFound();

        $this->assertSame(0, $fake->sends);
        $this->assertSame(OrderStatus::Waiting, $order->refresh()->status);
        $this->assertSame(1, PushSubscription::query()->where('order_id', $order->id)->count());
    }

    public function test_a_missing_vapid_key_does_not_throw(): void
    {
        config([
            'nadeeni.vapid.subject' => null,
            'nadeeni.vapid.public_key' => null,
            'nadeeni.vapid.private_key' => null,
        ]);

        $result = (new MinishlinkWebPushClient)->send(new PushSubscription([
            'endpoint' => 'https://push.example/a',
            'p256dh' => 'key',
            'auth' => 'secret',
            'content_encoding' => 'aes128gcm',
        ]), '{}');

        $this->assertFalse($result->success);
        $this->assertNull($result->statusCode);
    }

    private function fakePush(): FakeWebPushClient
    {
        $fake = new FakeWebPushClient;
        $this->app->instance(WebPushClient::class, $fake);

        return $fake;
    }

    /**
     * @return array{0: Order, 1: string, 2?: User}
     */
    private function openOrder(string $number = '0047', ?Restaurant $restaurant = null): array
    {
        $restaurant ??= Restaurant::factory()->create([
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
        ]);
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
        ]);
        $order = app(OrderService::class)->create($cashier, $number);

        return [$order, $order->cashierQr->token, $cashier];
    }

    private function subscriptionUrl(string $token): string
    {
        return '/api/public/orders/'.$token.'/push-subscriptions';
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'expirationTime' => null,
            'keys' => [
                'p256dh' => 'browser-public-key',
                'auth' => 'browser-auth-secret',
            ],
        ];
    }
}
