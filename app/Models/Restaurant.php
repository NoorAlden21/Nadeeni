<?php

namespace App\Models;

use App\Enums\PreferredQrMode;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'preferred_qr_mode',
        'timezone',
        'order_ttl_minutes',
        'is_active',
        'subscription_plan_id',
        'subscription_starts_at',
        'subscription_ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_qr_mode' => PreferredQrMode::class,
            'order_ttl_minutes' => 'integer',
            'is_active' => 'boolean',
            'subscription_starts_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::lower(trim($value)),
        );
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function hasCurrentSubscription(): bool
    {
        return $this->subscription_ends_at instanceof Carbon
            && $this->subscription_ends_at->greaterThan(now());
    }

    public function grant(SubscriptionPlan $plan): void
    {
        $start = $this->subscription_ends_at instanceof Carbon && $this->subscription_ends_at->greaterThan(now())
            ? $this->subscription_ends_at->copy()
            : now();

        $this->forceFill([
            'subscription_plan_id' => $plan->id,
            'subscription_starts_at' => $start,
            'subscription_ends_at' => $plan->periodEnd($start),
        ])->save();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
