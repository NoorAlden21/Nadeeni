<?php

namespace App\Models;

use Database\Factories\OrderReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReview extends Model
{
    /** @use HasFactory<OrderReviewFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'service_rating',
        'food_rating',
        'message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_rating' => 'integer',
            'food_rating' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'service_rating' => $this->service_rating,
            'food_rating' => $this->food_rating,
            'message' => $this->message,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
