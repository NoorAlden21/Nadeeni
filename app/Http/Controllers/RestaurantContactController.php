<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRestaurantContactRequest;
use App\Http\Resources\RestaurantResource;

class RestaurantContactController extends Controller
{
    public function update(UpdateRestaurantContactRequest $request): RestaurantResource
    {
        /** @var \App\Models\Restaurant $restaurant */
        $restaurant = $request->user()->restaurant;
        $restaurant->update($request->validated());

        return new RestaurantResource($restaurant->load('subscriptionPlan'));
    }
}
