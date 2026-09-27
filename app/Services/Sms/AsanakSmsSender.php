<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ارسال مستقیم به وب‌سرویس آسانک — همان `SSmsmessageController::sender()` پروژهٔ مرجع:
 * متن الگو (`config('yekari.sms.templates')`) با `#کلید#` پر می‌شود، عبارت لغو
 * به انتها اضافه می‌شود و با username/password/Source فرم POST می‌شود.
 * آسانک در موفقیت آرایه‌ای از شناسهٔ پیام (عدد مثبت) برمی‌گرداند.
 */
class AsanakSmsSender implements SmsSender
{
    public function sendPattern(string $mobile, string $pattern, array $params): array
    {
        $template = config("yekari.sms.templates.$pattern");
        if (! $template) {
            Log::channel('sms')->error('SMS template missing', ['pattern' => $pattern]);

            return ['success' => false, 'message' => 'ارسال پیامک ناموفق بود'];
        }

        $message = preg_replace_callback('/#(.*?)#/', fn ($m) => (string) ($params[$m[1]] ?? $m[0]), $template);
        if ($suffix = config('yekari.sms.asanak.suffix')) {
            $message .= "\n".$suffix;
        }

        $cfg = config('yekari.sms.asanak');
        try {
            $response = Http::asForm()->timeout(10)->post($cfg['url'], [
                'username' => $cfg['username'],
                'password' => $cfg['password'],
                'Source' => $cfg['source'],
                'Message' => $message,
                'destination' => $mobile,
            ]);
            $id = $response->json()[0] ?? null;
            if ($response->successful() && (int) $id > 0) {
                Log::channel('sms')->info('SMS sent', ['mobile' => $mobile, 'pattern' => $pattern, 'id' => $id]);

                return ['success' => true, 'message' => 'پیامک ارسال شد'];
            }
            Log::channel('sms')->error('SMS send failed', ['mobile' => $mobile, 'status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::channel('sms')->error('SMS send exception', ['mobile' => $mobile, 'error' => $e->getMessage()]);
        }

        return ['success' => false, 'message' => 'ارسال پیامک ناموفق بود'];
    }
}
