<?php

namespace App\Models;

use App\Enums\RestaurantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_platform_admin',
        'restaurant_id',
        'restaurant_role',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'restaurant_role' => RestaurantRole::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $isAdmin = (bool) $user->is_platform_admin;
            $hasRestaurant = $user->restaurant_id !== null;

            if ($isAdmin) {
                if ($hasRestaurant || $user->restaurant_role !== null) {
                    throw new InvalidArgumentException(
                        'A user must be a platform admin with no restaurant, or a cashier or owner assigned to one restaurant.'
                    );
                }

                return;
            }

            if (! $hasRestaurant || ! $user->restaurant_role instanceof RestaurantRole) {
                throw new InvalidArgumentException(
                    'A user must be a platform admin with no restaurant, or a cashier or owner assigned to one restaurant.'
                );
            }
        });
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by_user_id');
    }
}
