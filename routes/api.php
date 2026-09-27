<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Corporate\OrganizationController;
use App\Http\Controllers\Courier;
use App\Http\Controllers\Customer;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
 * همهٔ مسیرها زیر /api/v1. چهار گروه، هرکدام پشت `app:<name>` (توکن همان اپ + نقش):
 *   /customer/*  /courier/*  /admin/* (هر مسیر پشت permission:*)  /corporate/*
 * `/auth/*` و `/catalog` مشترک‌اند.
 */

/* ── ورود ─────────────────────────────────────────── */
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:otp')->group(function () {
        Route::post('otp/request', [AuthController::class, 'requestOtp']);
        Route::post('password/forgot', [AuthController::class, 'forgotPassword']);
    });
    Route::middleware('throttle:login')->group(function () {
        Route::post('otp/verify', [AuthController::class, 'verifyOtp']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('password/reset', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'changePassword']);
        Route::post('phone/request', [AuthController::class, 'requestPhoneChange'])->middleware('throttle:otp');
        Route::post('phone/confirm', [AuthController::class, 'confirmPhoneChange']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::match(['get', 'post'], 'payment/callback/{gateway}', [PaymentController::class, 'callback'])->name('payment.callback');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('catalog', [CatalogController::class, 'bootstrap']);

    /* ── اپ مشتری ─────────────────────────────────── */
    Route::prefix('customer')->middleware('app:customer')->group(function () {
        Route::apiResource('addresses', Customer\AddressController::class)->except('show');
        Route::post('addresses/{address}/default', [Customer\AddressController::class, 'makeDefault']);

        Route::post('orders/quote', [Customer\OrderController::class, 'quote']);
        Route::get('orders', [Customer\OrderController::class, 'index']);
        Route::post('orders', [Customer\OrderController::class, 'store']);
        Route::get('orders/{order}', [Customer\OrderController::class, 'show']);
        Route::post('orders/{order}/cancel', [Customer\OrderController::class, 'cancel']);
        Route::post('orders/{order}/rate', [Customer\OrderController::class, 'rate']);
        Route::post('orders/{order}/attachments', [Customer\OrderController::class, 'attach']);
        Route::post('orders/{order}/report', [Customer\OrderController::class, 'report']);
        Route::get('orders/{order}/messages', [ChatController::class, 'index']);
        Route::post('orders/{order}/messages', [ChatController::class, 'store']);

        Route::get('wallet', [WalletController::class, 'show']);
        Route::get('wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('wallet/topup', [WalletController::class, 'topup']);
        Route::post('wallet/withdraw', [WalletController::class, 'withdraw']);
        Route::get('cards', [WalletController::class, 'cards']);
        Route::post('cards', [WalletController::class, 'addCard']);
        Route::delete('cards/{card}', [WalletController::class, 'removeCard']);
        Route::post('cards/{card}/primary', [WalletController::class, 'primaryCard']);

        Route::get('tickets', [TicketController::class, 'index']);
        Route::post('tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply']);
        Route::post('tickets/{ticket}/close', [TicketController::class, 'close']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        Route::get('membership', [Customer\MembershipController::class, 'show']);
    });

    /* ── اپ پیک ───────────────────────────────────── */
    Route::prefix('courier')->middleware('app:courier')->group(function () {
        Route::get('profile', [Courier\ProfileController::class, 'show']);
        Route::patch('profile', [Courier\ProfileController::class, 'update']);
        Route::post('documents', [Courier\ProfileController::class, 'uploadDocument']);
        Route::post('online', [Courier\ProfileController::class, 'setOnline']);
        Route::post('location', [Courier\ProfileController::class, 'location'])->middleware('throttle:30,1');

        Route::get('current', [Courier\MissionController::class, 'current']);
        Route::post('offers/{offer}/accept', [Courier\MissionController::class, 'accept']);
        Route::post('offers/{offer}/decline', [Courier\MissionController::class, 'decline']);
        Route::get('missions', [Courier\MissionController::class, 'history']);
        Route::get('missions/{order}', [Courier\MissionController::class, 'show']);
        Route::post('missions/{order}/advance', [Courier\MissionController::class, 'advance']);
        Route::post('missions/{order}/expense', [Courier\MissionController::class, 'expense']);
        Route::post('missions/{order}/proof', [Courier\MissionController::class, 'proof']);
        Route::post('missions/{order}/issue', [Courier\MissionController::class, 'issue']);
        Route::post('missions/{order}/resolve', [Courier\MissionController::class, 'resolve']);
        Route::post('missions/{order}/abandon', [Courier\MissionController::class, 'abandon']);
        Route::get('missions/{order}/messages', [ChatController::class, 'index']);
        Route::post('missions/{order}/messages', [ChatController::class, 'store']);
        Route::get('earnings', [Courier\MissionController::class, 'earnings']);

        Route::get('blocks', [Courier\BlockController::class, 'index']);
        Route::post('blocks/{block}/toggle', [Courier\BlockController::class, 'toggle']);

        Route::get('wallet', [WalletController::class, 'show']);
        Route::get('wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('payouts', [WalletController::class, 'withdraw']);
        Route::get('cards', [WalletController::class, 'cards']);
        Route::post('cards', [WalletController::class, 'addCard']);
        Route::delete('cards/{card}', [WalletController::class, 'removeCard']);
        Route::post('cards/{card}/primary', [WalletController::class, 'primaryCard']);

        Route::get('tickets', [TicketController::class, 'index']);
        Route::post('tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
    });

    /* ── پنل مدیریت — هر مسیر پشت دسترسی ریزش ──────── */
    Route::prefix('admin')->middleware('app:admin')->group(function () {
        Route::get('dashboard', [Admin\DashboardController::class, 'index']);
        Route::get('reports', [Admin\DashboardController::class, 'reports'])->middleware('permission:reports.view');

        Route::middleware('permission:orders.view')->group(function () {
            Route::get('orders', [Admin\OrderController::class, 'index']);
            Route::get('orders/{order}', [Admin\OrderController::class, 'show']);
            Route::get('orders/{order}/messages', [Admin\OrderController::class, 'chat']);
        });
        Route::post('orders/{order}/cancel', [Admin\OrderController::class, 'cancel'])->middleware('permission:orders.manage');
        Route::post('orders/{order}/dispute', [Admin\OrderController::class, 'resolveDispute'])->middleware('permission:orders.disputes');

        Route::get('users', [Admin\UserController::class, 'users'])->middleware('permission:users.view');
        Route::post('users/{user}/block', [Admin\UserController::class, 'toggleBlock'])->middleware('permission:users.block');
        Route::middleware('permission:couriers.view')->group(function () {
            Route::get('couriers', [Admin\UserController::class, 'couriers']);
            Route::get('couriers/{user}', [Admin\UserController::class, 'courier']);
            Route::get('documents/{document}/file', [Admin\UserController::class, 'documentFile'])->name('admin.documents.file');
        });
        Route::middleware('permission:couriers.review')->group(function () {
            Route::post('documents/{document}/review', [Admin\UserController::class, 'reviewDocument']);
            Route::post('couriers/{user}/state', [Admin\UserController::class, 'setCourierState']);
        });

        Route::middleware('permission:finance.view')->group(function () {
            Route::get('withdrawals', [Admin\FinanceController::class, 'withdrawals']);
            Route::get('users/{user}/transactions', [Admin\FinanceController::class, 'userTransactions']);
        });
        Route::post('withdrawals/{withdrawal}/resolve', [Admin\FinanceController::class, 'resolveWithdrawal'])->middleware('permission:finance.payouts');
        Route::post('users/{user}/adjust', [Admin\FinanceController::class, 'adjust'])->middleware('permission:finance.adjust');

        Route::get('pricing', [Admin\PricingController::class, 'index'])->middleware('permission:pricing.view');
        Route::middleware('permission:pricing.update')->group(function () {
            Route::patch('pricing/tariffs/{type}', [Admin\PricingController::class, 'updateTariff']);
            Route::patch('pricing/settings', [Admin\PricingController::class, 'updateSettings']);
            Route::get('promos', [Admin\PricingController::class, 'promos']);
            Route::post('promos', [Admin\PricingController::class, 'savePromo']);
            Route::put('promos/{promo}', [Admin\PricingController::class, 'savePromo']);
        });

        Route::middleware('permission:tickets.view')->group(function () {
            Route::get('tickets', [Admin\TicketController::class, 'index']);
            Route::get('tickets/{ticket}', [Admin\TicketController::class, 'show']);
        });
        Route::middleware('permission:tickets.reply')->group(function () {
            Route::post('tickets/{ticket}/reply', [Admin\TicketController::class, 'reply']);
            Route::patch('tickets/{ticket}', [Admin\TicketController::class, 'update']);
        });

        Route::middleware('permission:content.manage')->group(function () {
            Route::get('content', [Admin\ContentController::class, 'index']);
            Route::post('slides', [Admin\ContentController::class, 'saveSlide']);
            Route::put('slides/{slide}', [Admin\ContentController::class, 'saveSlide']);
            Route::post('slides/{slide}/toggle', [Admin\ContentController::class, 'toggleSlide']);
            Route::delete('slides/{slide}', [Admin\ContentController::class, 'deleteSlide']);
            Route::post('faq', [Admin\ContentController::class, 'saveFaq']);
            Route::put('faq/{faq}', [Admin\ContentController::class, 'saveFaq']);
            Route::delete('faq/{faq}', [Admin\ContentController::class, 'deleteFaq']);
            Route::post('blocks', [Admin\ContentController::class, 'saveBlock']);
        });

        Route::middleware('permission:corporate.view')->group(function () {
            Route::get('contracts', [Admin\ContractController::class, 'index']);
            Route::get('contracts/{organization}', [Admin\ContractController::class, 'show']);
        });
        Route::middleware('permission:corporate.manage')->group(function () {
            Route::post('contracts', [Admin\ContractController::class, 'store']);
            Route::patch('contracts/{organization}', [Admin\ContractController::class, 'update']);
            Route::post('contracts/{organization}/status', [Admin\ContractController::class, 'setStatus']);
            Route::post('contracts/{organization}/credit', [Admin\ContractController::class, 'credit'])->middleware('permission:finance.adjust');
        });

        Route::middleware('permission:roles.view')->group(function () {
            Route::get('staff', [Admin\StaffController::class, 'index']);
            Route::get('roles', [Admin\StaffController::class, 'roles']);
        });
        Route::middleware('permission:roles.manage')->group(function () {
            Route::post('staff', [Admin\StaffController::class, 'store']);
            Route::post('staff/{user}/role', [Admin\StaffController::class, 'setRole']);
            Route::put('roles/{role}', [Admin\StaffController::class, 'updateRole']);
        });
        Route::get('logs', [Admin\LogController::class, 'index'])->middleware('permission:logs.view');
    });

    /* ── پنل سازمانی — مدیر سازمان ─────────────────── */
    Route::prefix('corporate')->middleware('app:corporate')->group(function () {
        Route::get('orgs', [OrganizationController::class, 'mine']);
        Route::prefix('orgs/{organization}')->group(function () {
            Route::get('/', [OrganizationController::class, 'show']);
            Route::get('members', [OrganizationController::class, 'members']);
            Route::post('members', [OrganizationController::class, 'addMember']);
            Route::patch('members/{member}', [OrganizationController::class, 'updateMember']);
            Route::get('cost-centers', [OrganizationController::class, 'costCenters']);
            Route::post('cost-centers', [OrganizationController::class, 'saveCostCenter']);
            Route::put('cost-centers/{costCenter}', [OrganizationController::class, 'saveCostCenter']);
            Route::get('usage', [OrganizationController::class, 'usage']);
            Route::get('orders', [OrganizationController::class, 'orders']);
            Route::get('invoices', [OrganizationController::class, 'invoices']);
            Route::get('invoices/{invoice}/csv', [OrganizationController::class, 'invoiceCsv']);
            Route::post('topup', [OrganizationController::class, 'topup']);
        });
    });
});
