<?php

namespace App\Services;

use App\Enums\AccessTokenPurpose;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(User $cashier, string $orderNumber): Order
    {
        $cashier->loadMissing('restaurant');

        return DB::transaction(function () use ($cashier, $orderNumber): Order {
            $this->expireDueNumber($cashier->restaurant_id, $orderNumber, $cashier);

            $expiresAt = now()->addMinutes($cashier->restaurant->order_ttl_minutes);

            try {
                $order = Order::query()->create([
                    'restaurant_id' => $cashier->restaurant_id,
                    'order_number' => $orderNumber,
                    'status' => OrderStatus::Waiting,
                    'created_by_user_id' => $cashier->id,
                    'expires_at' => $expiresAt,
                ]);
            } catch (QueryException $exception) {
                if ($this->isActiveNumberConflict($exception)) {
                    throw ValidationException::withMessages([
                        'order_number' => 'An active order with this number already exists.',
                    ]);
                }

                throw $exception;
            }

            $order->accessTokens()->create([
                'purpose' => AccessTokenPurpose::CashierQr,
                'expires_at' => $expiresAt,
            ]);

            $order->events()->create([
                'actor_user_id' => $cashier->id,
                'event_type' => OrderEventType::Created,
            ]);

            return $order->load('cashierQr');
        });
    }

    public function notify(Order $order, User $cashier): bool
    {
        return DB::transaction(function () use ($order, $cashier): bool {
            $locked = $this->lock($order);

            if ($locked->status === OrderStatus::Ready) {
                return true;
            }

            $this->assertStatus($locked, [OrderStatus::Waiting]);

            $locked->update([
                'status' => OrderStatus::Ready,
                'ready_at' => now(),
            ]);

            // notification_sent is reserved for a successful Web Push attempt.
            $locked->events()->create([
                'actor_user_id' => $cashier->id,
                'event_type' => OrderEventType::OrderMarkedReady,
            ]);

            return false;
        });
    }

    public function resend(Order $order, User $cashier): void
    {
        DB::transaction(function () use ($order, $cashier): void {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [OrderStatus::Ready]);

            $locked->events()->create([
                'actor_user_id' => $cashier->id,
                'event_type' => OrderEventType::NotificationResent,
            ]);
        });
    }

    public function collect(Order $order, User $cashier): void
    {
        $this->close($order, $cashier, OrderStatus::Collected, 'collected_at', OrderEventType::Collected);
    }

    public function cancel(Order $order, User $cashier): void
    {
        $this->close($order, $cashier, OrderStatus::Cancelled, 'cancelled_at', OrderEventType::Cancelled);
    }

    public function expireDue(): int
    {
        $expired = 0;

        Order::query()
            ->whereIn('status', [OrderStatus::Waiting, OrderStatus::Ready])
            ->where('expires_at', '<=', now())
            ->each(function (Order $order) use (&$expired): void {
                $didExpire = DB::transaction(function () use ($order): bool {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

                    if ($locked === null || ! $locked->status->isActive() || $locked->expires_at->gt(now())) {
                        return false;
                    }

                    $this->markExpired($locked, null);

                    return true;
                });

                if ($didExpire) {
                    $expired++;
                }
            });

        return $expired;
    }

    private function expireDueNumber(int|string $restaurantId, string $orderNumber, User $actor): void
    {
        $due = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->where('order_number', $orderNumber)
            ->whereIn('status', [OrderStatus::Waiting, OrderStatus::Ready])
            ->where('expires_at', '<=', now())
            ->lockForUpdate()
            ->get();

        foreach ($due as $order) {
            $this->markExpired($order, $actor);
        }
    }

    private function markExpired(Order $order, ?User $actor): void
    {
        $order->update([
            'status' => OrderStatus::Expired,
            'expired_at' => now(),
        ]);

        $order->events()->create([
            'actor_user_id' => $actor?->id,
            'event_type' => OrderEventType::Expired,
        ]);
    }

    private function close(Order $order, User $cashier, OrderStatus $status, string $timestamp, OrderEventType $eventType): void
    {
        DB::transaction(function () use ($order, $cashier, $status, $timestamp, $eventType): void {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [OrderStatus::Waiting, OrderStatus::Ready]);

            $locked->update([
                'status' => $status,
                $timestamp => now(),
            ]);

            $locked->events()->create([
                'actor_user_id' => $cashier->id,
                'event_type' => $eventType,
            ]);
        });
    }

    private function lock(Order $order): Order
    {
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<OrderStatus>  $allowed
     */
    private function assertStatus(Order $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw new InvalidOrderStatus;
        }
    }

    private function isActiveNumberConflict(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');
        $message = $exception->getMessage();

        // PostgreSQL: SQLSTATE 23505 and the partial index name.
        if ($state === '23505') {
            return str_contains($message, 'orders_one_active_number_per_restaurant');
        }

        // SQLite: SQLSTATE 23000. The message names the columns, not always the index.
        return $state === '23000'
            && str_contains($message, 'UNIQUE constraint failed')
            && (str_contains($message, 'orders.order_number')
                || str_contains($message, 'orders_one_active_number_per_restaurant'));
    }
}
