<?php

namespace App\Http\Controllers;

use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantMenuController extends Controller
{
    public function show(Request $request): AnonymousResourceCollection
    {
        $categories = MenuCategory::query()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->with([
                'restaurant',
                'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'items.restaurant',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return MenuCategoryResource::collection($categories);
    }
}
