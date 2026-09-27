<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourierService;
use App\Services\WalletService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    private function wallet(User $u)
    {
        return app(WalletService::class)->for($u)->fresh();
    }

    private function place(User $c, array $extra = [])
    {
        $a = $c->addresses()->create(['title' => 'خانه', 'detail' => 'سعادت‌آباد', 'lat' => 35.7861, 'lng' => 51.3752]);
        $b = $c->addresses()->create(['title' => 'فروشگاه', 'detail' => 'پاکنژاد', 'lat' => 35.7715, 'lng' => 51.3608]);

        return $this->postJson('/api/v1/customer/orders', array_merge([
            'type' => 'grocery', 'pickup' => ['id' => $b->id], 'dropoff' => $a->id,
            'description' => 'دو کیلو سیب قرمز و نان', 'budgetCap' => 400000, 'payMethod' => 'wallet',
        ], $extra), $this->tokenFor($c, 'customer'));
    }

    public function test_full_mission_moves_money_correctly(): void
    {
        Storage::fake('public');
        $customer = $this->user('09121234567', ['customer']);
        $this->fund($customer, 1000000);
        $courier = $this->courier();

        $res = $this->place($customer)->assertCreated()->assertJsonPath('data.status', 'searching');
        $order = Order::find($res['data']['id']);
        $hold = $order->total() + 400000;
        $this->assertSame(1000000 - $hold, (int) $this->wallet($customer)->balance);
        $this->assertSame($hold, (int) $this->wallet($customer)->held);

        // پیشنهاد به نزدیک‌ترین پیک آنلاین رسیده
        $ch = $this->tokenFor($courier, 'courier');
        $cur = $this->getJson('/api/v1/courier/current', $ch)->assertOk();
        $this->assertNotNull($cur['offer']);
        $this->postJson("/api/v1/courier/offers/{$cur['offer']['id']}/accept", [], $ch)->assertOk()->assertJsonPath('data.status', 'to_pickup');

        // پیک نمی‌تواند سفارش دیگری را جلو ببرد؛ مشتری نمی‌تواند وسط کار لغو کند… (هنوز to_pickup است، پس می‌تواند)
        $this->postJson("/api/v1/courier/missions/{$order->id}/advance", [], $ch)->assertOk()->assertJsonPath('data.status', 'in_progress');
        // نگهبان فاکتور
        $this->postJson("/api/v1/courier/missions/{$order->id}/advance", [], $ch)->assertStatus(422);
        $this->postJson("/api/v1/courier/missions/{$order->id}/expense", ['amount' => 500000, 'file' => UploadedFile::fake()->image('r.jpg')], $ch)->assertStatus(422);
        $this->postJson("/api/v1/courier/missions/{$order->id}/expense", ['amount' => 312000, 'file' => UploadedFile::fake()->image('r.jpg')], $ch)->assertOk();
        $this->postJson("/api/v1/courier/missions/{$order->id}/advance", [], $ch)->assertOk()->assertJsonPath('data.status', 'to_dropoff');
        // نگهبان مدرک تحویل
        $this->postJson("/api/v1/courier/missions/{$order->id}/advance", [], $ch)->assertStatus(422);
        $this->postJson("/api/v1/courier/missions/{$order->id}/proof", ['files' => [UploadedFile::fake()->image('p.jpg')]], $ch)->assertOk();
        $this->postJson("/api/v1/courier/missions/{$order->id}/advance", [], $ch)->assertOk()->assertJsonPath('data.status', 'completed');

        $order->refresh();
        $total = $order->total();
        $this->assertSame(312000, (int) $order->price_goods);
        $this->assertSame(1000000 - $total, (int) $this->wallet($customer)->balance);
        $this->assertSame(0, (int) $this->wallet($customer)->held);
        $share = (int) round($order->serviceTotal() * 0.82);
        $this->assertSame($share + 312000, (int) $this->wallet($courier)->balance);
        $this->assertSame($order->serviceTotal() - $share, (int) $order->commission_amount);

        // امتیاز + انعام
        $this->postJson("/api/v1/customer/orders/{$order->id}/rate", ['stars' => 3, 'tip' => 10000], $this->tokenFor($customer, 'customer'))->assertStatus(422);
        $this->postJson("/api/v1/customer/orders/{$order->id}/rate", ['stars' => 5, 'tags' => ['سریع بود'], 'tip' => 20000], $this->tokenFor($customer, 'customer'))->assertOk();
        $this->assertSame($share + 312000 + 20000, (int) $this->wallet($courier)->balance);
        $this->assertSame(5.0, (float) $courier->courierProfile->fresh()->rating);

        $this->getJson('/api/v1/courier/earnings', $ch)->assertOk()->assertJsonPath('done.0.code', $order->code);
    }

    public function test_cancel_releases_hold_and_other_users_cannot_touch_order(): void
    {
        $customer = $this->user('09121234567', ['customer']);
        $this->fund($customer, 1000000);
        $res = $this->place($customer)->assertCreated();
        $id = $res['data']['id'];

        $stranger = $this->user('09120000000', ['customer']);
        $this->getJson("/api/v1/customer/orders/$id", $this->tokenFor($stranger, 'customer'))->assertForbidden();
        $this->postJson("/api/v1/customer/orders/$id/cancel", [], $this->tokenFor($stranger, 'customer'))->assertForbidden();

        $this->postJson("/api/v1/customer/orders/$id/cancel", ['reason' => 'منصرف شدم'], $this->tokenFor($customer, 'customer'))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(1000000, (int) $this->wallet($customer)->balance);
        $this->assertSame(0, (int) $this->wallet($customer)->held);
    }

    public function test_insufficient_wallet_with_gateway_tops_up_then_activates(): void
    {
        $customer = $this->user('09121234567', ['customer']);
        $res = $this->place($customer, ['payMethod' => 'gateway'])->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->assertNotNull($res['paymentUrl']);
        // درگاه آزمایشی لینک مستقیم callback می‌دهد
        $this->get($res['paymentUrl'])->assertRedirect();
        $order = Order::find($res['data']['id']);
        $this->assertSame('searching', $order->status);
        $this->assertSame(0, (int) $this->wallet($customer)->balance);
        // callback تکراری دوباره شارژ نمی‌کند
        $this->get($res['paymentUrl'])->assertRedirect();
        $this->assertSame(1, WalletTransaction::where('kind', 'topup')->count());

        $this->place($customer, ['payMethod' => 'wallet'])->assertStatus(422);
    }

    public function test_decline_moves_offer_to_next_courier_and_overage_is_charged(): void
    {
        Storage::fake('public');
        $customer = $this->user('09121234567', ['customer']);
        $this->fund($customer, 2000000);
        $near = $this->courier('09351112233', 35.772, 51.361);
        $far = $this->courier('09354445566', 35.79, 51.38);

        $res = $this->place($customer, ['type' => 'send', 'budgetCap' => null])->assertCreated();
        $order = Order::find($res['data']['id']);
        $this->assertSame($near->id, $order->offers()->first()->courier_id);

        $nh = $this->tokenFor($near, 'courier');
        $offer = $this->getJson('/api/v1/courier/current', $nh)['offer'];
        $this->postJson("/api/v1/courier/offers/{$offer['id']}/decline", [], $nh)->assertNoContent();
        $fh = $this->tokenFor($far, 'courier');
        $offer2 = $this->getJson('/api/v1/courier/current', $fh)['offer'];
        $this->assertNotNull($offer2);
        $this->postJson("/api/v1/courier/offers/{$offer['id']}/accept", [], $nh)->assertStatus(409);
        $this->postJson("/api/v1/courier/offers/{$offer2['id']}/accept", [], $fh)->assertOk();

        // ۱۵ دقیقهٔ استاندارد ارسال بسته → ۴۰ دقیقه طول کشید = ۲۵ دقیقه اضافه
        $order->refresh()->update(['started_at' => now()->subMinutes(40), 'status' => 'to_dropoff']);
        $this->postJson("/api/v1/courier/missions/{$order->id}/proof", ['files' => [UploadedFile::fake()->image('p.jpg')]], $fh);
        $this->postJson("/api/v1/courier/missions/{$order->id}/advance", [], $fh)->assertOk();
        $order->refresh();
        $this->assertSame(25, $order->extra_minutes);
        $this->assertSame(25 * 1000, (int) $order->price_waiting);
        $this->assertSame(2000000 - $order->total(), (int) $this->wallet($customer)->balance);
    }

    public function test_courier_bonus_tiers_pay_difference_once(): void
    {
        $courier = $this->courier();
        $customer = $this->user('09121234567', ['customer']);
        for ($i = 0; $i < 5; $i++) {
            Order::create([
                'code' => "YK-1000$i", 'customer_id' => $customer->id, 'courier_id' => $courier->id, 'mission_type' => 'send', 'status' => 'completed',
                'pickup' => ['lat' => 35.7, 'lng' => 51.3], 'description' => 'x', 'price_service' => 1, 'price_distance' => 0, 'commission_rate' => 0.18,
                'pay_method' => 'wallet', 'finished_at' => now(),
            ]);
        }
        $svc = app(CourierService::class);
        $svc->payBonuses($courier);
        $svc->payBonuses($courier);
        $this->assertSame(150000, (int) $this->wallet($courier)->balance);
    }
}
