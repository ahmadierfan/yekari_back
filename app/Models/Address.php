<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** شکل snapshot ذخیره‌شده روی سفارش */
    public function snapshot(): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'detail' => $this->detail, 'icon' => $this->icon,
            'lat' => (float) $this->lat, 'lng' => (float) $this->lng, 'x' => $this->map_x, 'y' => $this->map_y,
        ];
    }
}
