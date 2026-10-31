<?php

namespace App\Support;

class FrontendUrl
{
    public static function order(string $token): string
    {
        return self::base().'/o/'.$token;
    }

    public static function restaurant(string $slug): string
    {
        return self::base().'/r/'.$slug;
    }

    private static function base(): string
    {
        return rtrim((string) config('nadeeni.frontend_url'), '/');
    }
}
