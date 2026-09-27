<?php

namespace App\Services;

use App\Domain\Domain;
use App\Models\CourierProfile;
use App\Models\Order;
use App\Models\OrderOffer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تخصیص پیک: پیشنهاد به نزدیک‌ترین پیکِ آنلاینِ آزاد، یکی‌یکی، با پنجرهٔ پذیرش.
 * رد یا انقضا → پیک بعدی. نسخهٔ اول ساده و قابل‌فهم است؛ امتیاز/نرخ پذیرش/بلوک کاری
 * بعداً فقط در `candidates()` وزن می‌گیرند.
 */
class DispatchService
{
    public function dispatch(Order $order): ?OrderOffer
    {
        if ($order->status !== 'searching' || $order->courier_id) {
            return null;
        }
        if ($order->offers()->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
            return null;
        }
        // سفارش زمان‌بندی‌شده تا ۱۵ دقیقه قبل از شروع بازه پیشنهاد نمی‌شود
        if ($order->scheduled_from && $order->scheduled_from->gt(now()->addMinutes(15))) {
            return null;
        }

        $candidate = $this->candidates($order)->first();
        if (! $candidate) {
            return null;
        }

        return DB::transaction(function () use ($order, $candidate) {
            $offer = $order->offers()->create([
                'courier_id' => $candidate['profile']->user_id,
                'payout' => PricingService::courierShare($order->serviceTotal(), $order->commission_rate),
                'to_pickup_km' => round($candidate['km'], 2),
                'expires_at' => now()->addSeconds((int) config('yekari.offer_window_seconds')),
            ]);
            $candidate['profile']->increment('offers_count');

            return $offer;
        });
    }

    /** @return Collection<int, array{profile: CourierProfile, km: float}> */
    public function candidates(Order $order)
    {
        $offered = $order->offers()->pluck('courier_id');
        $busy = Order::whereIn('status', Domain::LIVE)->whereNotNull('courier_id')->pluck('courier_id');
        $pendingElsewhere = OrderOffer::where('status', 'pending')->where('expires_at', '>', now())->pluck('courier_id');
        $radius = (float) config('yekari.dispatch_radius_km');

        return CourierProfile::query()
            ->where('state', 'active')->where('online', true)
            ->whereNotNull('lat')
            ->whereNotIn('user_id', $offered->merge($busy)->merge($pendingElsewhere)->merge([$order->customer_id])->unique())
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (CourierProfile $p) => ['profile' => $p, 'km' => Domain::haversine($p->lat, $p->lng, $order->pickup['lat'], $order->pickup['lng'])])
            ->filter(fn ($c) => $c['km'] <= $radius)
            ->sortBy('km')
            ->values();
    }

    /** پیشنهادهای منقضی‌شده را می‌بندد و سفارششان را به پیک بعدی می‌دهد */
    public function sweep(): void
    {
        OrderOffer::where('status', 'pending')->where('expires_at', '<=', now())
            ->get()
            ->each(function (OrderOffer $offer) {
                $offer->update(['status' => 'expired']);
                $this->dispatch($offer->order);
            });

        Order::where('status', 'searching')->whereNull('courier_id')
            ->whereDoesntHave('offers', fn ($q) => $q->where('status', 'pending'))
            ->get()
            ->each(fn (Order $o) => $this->dispatch($o));
    }
}
