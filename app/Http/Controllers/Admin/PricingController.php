<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MissionTypeResource;
use App\Models\MissionType;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\Request;

/** تعرفه، کمیسیون، پله‌های پاداش، کدهای تخفیف */
class PricingController extends Controller
{
    public function index()
    {
        return response()->json([
            'tariffs' => MissionTypeResource::collection(MissionType::orderBy('sort')->get()),
            'commission' => (int) round(Setting::get('commission_rate') * 100),
            'bonusTiers' => Setting::get('bonus_tiers'),
            'minTip' => Setting::get('min_tip'),
            'minWithdraw' => Setting::get('min_withdraw'),
        ]);
    }

    public function updateTariff(Request $r, MissionType $type)
    {
        $d = $r->validate([
            'base' => 'sometimes|integer|min:0', 'perKm' => 'sometimes|integer|min:0', 'perMin' => 'sometimes|integer|min:0',
            'estimatedMinutes' => 'sometimes|integer|min:1|max:600', 'active' => 'sometimes|boolean', 'title' => 'sometimes|string|max:60',
        ]);
        $map = ['base' => 'base_fee', 'perKm' => 'per_km', 'perMin' => 'per_min', 'estimatedMinutes' => 'estimated_minutes', 'active' => 'active', 'title' => 'title'];
        $type->update(collect($d)->mapWithKeys(fn ($v, $k) => [$map[$k] => $v])->all());
        Audit::log('update', "تعرفهٔ «{$type->title}» تغییر کرد", $type, $d);

        return new MissionTypeResource($type);
    }

    public function updateSettings(Request $r)
    {
        $d = $r->validate([
            'commission' => 'sometimes|integer|min:0|max:40',
            'bonusTiers' => 'sometimes|array', 'bonusTiers.*.missions' => 'required|integer|min:1', 'bonusTiers.*.amount' => 'required|integer|min:0',
            'minTip' => 'sometimes|integer|min:0', 'minWithdraw' => 'sometimes|integer|min:0',
        ]);
        if (isset($d['commission'])) {
            $old = (int) round(Setting::get('commission_rate') * 100);
            if ($old !== $d['commission']) {
                Setting::put('commission_rate', $d['commission'] / 100);
                Audit::log('update', "کمیسیون از {$old}٪ به {$d['commission']}٪ تغییر کرد");
            }
        }
        if (isset($d['bonusTiers'])) {
            Setting::put('bonus_tiers', collect($d['bonusTiers'])->sortBy('missions')->values()->all());
            Audit::log('update', 'پله‌های پاداش پیک تغییر کرد');
        }
        foreach (['minTip' => 'min_tip', 'minWithdraw' => 'min_withdraw'] as $k => $key) {
            if (isset($d[$k])) {
                Setting::put($key, $d[$k]);
                Audit::log('update', "تنظیم $key تغییر کرد");
            }
        }

        return $this->index();
    }

    public function promos()
    {
        return response()->json(['data' => PromoCode::latest('id')->get()]);
    }

    public function savePromo(Request $r, ?PromoCode $promo = null)
    {
        $d = $r->validate([
            'code' => 'required|string|max:32|alpha_num', 'mission_type' => 'nullable|exists:mission_types,key', 'percent' => 'required|integer|min:1|max:100',
            'max_amount' => 'nullable|integer|min:0', 'usage_limit' => 'nullable|integer|min:1', 'per_user_limit' => 'integer|min:1',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'active' => 'boolean',
        ]);
        $d['code'] = strtoupper($d['code']);
        $promo = $promo?->exists ? tap($promo)->update($d) : PromoCode::create($d);
        Audit::log($promo->wasRecentlyCreated ? 'create' : 'update', "کد تخفیف {$promo->code} ذخیره شد");

        return response()->json(['data' => $promo]);
    }
}
