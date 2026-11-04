<?php

namespace App\Http\Controllers;

use App\Exceptions\BannerLimit;
use App\Http\Requests\StoreMenuBannerRequest;
use App\Http\Requests\UpdateMenuBannerRequest;
use App\Http\Resources\MenuBannerResource;
use App\Models\MenuBanner;
use App\Models\Restaurant;
use App\Support\StoredImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class MenuBannerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $restaurant = $request->user()->restaurant;
        $banners = MenuBanner::query()
            ->withoutImage()
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $banners->each(fn (MenuBanner $banner) => $banner->setRelation('restaurant', $restaurant));

        return MenuBannerResource::collection($banners);
    }

    public function store(StoreMenuBannerRequest $request): JsonResponse
    {
        $banner = DB::transaction(function () use ($request) {
            $restaurantId = $request->user()->restaurant_id;
            Restaurant::query()->whereKey($restaurantId)->lockForUpdate()->first();

            if (MenuBanner::query()->where('restaurant_id', $restaurantId)->count() >= MenuBanner::LIMIT) {
                throw new BannerLimit;
            }

            [$encoded, $mime] = StoredImage::encode($request->file('image'));

            return MenuBanner::query()->create([
                'restaurant_id' => $restaurantId,
                'sort_order' => $request->has('sort_order') ? $request->integer('sort_order') : 0,
                'is_active' => $request->boolean('is_active', true),
                'image' => $encoded,
                'image_mime' => $mime,
                'image_sha' => substr(sha1($encoded), 0, 12),
            ]);
        });

        $banner->setRelation('restaurant', $request->user()->restaurant);

        return (new MenuBannerResource($banner))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMenuBannerRequest $request, int $banner): MenuBannerResource
    {
        $model = $this->find($request, $banner);
        $model->update($request->safe()->except(['image']));

        $image = $request->file('image');
        if ($image instanceof UploadedFile) {
            $model->storeImage($image);
        }

        $model->setRelation('restaurant', $request->user()->restaurant);

        return new MenuBannerResource($model);
    }

    public function destroy(Request $request, int $banner): JsonResponse
    {
        $this->find($request, $banner)->delete();

        return response()->json([
            'message' => 'deleted',
        ]);
    }

    private function find(Request $request, int $banner): MenuBanner
    {
        return MenuBanner::query()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereKey($banner)
            ->firstOrFail();
    }
}
