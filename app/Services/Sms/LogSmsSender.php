<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** محیط توسعه: پیامک فقط در لاگ نوشته می‌شود. */
class LogSmsSender implements SmsSender
{
    public function sendPattern(string $mobile, string $pattern, array $params): array
    {
        Log::channel('sms')->info('SMS (log driver)', compact('mobile', 'pattern', 'params'));

        return ['success' => true, 'message' => 'پیامک ارسال شد'];
    }
}
