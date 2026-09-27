<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Maps\MapirClient;
use Illuminate\Http\Request;
use Throwable;

/**
 * جست‌وجوی مکان و آدرس از مختصات برای اپ‌ها — پراکسی map.ir تا کلید سرویس‌ها
 * سمت سرور بماند. (کلید نقشهٔ پایه جداست و در اپ است: NUXT_PUBLIC_MAPIR_KEY)
 */
class GeoController extends Controller
{
    public function __construct(private MapirClient $mapir) {}

    private function ensure(): void
    {
        if (! $this->mapir->configured()) {
            throw new ApiException('سرویس نقشه تنظیم نشده است (MAPIR_API_KEY)', 503);
        }
    }

    public function search(Request $r)
    {
        $d = $r->validate([
            'q' => 'required|string|min:2|max:120',
            'lat' => 'nullable|numeric|between:24,40', 'lng' => 'nullable|numeric|between:44,64',
        ]);
        $this->ensure();
        try {
            $items = $this->mapir->search($d['q'], isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null);
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('جست‌وجوی نقشه در دسترس نیست؛ کمی بعد دوباره امتحان کن', 502);
        }

        return response()->json(['data' => array_slice($items, 0, 10)]);
    }

    public function reverse(Request $r)
    {
        $d = $r->validate(['lat' => 'required|numeric|between:24,40', 'lng' => 'required|numeric|between:44,64']);
        $this->ensure();
        try {
            $a = $this->mapir->reverse((float) $d['lat'], (float) $d['lng']);
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('نشانی این نقطه پیدا نشد؛ خودت بنویسش', 502);
        }

        return response()->json(['data' => $a]);
    }
}
