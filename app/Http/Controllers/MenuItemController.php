<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMenuItemRequest;
use App\Http\Requests\UpdateMenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MenuItemController extends Controller
{
    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $item = MenuItem::query()->create([
            'restaurant_id' => $request->user()->restaurant_id,
            'menu_category_id' => $request->integer('category_id'),
            'name_ar' => $request->string('name_ar')->toString(),
            'name_en' => $request->input('name_en'),
            'description_ar' => $request->input('description_ar'),
            'description_en' => $request->input('description_en'),
            'price' => $request->integer('price'),
            'sort_order' => $request->has('sort_order') ? $request->integer('sort_order') : 0,
            'is_available' => $request->boolean('is_available', true),
            'badge' => $request->input('badge'),
        ]);

        $this->storePhoto($request, $item);

        return (new MenuItemResource($item->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMenuItemRequest $request, int $item): MenuItemResource
    {
        $model = $this->find($request, $item);
        $fields = $request->safe()->except(['photo', 'category_id']);

        if ($request->exists('category_id')) {
            $fields['menu_category_id'] = $request->integer('category_id');
        }

        $model->update($fields);
        $this->storePhoto($request, $model);

        return new MenuItemResource($model->refresh());
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->find($request, $item)->delete();

        return response()->json([
            'message' => 'deleted',
        ]);
    }

    private function find(Request $request, int $item): MenuItem
    {
        return MenuItem::query()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereKey($item)
            ->firstOrFail();
    }

    private function storePhoto(Request $request, MenuItem $item): void
    {
        $photo = $request->file('photo');

        if ($photo instanceof UploadedFile) {
            $item->storePhoto($photo);
        }
    }
}
