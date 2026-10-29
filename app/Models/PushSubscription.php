<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'order_access_token_id',
        'endpoint',
        'p256dh',
        'auth',
        'content_encoding',
        'expires_at',
        'last_success_at',
        'last_error_at',
        'failure_count',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'endpoint',
        'p256dh',
        'auth',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(OrderAccessToken::class, 'order_access_token_id');
    }
}
