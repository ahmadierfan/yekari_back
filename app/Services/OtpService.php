<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;

/**
 * کد یک‌بارمصرف — الگوی `VerificationController` پروژهٔ مرجع (کد تصادفی، انقضا، فاصلهٔ
 * ارسال دوباره، علامت استفاده‌شده) + هش‌کردن کد و سقف تلاش غلط.
 */
class OtpService
{
    public function __construct(private SmsSender $sms) {}

    /** @return int ثانیه تا اجازهٔ ارسال دوباره */
    public function send(string $mobile, string $purpose = 'login', ?int $userId = null, ?string $ip = null): int
    {
        $wait = (int) config('yekari.otp.resend_seconds');
        $last = OtpCode::where('mobile', $mobile)->where('purpose', $purpose)->latest('id')->first();
        if ($last && $last->created_at->diffInSeconds(now()) < $wait) {
            throw new ApiException('کد قبلی هنوز معتبر است؛ کمی بعد دوباره تلاش کن', 429, [
                'resend_in' => [$wait - (int) $last->created_at->diffInSeconds(now())],
            ]);
        }

        $len = (int) config('yekari.otp.length');
        $code = (string) random_int(10 ** ($len - 1), 10 ** $len - 1);

        $result = $this->sms->sendPattern($mobile, 'otp', ['verificationcode' => $code]);
        if (! $result['success']) {
            throw new ApiException($result['message'] ?: 'ارسال پیامک ناموفق بود', 502);
        }

        OtpCode::create([
            'mobile' => $mobile, 'purpose' => $purpose, 'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('yekari.otp.ttl_minutes')), 'ip' => $ip, 'user_id' => $userId,
        ]);

        return $wait;
    }

    public function verify(string $mobile, string $code, string $purpose = 'login', ?int $userId = null): bool
    {
        $code = preg_replace('/\D/', '', strtr($code, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));

        // کد نمایشی فقط خارج از production — برای مرور طراحی بدون پیامک واقعی
        $demo = config('yekari.otp.demo_code');
        if ($demo && ! app()->isProduction() && $code === (string) $demo) {
            return true;
        }

        $otp = OtpCode::where('mobile', $mobile)->where('purpose', $purpose)
            ->whereNull('used_at')->where('expires_at', '>=', now())
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->latest('id')->first();
        if (! $otp) {
            return false;
        }
        if ($otp->attempts >= (int) config('yekari.otp.max_attempts')) {
            throw new ApiException('تعداد تلاش بیش از حد مجاز است؛ کد تازه بگیر', 429);
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }
        $otp->update(['used_at' => now()]);

        return true;
    }
}
