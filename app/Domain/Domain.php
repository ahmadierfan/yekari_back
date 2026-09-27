<?php

namespace App\Domain;

/**
 * نسخهٔ سرور از `design-system/app/utils/domain.ts`.
 * برچسب‌ها و رنگ‌ها مال فرانت‌اند و اینجا تکرار نمی‌شوند؛ اینجا فقط کلیدها و قواعد.
 */
final class Domain
{
    public const ORDER_STATUSES = [
        'draft', 'searching', 'accepted', 'to_pickup', 'in_progress',
        'to_dropoff', 'completed', 'cancelled', 'expired', 'disputed',
    ];

    public const LIVE = ['searching', 'accepted', 'to_pickup', 'in_progress', 'to_dropoff'];

    /** مرحله‌های کار پیک به ترتیب. `completed` عمداً نیست — نتیجهٔ آخرین مرحله است. */
    public const COURIER_STEPS = ['to_pickup', 'in_progress', 'to_dropoff'];

    public const TX_SIGN = [
        'topup' => 1, 'withdraw' => -1, 'spend' => -1, 'tip' => -1, 'refund' => 1, 'hold' => 0,
        'release' => 0, 'payout' => -1, 'commission' => -1, 'bonus' => 1, 'penalty' => -1,
        'earning' => 1,
    ];

    public const PAY_METHODS = ['wallet', 'gateway', 'corporate'];

    public const COURIER_DOCS = ['id', 'license', 'vehicle', 'selfie', 'no_addiction'];

    public const TICKET_TOPICS = ['order', 'payment', 'courier', 'damage', 'account', 'other'];

    public const COURIER_ISSUES = ['no_answer', 'closed', 'out_of_stock', 'over_budget', 'address', 'other'];

    public const LOG_KINDS = ['login', 'create', 'update', 'delete', 'money', 'block'];

    public static function isLive(string $status): bool
    {
        return in_array($status, self::LIVE, true);
    }

    /** بانک صادرکننده از شش رقم اول کارت (BIN) */
    public const BANK_BINS = [
        '603799' => 'بانک ملی', '610433' => 'بانک ملت', '991975' => 'بانک ملت',
        '627353' => 'بانک تجارت', '585983' => 'بانک تجارت',
        '621986' => 'بانک سامان', '589210' => 'بانک سپه', '627961' => 'بانک صنعت و معدن',
        '603770' => 'بانک کشاورزی', '639217' => 'بانک کشاورزی',
        '628023' => 'بانک مسکن', '627760' => 'پست بانک', '502908' => 'بانک توسعه تعاون',
        '627412' => 'بانک اقتصاد نوین', '622106' => 'بانک پارسیان', '639194' => 'بانک پارسیان',
        '502229' => 'بانک پاسارگاد', '639347' => 'بانک پاسارگاد',
        '502806' => 'بانک شهر', '504706' => 'بانک شهر',
        '502938' => 'بانک دی', '636214' => 'بانک آینده', '636949' => 'بانک حکمت ایرانیان',
        '639599' => 'بانک قوامین', '639607' => 'بانک سرمایه', '627488' => 'بانک کارآفرین',
        '502910' => 'بانک کارآفرین', '636795' => 'بانک مرکزی', '505785' => 'بانک ایران زمین',
        '505416' => 'بانک گردشگری', '606373' => 'بانک قرض‌الحسنه مهر',
    ];

    public static function bankOf(string $pan): string
    {
        return self::BANK_BINS[substr($pan, 0, 6)] ?? 'بانک نامشخص';
    }

    /** اعتبارسنجی لان — همان `isValidPan` فرانت */
    public static function isValidPan(string $pan): bool
    {
        $d = preg_replace('/\D/', '', $pan);
        if (! preg_match('/^\d{16}$/', $d)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 16; $i++) {
            $n = (int) $d[$i];
            if ($i % 2 === 0) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
        }

        return $sum % 10 === 0;
    }

    /** ۰۹۱۲… | 98912… | 912… | ارقام فارسی → 09123456789 */
    public static function normalizeMobile(?string $raw): string
    {
        $raw = strtr((string) $raw, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $d = preg_replace('/\D/', '', $raw);
        $d = preg_replace('/^0098/', '0', $d);
        $d = preg_replace('/^98(?=9\d{9}$)/', '0', $d);
        $d = preg_replace('/^9(?=\d{9}$)/', '09', $d);

        return $d;
    }

    public static function isValidMobile(string $mobile): bool
    {
        return (bool) preg_match('/^09\d{9}$/', $mobile);
    }

    /** فاصلهٔ خط مستقیم (کیلومتر) */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
