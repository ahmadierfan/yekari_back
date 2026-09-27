<?php

namespace App\Domain;

/**
 * دسترسی‌های پنل. گروه‌ها همان هشت کلید `PERMISSIONS` در domain.ts هستند (ماتریس صفحهٔ
 * «نقش‌ها» همان‌طور گروهی می‌ماند)، ولی هر گروه به کارهای ریز «دیدن»/«تغییر» شکسته شده
 * تا مثلاً پشتیبانی سفارش‌ها را ببیند ولی نتواند لغو کند.
 *
 * نقش `super-admin` در این فهرست نیست: `Gate::before` همه‌چیز را برایش باز می‌کند.
 */
final class Permissions
{
    public const GROUPS = [
        'orders' => ['orders.view', 'orders.manage', 'orders.disputes'],
        'users' => ['users.view', 'users.block', 'couriers.view', 'couriers.review'],
        'finance' => ['finance.view', 'finance.payouts', 'finance.adjust', 'reports.view'],
        'pricing' => ['pricing.view', 'pricing.update'],
        'tickets' => ['tickets.view', 'tickets.reply'],
        'content' => ['content.manage'],
        'corporate' => ['corporate.view', 'corporate.manage'],
        'roles' => ['roles.view', 'roles.manage', 'logs.view'],
    ];

    /** نقش‌های پیش‌فرض پنل — همان `ADMIN_ROLES` در domain.ts */
    public const ROLES = [
        'super-admin' => ['title' => 'مدیر ارشد', 'groups' => ['orders', 'users', 'finance', 'pricing', 'tickets', 'content', 'corporate', 'roles']],
        'ops' => ['title' => 'اپراتور عملیات', 'groups' => ['orders', 'users', 'tickets']],
        'finance' => ['title' => 'کارشناس مالی', 'groups' => ['finance', 'pricing', 'corporate'], 'extra' => ['orders.view']],
        'support' => ['title' => 'کارشناس پشتیبانی', 'groups' => ['tickets'], 'extra' => ['orders.view', 'users.view', 'couriers.view']],
    ];

    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function forGroups(array $groups): array
    {
        return array_merge(...array_map(fn ($g) => self::GROUPS[$g] ?? [], $groups ?: ['__none']));
    }

    /** گروه‌هایی که یک نقش کامل دارد (برای ماتریس ساده‌شدهٔ پنل) */
    public static function groupsOf(array $perms): array
    {
        return array_keys(array_filter(self::GROUPS, fn ($ps) => ! array_diff($ps, $perms)));
    }
}
