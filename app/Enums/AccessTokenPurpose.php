<?php

namespace App\Enums;

enum AccessTokenPurpose: string
{
    case CashierQr = 'cashier_qr';
    case SharedRegistration = 'shared_registration';
}
