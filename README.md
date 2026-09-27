# یکاری — API

بک‌اند مشترک هر چهار اپ یکاری (`customer`, `courier`, `admin`, `corporate`). Laravel 13، MySQL،
توکن Sanctum، نقش/دسترسی با `spatie/laravel-permission`.

## اجرا

```bash
composer install
cp .env.example .env && php artisan key:generate
# دیتابیس MySQL به نام yekari بساز، بعد:
php artisan migrate --seed
php artisan storage:link
php artisan serve --port=8000        # API روی http://localhost:8000/api/v1
php artisan schedule:work            # تخصیص پیک، انقضای سفارش، فاکتور ماهانه
```

حساب‌های نمایشی (`DemoSeeder`، فقط خارج از production) — ورود با کد **۱۲۳۴۵** یا رمز `password123`:

| نقش | موبایل |
|---|---|
| مدیر ارشد | 09121110000 |
| اپراتور عملیات | 09122220000 |
| کارشناس مالی | 09123330000 |
| پشتیبانی | 09124440000 |
| مشتری (عضو سازمان «کلینیک دی») | 09121234567 |
| مدیر سازمان «کلینیک دی» | 09123456789 |
| پیک (مدارک تأییدشده) | 09351112233 |

تست: `php artisan test` (SQLite در حافظه؛ ورود، چرخهٔ کامل مأموریت و پول، دسترسی نقش‌ها، سازمانی، فاکتور).

## ورود و توکن

دو روش، برای هر چهار اپ: **OTP پیامکی** (`/auth/otp/request` → `/auth/otp/verify`) و **رمز** با موبایل یا ایمیل
(`/auth/login`). بازیابی رمز با OTP (`/auth/password/forgot` → `/reset`).

هر درخواست ورود یک `app` دارد و توکن فقط برای همان اپ معتبر است (ability `app:<name>`، میان‌افزار `EnsureApp`):

| app | چه کسی وارد می‌شود | ثبت‌نام آزاد |
|---|---|---|
| `customer` | هر کسی؛ نقش `customer` خودکار داده می‌شود | ✓ |
| `courier` | هر کسی؛ نقش `courier` + پروفایل «در انتظار تأیید مدارک» | ✓ |
| `admin` | فقط کارمند با یکی از نقش‌های پنل | ✗ |
| `corporate` | فقط مدیر یک حساب سازمانی فعال | ✗ |

## نقش‌ها و دسترسی‌ها

- **نقش‌های سراسری:** `customer`, `courier`, و چهار نقش پنل: `super-admin`, `ops`, `finance`, `support`.
- **دسترسی‌های پنل** ریزدانه‌اند (`orders.view`, `orders.manage`, `finance.payouts`, …) ولی در همان هشت گروه صفحهٔ
  «نقش‌ها» (`app/Domain/Permissions.php`) دسته‌بندی شده‌اند. هر مسیر `/admin/*` پشت `permission:*` است و
  ماتریس از پنل قابل ویرایش است (`PUT /admin/roles/{role}`). `super-admin` با `Gate::before` همه‌چیز دارد و
  فقط خودش می‌تواند `super-admin` بسازد.
- **نقش داخل سازمان** (`manager`/`member`) روی `organization_members` است، نه نقش سراسری؛ `OrganizationPolicy`.
- **مالکیت داده** با Policy: مشتری فقط سفارش خودش، پیک فقط مأموریت خودش، چت فقط بین دو طرف.
- هر اکشن پنل که چیزی را عوض می‌کند در `activity_logs` ثبت می‌شود (`App\Services\Audit`).

## ساختار

| مسیر | کار |
|---|---|
| `app/Domain/` | نسخهٔ سرور `domain.ts`: وضعیت‌ها، مراحل پیک، BIN بانک، لان، ماه شمسی، دسترسی‌ها |
| `app/Services/OrderService.php` | چرخهٔ عمر مأموریت: ثبت، پرداخت امن، پذیرش، نگهبان فاکتور و مدرک تحویل، اضافه‌کاری، سهم پیک، لغو، شکایت |
| `app/Services/WalletService.php` | تنها نویسندهٔ موجودی؛ قفل ردیف + دفتر تراکنش با موجودی بعد از هر حرکت |
| `app/Services/DispatchService.php` | پیشنهاد به نزدیک‌ترین پیک آنلاین آزاد، یکی‌یکی با پنجرهٔ ۲۰ ثانیه |
| `app/Services/PricingService.php` | برآورد تفکیک‌شده (خدمات + مسافت + انتظار − تخفیف)، کد تخفیف، تخفیف قراردادی |
| `app/Services/Sms/` | `SmsSender` — درایور `asanak`: ارسال مستقیم به وب‌سرویس آسانک، مثل پروژهٔ مرجع (متن الگوها در `config/yekari.php`) |
| `app/Services/Payment/` | `PaymentGateway` — زیبال، زرین‌پال (v4 با verify)، و `fake` برای توسعه |
| `app/Http/Resources/` | شکل JSON دقیقاً مطابق تایپ‌های `mock.ts` اپ‌ها (camelCase) |

پول همه‌جا **تومان و عدد صحیح** است؛ فقط لحظهٔ صحبت با درگاه به ریال تبدیل می‌شود. «ماه» (سقف سازمان، فاکتور)
ماه شمسی است.

## قالب پاسخ

موفق: منبع در `data` (فهرست‌ها صفحه‌بندی Laravel). خطا — همان قالب پروژهٔ مرجع:

```json
{ "status": "error", "message": "موجودی کیف پول کافی نیست", "code": 422, "issues": { "balance": ["…"] } }
```

## هنوز نیست (عمداً یا منتظر تصمیم)

- **ارتباط زنده**: پیشنهاد، وضعیت و چت با poll کار می‌کنند (`GET /courier/current`, `?after=` در چت). جای
  رویدادهای Reverb/سوکت در `Notifier` و `OrderService` آماده است.
- **نقشه و مسیریابی واقعی**: فاصله = خط مستقیم × ۱٫۳ (`road_factor`).
- **پرداخت رمزارز**: عمداً پشتیبانی نمی‌شود (ریسک حقوقی).
- **شمارهٔ واسط تماس، پوش (FCM)، ذخیرهٔ فایل ابری**: فعلاً دیسک `public`/`local`.
