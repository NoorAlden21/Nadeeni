<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterOrderRequest;
use App\Models\Restaurant;
use App\Services\OrderService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PublicRestaurantController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function show(string $slug): JsonResponse
    {
        $restaurant = $this->activeRestaurant($slug);

        if ($restaurant === null) {
            return $this->notFound();
        }

        return response()->json([
            'name' => $restaurant->name,
            'icon_url' => $restaurant->iconUrl(),
            'features' => $restaurant->features(),
        ]);
    }

    public function icon(string $slug): Response
    {
        $restaurant = Restaurant::query()
            ->where('slug', Str::lower($slug))
            ->first();

        $bytes = $restaurant?->iconBytes();

        if ($restaurant === null || $bytes === null || $restaurant->icon_mime === null) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $restaurant->icon_mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($restaurant->icon_mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return response($bytes, 200, $headers);
    }

    public function register(RegisterOrderRequest $request, string $slug): JsonResponse
    {
        $restaurant = $this->activeRestaurant($slug);

        if ($restaurant === null || ! $restaurant->pager) {
            return $this->notFound();
        }

        $visitorId = $request->validated('visitor_id');

        $token = $this->orders->registerShared(
            $restaurant,
            $request->string('order_number')->toString(),
            is_string($visitorId) ? Str::lower($visitorId) : null,
        );

        return response()->json([
            'token' => $token->token,
            'url' => FrontendUrl::order($token->token),
            'restaurant_name' => $restaurant->name,
            'order_number' => $token->order->order_number,
            'status' => $token->order->status->value,
        ], 201);
    }

    private function activeRestaurant(string $slug): ?Restaurant
    {
        return Restaurant::query()
            ->where('slug', Str::lower($slug))
            ->where('is_active', true)
            ->where('subscription_ends_at', '>', now())
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Not found.',
        ], 404);
    }
}
