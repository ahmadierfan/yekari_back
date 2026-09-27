<?php

namespace Tests\Feature;

use App\Models\MissionType;
use App\Services\PricingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapirTest extends TestCase
{
    private function withKey(): void
    {
        config(['yekari.maps.mapir' => ['key' => 'test-key', 'url' => 'https://map.test', 'timeout' => 2]]);
    }

    public function test_search_proxies_autocomplete_with_key_header(): void
    {
        $this->withKey();
        Http::fake(['map.test/search/v2/autocomplete' => Http::response(['value' => [
            ['title' => 'میدان ونک', 'address' => 'تهران، ونک', 'geom' => ['type' => 'Point', 'coordinates' => [51.4105, 35.7575]]],
        ]])]);
        $h = $this->tokenFor($this->user('09121234567', ['customer']), 'customer');

        $this->getJson('/api/v1/geo/search?q=ونک&lat=35.7&lng=51.4', $h)
            ->assertOk()
            ->assertJsonPath('data.0.title', 'میدان ونک')
            ->assertJsonPath('data.0.lat', 35.7575)
            ->assertJsonPath('data.0.lng', 51.4105);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'test-key') && $r['text'] === 'ونک' && $r['lon'] == 51.4);
    }

    public function test_reverse_prefers_postal_address(): void
    {
        $this->withKey();
        Http::fake(['map.test/reverse*' => Http::response([
            'address' => 'ایران، تهران، ونک', 'postal_address' => 'تهران، ونک، خیابان ملاصدرا', 'address_compact' => 'ونک، ملاصدرا',
            'city' => 'تهران', 'neighbourhood' => 'ونک',
        ])]);
        $h = $this->tokenFor($this->user('09121234567', ['customer']), 'customer');

        $this->getJson('/api/v1/geo/reverse?lat=35.7575&lng=51.4105', $h)
            ->assertOk()
            ->assertJsonPath('data.address', 'تهران، ونک، خیابان ملاصدرا')
            ->assertJsonPath('data.short', 'ونک، ملاصدرا');
    }

    public function test_geo_needs_a_key(): void
    {
        $h = $this->tokenFor($this->user('09121234567', ['customer']), 'customer');
        $this->getJson('/api/v1/geo/reverse?lat=35.7&lng=51.4', $h)->assertStatus(503);
    }

    public function test_quote_uses_road_distance_and_falls_back_to_estimate(): void
    {
        $type = MissionType::firstOrFail();
        $a = ['lat' => 35.7861, 'lng' => 51.3752];
        $b = ['lat' => 35.7715, 'lng' => 51.3608];

        // بدون کلید: خط مستقیم × ۱٫۳
        $fallback = app(PricingService::class)->quote($type, $a, $b)['distance_km'];
        $this->assertEqualsWithDelta(2.66, $fallback, 0.1);

        $this->withKey();
        $down = false;
        Http::fake(['map.test/routes/*' => function () use (&$down) {
            return $down
                ? Http::response('down', 500)
                : Http::response(['code' => 'Ok', 'routes' => [['distance' => 4321.0, 'duration' => 600]]]);
        }]);
        $this->assertSame(4.32, app(PricingService::class)->quote($type, $a, $b)['distance_km']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/routes/route/v1/driving/51.375200,35.786100;51.360800,35.771500'));

        // map.ir خطا داد: دوباره برآورد، نه شکست سفارش
        $down = true;
        $this->assertEqualsWithDelta($fallback, app(PricingService::class)->quote($type, ['lat' => 35.7861, 'lng' => 51.3753], $b)['distance_km'], 0.1);
    }
}
