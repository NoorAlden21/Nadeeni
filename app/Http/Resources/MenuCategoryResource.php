<?php

namespace App\Http\Resources;

use App\Models\MenuCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MenuCategory */
class MenuCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => [
                'ar' => $this->name_ar,
                'en' => $this->name_en,
            ],
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'icon_url' => $this->iconUrl(),
            'items' => MenuItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
