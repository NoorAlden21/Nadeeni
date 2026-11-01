<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\AlreadyReviewed;
use App\Exceptions\InvalidOrderStatus;
use App\Http\Requests\StoreOrderReviewRequest;
use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Models\OrderReview;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PublicOrderReviewController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $order = $this->orderForToken($token);

        if ($order === null || $order->review === null) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        return response()->json($order->review->toPublicArray());
    }

    public function store(StoreOrderReviewRequest $request, string $token): JsonResponse
    {
        $order = $this->orderForToken($token);

        if ($order === null) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        if (! in_array($order->status, [OrderStatus::Ready, OrderStatus::Collected], true)) {
            throw new InvalidOrderStatus;
        }

        try {
            $review = DB::transaction(function () use ($order, $request): OrderReview {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->review()->exists()) {
                    throw new AlreadyReviewed;
                }

                return $locked->review()->create([
                    'service_rating' => $request->integer('service_rating'),
                    'food_rating' => $request->integer('food_rating'),
                    'message' => $request->validated('message'),
                ]);
            });
        } catch (QueryException $exception) {
            if (! $this->isReviewConflict($exception)) {
                throw $exception;
            }

            throw new AlreadyReviewed;
        }

        return response()->json($review->toPublicArray());
    }

    private function orderForToken(string $token): ?Order
    {
        $accessToken = OrderAccessToken::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->first();

        if ($accessToken === null) {
            return null;
        }

        return $accessToken->order()->with('review')->first();
    }

    private function isReviewConflict(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');

        return $state === '23505' || $state === '23000';
    }
}
