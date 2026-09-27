<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('courier_id')->nullable()->constrained('users');
            $table->string('mission_type', 32);
            $table->string('status', 16)->default('searching')->index();
            /** وضعیت پیش از شکایت — تا مأموریت گزارش‌شده بن‌بست نشود */
            $table->string('disputed_from', 16)->nullable();
            // آدرس‌ها snapshot می‌شوند: ویرایش دفترچهٔ آدرس نباید تاریخچهٔ سفارش را عوض کند
            $table->json('pickup');
            $table->json('dropoff')->nullable();
            $table->text('description');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('budget_cap')->nullable();
            $table->decimal('distance_km', 6, 2)->default(0);
            // قیمت تفکیک‌شده — تومان
            $table->unsignedBigInteger('price_service');
            $table->unsignedBigInteger('price_distance');
            $table->unsignedBigInteger('price_waiting')->default(0);
            $table->unsignedBigInteger('price_goods')->default(0);
            $table->unsignedBigInteger('price_discount')->default(0);
            /** نرخ کمیسیون لحظهٔ ثبت — تغییر بعدی نرخ، سفارش‌های قبلی را عوض نمی‌کند */
            $table->decimal('commission_rate', 4, 3);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('courier_payout')->default(0);
            $table->unsignedBigInteger('held_amount')->default(0);
            $table->boolean('escrow')->default(true);
            $table->string('pay_method', 16); // wallet | gateway | corporate
            $table->string('payment_status', 16)->default('pending'); // pending | held | settled | refunded
            $table->string('promo_code', 32)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scheduled_from')->nullable();
            $table->timestamp('scheduled_to')->nullable();
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->unsignedSmallInteger('extra_minutes')->default(0);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->json('rating_tags')->nullable();
            $table->string('rating_note', 500)->nullable();
            $table->unsignedBigInteger('tip')->default(0);
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['courier_id', 'status']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('kind', 16); // photo | prescription | receipt | proof
            $table->string('path');
            $table->unsignedBigInteger('amount')->nullable(); // مبلغ فاکتور خرید
            $table->timestamps();
        });

        /** پیشنهاد مأموریت به یک پیک — با انقضا؛ رد یا انقضا یعنی رفتن سراغ پیک بعدی */
        Schema::create('order_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending | accepted | declined | expired
            $table->unsignedBigInteger('payout');
            $table->decimal('to_pickup_km', 6, 2)->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['courier_id', 'status']);
            $table->unique(['order_id', 'courier_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->string('sender_role', 12); // customer | courier
            $table->string('kind', 8); // text | voice | image
            $table->text('text')->nullable();
            $table->string('path')->nullable();
            $table->unsignedSmallInteger('duration')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 16)->default('customer');
            $table->string('topic', 16);
            $table->string('status', 12)->default('open'); // open | answered | resolved | closed
            $table->string('subject');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from', 8); // me | agent
            $table->text('text');
            $table->json('attachments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ticket_messages', 'tickets', 'chat_messages', 'order_offers', 'order_attachments', 'order_events', 'orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
