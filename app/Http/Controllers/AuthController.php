<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json([
                'message' => 'invalid_credentials',
            ], 401);
        }

        $user->load('restaurant');

        if (! $user->is_active || ($user->restaurant !== null && ! $user->restaurant->is_active)) {
            return response()->json([
                'message' => 'inactive',
            ], 403);
        }

        if ($user->restaurant !== null && ! $user->restaurant->hasCurrentSubscription()) {
            return response()->json([
                'message' => 'subscription_inactive',
            ], 403);
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'logged_out',
        ]);
    }

    public function me(Request $request): UserResource
    {
        $request->user()->load('restaurant');

        return new UserResource($request->user());
    }
}
