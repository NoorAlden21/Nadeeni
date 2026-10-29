<?php

namespace App\Enums;

enum PreferredQrMode: string
{
    case Shared = 'shared';
    case PerOrder = 'per_order';
}
