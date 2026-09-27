<?php

namespace App\Services;

use App\Models\AppNotification;

/**
 * اعلان داخل‌اپی. پوش (FCM) و سوکت بعداً همین‌جا اضافه می‌شوند تا صدازننده‌ها عوض نشوند.
 */
class Notifier
{
    public static function send(int $userId, string $app, string $title, ?string $body = null, string $icon = 'bell', string $tone = 'info', ?string $to = null): void
    {
        AppNotification::create(compact('title', 'body', 'icon', 'tone', 'to', 'app') + ['user_id' => $userId]);
    }
}
