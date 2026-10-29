<?php

namespace App\Models;

use App\Enums\AccessTokenPurpose;
use Database\Factories\OrderAccessTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderAccessToken extends Model
{
    /** @use HasFactory<OrderAccessTokenFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'token',
        'purpose',
        'expires_at',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => AccessTokenPurpose::class,
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderAccessToken $accessToken): void {
            if (! is_string($accessToken->token) || $accessToken->token === '') {
                $accessToken->token = self::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }
}
