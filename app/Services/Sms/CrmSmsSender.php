<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * همان مسیر `Crm::sendSmsSync()` پروژهٔ مرجع: پیامک الگو‌محور از طریق سرویس CRM
 * (`/api/crm/v1/company/auth/send-sms` با `fk_smspattern` و `paramsArray`).
 * شمارهٔ الگو از `config('yekari.sms.patterns')` می‌آید.
 */
class CrmSmsSender implements SmsSender
{
    public function sendPattern(string $mobile, string $pattern, array $params): array
    {
        $fkPattern = config("yekari.sms.patterns.$pattern");
        $payload = ['mobile' => $mobile, 'fk_smspattern' => $fkPattern, 'paramsArray' => $params ?: ['' => '']];
        if ($registrar = config('yekari.sms.registrar')) {
            $payload['fk_registrar'] = (int) $registrar;
        }
        $url = rtrim((string) config('yekari.sms.crm_url'), '/').'/api/crm/v1/company/auth/send-sms';

        try {
            $response = Http::timeout(10)->post($url, $payload);
            if ($response->successful()) {
                Log::channel('sms')->info('SMS sent', ['mobile' => $mobile, 'pattern' => $pattern]);

                return ['success' => true, 'message' => $response->json('message') ?? 'پیامک ارسال شد'];
            }
            Log::channel('sms')->error('SMS send failed', ['mobile' => $mobile, 'status' => $response->status(), 'body' => $response->body()]);

            return ['success' => false, 'message' => $response->json('message') ?? 'ارسال پیامک ناموفق بود'];
        } catch (\Throwable $e) {
            Log::channel('sms')->error('SMS send exception', ['mobile' => $mobile, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'ارسال پیامک ناموفق بود'];
        }
    }
}
