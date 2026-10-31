<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PreferredQrMode;
use App\Enums\RestaurantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashierRequest;
use App\Http\Requests\StoreRestaurantRequest;
use App\Http\Requests\UpdateRestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Http\Resources\UserResource;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RestaurantResource::collection(
            Restaurant::query()->orderBy('name')->get()
        );
    }

    public function store(StoreRestaurantRequest $request): JsonResponse
    {
        $restaurant = Restaurant::query()->create(array_merge([
            'preferred_qr_mode' => PreferredQrMode::Shared->value,
            'timezone' => 'Asia/Damascus',
            'order_ttl_minutes' => 240,
            'is_active' => true,
        ], $request->validated()));

        return (new RestaurantResource($restaurant))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): RestaurantResource
    {
        $restaurant->update($request->validated());

        return new RestaurantResource($restaurant);
    }

    public function storeCashier(StoreCashierRequest $request, Restaurant $restaurant): JsonResponse
    {
        return $this->createRestaurantUser($request, $restaurant, RestaurantRole::Cashier);
    }

    public function storeOwner(StoreCashierRequest $request, Restaurant $restaurant): JsonResponse
    {
        return $this->createRestaurantUser($request, $restaurant, RestaurantRole::Owner);
    }

    private function createRestaurantUser(StoreCashierRequest $request, Restaurant $restaurant, RestaurantRole $role): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'is_platform_admin' => false,
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => $role,
            'is_active' => true,
        ]);

        $user->load('restaurant');

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
