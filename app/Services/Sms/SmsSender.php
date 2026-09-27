<?php

namespace App\Services\Sms;

/** ارسال پیامک الگو‌محور. درایور با SMS_DRIVER انتخاب می‌شود (log | asanak). */
interface SmsSender
{
    /**
     * @param  array<string, string|int>  $params  متغیرهای الگو، مثلاً ['verificationcode' => 12345]
     * @return array{success: bool, message: string}
     */
    public function sendPattern(string $mobile, string $pattern, array $params): array;
}
