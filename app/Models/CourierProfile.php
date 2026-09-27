<?php

namespace App\Models;

use App\Domain\Domain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'plate' => 'array', 'online' => 'boolean', 'located_at' => 'datetime',
            'lat' => 'float', 'lng' => 'float', 'rating' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function acceptRate(): int
    {
        return $this->offers_count ? (int) round($this->accepted_count * 100 / $this->offers_count) : 0;
    }

    public function cancelRate(): int
    {
        return $this->accepted_count ? (int) round($this->cancelled_count * 100 / $this->accepted_count) : 0;
    }

    public function onTimeRate(): int
    {
        return $this->missions_count ? (int) round($this->on_time_count * 100 / $this->missions_count) : 0;
    }

    /** همهٔ مدارک تأییدشده؟ — شرط آنلاین‌شدن */
    public function isVerified(): bool
    {
        $verified = CourierDocument::where('user_id', $this->user_id)->where('status', 'verified')->pluck('key')->all();

        return count(array_diff(Domain::COURIER_DOCS, $verified)) === 0;
    }
}
