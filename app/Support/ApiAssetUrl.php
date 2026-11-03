<?php

namespace App\Support;

class ApiAssetUrl
{
    public static function make(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $configured = rtrim((string) config('app.url'), '/');
        $configuredHost = parse_url($configured, PHP_URL_HOST);

        // url() follows X-Forwarded-Host. The admin app proxies through Vercel,
        // so that host is the Next.js app and the image request never reaches this API.
        if (is_string($configuredHost) && ! in_array($configuredHost, ['localhost', '127.0.0.1'], true)) {
            return $configured.$path;
        }

        $host = request()->headers->get('Host');
        if (is_string($host) && $host !== '') {
            return request()->getScheme().'://'.$host.$path;
        }

        return url($path);
    }
}
