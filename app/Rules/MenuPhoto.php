<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class MenuPhoto implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The photo must be a JPEG, PNG, or WebP file.');

            return;
        }

        if ($value->getSize() !== false && $value->getSize() > 1024 * 1024) {
            $fail('The photo must not be greater than 1024 kilobytes.');

            return;
        }

        if (! in_array((string) $value->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $fail('The photo must be a JPEG, PNG, or WebP file.');
        }
    }
}
