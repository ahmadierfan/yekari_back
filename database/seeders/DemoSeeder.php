<?php

namespace Database\Seeders;

use App\Domain\Domain;
use App\Models\CourierDocument;
use App\Models\CourierProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Database\Seeder;

/**
 * حساب‌های نمایشی برای توسعه (فقط خارج از production). ورود با OTP_DEMO_CODE یا رمز
 * `password123`. شماره‌ها همان شماره‌های mock اپ‌ها هستند.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $w = app(WalletService::class);
        $mk = fn (string $mobile, string $name, array $roles) => tap(User::firstOrCreate(['mobile' => $mobile], [
            'name' => $name, 'password' => 'password123', 'mobile_verified_at' => now(), 'onboarded_at' => now(),
        ]))->syncRoles($roles);

        $staff = [
            ['09121110000', 'بهزاد آذری', 'super-admin'], ['09122220000', 'شیما قربانی', 'ops'],
            ['09123330000', 'کامران دهقان', 'finance'], ['09124440000', 'یاسمن پورعلی', 'support'],
        ];
        foreach ($staff as [$m, $n, $r]) {
            $mk($m, $n, [$r]);
        }

        $customer = $mk('09121234567', 'سارا رضایی', ['customer']);
        if (! $customer->addresses()->exists()) {
            $customer->addresses()->createMany([
                ['title' => 'خانه', 'detail' => 'سعادت‌آباد، بلوار دریا، کوچهٔ نیلوفر، پلاک ۱۲', 'icon' => 'home', 'map_x' => 47, 'map_y' => 36, 'lat' => 35.7861, 'lng' => 51.3752, 'sort' => 0],
                ['title' => 'محل کار', 'detail' => 'ونک، خیابان ملاصدرا، برج آسمان، طبقهٔ ۷', 'icon' => 'briefcase', 'map_x' => 72, 'map_y' => 31, 'lat' => 35.7554, 'lng' => 51.4103, 'sort' => 1],
                ['title' => 'هایپر استار', 'detail' => 'سعادت‌آباد، بلوار پاکنژاد', 'icon' => 'store', 'map_x' => 20, 'map_y' => 42, 'lat' => 35.7715, 'lng' => 51.3608, 'sort' => 2],
            ]);
            $w->credit($w->for($customer), 'topup', 2000000, 'اعتبار نمایشی');
        }

        $courier = $mk('09351112233', 'مهدی کریمی', ['courier']);
        CourierProfile::updateOrCreate(['user_id' => $courier->id], [
            'state' => 'active', 'vehicle' => 'موتور', 'plate' => ['two' => '۱۲', 'letter' => 'ب', 'three' => '۳۴۵', 'iran' => '۶۸'],
            'zone_id' => 1, 'lat' => 35.779, 'lng' => 51.372, 'located_at' => now(), 'rating' => 4.9, 'rating_count' => 312, 'missions_count' => 1284,
        ]);
        foreach (Domain::COURIER_DOCS as $k) {
            CourierDocument::firstOrCreate(['user_id' => $courier->id, 'key' => $k], ['status' => 'verified', 'path' => 'demo/none', 'reviewed_at' => now()]);
        }

        $org = Organization::firstOrCreate(['name' => 'کلینیک دی'], [
            'status' => 'active', 'billing_model' => 'prepaid', 'discount_percent' => 12, 'monthly_ceiling' => 60000000, 'seats' => 14,
            'starts_on' => now()->subMonths(3), 'ends_on' => now()->addMonths(9), 'contact_phone' => '02188990011',
        ]);
        if (! $org->costCenters()->exists()) {
            $org->costCenters()->createMany([['title' => 'پذیرش'], ['title' => 'اداری'], ['title' => 'انبار دارو']]);
            $w->credit($w->for($org), 'topup', 18700000, 'اعتبار نمایشی');
        }
        $manager = $mk('09123456789', 'امیر حسینی', ['customer']);
        $org->members()->updateOrCreate(['user_id' => $manager->id], ['role' => 'manager']);
        $org->members()->updateOrCreate(['user_id' => $customer->id], ['role' => 'member', 'monthly_limit' => 5000000]);
    }
}
