<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Waiting = 'waiting';
    case Ready = 'ready';
    case Collected = 'collected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Uncollected = 'uncollected';

    public function isActive(): bool
    {
        return $this === self::Waiting || $this === self::Ready;
    }
}
