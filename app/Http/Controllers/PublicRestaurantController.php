<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterOrderRequest;
use App\Models\Restaurant;
use App\Services\OrderService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
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
        ]);
    }

    public function register(RegisterOrderRequest $request, string $slug): JsonResponse
    {
        $restaurant = $this->activeRestaurant($slug);

        if ($restaurant === null) {
            return $this->notFound();
        }

        $token = $this->orders->registerShared(
            $restaurant,
            $request->string('order_number')->toString(),
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
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Not found.',
        ], 404);
    }
}
