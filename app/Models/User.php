<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** نقش‌های پنل؛ هرکدام از این‌ها یعنی «کارمند یکاری» */
    public const STAFF_ROLES = ['super-admin', 'ops', 'finance', 'support'];

    protected $fillable = ['name', 'mobile', 'email', 'password', 'status', 'avatar', 'prefs', 'onboarded_at', 'last_seen_at', 'mobile_verified_at'];

    protected $hidden = ['password', 'remember_token'];

    /** نقش‌ها با guard پیش‌فرض web ثبت شده‌اند؛ توکن Sanctum همان کاربر را برمی‌گرداند */
    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'prefs' => 'array',
            'password' => 'hashed',
        ];
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderBy('sort')->orderBy('id');
    }

    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'owner');
    }

    public function courierProfile(): HasOne
    {
        return $this->hasOne(CourierProfile::class);
    }

    public function courierDocuments(): HasMany
    {
        return $this->hasMany(CourierDocument::class);
    }

    public function bankCards(): HasMany
    {
        return $this->hasMany(BankCard::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /** عضویت فعال در یک سازمانِ جاری (اولی کافی است؛ چندسازمانی بودن یک کاربر نادر است) */
    public function activeMembership(): ?OrganizationMember
    {
        return $this->memberships()
            ->where('active', true)
            ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
            ->with('organization')
            ->first();
    }
}
