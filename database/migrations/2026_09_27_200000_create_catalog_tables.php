<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دادهٔ پایهٔ قابل‌ویرایش از پنل: دسته‌های مأموریت (همراه تعرفه)، تنظیمات سراسری،
 * مناطق کاری، محتوا. مقدار اولیه از `design-system/app/utils/domain.ts` seed می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_types', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->string('title');
            $table->string('hint')->default('');
            $table->string('icon', 40);
            $table->json('fields');
            $table->boolean('needs_dropoff')->default(true);
            $table->unsignedSmallInteger('estimated_minutes');
            // تعرفه — تومان
            $table->unsignedInteger('base_fee');
            $table->unsignedInteger('per_km');
            $table->unsignedInteger('per_min');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('hint')->default('');
            $table->string('busy', 8)->default('mid'); // low | mid | high
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('slides', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('sub')->default('');
            $table->string('cta')->default('');
            $table->string('to')->default('');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 16)->default('customer'); // customer | courier
            $table->string('question');
            $table->text('answer');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('mission_type', 32)->nullable();
            $table->unsignedTinyInteger('percent');
            $table->unsignedInteger('max_amount')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['promo_codes', 'faqs', 'slides', 'zones', 'settings', 'mission_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
