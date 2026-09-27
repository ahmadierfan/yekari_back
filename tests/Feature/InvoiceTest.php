<?php

namespace Tests\Feature;

use App\Domain\Period;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    public function test_postpaid_org_gets_one_invoice_per_jalali_month_with_vat(): void
    {
        $org = Organization::create(['name' => 'دفتر حقوقی', 'status' => 'active', 'billing_model' => 'postpaid']);
        $u = $this->user('09121234567', ['customer']);
        [$start] = Period::jalaliMonth(Carbon::now()->subMonthNoOverflow());
        foreach ([100000, 50000] as $i => $service) {
            Order::create([
                'code' => "YK-2000$i", 'customer_id' => $u->id, 'mission_type' => 'send', 'status' => 'completed', 'organization_id' => $org->id,
                'pickup' => ['lat' => 35.7, 'lng' => 51.3], 'description' => 'x', 'price_service' => $service, 'price_distance' => 0,
                'price_discount' => 10000, 'commission_rate' => 0.18, 'pay_method' => 'corporate', 'finished_at' => $start->copy()->addDays(3),
            ]);
        }
        $this->artisan('invoices:issue', ['--month' => $start->copy()->addDays(3)->toDateString()])->assertSuccessful();
        $this->artisan('invoices:issue', ['--month' => $start->copy()->addDays(3)->toDateString()])->assertSuccessful();
        $inv = $org->invoices()->sole();
        $this->assertSame(150000, (int) $inv->subtotal);
        $this->assertSame(20000, (int) $inv->discount);
        $this->assertSame(13000, (int) $inv->vat);
        $this->assertSame(143000, (int) $inv->total);
    }
}
