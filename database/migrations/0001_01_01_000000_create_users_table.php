<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * کاربر یکتای همهٔ اپ‌ها. نقش (مشتری/پیک/کارمند پنل) روی همین رکورد با
 * spatie/laravel-permission سوار می‌شود — «یک حساب، چند نقش».
 * شناسهٔ اصلی ورود موبایل است؛ ایمیل/رمز اختیاری و برای ورود با رمز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('');
            $table->string('mobile', 11)->unique();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('status', 16)->default('active'); // active | blocked
            $table->string('avatar')->nullable();
            $table->json('prefs')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        /**
         * کد یک‌بارمصرف — همان الگوی m_verificationcodes پروژهٔ مرجع (انقضا + is_used +
         * محدودیت ارسال دوباره)، با دو تفاوت: خودِ کد هش می‌شود و تعداد تلاش غلط شمرده
         * می‌شود تا کد پنج‌رقمی با brute-force شکسته نشود.
         */
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 11)->index();
            $table->string('purpose', 24)->default('login'); // login | phone_change | password_reset
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreignId('user_id')->nullable(); // برای phone_change: حسابی که شماره‌اش عوض می‌شود
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
