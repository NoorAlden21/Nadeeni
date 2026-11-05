<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicMenuCategoryResource;
use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PublicMenuController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $restaurant = Restaurant::query()
            ->where('slug', Str::lower($slug))
            ->where('is_active', true)
            ->where('menu', true)
            ->where('subscription_ends_at', '>', now())
            ->first();

        if ($restaurant === null) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        $categories = MenuCategory::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->with([
                'restaurant',
                'items' => fn ($query) => $query
                    ->where('is_available', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'items.restaurant',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (MenuCategory $category): bool => $category->items->isNotEmpty())
            ->values();

        $banners = MenuBanner::query()
            ->withoutImage()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $banners->each(fn (MenuBanner $banner) => $banner->setRelation('restaurant', $restaurant));

        return response()->json([
            'banners' => $banners->map(fn (MenuBanner $banner): array => [
                'image_url' => $banner->imageUrl(),
            ])->values(),
            'categories' => PublicMenuCategoryResource::collection($categories)->resolve(),
        ]);
    }

    public function banner(string $slug, int $banner): Response
    {
        $model = MenuBanner::query()
            ->whereKey($banner)
            ->whereHas('restaurant', fn ($query) => $query->where('slug', Str::lower($slug)))
            ->first();

        return $this->image($model?->imageBytes(), $model?->image_mime);
    }

    public function icon(string $slug, int $category): Response
    {
        $model = MenuCategory::query()
            ->whereKey($category)
            ->whereHas('restaurant', fn ($query) => $query->where('slug', Str::lower($slug)))
            ->first();

        return $this->image($model?->iconBytes(), $model?->icon_mime);
    }

    public function photo(string $slug, int $item): Response
    {
        $model = MenuItem::query()
            ->whereKey($item)
            ->whereHas('restaurant', fn ($query) => $query->where('slug', Str::lower($slug)))
            ->first();

        return $this->image($model?->photoBytes(), $model?->photo_mime);
    }

    private function image(?string $bytes, ?string $mime): Response
    {
        if ($bytes === null || $mime === null) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return response($bytes, 200, $headers);
    }
}
