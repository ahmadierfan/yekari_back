<?php

return [
    /*
     * اپ‌هایی که توکن می‌گیرند. هر توکن فقط برای همان اپ معتبر است (ability = app:<name>)؛
     * `roles` یعنی کاربر برای ورود به آن اپ باید حداقل یکی از این نقش‌ها را داشته باشد.
     * `self_signup` یعنی ورود با OTP برای شمارهٔ ناشناس، حساب تازه می‌سازد.
     */
    'apps' => [
        'customer' => ['roles' => ['customer'], 'self_signup' => true, 'grant_role' => 'customer'],
        'courier' => ['roles' => ['courier'], 'self_signup' => true, 'grant_role' => 'courier'],
        'admin' => ['roles' => ['super-admin', 'ops', 'finance', 'support'], 'self_signup' => false],
        'corporate' => ['roles' => [], 'self_signup' => false, 'org_manager' => true],
    ],

    'otp' => [
        'length' => (int) env('OTP_LENGTH', 5),
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 2),
        'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 90),
        'max_attempts' => 5,
        /** فقط محیط local/testing: کد ثابت برای مرور طراحی (همان ۱۲۳۴۵ اپ‌ها) */
        'demo_code' => env('OTP_DEMO_CODE'),
    ],

    'sms' => [
        // log | crm — crm همان سرویس پیامک الگو‌محور پروژهٔ مرجع است
        'driver' => env('SMS_DRIVER', 'log'),
        'crm_url' => env('CRM_SERVICE_URL'),
        'registrar' => env('SMS_REGISTRAR_ID'),
        'patterns' => [
            'otp' => (int) env('SMS_PATTERN_OTP', 14),
        ],
    ],

    'payment' => [
        // zibal | zarinpal | fake
        'gateway' => env('PAYMENT_GATEWAY', 'fake'),
        'zibal_merchant' => env('ZIBAL_MERCHANT'),
        'zarinpal_merchant' => env('ZARINPAL_MERCHANT'),
        /** اپ‌ها بعد از بازگشت از درگاه به این آدرس‌ها برمی‌گردند (?status=ok|cancel&tx=) */
        'return_urls' => [
            'customer' => env('CUSTOMER_APP_URL', 'http://localhost:3500').'/app/wallet',
            'corporate' => env('CORPORATE_APP_URL', 'http://localhost:3503').'/billing',
        ],
    ],

    /** مالیات بر ارزش افزوده روی فاکتور سازمانی */
    'vat_percent' => (int) env('VAT_PERCENT', 10),

    /** مسافت جاده‌ای ≈ مسافت خط مستقیم × این ضریب، تا وقتی سرویس مسیریابی وصل نشده */
    'road_factor' => 1.3,

    'offer_window_seconds' => 20,
    'dispatch_radius_km' => 8,
];
