<?php

namespace App\Models;

use App\Enums\PlanInterval;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SubscriptionPlan extends Model
{
    /** @use HasFactory<SubscriptionPlanFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'interval',
        'duration_days',
        'price',
        'currency',
        'is_active',
        'pager',
        'menu',
        'ordering',
        'kitchen',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval' => PlanInterval::class,
            'duration_days' => 'integer',
            'price' => 'integer',
            'is_active' => 'boolean',
            'pager' => 'boolean',
            'menu' => 'boolean',
            'ordering' => 'boolean',
            'kitchen' => 'boolean',
        ];
    }

    public function copyFeaturesToRestaurants(): void
    {
        Restaurant::query()
            ->where('subscription_plan_id', $this->id)
            ->update([
                'pager' => $this->pager,
                'menu' => $this->menu,
                'ordering' => $this->ordering,
                'kitchen' => $this->kitchen,
            ]);
    }

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    public function periodEnd(Carbon $start): Carbon
    {
        return match ($this->interval) {
            PlanInterval::Month => $start->copy()->addMonth(),
            PlanInterval::Year => $start->copy()->addYear(),
            PlanInterval::Trial => $start->copy()->addDays((int) $this->duration_days),
        };
    }
}
