<?php

namespace App\Services;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Models\MissionType;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Models\User;

/**
 * برآورد قیمت — تفکیک‌شده، هیچ‌وقت یک عدد مبهم (تصمیم DESIGN.md):
 * خدمات (پایهٔ دسته) + مسافت (کیلومتر × تعرفه) + انتظار − تخفیف. کالا بعداً با فاکتور پیک.
 */
class PricingService
{
    /**
     * @param  array{lat: float, lng: float}  $pickup
     * @param  array{lat: float, lng: float}|null  $dropoff
     */
    public function quote(MissionType $type, array $pickup, ?array $dropoff, ?string $promo = null, ?User $user = null, ?Organization $org = null): array
    {
        $km = $dropoff ? round(Domain::haversine($pickup['lat'], $pickup['lng'], $dropoff['lat'], $dropoff['lng']) * config('yekari.road_factor'), 2) : 0.0;
        $service = (int) $type->base_fee;
        $distance = $dropoff ? (int) (round($type->per_km * $km / 1000) * 1000) : 0;
        $waiting = 0; // اضافه‌کاری فقط لحظهٔ تحویل و با زمان واقعی حساب می‌شود (missionOverage)

        $discount = 0;
        $promoApplied = null;
        if ($promo) {
            $p = $this->validPromo($promo, $type->key, $user);
            $discount += (int) (round(($service + $waiting) * $p->percent / 100 / 1000) * 1000);
            if ($p->max_amount) {
                $discount = min($discount, (int) $p->max_amount);
            }
            $promoApplied = $p->code;
        }
        if ($org && $org->discount_percent) {
            $discount += (int) (round(($service + $distance) * $org->discount_percent / 100 / 1000) * 1000);
        }
        $discount = min($discount, $service + $distance + $waiting);

        return [
            'service' => $service, 'distance' => $distance, 'waiting' => $waiting, 'goods' => 0, 'discount' => $discount,
            'total' => $service + $distance + $waiting - $discount,
            'distance_km' => $km, 'promo' => $promoApplied,
            'commission_rate' => (float) Setting::get('commission_rate'),
        ];
    }

    public function validPromo(string $code, string $type, ?User $user): PromoCode
    {
        $p = PromoCode::where('code', strtoupper(trim($code)))->where('active', true)->first();
        $bad = ! $p
            || ($p->starts_at && $p->starts_at->isFuture())
            || ($p->ends_at && $p->ends_at->isPast())
            || ($p->mission_type && $p->mission_type !== $type)
            || ($p->usage_limit && $p->used >= $p->usage_limit);
        if (! $bad && $user) {
            $mine = Order::where('customer_id', $user->id)->where('promo_code', $p->code)->whereNotIn('status', ['cancelled', 'expired'])->count();
            $bad = $mine >= $p->per_user_limit;
        }
        if ($bad) {
            throw new ApiException('کد تخفیف معتبر نیست', 422, ['promo' => ['کد تخفیف معتبر نیست']]);
        }

        return $p;
    }

    /** سهم پیک: (خدمات + مسافت + انتظار) × (۱ − کمیسیون). کالا عیناً پس داده می‌شود. */
    public static function courierShare(int $serviceTotal, float $rate): int
    {
        return (int) round($serviceTotal * (1 - $rate));
    }
}
