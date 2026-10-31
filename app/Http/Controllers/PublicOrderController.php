<?php

namespace App\Http\Controllers;

use App\Models\OrderAccessToken;
use Illuminate\Http\JsonResponse;

class PublicOrderController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $accessToken = OrderAccessToken::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->first();

        if ($accessToken === null) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        $accessToken->forceFill([
            'last_seen_at' => now(),
        ])->save();

        $order = $accessToken->order()->with('restaurant')->firstOrFail();

        return response()->json([
            'restaurant_name' => $order->restaurant->name,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
        ]);
    }
}
