<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Domain;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\CourierProfile;
use App\Models\User;
use App\Services\Audit;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * ورود مشترک هر چهار اپ، به دو روش: OTP پیامکی و رمز (با موبایل یا ایمیل).
 * `app` تعیین می‌کند توکن برای کدام اپ صادر شود و چه کسی اجازهٔ ورود دارد
 * (config/yekari.php → apps).
 */
class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    private function appRule(): array
    {
        return ['required', Rule::in(array_keys(config('yekari.apps')))];
    }

    private function mobile(Request $r, string $key = 'mobile'): string
    {
        $m = Domain::normalizeMobile($r->input($key));
        if (! Domain::isValidMobile($m)) {
            throw new ApiException('شمارهٔ موبایل معتبر نیست', 422, [$key => ['شمارهٔ موبایل معتبر نیست']]);
        }

        return $m;
    }

    public function requestOtp(Request $r)
    {
        $r->validate(['mobile' => 'required|string', 'app' => $this->appRule()]);
        $mobile = $this->mobile($r);
        // برای اپ‌های بدون ثبت‌نام آزاد، به شمارهٔ ناشناس پیامک نمی‌دهیم — ولی پاسخ یکسان
        // است تا وجود یا نبود حساب لو نرود
        $cfg = config('yekari.apps.'.$r->app);
        if (! $cfg['self_signup'] && ! User::where('mobile', $mobile)->exists()) {
            return response()->json(['resendIn' => (int) config('yekari.otp.resend_seconds')]);
        }
        $wait = $this->otp->send($mobile, 'login', ip: $r->ip());

        return response()->json(['resendIn' => $wait, 'length' => (int) config('yekari.otp.length')]);
    }

    public function verifyOtp(Request $r)
    {
        $r->validate(['mobile' => 'required|string', 'code' => 'required|string', 'app' => $this->appRule(), 'device' => 'nullable|string|max:60']);
        $mobile = $this->mobile($r);
        if (! $this->otp->verify($mobile, $r->code)) {
            throw new ApiException('کد وارد‌شده درست نیست', 422, ['code' => ['کد وارد‌شده درست نیست']]);
        }

        $user = User::where('mobile', $mobile)->first();
        $isNew = false;
        if (! $user) {
            if (! config('yekari.apps.'.$r->app.'.self_signup')) {
                throw ApiException::forbidden('حساب کاربری با این شماره پیدا نشد');
            }
            $user = User::create(['mobile' => $mobile, 'mobile_verified_at' => now()]);
            $isNew = true;
        } elseif (! $user->mobile_verified_at) {
            $user->update(['mobile_verified_at' => now()]);
        }

        return $this->issue($user, $r->app, $r->device, $isNew);
    }

    public function login(Request $r)
    {
        $r->validate(['login' => 'required|string', 'password' => 'required|string', 'app' => $this->appRule(), 'device' => 'nullable|string|max:60']);
        $login = trim($r->login);
        $user = str_contains($login, '@')
            ? User::where('email', strtolower($login))->first()
            : User::where('mobile', Domain::normalizeMobile($login))->first();
        if (! $user || ! $user->password || ! Hash::check($r->password, $user->password)) {
            throw new ApiException('نام کاربری یا رمز عبور درست نیست', 422, ['login' => ['نام کاربری یا رمز عبور درست نیست']]);
        }

        return $this->issue($user, $r->app, $r->device, false);
    }

    /** صدور توکن مخصوص یک اپ، بعد از چک نقش */
    private function issue(User $user, string $app, ?string $device, bool $isNew)
    {
        if ($user->isBlocked()) {
            throw ApiException::forbidden('حساب شما مسدود شده است؛ با پشتیبانی تماس بگیرید');
        }
        $cfg = config("yekari.apps.$app");

        DB::transaction(function () use ($user, $cfg, $app) {
            // مشتری/پیک: ورود با OTP خودش ثبت‌نام است (نقش همان اپ داده می‌شود)
            if (! empty($cfg['grant_role']) && ! $user->hasRole($cfg['grant_role'])) {
                $user->assignRole($cfg['grant_role']);
                if ($app === 'courier') {
                    CourierProfile::firstOrCreate(['user_id' => $user->id]);
                }
            }
        });

        if ($cfg['roles'] && ! $user->hasAnyRole($cfg['roles'])) {
            throw ApiException::forbidden('به این پنل دسترسی ندارید');
        }
        if (! empty($cfg['org_manager']) && ! $user->memberships()->where('role', 'manager')->where('active', true)->exists()) {
            throw ApiException::forbidden('فقط مدیر حساب سازمانی به این پنل دسترسی دارد');
        }

        $token = $user->createToken($device ? "$app:$device" : $app, ["app:$app"])->plainTextToken;
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        if ($app === 'admin') {
            auth()->setUser($user);
            Audit::log('login', 'ورود به پنل');
        }

        return response()->json(['token' => $token, 'isNew' => $isNew, 'user' => new UserResource($user->fresh())]);
    }

    public function forgotPassword(Request $r)
    {
        $r->validate(['mobile' => 'required|string']);
        $mobile = $this->mobile($r);
        if (User::where('mobile', $mobile)->exists()) {
            $this->otp->send($mobile, 'password_reset', ip: $r->ip());
        }

        return response()->json(['resendIn' => (int) config('yekari.otp.resend_seconds')]);
    }

    public function resetPassword(Request $r)
    {
        $r->validate(['mobile' => 'required|string', 'code' => 'required|string', 'password' => ['required', Password::min(8)->letters()->numbers()]]);
        $mobile = $this->mobile($r);
        $user = User::where('mobile', $mobile)->first();
        if (! $user || ! $this->otp->verify($mobile, $r->code, 'password_reset')) {
            throw new ApiException('کد وارد‌شده درست نیست', 422, ['code' => ['کد وارد‌شده درست نیست']]);
        }
        $user->update(['password' => $r->password]);
        // رمز که عوض شد، همهٔ نشست‌های قبلی باطل
        $user->tokens()->delete();

        return response()->json(['message' => 'رمز عبور تغییر کرد']);
    }

    /* ── نیازمند توکن ───────────────────────────── */

    public function me(Request $r)
    {
        return new UserResource($r->user());
    }

    public function updateProfile(Request $r)
    {
        $user = $r->user();
        $data = $r->validate([
            'name' => 'sometimes|string|min:2|max:80',
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'prefs' => 'sometimes|array',
            'prefs.push' => 'boolean', 'prefs.sms' => 'boolean', 'prefs.offers' => 'boolean',
            'onboarded' => 'sometimes|boolean',
        ]);
        if (array_key_exists('onboarded', $data)) {
            $data['onboarded_at'] = $data['onboarded'] ? ($user->onboarded_at ?? now()) : null;
            unset($data['onboarded']);
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        if (isset($data['prefs'])) {
            $data['prefs'] = array_merge($user->prefs ?? [], $data['prefs']);
        }
        $user->update($data);

        return new UserResource($user);
    }

    public function changePassword(Request $r)
    {
        $user = $r->user();
        $r->validate([
            'current' => [$user->password ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        if ($user->password && ! Hash::check($r->current, $user->password)) {
            throw new ApiException('رمز فعلی درست نیست', 422, ['current' => ['رمز فعلی درست نیست']]);
        }
        $user->update(['password' => $r->password]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'رمز عبور ذخیره شد']);
    }

    /** تغییر شماره: شماره تا تأیید کد روی شمارهٔ جدید عوض نمی‌شود */
    public function requestPhoneChange(Request $r)
    {
        $r->validate(['mobile' => 'required|string']);
        $mobile = $this->mobile($r);
        if ($mobile === $r->user()->mobile || User::where('mobile', $mobile)->exists()) {
            throw new ApiException('این شماره قابل استفاده نیست', 422, ['mobile' => ['این شماره قابل استفاده نیست']]);
        }

        return response()->json(['resendIn' => $this->otp->send($mobile, 'phone_change', $r->user()->id, $r->ip())]);
    }

    public function confirmPhoneChange(Request $r)
    {
        $r->validate(['mobile' => 'required|string', 'code' => 'required|string']);
        $mobile = $this->mobile($r);
        if (! $this->otp->verify($mobile, $r->code, 'phone_change', $r->user()->id)) {
            throw new ApiException('کد وارد‌شده درست نیست', 422, ['code' => ['کد وارد‌شده درست نیست']]);
        }
        $r->user()->update(['mobile' => $mobile, 'mobile_verified_at' => now()]);

        return new UserResource($r->user());
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'خارج شدید']);
    }
}
