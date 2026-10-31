<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCashier
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->loadMissing('restaurant');

        if (! $user || $user->is_platform_admin || $user->restaurant_id === null) {
            return response()->json([
                'message' => 'forbidden',
            ], 403);
        }

        if (! $user->is_active || ! $user->restaurant?->is_active) {
            return response()->json([
                'message' => 'inactive',
            ], 403);
        }

        return $next($request);
    }
}
