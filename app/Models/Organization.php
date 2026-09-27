<?php

namespace App\Models;

use App\Domain\Period;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Organization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(OrganizationInvoice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'owner');
    }

    public function isPrepaid(): bool
    {
        return $this->billing_model === 'prepaid';
    }

    /** مصرف از ابتدای ماه شمسی جاری (سفارش‌های لغونشده) — تومان */
    public function usedThisMonth(?int $userId = null): int
    {
        $q = $this->orders()
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->where('created_at', '>=', Period::jalaliMonth()[0]);
        if ($userId) {
            $q->where('customer_id', $userId);
        }

        return (int) $q->get()->sum(fn (Order $o) => $o->total());
    }
}
