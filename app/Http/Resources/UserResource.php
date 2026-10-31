<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_platform_admin' => $this->is_platform_admin,
            'restaurant_role' => $this->restaurant_role?->value,
            'is_active' => $this->is_active,
            'restaurant' => $this->restaurant_id === null ? null : [
                'name' => $this->restaurant->name,
                'slug' => $this->restaurant->slug,
                'preferred_qr_mode' => $this->restaurant->preferred_qr_mode->value,
                'timezone' => $this->restaurant->timezone,
            ],
        ];
    }
}
