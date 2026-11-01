<?php

namespace App\Enums;

enum PlanInterval: string
{
    case Month = 'month';
    case Year = 'year';
    case Trial = 'trial';
}
