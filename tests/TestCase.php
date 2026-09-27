<?php

namespace Tests;

use App\Domain\Domain;
use App\Models\CourierDocument;
use App\Models\CourierProfile;
use App\Models\User;
use App\Services\Sms\SmsSender;
use App\Services\WalletService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** آخرین کد ارسال‌شده به هر شماره — جای سرویس پیامک در تست */
    public array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, CatalogSeeder::class]);
        $test = $this;
        $this->app->instance(SmsSender::class, new class($test) implements SmsSender
        {
            public function __construct(private $test) {}

            public function sendPattern(string $mobile, string $pattern, array $params): array
            {
                $this->test->sms[$mobile] = (string) $params['verificationcode'];

                return ['success' => true, 'message' => 'ok'];
            }
        });
    }

    /** گارد sanctum کاربر را بین درخواست‌های یک تست کش می‌کند؛ هر درخواست باید از توکن خودش بخواند */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function user(string $mobile, array $roles = [], string $name = 'کاربر'): User
    {
        return tap(User::create(['mobile' => $mobile, 'name' => $name, 'password' => 'password123']))->syncRoles($roles);
    }

    protected function tokenFor(User $user, string $app): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken($app, ["app:$app"])->plainTextToken, 'Accept' => 'application/json'];
    }

    protected function courier(string $mobile = '09351112233', float $lat = 35.779, float $lng = 51.372): User
    {
        $u = $this->user($mobile, ['courier'], 'پیک');
        CourierProfile::create(['user_id' => $u->id, 'state' => 'active', 'online' => true, 'lat' => $lat, 'lng' => $lng, 'located_at' => now()]);
        foreach (Domain::COURIER_DOCS as $k) {
            CourierDocument::create(['user_id' => $u->id, 'key' => $k, 'status' => 'verified', 'path' => 'x']);
        }

        return $u;
    }

    protected function fund(User $u, int $amount): void
    {
        $w = app(WalletService::class);
        $w->credit($w->for($u), 'topup', $amount, 'test');
    }
}
