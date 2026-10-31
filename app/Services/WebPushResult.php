<?php

namespace App\Services;

class WebPushResult
{
    public function __construct(
        public bool $success,
        public ?int $statusCode = null,
    ) {}
}
