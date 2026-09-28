<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderOffer extends Model
{
    /** همان پیش‌فرض ستون دیتابیس، تا مدل تازه‌ساخته (بدون refresh) در پاسخ API وضعیت داشته باشد */
    protected $attributes = ['status' => 'pending'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'to_pickup_km' => 'float'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }
}
