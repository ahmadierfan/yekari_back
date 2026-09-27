<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * تنظیمات سراسری قابل‌ویرایش از پنل (کمیسیون، پله‌های پاداش، حداقل‌ها…).
 * پیش‌فرض‌ها همان ثابت‌های `domain.ts` است؛ ردیف دیتابیس فقط وقتی ساخته می‌شود که
 * پنل عوضش کند.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public const DEFAULTS = [
        'commission_rate' => 0.18,
        'bonus_tiers' => [['missions' => 5, 'amount' => 150000], ['missions' => 8, 'amount' => 300000], ['missions' => 12, 'amount' => 550000]],
        'tip_presets' => [10000, 20000, 50000],
        'min_tip' => 5000,
        'min_withdraw' => 100000,
    ];

    public static function get(string $key): mixed
    {
        return Cache::rememberForever("setting:$key", function () use ($key) {
            $row = static::find($key);

            return $row ? $row->value : (self::DEFAULTS[$key] ?? null);
        });
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:$key");
    }
}
