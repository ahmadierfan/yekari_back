<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * وب‌سرویس‌های map.ir — همان مسیرهایی که SDK های رسمی (`@map.ir/services-sdk`، `mapir-api`)
 * صدا می‌زنند؛ همه با هدر `x-api-key`:
 *
 * - جست‌وجو:        POST /search/v2/autocomplete  {text, lat, lon}  → value[] (title, address, geom)
 * - آدرس از نقطه:   GET  /reverse?lat&lon                          → address, address_compact, …
 * - مسیر (OSRM):    GET  /routes/route/v1/driving/{lng,lat};{lng,lat} → routes[0].distance (متر)
 *
 * کلید فقط سمت سرور است (MAPIR_API_KEY). بدون کلید یا اگر map.ir جواب ندهد، `configured()`
 * و خروجی null به فراخواننده می‌گوید سراغ جایگزین برود (مسافت خط مستقیم × road_factor).
 */
class MapirClient
{
    public function configured(): bool
    {
        return (bool) config('yekari.maps.mapir.key');
    }

    private function http()
    {
        return Http::baseUrl(rtrim(config('yekari.maps.mapir.url'), '/'))
            ->withHeaders(['x-api-key' => (string) config('yekari.maps.mapir.key')])
            ->acceptJson()
            ->timeout((int) config('yekari.maps.mapir.timeout'));
    }

    /**
     * @return list<array{title: string, address: string, lat: float, lng: float}>
     */
    public function search(string $text, ?float $lat = null, ?float $lng = null): array
    {
        $body = ['text' => $text];
        if ($lat !== null && $lng !== null) {
            $body += ['lat' => $lat, 'lon' => $lng];
        }
        $res = $this->http()->post('/search/v2/autocomplete', $body)->throw()->json();

        return collect($res['value'] ?? [])
            ->filter(fn ($v) => isset($v['geom']['coordinates'][1]))
            ->map(fn ($v) => [
                'title' => (string) ($v['title'] ?? ''),
                'address' => (string) ($v['address'] ?? ''),
                'lat' => (float) $v['geom']['coordinates'][1],
                'lng' => (float) $v['geom']['coordinates'][0],
            ])
            ->values()->all();
    }

    /**
     * @return array{address: string, short: string, city: string, neighbourhood: string}|null
     */
    public function reverse(float $lat, float $lng): ?array
    {
        // ۵ رقم اعشار ≈ ۱ متر؛ کش با همین دقت تا جابه‌جایی جزئی پین درخواست تازه نسازد
        $key = sprintf('mapir:rev:%.5f,%.5f', $lat, $lng);

        return Cache::remember($key, now()->addDay(), function () use ($lat, $lng) {
            $r = $this->http()->get('/reverse', ['lat' => $lat, 'lon' => $lng])->throw()->json();
            $address = trim((string) ($r['postal_address'] ?? '')) ?: trim((string) ($r['address'] ?? ''));
            if ($address === '') {
                return null;
            }

            return [
                'address' => $address,
                'short' => trim((string) ($r['address_compact'] ?? '')) ?: $address,
                'city' => (string) ($r['city'] ?? ''),
                'neighbourhood' => (string) ($r['neighbourhood'] ?? ''),
            ];
        });
    }

    /** مسافت جاده‌ای به کیلومتر؛ null یعنی در دسترس نیست و فراخواننده باید برآورد کند */
    public function roadKm(float $fromLat, float $fromLng, float $toLat, float $toLng): ?float
    {
        if (! $this->configured()) {
            return null;
        }
        $coords = sprintf('%.6f,%.6f;%.6f,%.6f', $fromLng, $fromLat, $toLng, $toLat);

        try {
            return Cache::remember('mapir:route:'.$coords, now()->addHours(6), function () use ($coords) {
                $r = $this->http()->get('/routes/route/v1/driving/'.$coords, [
                    'alternatives' => 'false', 'steps' => 'false', 'overview' => 'false',
                ])->throw()->json();
                $m = $r['routes'][0]['distance'] ?? null;

                return is_numeric($m) ? round($m / 1000, 2) : throw new \RuntimeException('no route');
            });
        } catch (Throwable $e) {
            Log::warning('map.ir route failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
