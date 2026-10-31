<?php

namespace App\Services;

use App\Contracts\WebPushClient;
use App\Enums\OrderEventType;
use App\Exceptions\SubscriptionLimit;
use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PushNotifier
{
    public const SUBSCRIPTION_LIMIT = 5;

    public const FAILURE_LIMIT = 5;

    public function __construct(private WebPushClient $client) {}

    /**
     * @param  array{endpoint: string, p256dh: string, auth: string, content_encoding?: string, expirationTime?: int|float|string|null}  $subscription
     */
    public function subscribe(OrderAccessToken $token, array $subscription): bool
    {
        try {
            return $this->store($token, $subscription);
        } catch (QueryException $exception) {
            if (! $this->isEndpointConflict($exception)) {
                throw $exception;
            }

            return $this->store($token, $subscription);
        }
    }

    public function unsubscribe(OrderAccessToken $token, string $endpoint): bool
    {
        $deleted = PushSubscription::query()
            ->where('order_access_token_id', $token->id)
            ->where('order_id', $token->order_id)
            ->where('endpoint', $endpoint)
            ->delete();

        return $deleted > 0;
    }

    public function deliver(Order $order, User $cashier): void
    {
        $subscriptions = $order->pushSubscriptions()->with(['order.restaurant', 'accessToken'])->get();

        foreach ($subscriptions as $subscription) {
            try {
                $result = $this->client->send($subscription, $this->payload($subscription));
            } catch (Throwable) {
                $result = new WebPushResult(false);
            }

            $this->record($subscription, $order, $cashier, $result);
        }
    }

    /**
     * @param  array{endpoint: string, p256dh: string, auth: string, content_encoding?: string, expirationTime?: int|float|string|null}  $subscription
     */
    private function store(OrderAccessToken $token, array $subscription): bool
    {
        return DB::transaction(function () use ($token, $subscription): bool {
            $order = Order::query()->whereKey($token->order_id)->lockForUpdate()->firstOrFail();
            $existing = PushSubscription::query()
                ->where('endpoint', $subscription['endpoint'])
                ->lockForUpdate()
                ->first();
            $alreadyHere = $existing !== null && $existing->order_id === $order->id;

            if (! $alreadyHere && $order->pushSubscriptions()->count() >= self::SUBSCRIPTION_LIMIT) {
                throw new SubscriptionLimit;
            }

            $attributes = [
                'order_id' => $order->id,
                'order_access_token_id' => $token->id,
                'endpoint' => $subscription['endpoint'],
                'p256dh' => $subscription['p256dh'],
                'auth' => $subscription['auth'],
                'content_encoding' => $subscription['content_encoding'] ?? 'aes128gcm',
                'expires_at' => $this->expiration($subscription['expirationTime'] ?? null),
            ];

            if ($existing === null) {
                PushSubscription::query()->create($attributes);

                return true;
            }

            if (! $alreadyHere) {
                $attributes['failure_count'] = 0;
                $attributes['last_success_at'] = null;
                $attributes['last_error_at'] = null;
            }

            $existing->update($attributes);

            return false;
        });
    }

    private function record(PushSubscription $subscription, Order $order, User $cashier, WebPushResult $result): void
    {
        $order->events()->create([
            'actor_user_id' => $cashier->id,
            'event_type' => $result->success ? OrderEventType::NotificationSent : OrderEventType::NotificationFailed,
            'metadata' => $result->statusCode === null ? null : ['status_code' => $result->statusCode],
        ]);

        if ($result->success) {
            $subscription->update([
                'failure_count' => 0,
                'last_success_at' => now(),
            ]);

            return;
        }

        if ($result->statusCode === 410) {
            $subscription->delete();

            return;
        }

        $subscription->forceFill([
            'failure_count' => $subscription->failure_count + 1,
            'last_error_at' => now(),
        ])->save();

        if ($subscription->failure_count >= self::FAILURE_LIMIT) {
            $subscription->delete();
        }
    }

    private function payload(PushSubscription $subscription): string
    {
        $order = $subscription->order;
        $number = $order->order_number;

        return json_encode([
            'restaurant_name' => $order->restaurant->name,
            'order_number' => $number,
            'message' => 'طلب '.$number.' جاهز',
            'url' => FrontendUrl::order($subscription->accessToken->token),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function expiration(int|float|string|null $expirationTime): ?Carbon
    {
        if ($expirationTime === null || $expirationTime === '') {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $expirationTime);
    }

    private function isEndpointConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'push_subscriptions_endpoint_unique')
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'push_subscriptions.endpoint'));
    }
}
