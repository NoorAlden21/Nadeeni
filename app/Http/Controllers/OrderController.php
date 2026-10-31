<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\IndexOrdersRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(IndexOrdersRequest $request): AnonymousResourceCollection
    {
        $restaurant = $request->user()->restaurant;
        $status = $request->validated('status');

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status === null) {
            $orders->whereIn('status', [OrderStatus::Waiting, OrderStatus::Ready]);
        } else {
            $parsed = OrderStatus::from($status);
            $orders->where('status', $parsed);

            if (! $parsed->isActive()) {
                $orders->where('updated_at', '>=', now()->subMinutes($restaurant->order_ttl_minutes));
            }
        }

        return OrderResource::collection($orders->get());
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create(
            $request->user(),
            $request->string('order_number')->toString(),
        );

        return OrderResource::withCashierQr($order)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $order): OrderResource
    {
        return OrderResource::withCashierQr(
            $this->findOrder($request, $order)->load('cashierQr')
        );
    }

    public function update(UpdateOrderRequest $request, string $order): OrderResource
    {
        $model = $this->findOrder($request, $order);
        $this->orders->updateNumber(
            $model,
            $request->user(),
            $request->string('order_number')->toString(),
        );

        return new OrderResource($model->refresh());
    }

    public function notify(Request $request, string $order): OrderResource
    {
        $model = $this->findOrder($request, $order);
        $alreadyNotified = $this->orders->notify($model, $request->user());

        $resource = new OrderResource($model->refresh());
        $resource->alreadyNotified = $alreadyNotified;

        return $resource;
    }

    public function resend(Request $request, string $order): OrderResource
    {
        $model = $this->findOrder($request, $order);
        $this->orders->resend($model, $request->user());

        return new OrderResource($model->refresh());
    }

    public function collect(Request $request, string $order): OrderResource
    {
        $model = $this->findOrder($request, $order);
        $this->orders->collect($model, $request->user());

        return new OrderResource($model->refresh());
    }

    public function cancel(Request $request, string $order): OrderResource
    {
        $model = $this->findOrder($request, $order);
        $this->orders->cancel($model, $request->user());

        return new OrderResource($model->refresh());
    }

    private function findOrder(Request $request, string $orderId): Order
    {
        return Order::query()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereKey($orderId)
            ->firstOrFail();
    }
}
