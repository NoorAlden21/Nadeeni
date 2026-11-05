<?php

namespace App\Http\Middleware;

use App\Enums\RestaurantRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->loadMissing('restaurant');

        if (! $user || ! in_array($user->restaurant_role, [RestaurantRole::Cashier, RestaurantRole::Owner], true)) {
            return response()->json([
                'message' => 'forbidden',
            ], 403);
        }

        if (! $user->is_active || ! $user->restaurant?->is_active) {
            return response()->json([
                'message' => 'inactive',
            ], 403);
        }

        if (! $user->restaurant->hasCurrentSubscription()) {
            return response()->json([
                'message' => 'subscription_inactive',
            ], 403);
        }

        if (! $user->restaurant->menu) {
            return response()->json([
                'message' => 'feature_unavailable',
            ], 403);
        }

        return $next($request);
    }
}
