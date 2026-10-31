<?php

namespace App\Support;

use Illuminate\Support\Str;

class VisitorId
{
    public static function fromHeader(?string $value): ?string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return Str::lower($value);
    }
}
