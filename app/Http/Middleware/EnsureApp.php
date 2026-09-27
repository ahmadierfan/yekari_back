<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

/**
 * هر توکن فقط برای اپی که با آن وارد شده معتبر است (`app:customer`، `app:courier`…).
 * یعنی توکن اپ مشتری روی مسیرهای پنل کار نمی‌کند، حتی اگر همان آدم کارمند هم باشد.
 * نقش هم دوباره چک می‌شود: اگر نقش بعد از ورود گرفته شد، توکن قبلی فوراً بی‌اثر است.
 */
class EnsureApp
{
    public function handle(Request $request, Closure $next, string $app)
    {
        $user = $request->user();
        if (! $user || ! $user->tokenCan("app:$app")) {
            throw ApiException::forbidden('این توکن برای این اپ صادر نشده است');
        }
        if ($user->isBlocked()) {
            throw ApiException::forbidden('حساب شما مسدود شده است');
        }
        $cfg = config("yekari.apps.$app");
        if ($cfg['roles'] && ! $user->hasAnyRole($cfg['roles'])) {
            throw ApiException::forbidden();
        }
        if (! empty($cfg['org_manager']) && ! $user->memberships()->where('role', 'manager')->where('active', true)->exists()) {
            throw ApiException::forbidden('فقط مدیر حساب سازمانی به این پنل دسترسی دارد');
        }
        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
