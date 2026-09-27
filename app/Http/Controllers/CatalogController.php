<?php

namespace App\Http\Controllers;

use App\Http\Resources\MissionTypeResource;
use App\Models\Faq;
use App\Models\MissionType;
use App\Models\Setting;
use App\Models\Slide;
use App\Models\Zone;
use Illuminate\Http\Request;

/** دادهٔ عمومی که هر اپ بعد از ورود یک‌بار می‌گیرد (دسته‌ها، تعرفه، محتوا، ثابت‌های قابل‌ویرایش) */
class CatalogController extends Controller
{
    public function bootstrap(Request $r)
    {
        $audience = $r->query('audience', 'customer');

        return response()->json([
            'missionTypes' => MissionTypeResource::collection(MissionType::where('active', true)->orderBy('sort')->get()),
            'slides' => Slide::where('active', true)->orderBy('sort')->get(['id', 'title', 'sub', 'cta', 'to']),
            'faq' => Faq::where('active', true)->where('audience', $audience)->orderBy('sort')->get(['id', 'question as q', 'answer as a']),
            'zones' => Zone::where('active', true)->get(['id', 'title', 'hint', 'busy']),
            'settings' => [
                'commissionRate' => (float) Setting::get('commission_rate'),
                'bonusTiers' => Setting::get('bonus_tiers'),
                'tipPresets' => Setting::get('tip_presets'),
                'minTip' => (int) Setting::get('min_tip'),
                'minWithdraw' => (int) Setting::get('min_withdraw'),
                'otpLength' => (int) config('yekari.otp.length'),
            ],
        ]);
    }
}
