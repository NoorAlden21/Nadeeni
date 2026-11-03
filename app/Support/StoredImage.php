<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class StoredImage
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function encode(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;
        $mime = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        if ($contents === false || ! is_string($mime)) {
            throw new RuntimeException('The image could not be read.');
        }

        if ($extension === 'svg' || $mime === 'image/svg' || $mime === 'image/svg+xml') {
            $mime = 'image/svg+xml';
        }

        return [base64_encode($contents), $mime];
    }

    public static function decode(?string $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = base64_decode($stored, true);

        return is_string($decoded) ? $decoded : null;
    }
}
