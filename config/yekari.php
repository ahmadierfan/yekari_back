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
        // log (فقط لاگ، توسعه) | asanak (وب‌سرویس مستقیم آسانک، مثل پروژهٔ مرجع)
        'driver' => env('SMS_DRIVER', 'log'),
        'asanak' => [
            'url' => env('ASANAK_SMS_URL', 'https://panel.asanak.com/webservice/v1rest/sendsms'),
            'username' => env('ASANAK_SMS_USERNAME'),
            'password' => env('ASANAK_SMS_PASSWORD'),
            'source' => env('ASANAK_SMS_SOURCE'),
            // عبارت لغو انتهای پیامک (مرجع: «لغو۱۱»)؛ خالی = بدون عبارت
            'suffix' => env('ASANAK_SMS_SUFFIX', 'لغو۱۱'),
        ],
        // متن هر الگو؛ #کلید# با پارامترها پر می‌شود (همان parseMessage مرجع)
        'templates' => [
            'otp' => "کد ورود به یکاری\n#verificationcode#",
            'password_reset' => "کاربر گرامی\nجهت تنظیم رمز جدید یکاری از کد زیر استفاده نمایید\n#verificationcode#",
        ],
    ],

    'payment' => [
        // zibal | zarinpal | fake
        'gateway' => env('PAYMENT_GATEWAY', 'zibal'),
        'zibal_merchant' => env('ZIBAL_MERCHANT'),
        'zarinpal_merchant' => env('ZARINPAL_MERCHANT'),
        /** اپ‌ها بعد از بازگشت از درگاه به این آدرس‌ها برمی‌گردند (?status=ok|cancel&tx=) */
        'return_urls' => [
            'customer' => env('CUSTOMER_APP_URL', 'http://localhost:3500').'/app/wallet',
            'order' => env('CUSTOMER_APP_URL', 'http://localhost:3500').'/app/new/success',
            'corporate' => env('CORPORATE_APP_URL', 'http://localhost:3503').'/billing',
        ],
    ],

    /** مالیات بر ارزش افزوده روی فاکتور سازمانی */
    'vat_percent' => (int) env('VAT_PERCENT', 10),

    /** مسافت جاده‌ای ≈ مسافت خط مستقیم × این ضریب — فقط وقتی مسیریابی map.ir در دسترس نیست */
    'road_factor' => 1.3,

    'maps' => [
        'mapir' => [
            // کلید از پنل map.ir (corp.map.ir). بدون آن جست‌وجو/آدرس غیرفعال و مسافت برآوردی است
            'key' => env('MAPIR_API_KEY'),
            'url' => env('MAPIR_URL', 'https://map.ir'),
            'timeout' => (int) env('MAPIR_TIMEOUT', 5),
        ],
    ],

    'offer_window_seconds' => 20,
    'dispatch_radius_km' => 8,
];
