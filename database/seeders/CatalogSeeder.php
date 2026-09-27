<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\MissionType;
use App\Models\PromoCode;
use App\Models\Slide;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/** مقدار اولیه از design-system/app/utils/domain.ts (MISSION_TYPES + MISSION_TARIFFS) */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['grocery', 'خرید از فروشگاه', 'سوپرمارکت، نانوایی، لوازم خانه', 'shopping-bag', ['shoppingList', 'budget', 'photos'], true, 25, 48000, 7000, 1200],
            ['produce', 'میوه و تره‌بار', 'میدان میوه، سبزی‌فروشی', 'apple', ['shoppingList', 'budget', 'photos'], true, 20, 52000, 7000, 1200],
            ['pharmacy', 'دارو و داروخانه', 'با نسخه یا بدون نسخه', 'pill', ['prescription', 'budget', 'photos'], true, 20, 55000, 7500, 1500],
            ['send', 'ارسال بسته', 'از من به مقصد', 'package', ['parcel', 'photos'], true, 15, 42000, 6500, 1000],
            ['pickup', 'دریافت بسته', 'گرفتن از جایی و آوردن', 'inbox', ['parcel', 'photos'], true, 15, 45000, 6500, 1000],
            ['office', 'امور اداری', 'بانک، اداره، دفترخانه', 'file-text', ['documents', 'queue', 'photos'], true, 45, 90000, 7000, 2500],
            ['gift', 'خرید هدیه', 'گل، کیک، کادو + پیام', 'gift', ['budget', 'giftNote', 'photos'], true, 30, 60000, 7000, 1200],
            ['report', 'گزارش تصویری', 'بازدید از مکان و عکس/فیلم', 'camera', ['photos'], false, 20, 75000, 7000, 2000],
            ['queue', 'ایستادن در صف', 'نوبت‌گیری و نگه‌داشتن جا', 'users', ['queue', 'photos'], false, 40, 85000, 6000, 3000],
            ['custom', 'مأموریت دلخواه', 'خودت بنویس چه می‌خواهی', 'sparkles', ['budget', 'photos'], true, 30, 50000, 7000, 1500],
        ];
        foreach ($types as $i => [$key, $title, $hint, $icon, $fields, $drop, $min, $base, $km, $perMin]) {
            MissionType::firstOrCreate(['key' => $key], [
                'title' => $title, 'hint' => $hint, 'icon' => $icon, 'fields' => $fields, 'needs_dropoff' => $drop,
                'estimated_minutes' => $min, 'base_fee' => $base, 'per_km' => $km, 'per_min' => $perMin, 'sort' => $i,
            ]);
        }

        if (! Zone::exists()) {
            Zone::insert([
                ['title' => 'سعادت‌آباد و شهرک غرب', 'hint' => 'نزدیک‌ترین محدوده به تو', 'busy' => 'high', 'center_lat' => 35.78, 'center_lng' => 51.37, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['title' => 'ونک، ملاصدرا، گاندی', 'hint' => 'ترافیک بالا، مأموریت اداری زیاد', 'busy' => 'mid', 'center_lat' => 35.756, 'center_lng' => 51.41, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['title' => 'تجریش و زعفرانیه', 'hint' => 'مسافت‌های بلندتر، کرایهٔ بیشتر', 'busy' => 'low', 'center_lat' => 35.805, 'center_lng' => 51.43, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (! Slide::exists()) {
            Slide::insert([
                ['title' => 'هر کاری، همین حالا', 'sub' => 'خرید، دارو، بسته و امور اداری', 'cta' => 'ثبت مأموریت', 'to' => '/app/new', 'active' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['title' => 'پرداخت امن یکاری', 'sub' => 'پول تا تأیید تحویل نزد ما می‌ماند', 'cta' => 'بیشتر بدان', 'to' => '/app/support', 'active' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (! Faq::exists()) {
            $faq = [
                ['پرداخت امن یعنی چه؟', 'مبلغ مأموریت هنگام ثبت از موجودی شما بلوکه می‌شود، ولی تا وقتی تحویل را تأیید نکنید به انجام‌دهنده پرداخت نمی‌شود. اگر مأموریت لغو شود، همان مبلغ بلافاصله آزاد می‌شود.'],
                ['چرا مبلغ نهایی با برآورد اولیه فرق دارد؟', 'در مأموریت‌های خرید، مبلغ کالا تا وقتی فاکتور فروشگاه ثبت نشود معلوم نیست. آن‌چه اول بلوکه می‌شود «سقف خرید» است، نه مبلغ قطعی؛ باقی‌ماندهٔ سقف بعد از ثبت فاکتور آزاد می‌شود.'],
                ['اگر انجام‌دهنده کالای اشتباه بخرد چه می‌شود؟', 'از دکمهٔ «گزارش مشکل» در صفحهٔ همان مأموریت درخواست ثبت کنید و عکس فاکتور یا کالا را پیوست کنید. تا پایان بررسی، مبلغ نزد یکاری می‌ماند.'],
                ['برداشت از کیف پول چقدر طول می‌کشد؟', 'درخواست برداشت در اولین سیکل پایا (روزهای کاری، سه نوبت در روز) به حساب مقصد واریز می‌شود؛ معمولاً کمتر از ۲۴ ساعت کاری.'],
                ['چطور مأموریت را لغو کنم؟', 'تا قبل از شروع خرید یا انجام کار، از صفحهٔ مأموریت دکمهٔ «لغو مأموریت» را بزنید. اگر هنوز انجام‌دهنده‌ای نپذیرفته باشد، لغو کاملاً رایگان است.'],
            ];
            foreach ($faq as $i => [$q, $a]) {
                Faq::create(['question' => $q, 'answer' => $a, 'sort' => $i]);
            }
        }

        PromoCode::firstOrCreate(['code' => 'OFFICE20'], ['mission_type' => 'office', 'percent' => 20, 'per_user_limit' => 1]);
    }
}
