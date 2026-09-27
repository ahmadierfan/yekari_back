<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('detail');
            $table->string('icon', 40)->default('map-pin');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            // موقعیت روی بوم نقشهٔ سبک‌دار اپ (درصدی) — تا نقشهٔ واقعی بیاید
            $table->unsignedTinyInteger('map_x')->default(50);
            $table->unsignedTinyInteger('map_y')->default(36);
            // آدرس پیش‌فرض = کمترین sort (همان قاعدهٔ «اولین آیتم فهرست» در اپ مشتری)
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('courier_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('state', 16)->default('pending'); // pending | active | suspended
            $table->string('vehicle', 32)->default('موتور');
            $table->json('plate')->nullable();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->boolean('online')->default(false);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('located_at')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('missions_count')->default(0);
            $table->unsignedInteger('offers_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedInteger('on_time_count')->default(0);
            /** کف تسویه — مقداری که همیشه نزد یکاری می‌ماند (تومان) */
            $table->unsignedInteger('min_payout')->default(200000);
            $table->timestamps();
        });

        Schema::create('courier_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 24); // id | license | vehicle | selfie | no_addiction
            $table->string('status', 12)->default('pending'); // pending | verified | rejected
            $table->string('path');
            $table->string('reject_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        Schema::create('work_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedInteger('bonus')->nullable();
            $table->timestamps();
        });

        Schema::create('work_block_reservations', function (Blueprint $table) {
            $table->foreignId('work_block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['work_block_id', 'user_id']);
        });

        Schema::create('bank_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pan', 16);
            $table->string('bank');
            $table->string('iban', 26)->nullable();
            $table->string('owner');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'pan']);
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 16)->default('customer'); // customer | courier | admin | corporate
            $table->string('icon', 40)->default('bell');
            $table->string('tone', 12)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('to')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'app', 'read_at']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 12); // login | create | update | delete | money | block
            $table->string('what');
            $table->nullableMorphs('subject');
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('kind');
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'app_notifications', 'bank_cards', 'work_block_reservations', 'work_blocks', 'courier_documents', 'courier_profiles', 'addresses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
