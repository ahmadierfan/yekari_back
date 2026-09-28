<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierDocument extends Model
{
    /** همان پیش‌فرض ستون دیتابیس، تا مدل تازه‌ساخته (بدون refresh) در پاسخ API وضعیت داشته باشد */
    protected $attributes = ['status' => 'pending'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
