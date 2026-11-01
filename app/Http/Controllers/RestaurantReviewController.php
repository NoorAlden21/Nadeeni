<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexRestaurantReviewsRequest;
use App\Models\OrderReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class RestaurantReviewController extends Controller
{
    public function index(IndexRestaurantReviewsRequest $request): JsonResponse
    {
        $owner = $request->user();
        $timezone = $owner->restaurant->timezone;
        $from = $request->validated('from');
        $to = $request->validated('to');

        $reviews = OrderReview::query()
            ->whereHas('order', function ($query) use ($owner): void {
                $query->where('restaurant_id', $owner->restaurant_id);
            })
            ->with('order')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (is_string($from) && is_string($to)) {
            $start = Carbon::parse($from, $timezone)->startOfDay();
            $end = Carbon::parse($to, $timezone)->addDay()->startOfDay();

            $reviews->where('created_at', '>=', $start->copy()->utc())
                ->where('created_at', '<', $end->copy()->utc());
        }

        $reviews = $reviews->get();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'reviews' => $reviews->count(),
            'average_service_rating' => $this->average($reviews->avg('service_rating')),
            'average_food_rating' => $this->average($reviews->avg('food_rating')),
            'items' => $reviews->map(fn (OrderReview $review): array => [
                'order_number' => $review->order->order_number,
                'service_rating' => $review->service_rating,
                'food_rating' => $review->food_rating,
                'message' => $review->message,
                'created_at' => $review->created_at->toISOString(),
            ])->all(),
        ]);
    }

    private function average(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round((float) $value, 1);
    }
}
