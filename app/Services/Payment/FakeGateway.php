<?php

namespace App\Services\Payment;

use Illuminate\Support\Str;

/**
 * درگاه آزمایشی برای توسعه و تست: لینک مستقیم به callback خود API برمی‌گرداند.
 * در production هرگز فعال نکن (PAYMENT_GATEWAY=zibal|zarinpal).
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function request(int $amountToman, string $orderRef, string $callbackUrl): array
    {
        $authority = 'FAKE-'.Str::upper(Str::random(12));

        return ['ok' => true, 'authority' => $authority, 'link' => $callbackUrl.'?authority='.$authority.'&status=OK'];
    }

    public function verify(array $callbackQuery, int $amountToman): array
    {
        return ['authority' => $callbackQuery['authority'] ?? null, 'ok' => ($callbackQuery['status'] ?? '') === 'OK', 'ref_id' => 'FAKE'];
    }
}
