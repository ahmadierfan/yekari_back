<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پول. سه اصل:
 * ۱. موجودی هیچ‌وقت مستقیم ویرایش نمی‌شود؛ فقط از `WalletService` و همیشه همراه یک
 *    ردیف دفتر (`wallet_transactions`) با موجودی بعد از تراکنش.
 * ۲. «قابل استفاده» (balance) از «بلوکه‌شده» (held) جداست — پرداخت امن.
 * ۳. همه‌چیز تومان و عدد صحیح. تبدیل به ریال فقط لحظهٔ صحبت با درگاه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner'); // User | Organization
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('held')->default(0);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12); // TxKind در domain.ts
            $table->unsignedBigInteger('amount');
            $table->bigInteger('balance_after');
            $table->unsignedBigInteger('held_after');
            $table->string('title');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_id', 'created_at']);
        });

        /** برداشت مشتری و تسویهٔ پیک — هر دو صف بررسی مالی دارند */
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_card_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12); // withdraw | payout
            $table->unsignedBigInteger('amount');
            $table->string('pan', 16);
            $table->string('status', 12)->default('pending'); // pending | done | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reference')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
        });

        /** همان نقش m_gatewaytransactions پروژهٔ مرجع: یک ردیف به ازای هر رفت‌وبرگشت درگاه */
        Schema::create('gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 16); // zibal | zarinpal | fake
            $table->unsignedBigInteger('amount'); // تومان
            $table->string('authority')->nullable()->unique();
            $table->string('status', 12)->default('pending'); // pending | paid | cancelled
            $table->string('ref_id')->nullable();
            $table->string('return_url')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['gateway_transactions', 'withdrawals', 'wallet_transactions', 'wallets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
