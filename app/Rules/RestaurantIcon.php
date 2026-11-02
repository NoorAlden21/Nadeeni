<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class RestaurantIcon implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The icon must be a JPEG, PNG, WebP, or SVG file.');

            return;
        }

        if ($value->getSize() !== false && $value->getSize() > 1024 * 1024) {
            $fail('The icon must not be greater than 1024 kilobytes.');

            return;
        }

        $mime = (string) $value->getMimeType();

        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml', 'image/svg'], true)) {
            return;
        }

        if (strtolower($value->getClientOriginalExtension()) === 'svg' && self::looksLikeSvg($value)) {
            return;
        }

        $fail('The icon must be a JPEG, PNG, WebP, or SVG file.');
    }

    public static function looksLikeSvg(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        $head = is_string($path) ? file_get_contents($path, false, null, 0, 512) : false;

        return is_string($head) && preg_match('/<svg[\s>]/i', $head) === 1;
    }
}
