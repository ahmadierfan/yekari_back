<?php

namespace App\Services;

use App\Domain\Period;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use Carbon\CarbonInterface as Carbon;

/** فاکتور ماهانهٔ (شمسی) سازمان پس‌پرداخت: مجموع مأموریت‌های تکمیل‌شدهٔ آن ماه + ارزش افزوده */
class InvoiceService
{
    public function ordersFor(Organization $org, Carbon $start, Carbon $end)
    {
        return Order::where('organization_id', $org->id)->where('status', 'completed')
            ->whereBetween('finished_at', [$start, $end]);
    }

    public function issue(Organization $org, Carbon $inMonth): ?OrganizationInvoice
    {
        [$start, $end] = Period::jalaliMonth($inMonth);
        if ($org->invoices()->whereDate('period_start', $start->toDateString())->exists()) {
            return null;
        }
        $orders = $this->ordersFor($org, $start, $end)->get();
        if ($orders->isEmpty()) {
            return null;
        }
        $discount = (int) $orders->sum('price_discount');
        $subtotal = (int) $orders->sum(fn (Order $o) => $o->total()) + $discount;
        $vat = (int) round(($subtotal - $discount) * config('yekari.vat_percent') / 100);

        return $org->invoices()->create([
            'number' => 'INV-'.$org->id.'-'.$start->format('Ymd'),
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'orders_count' => $orders->count(), 'subtotal' => $subtotal, 'discount' => $discount,
            'vat' => $vat, 'total' => $subtotal - $discount + $vat,
        ]);
    }
}
