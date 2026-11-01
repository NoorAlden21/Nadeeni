<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRestaurantAccountRequest;
use App\Http\Resources\UserResource;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantAccountController extends Controller
{
    public function index(Restaurant $restaurant): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_platform_admin', false)
                ->with('restaurant')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
        );
    }

    public function show(User $account): UserResource
    {
        $account = $this->restaurantAccount($account);
        $account->load('restaurant');

        return new UserResource($account);
    }

    public function update(UpdateRestaurantAccountRequest $request, User $account): UserResource
    {
        $account = $this->restaurantAccount($account);
        $account->update($request->validated());

        if ($request->exists('password') || ($request->exists('is_active') && $request->boolean('is_active') === false)) {
            $account->tokens()->delete();
        }

        $account->load('restaurant');

        return new UserResource($account);
    }

    public function destroy(User $account): JsonResponse
    {
        $account = $this->restaurantAccount($account);
        $account->tokens()->delete();
        $account->delete();

        return response()->json([
            'message' => 'deleted',
        ]);
    }

    private function restaurantAccount(User $account): User
    {
        if ($account->is_platform_admin || $account->restaurant_id === null) {
            abort(404);
        }

        return $account;
    }
}
