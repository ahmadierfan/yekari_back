<?php

namespace App\Providers;

use App\Models\CourierDocument;
use App\Models\MissionType;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payment\FakeGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\ZarinpalGateway;
use App\Services\Payment\ZibalGateway;
use App\Services\Sms\CrmSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, fn () => match (config('yekari.sms.driver')) {
            'crm' => new CrmSmsSender,
            default => new LogSmsSender,
        });

        $this->app->bind(PaymentGateway::class, fn () => match (config('yekari.payment.gateway')) {
            'zibal' => new ZibalGateway,
            'zarinpal' => new ZarinpalGateway,
            default => new FakeGateway,
        });
    }

    public function boot(): void
    {
        // مدیر ارشد همه‌چیز را دارد؛ لازم نیست هر دسترسی تازه دستی به او داده شود
        Gate::before(fn (User $user) => $user->hasRole('super-admin') ? true : null);

        Relation::enforceMorphMap([
            'user' => User::class,
            'organization' => Organization::class,
            'order' => Order::class,
            'ticket' => Ticket::class,
            'withdrawal' => Withdrawal::class,
            'courier_document' => CourierDocument::class,
            'mission_type' => MissionType::class,
        ]);

        // پیامک هزینه دارد و OTP هدف brute-force است: هم به ازای IP هم به ازای شماره
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(5)->by('ip:'.$r->ip()),
            Limit::perHour(10)->by('mobile:'.$r->input('mobile')),
        ]);
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}
