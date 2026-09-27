<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حساب سازمانی (قرارداد). نقش داخل سازمان (manager/member) روی جدول عضویت است، نه
 * نقش سراسری — یک نفر می‌تواند مدیر یک شرکت و عضو سادهٔ شرکت دیگری باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status', 12)->default('draft'); // draft | active | expired
            $table->string('billing_model', 12); // prepaid | postpaid
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->unsignedBigInteger('monthly_ceiling')->default(0);
            $table->unsignedSmallInteger('seats')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('national_id', 16)->nullable();
            $table->string('economic_code', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('member'); // manager | member
            /** سقف مصرف ماهانهٔ همین عضو؛ null یعنی فقط سقف کل شرکت */
            $table->unsignedBigInteger('monthly_limit')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 24)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('orders_count');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount');
            $table->unsignedBigInteger('vat');
            $table->unsignedBigInteger('total');
            $table->string('status', 12)->default('issued'); // issued | paid | void
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'period_start']);
        });
    }

    public function down(): void
    {
        foreach (['organization_invoices', 'cost_centers', 'organization_members', 'organizations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
