<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;

/** الگوی `Zarinpal.php` پروژهٔ مرجع (v4) + مرحلهٔ verify که آن‌جا جا مانده بود. */
class ZarinpalGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'zarinpal';
    }

    public function request(int $amountToman, string $orderRef, string $callbackUrl): array
    {
        $result = Http::acceptJson()->post('https://payment.zarinpal.com/pg/v4/payment/request.json', [
            'merchant_id' => config('yekari.payment.zarinpal_merchant'),
            'amount' => $amountToman * 10,
            'callback_url' => $callbackUrl,
            'description' => $orderRef,
        ])->json();

        if (($result['data']['code'] ?? null) == 100) {
            $authority = $result['data']['authority'];

            return ['ok' => true, 'authority' => $authority, 'link' => "https://payment.zarinpal.com/pg/StartPay/{$authority}"];
        }

        return ['ok' => false, 'error' => 'خطا در ارتباط با زرین‌پال'];
    }

    public function verify(array $callbackQuery, int $amountToman): array
    {
        $authority = $callbackQuery['Authority'] ?? null;
        if (! $authority || ($callbackQuery['Status'] ?? '') !== 'OK') {
            return ['authority' => $authority, 'ok' => false];
        }
        $result = Http::acceptJson()->post('https://payment.zarinpal.com/pg/v4/payment/verify.json', [
            'merchant_id' => config('yekari.payment.zarinpal_merchant'),
            'amount' => $amountToman * 10,
            'authority' => $authority,
        ])->json();
        // ۱۰۰ موفق، ۱۰۱ قبلاً تأیید شده
        $code = $result['data']['code'] ?? null;

        return ['authority' => $authority, 'ok' => in_array($code, [100, 101]), 'ref_id' => isset($result['data']['ref_id']) ? (string) $result['data']['ref_id'] : null];
    }
}
