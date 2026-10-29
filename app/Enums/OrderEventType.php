<?php

namespace App\Enums;

enum OrderEventType: string
{
    case Created = 'created';
    case CustomerLinked = 'customer_linked';
    case NotificationSent = 'notification_sent';
    case NotificationFailed = 'notification_failed';
    case NotificationResent = 'notification_resent';
    case Collected = 'collected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
