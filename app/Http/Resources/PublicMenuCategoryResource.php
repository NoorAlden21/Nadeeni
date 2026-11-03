<?php

namespace App\Http\Resources;

use App\Models\MenuCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MenuCategory */
class PublicMenuCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => [
                'ar' => $this->name_ar,
                'en' => $this->name_en,
            ],
            'icon_url' => $this->iconUrl(),
            'items' => $this->items->map(fn ($item): array => [
                'name' => [
                    'ar' => $item->name_ar,
                    'en' => $item->name_en,
                ],
                'description' => [
                    'ar' => $item->description_ar,
                    'en' => $item->description_en,
                ],
                'price' => $item->price,
                'photo_url' => $item->photoUrl(),
            ])->values(),
        ];
    }
}
