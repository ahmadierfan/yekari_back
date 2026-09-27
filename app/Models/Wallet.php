<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** ⚠️ موجودی را مستقیم ویرایش نکن — فقط `App\Services\WalletService` */
class Wallet extends Model
{
    protected $guarded = ['id', 'balance', 'held'];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }
}
