<?php

namespace App\Http\Resources;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Restaurant */
class RestaurantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'preferred_qr_mode' => $this->preferred_qr_mode->value,
            'timezone' => $this->timezone,
            'order_ttl_minutes' => $this->order_ttl_minutes,
            'is_active' => $this->is_active,
        ];
    }
}
