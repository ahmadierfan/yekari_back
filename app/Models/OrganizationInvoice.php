<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvoice extends Model
{
    /** همان پیش‌فرض ستون دیتابیس، تا مدل تازه‌ساخته (بدون refresh) در پاسخ API وضعیت داشته باشد */
    protected $attributes = ['status' => 'issued'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'paid_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
