<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderAccessToken;
use App\Support\VisitorId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicOrderController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
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
        $this->rememberVisitor($order, VisitorId::fromHeader($request->header('X-Visitor-Id')));

        return response()->json([
            'restaurant_name' => $order->restaurant->name,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
        ]);
    }

    private function rememberVisitor(Order $order, ?string $visitorId): void
    {
        if ($visitorId === null || $order->visitor_id !== null) {
            return;
        }

        Order::query()
            ->whereKey($order->id)
            ->whereNull('visitor_id')
            ->update([
                'visitor_id' => $visitorId,
            ]);
    }
}
