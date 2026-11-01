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
            'icon_url' => $this->iconUrl(),
            'subscription' => $this->subscriptionPayload(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionPayload(): ?array
    {
        if ($this->subscription_plan_id === null) {
            return null;
        }

        return [
            'plan_id' => $this->subscription_plan_id,
            'plan_name' => $this->subscriptionPlan->name,
            'interval' => $this->subscriptionPlan->interval->value,
            'starts_at' => $this->subscription_starts_at?->toISOString(),
            'ends_at' => $this->subscription_ends_at?->toISOString(),
            'is_current' => $this->hasCurrentSubscription(),
        ];
    }
}
