<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Notifier;
use App\Services\OrderService;
use Illuminate\Console\Command;

/** سفارشی که پیکی پیدا نکرد نباید پول مشتری را تا ابد بلوکه نگه دارد */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire {--minutes=45}';

    protected $description = 'منقضی‌کردن سفارش‌های بی‌پیک و آزادسازی وجه';

    public function handle(OrderService $orders): int
    {
        $limit = now()->subMinutes((int) $this->option('minutes'));
        Order::where('status', 'searching')->whereNull('courier_id')
            ->where(fn ($q) => $q->whereNull('scheduled_to')->where('created_at', '<', $limit)->orWhere('scheduled_to', '<', now()))
            ->each(function (Order $o) use ($orders) {
                $orders->expireSearching($o, 'انجام‌دهنده‌ای پیدا نشد');
                Notifier::send($o->customer_id, 'customer', "مأموریت {$o->code} منقضی شد", 'وجه بلوکه‌شده آزاد شد.', 'clock-x', 'neutral', "/app/orders/{$o->id}");
            });
        // پیش‌نویس‌هایی که پرداختشان هیچ‌وقت کامل نشد
        Order::where('status', 'draft')->where('created_at', '<', now()->subDay())
            ->each(fn (Order $o) => $orders->expireDraft($o));

        return self::SUCCESS;
    }
}
