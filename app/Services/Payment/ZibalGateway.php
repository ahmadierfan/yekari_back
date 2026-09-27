<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;

/** الگوی `Zibal.php` پروژهٔ مرجع (request → start/{trackId} → verify با کد ۱۰۰/۲۰۱). */
class ZibalGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'zibal';
    }

    public function request(int $amountToman, string $orderRef, string $callbackUrl): array
    {
        $result = Http::acceptJson()->post('https://gateway.zibal.ir/v1/request', [
            'merchant' => config('yekari.payment.zibal_merchant'),
            'amount' => $amountToman * 10,
            'callbackUrl' => $callbackUrl,
            'orderId' => $orderRef,
        ])->json();

        if (($result['result'] ?? null) == 100) {
            return ['ok' => true, 'authority' => (string) $result['trackId'], 'link' => "https://gateway.zibal.ir/start/{$result['trackId']}"];
        }

        return ['ok' => false, 'error' => $result['message'] ?? 'خطا در ارتباط با زیبال'];
    }

    public function verify(array $callbackQuery, int $amountToman): array
    {
        $trackId = $callbackQuery['trackId'] ?? null;
        if (! $trackId) {
            return ['authority' => null, 'ok' => false];
        }
        $result = Http::acceptJson()->post('https://gateway.zibal.ir/v1/verify', [
            'merchant' => config('yekari.payment.zibal_merchant'),
            'trackId' => $trackId,
        ])->json();

        // ۱۰۰ = تأیید شد، ۲۰۱ = قبلاً تأیید شده (callback تکراری) — هر دو موفق‌اند
        $ok = in_array($result['result'] ?? null, [100, 201]);

        return ['authority' => (string) $trackId, 'ok' => $ok, 'ref_id' => isset($result['refNumber']) ? (string) $result['refNumber'] : null];
    }
}
