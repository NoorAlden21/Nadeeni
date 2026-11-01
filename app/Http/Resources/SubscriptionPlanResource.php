<?php

namespace App\Http\Resources;

use App\Enums\PlanInterval;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionPlan */
class SubscriptionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'interval' => $this->interval->value,
            'duration_days' => $this->interval === PlanInterval::Trial ? $this->duration_days : null,
            'price' => $this->price,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
        ];
    }
}
