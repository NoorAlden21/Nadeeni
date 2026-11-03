<?php

namespace App\Http\Controllers;

use App\Exceptions\CategoryInUse;
use App\Http\Requests\StoreMenuCategoryRequest;
use App\Http\Requests\UpdateMenuCategoryRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MenuCategoryController extends Controller
{
    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $category = MenuCategory::query()->create([
            'restaurant_id' => $request->user()->restaurant_id,
            'name_ar' => $request->string('name_ar')->toString(),
            'name_en' => $request->input('name_en'),
            'sort_order' => $request->has('sort_order') ? $request->integer('sort_order') : 0,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->storeIcon($request, $category);
        $category->refresh();
        $category->setRelation('items', collect());

        return (new MenuCategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMenuCategoryRequest $request, int $category): MenuCategoryResource
    {
        $model = $this->find($request, $category);
        $model->update($request->safe()->except(['icon']));
        $this->storeIcon($request, $model);

        return new MenuCategoryResource($model->refresh());
    }

    public function destroy(Request $request, int $category): JsonResponse
    {
        $model = $this->find($request, $category);

        if ($model->items()->exists()) {
            throw new CategoryInUse;
        }

        $model->delete();

        return response()->json([
            'message' => 'deleted',
        ]);
    }

    private function find(Request $request, int $category): MenuCategory
    {
        return MenuCategory::query()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereKey($category)
            ->firstOrFail();
    }

    private function storeIcon(Request $request, MenuCategory $category): void
    {
        $icon = $request->file('icon');

        if ($icon instanceof UploadedFile) {
            $category->storeIcon($icon);
        }
    }
}
