<?php

namespace Tests\Feature;

use App\Services\Sms\AsanakSmsSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsanakSmsTest extends TestCase
{
    public function test_fills_template_and_posts_form_to_asanak(): void
    {
        config(['yekari.sms.asanak' => ['url' => 'https://asanak.test/send', 'username' => 'u', 'password' => 'p', 'source' => '9821000', 'suffix' => 'لغو۱۱']]);
        Http::fake(['asanak.test/*' => Http::response([123456])]);

        $res = (new AsanakSmsSender)->sendPattern('09121234567', 'otp', ['verificationcode' => '48213']);

        $this->assertTrue($res['success']);
        Http::assertSent(fn (Request $r) => $r['destination'] === '09121234567' && $r['Source'] === '9821000'
            && str_contains($r['Message'], '48213') && str_ends_with($r['Message'], 'لغو۱۱') && ! str_contains($r['Message'], '#'));
    }

    public function test_reports_failure_when_asanak_rejects(): void
    {
        config(['yekari.sms.asanak.url' => 'https://asanak.test/send']);
        Http::fake(['asanak.test/*' => Http::response(['error' => 'auth'], 200)]);

        $this->assertFalse((new AsanakSmsSender)->sendPattern('09121234567', 'otp', ['verificationcode' => '1'])['success']);
    }
}
