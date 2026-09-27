<?php

use App\Services\DispatchService;
use Illuminate\Support\Facades\Schedule;

// پیشنهادهای منقضی → پیک بعدی؛ سفارش‌های بی‌پیشنهاد → تلاش دوباره
Schedule::call(fn () => app(DispatchService::class)->sweep())->name('dispatch:sweep')->everyTenSeconds()->withoutOverlapping();

// سفارشی که ۴۵ دقیقه در جست‌وجو ماند منقضی می‌شود و وجهش آزاد
Schedule::command('orders:expire')->everyMinute();

// فاکتور ماهانهٔ سازمان‌های پس‌پرداخت — روز اول هر ماه برای ماه قبل
Schedule::command('invoices:issue')->monthlyOn(1, '02:00');
