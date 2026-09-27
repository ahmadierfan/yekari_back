<?php

namespace App\Console\Commands;

use App\Domain\Period;
use App\Models\Organization;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class IssueInvoices extends Command
{
    protected $signature = 'invoices:issue {--month= : تاریخی داخل ماه موردنظر (پیش‌فرض: ماه شمسی قبل)}';

    protected $description = 'صدور فاکتور ماهانهٔ سازمان‌های پس‌پرداخت';

    public function handle(InvoiceService $svc): int
    {
        $at = $this->option('month') ? Carbon::parse($this->option('month')) : Period::jalaliMonth()[0]->subDay();
        Organization::where('billing_model', 'postpaid')->whereIn('status', ['active', 'expired'])->each(function ($org) use ($svc, $at) {
            if ($inv = $svc->issue($org, $at)) {
                $this->info("{$org->name}: {$inv->number}");
            }
        });

        return self::SUCCESS;
    }
}
