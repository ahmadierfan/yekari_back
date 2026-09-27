<?php

namespace App\Services\Payment;

/** درگاه پرداخت. مبلغ ورودی تومان است؛ تبدیل به ریال داخل خود درایور. */
interface PaymentGateway
{
    public function name(): string;

    /**
     * @return array{ok: bool, link?: string, authority?: string, error?: string}
     */
    public function request(int $amountToman, string $orderRef, string $callbackUrl): array;

    /**
     * authority/trackId را از کوئری callback بیرون می‌کشد و تراکنش را تأیید می‌کند.
     *
     * @return array{authority: ?string, ok: bool, ref_id?: string}
     */
    public function verify(array $callbackQuery, int $amountToman): array;
}
